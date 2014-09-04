<?php

require('../../config.php');
require('../hub/top/sites/siteslib.php');
require_once($CFG->libdir.'/tablelib.php');

define('LINKCHECKER_DIR', 'local/linkchecker');

$limitnum = optional_param('limitnum', 400, PARAM_INT); //for now a fixed 400 out of 183000 for 95% (-/+5%) confidence.

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/linkchecker/'));
$PAGE->requires->jquery();

$isadmin = ismoodlesiteadmin();
if (!$isadmin) {
    redirect('http://moodle.net/sites'); //shoo
}
$chkurl = optional_param('url', null, PARAM_URL);
//$siteid = optional_param('id', null, PARAM_URL);
if ($chkurl !== null) {
    $hdrs = get_headers($chkurl.'/lib/womenslib.php',1);
    if ($hdrs == false) {
        //return some error info.
        $err = error_get_last();
        echo $err['message'];
    } else {
        //also can check login/forgot_password.php ..for some stuff...
        // anyway the point is humans check and feedback. Devs filter that feedback and transform into rules.. barring AI ofcourse.
        echo htmlspecialchars($hdrs[0]); // ' Mod:'. $hdrs[3]); //http code and modified.
    }
    die();
}

$PAGE->navbar->add('Registered sites', new moodle_url('/local/linkchecker/'));
$PAGE->set_title(get_string('registeredmoodlesites_moodlenet', 'local_hub'));
$PAGE->set_heading(get_string('registeredmoodlesites', 'local_hub'));


echo $OUTPUT->header();

$totrecs = $DB->count_records('hub_site_directory');

$limitfrom = optional_param('limitfrom', rand(1, $totrecs-$limitnum), PARAM_INT);

list($where, $params) = local_hub_stats_get_confirmed_sql();

$allfailedrecids = $DB->get_fieldset_sql('Select id from {hub_site_directory} r WHERE NOT('. $where. ')' , $params); //get all failed site ids.

$randomrecordids = array();
$id=0;
while (count($randomrecordids)<$limitnum) {
    while(!in_array($id=rand(0, $totrecs-1), $randomrecordids) && in_array($id,$allfailedrecids)) { //get unique random list of ids.
        $randomrecordids[] = $id;
        break;
    }
}

list($in_sql, $params) = $DB->get_in_or_equal($randomrecordids, SQL_PARAMS_NAMED, 'r', true);
$im = getcoverageimg($totrecs, $randomrecordids);
$failedrecs = $DB->get_records_sql('Select id, url, unreachable, score, fingerprint, errormsg '
        . 'from {hub_site_directory} r WHERE id '.$in_sql , $params);

// Outputs table
    $table = new html_table();
//    $table->attributes['class'] = 'collection';

    $table->head = array(
                "id",
                'url',
                'unreachable',
                'score',
                'Previous fingerprint OR cron-linkchecker:errormsg',
                'Now checking for womens liberty..'
            );
    $table->colclasses = array();
    
    foreach ($failedrecs as $rec) {
        
        $cell = new html_table_cell('Checking..');
        $cell->attributes = array('id' => $rec->id, 'class' => 'manualcheck', 'url' => $rec->url);

        $row = array($rec->id, '<a href="'.$rec->url.'" target="_blank">'.$rec->url.'</a>', $rec->unreachable, $rec->score, (strlen($rec->errormsg)>0)?$rec->errormsg:'fingerprint:'.$rec->fingerprint, $cell);
        $table->data[] = $row;
    }
    $htmltable = html_writer::table($table);

    list($sql, $params) = local_hub_stats_get_confirmed_sql();
    $sql = "SELECT count(*) as onlinesitescount FROM {hub_site_directory} r WHERE ".$sql;
    $totsitesonline = $DB->get_record_sql($sql, $params);
    
    echo '<span class="totrec">Total sites: '. $totrecs. '</span> | <span class="online">Total online &amp; moodley: '.$totsitesonline->onlinesitescount .'</span> | <span class="limitnum">Offline sites loaded in table: '. $limitnum. ' </span> | ';
    echo '<span class="checked">Checked: <span class="chkcnt">0</span></span> | <span class="notfail">not failed: <span class="notfailcnt">0</span></span>  | <span class="fails">Desired Fails: <span class="failcnt">0</span></span> | <span class="percentage">linkchecking fraction (desiredfails/checked): <span class="perc" style="color:#C00;"></span></span>';
    echo '<br/>Coverage of unmoodle sites:<img class="samplingfailedrecsdistribution" style="width:100%; height:20px;" src="data:image/png;base64,';
    ob_start();
    imagepng($im);
    $im = ob_get_contents();
    ob_end_clean();
    echo base64_encode($im);
    echo '" />';
    echo '<br/><span class="confidence">Default sample size 400 is for a 95% (+/-5%) confidence test.  Adjustable in url by appending "?limitnum=xxx". Please remember to adjust your linkchecking percentage after human checking and raise moodley sites from here to developers.</span>';

    echo $htmltable ;
//    $nonmoodlecnt = $totrecs-$totsitesonline->onlinesitescount;

$jsscr = <<<js
<script>
    function linkchecker() {
            var chkcnt;
            var failcnt;
            var notfailcnt;
            var percentage;
            var confidence;
            var Z = 1.96; //for 95% confidence in a std distribution (about Random 400 samples for over 183k sites will be good)
            $(".manualcheck").each(function(index, element){
                element.innerHTML = 'Checking, sent to server, awaiting response..';
                $.get('index.php',
                    { url: this.getAttribute('url') },
                    function(responseText) {
                    console.log(responseText);
                    element.innerHTML = responseText;
                    chkcnt = $(".chkcnt").html();
                    chkcnt++;
                    $(".chkcnt").html(chkcnt);
                    if (responseText.indexOf("200 OK") >= 0) {
                        element.innerHTML = '<span style="color:#ff0000">' + responseText + ' Human verification will be good here.</span>';
                        notfailcnt = $(".notfailcnt").html();
                        notfailcnt++;
                        $(".notfailcnt").html(notfailcnt);
                    } else {
                        failcnt = $(".failcnt").html();
                        failcnt++;
                        $(".failcnt").html(failcnt);
                        console.log("desired fail count:"+failcnt);
                        percentage = failcnt / chkcnt;
                        console.log("linkchecker percentage(%):"+percentage);
                        $(".perc").html(percentage);
                    }
                });
            });
    }
    linkchecker();
</script>
js;

echo $jsscr; // bloody yui

echo $OUTPUT->footer();

function getcoverageimg($totrecs, $randomrecordids, $highlightrecs=null) {
    core_php_time_limit::raise(300);
    $width = 18000; $height = 10; $padding = 10;
    $column_width = $width / $totrecs ;
    $im        = imagecreate($width,$height);
    $gray      = imagecolorallocate ($im,0xcc,0xcc,0xcc);
    $gray_lite = imagecolorallocate ($im,0xee,0xee,0xee);
    $gray_dark = imagecolorallocate ($im,0x7f,0x7f,0x7f);
    $white     = imagecolorallocate ($im,0xff,0xff,0xff);
    imagefilledrectangle($im,0,0,$width,$height,$white);
    $maxv = 1;
    for($i=0;$i<$totrecs;$i++)
    {
        $column_height = 1+ (9* (int) in_array($i, $randomrecordids));
        $x1 = $i*$column_width;
        $y1 = $height-$column_height;
        $x2 = (($i+1)*$column_width)-$padding;
        $y2 = $height;
        imagefilledrectangle($im,$x1,$y1,$x2,$y2,$gray);
    }
    return $im;
}