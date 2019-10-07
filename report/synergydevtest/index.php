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
 * Report the progress of any given user in their enrolled courses
 *
 * @package    report
 * @author     Steven McCullagh <career@stevenmccullagh.com>
 * @subpackage synergydevtest
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/report/completion/lib.php');
require_once($CFG->libdir.'/completionlib.php');

// Requires login
require_login();

// User must be a site admin, or redirect to login
if (!is_siteadmin()) {
    redirect($CFG->wwwroot.'/login/index.php');
}

global $DB;

// Print the header
admin_externalpage_setup('reportsynergydevtest', '', null, '', array('pagelayout'=>'report'));
echo $OUTPUT->header();

// Optionally require the id of the student the report is for
$student_id = optional_param('id', 0, PARAM_INTEGER);

// When there's no student, show a list of students
if (empty($student_id)) :
    // Pull all known students from the database, ordered by name
    // We must identify user role assignments to verify that they are students enrolled on courses
    // To avoid issues of duplicated IDs, and given the field is useless to us anyway, the first
    // field of this query is a random number. This satisfies moodle data retrieval limitations.
    $all_students = $DB->get_records_sql('
        SELECT RAND() as id, course.fullname as course_name, usr.id as user_id, usr.firstname, usr.lastname, usr.email
        FROM {course} course
         INNER JOIN {context} cx ON course.id = cx.instanceid
         INNER JOIN {role_assignments} ra ON cx.id = ra.contextid
         INNER JOIN {user} usr ON ra.userid = usr.id
        GROUP BY user_id
        ORDER BY usr.lastname, usr.firstname');

    echo $OUTPUT->box_start('generalbox boxwidthwide boxaligncenter centerpara');
    echo $OUTPUT->heading(get_string('selectstudent', 'report_synergydevtest'));
    echo '<p id="intro">', get_string('intro', 'report_synergydevtest') , '</p>';

    // If there are no students, inform the user
    if (empty($all_students)) {
        echo '<p>' . get_string('nostudents', 'report_synergydevtest') . '</p>';
    } else {
        // Display list of users
        $table_data = [];
        foreach ($all_students as $key => $student) {
             $table_data[$key]["firstname"] = $student->firstname;
             $table_data[$key]["lastname"] = $student->lastname;
             $table_data[$key]["action"] = '<a href="?id=' . $student->user_id . '">' . get_string('viewreport', 'report_synergydevtest') . '</a>';
        }

        $table = new html_table();
        $table->head  = array(
            get_string('firstname', 'report_synergydevtest'),
            get_string('lastname', 'report_synergydevtest'),
            get_string('action', 'report_synergydevtest'),
        );
        $table->colclasses = array('mdl-left issue', 'mdl-left value', 'mdl-right comments');
        $table->attributes = array('class' => 'admintable generaltable');
        $table->id = 'studentlist';
        $table->data  = $table_data;
        echo html_writer::table($table);
    }
    echo '</div></form>';
    echo $OUTPUT->box_end();
endif;

// If a student id has been provided, get student data
if (!empty($student_id)) :
    // Find student in db
    $student = $DB->get_record('user', array('id' => $student_id, 'deleted' => 0), '*', MUST_EXIST);
    if (empty($student)) {
        echo '<p>' . get_string('studentnotfound', 'report_synergydevtest') . '</p>';
    } else {

        echo $OUTPUT->box_start('generalbox boxwidthwide boxaligncenter centerpara');
        echo $OUTPUT->heading(get_string('reporttitle', 'report_synergydevtest', $student->firstname . " " . $student->lastname));

        // Find courses student is enrolled in
        $student_courses = enrol_get_all_users_courses($student->id, true);

        $table_data = [];

        // Sort courses alphabetically
        usort($student_courses, function($a, $b) {
            return strcmp($a->fullname, $b->fullname);
        });

        foreach ($student_courses as $key => $course) {
            $cinfo = new completion_info($course);
            $is_enabled = $cinfo->is_enabled();

            // Verify that course completion is enabled on the given course within Moodle
            if (!$is_enabled) {
                echo '<p>' . get_string('nocompletiontracking', 'report_synergydevtest') . '</p>';
            } else {
                // Get course completion data
                $params = array(
                    'userid'    => $student->id,
                    'course'  => $course->id
                );

                $ccompletion = new completion_completion($params);
                $completion_data =  $ccompletion->fetch($params);
                $completedate = "n/a";
                if (!empty($completion_data)) {
                    $completedate = userdate($completion_data->timecompleted, get_string('strftimedatetime', 'langconfig'));
                }

                $iscomplete = $cinfo->is_course_complete($student->id);
                $table_data[$key]["title"] = $course->fullname;
                $table_data[$key]["completedate"] = $completedate;
                $table_data[$key]["complete"] = ($iscomplete) ? "Complete" : "Not Complete";
                $url = new moodle_url('/course/view.php', array('id' => $course->id));
                $table_data[$key]["id"] = '<a href="' . $url . '"> &raquo; ' . get_string('viewcourse', 'report_synergydevtest', $course->id) . '</a>';
            }
        }

        // Output table
        $table = new html_table();
        $table->head  = array(
            get_string('coursetitle', 'report_synergydevtest'),
            get_string('coursedatecompleted', 'report_synergydevtest'),
            get_string('coursestatus', 'report_synergydevtest'),
            get_string('courselink', 'report_synergydevtest'));
        $table->colclasses = array('mdl-left issue', 'mdl-left value','mdl-left value', 'mdl-right comments');
        $table->attributes = array('class' => 'admintable generaltable');
        $table->id = 'courseprogress';
        $table->data  = $table_data;
        echo html_writer::table($table);
        echo $OUTPUT->box_end();
    }
endif;

// Footer.
echo $OUTPUT->footer();
