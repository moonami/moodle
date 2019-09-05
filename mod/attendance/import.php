<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Import attendance sessions
 *
 * @package   mod_attendance
 * @copyright  2011 Artem Andreev <andreev.artem@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);

require_once(dirname(__FILE__).'/../../config.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once(dirname(__FILE__).'/import_form.php');
require_once(dirname(__FILE__).'/renderables.php');
require_once(dirname(__FILE__).'/renderhelpers.php');
require_once($CFG->libdir.'/csvlib.class.php');

$id             = required_param('id', PARAM_INT);

$cm             = get_coursemodule_from_id('attendance', $id, 0, false, MUST_EXIST);
$course         = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$att            = $DB->get_record('attendance', array('id' => $cm->instance), '*', MUST_EXIST);

core_php_time_limit::raise(60*60); // 1 hour should be enough
raise_memory_limit(MEMORY_HUGE);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/attendance:manageattendances', $context);

$att = new mod_attendance_structure($att, $cm, $course, $context);

$PAGE->set_url($att->url_import());
$PAGE->set_title($course->shortname. ": ".$att->name);
$PAGE->set_heading($course->fullname);
$PAGE->force_settings_menu(true);
$PAGE->set_cacheable(true);
$PAGE->navbar->add(get_string('import', 'attendance'));

$formparams = array('course' => $course, 'cm' => $cm, 'modcontext' => $context);
$mform = new mod_attendance_import_form($att->url_import(), $formparams);

$returnurl = $att->url_import();

if ($formdata = $mform->get_data()) {
    $now = time();
    $iid = csv_import_reader::get_new_iid('importattendance');
    $cir = new csv_import_reader($iid, 'importattendance');

    $content = $mform->get_file_content('importfile');

    $readcount = $cir->load_csv_content($content, $formdata->encoding, $formdata->delimiter_name);
    $csvloaderror = $cir->get_error();
    unset($content);

    if (!is_null($csvloaderror)) {
        print_error('csvloaderror', '', $returnurl, $csvloaderror);
    }

    // init csv import helper
    $cir->init();
    $linenum = 1; //column header is first line
    $columns = array_flip($cir->get_columns());
    $sessions = array();

    while ($line = $cir->next()) {
        $linenum++;

        $sess = new stdClass();
        $sess->sessdate = 0;
        $sess->duration = round($line[$columns['duration']] * 3600);
        $sess->descriptionitemid = 0;
        $sess->description = '<p>' . $line[$columns['idnumber']] . ': ' . $line[$columns['description']] . '</p>';
        $sess->descriptionformat = FORMAT_HTML;
        $sess->calendarevent = 0;
        $sess->timemodified = $now;
        $sess->studentscanmark = 0;
        $sess->autoassignstatus = 0;
        $sess->subnet = '';
        $sess->studentpassword = '';
        $sess->automark = 0;
        $sess->automarkcompleted = 0;
        $sess->absenteereport = 1;
        $sess->includeqrcode = 0;
        $sess->lasttaken = 0;
        $sess->lasttakenby = 0;

        $sessions[] = $sess;
    }

    $cir->close();

    if (!empty($sessions)) {
        $att->add_sessions($sessions);
    }

    redirect($att->url_manage(), get_string('sessionsgenerated', 'attendance', $linenum));
}

$output = $PAGE->get_renderer('mod_attendance');
$tabs = new attendance_tabs($att, attendance_tabs::TAB_IMPORT);
echo $output->header();
echo $output->heading(get_string('attendanceforthecourse', 'attendance').' :: ' .format_string($course->fullname));
echo $output->render($tabs);

$mform->display();

echo $OUTPUT->footer();
