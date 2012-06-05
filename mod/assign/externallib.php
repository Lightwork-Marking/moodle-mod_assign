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
 * External assign API
 *
 * @package    mod_assign
 * @since      Moodle 2.3
 * @copyright  2012 Paul Charsley
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");

/**
 * Assign functions
 */
class mod_assign_external extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     * @since  Moodle 2.3
     */
    public static function get_assignments_parameters() {
        return new external_function_parameters(
            array(
                'courseids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'course id'),
                    '0 or more course ids',
                    VALUE_DEFAULT, array()),
                'capabilities'  => new external_multiple_structure(
                    new external_value(PARAM_CAPABILITY, 'capability'),
                    'ANDed list of capabilities used to filter courses',
                    VALUE_DEFAULT, array())
            )
        );
    }

    /**
     * Return assignments for courses in which the user is enrolled, optionally filtered
     * by course id and/or capability
     * @param array of ints $courseids specify course ids to be returned
     * @param array of strings $capabilities specify required capabilities for the courses returned
     * @return array of courses and warnings
     * @since  Moodle 2.3
     */
    public static function get_assignments($courseids = array(), $capabilities = array()) {
        global $USER, $DB;
        $params = self::validate_parameters(self::get_assignments_parameters(),
                        array('courseids' => $courseids, 'capabilities' => $capabilities));

        $warnings = array();
        $courses = array();
        $fields = 'sortorder,shortname,fullname,timemodified';
        $courses = enrol_get_users_courses($USER->id, true, $fields);
        // Used to test for ids that have been requested but can't be returned.
        $unavailablecourseids = array();
        if (count($params['courseids'])>0) {
            $unavailablecourseids = $params['courseids'];
            foreach ($courses as $id => $course) {
                $found = in_array($id, $params['courseids']);
                if ($found) {
                    $unavailablecourseids = array_diff($unavailablecourseids, array($id));
                } else {
                    unset($courses[$id]);
                }
            }
        }
        foreach ($courses as $id => $course) {
            $context = context_course::instance($id);
            try {
                self::validate_context($context);
                require_capability('moodle/course:viewparticipants', $context);
            } catch (Exception $e) {
                unset($courses[$id]);
                $warning = array();
                $warning['item'] = 'course';
                $warning['itemid'] = $id;
                $warning['warningcode'] = '1';
                $warning['message'] = 'No access rights in course context';
                $warnings[] = $warning;
                continue;
            }
            foreach ($params['capabilities'] as $cap) {
                if (!has_capability($cap, $context)) {
                    unset($courses[$id]);
                }
            }
        }
        $extrafields='m.id as assignmentid, m.course, m.preventlatesubmissions, m.submissiondrafts, m.sendnotifications, '.
                     'm.duedate, m.allowsubmissionsfromdate, m.grade, m.timemodified';
        $coursearray = array();
        foreach ($courses as $id => $course) {
            $assignmentarray = array();
            // Get a list of assignments for the course.
            if ($modules = get_coursemodules_in_course('assign', $courses[$id]->id, $extrafields)) {
                foreach ($modules as $module) {
                    $context = context_module::instance($module->id);
                    try {
                        self::validate_context($context);
                        require_capability('mod/assign:view', $context);
                    } catch (Exception $e) {
                        $warning = array();
                        $warning['item'] = 'module';
                        $warning['itemid'] = $module->id;
                        $warning['warningcode'] = '1';
                        $warning['message'] = 'No access rights in module context';
                        $warnings[] = $warning;
                        continue;
                    }
                    $config_records = $DB->get_records('assign_plugin_config', array('assignment'=>$module->assignmentid));
                    $configarray = array();
                    foreach ($config_records as $config_record) {
                        $configarray[] = array('id'=>$config_record->id,
                                               'assignment'=>$config_record->assignment,
                                               'plugin'=>$config_record->plugin,
                                               'subtype'=>$config_record->subtype,
                                               'name'=>$config_record->name,
                                               'value'=>$config_record->value
                        );
                    }

                    $assignmentarray[]= array('id'=>$module->assignmentid,
                                              'cmid'=>$module->id,
                                              'course'=>$module->course,
                                              'name'=>$module->name,
                                              'preventlatesubmissions'=>$module->preventlatesubmissions,
                                              'submissiondrafts'=>$module->submissiondrafts,
                                              'sendnotifications'=>$module->sendnotifications,
                                              'duedate'=>$module->duedate,
                                              'allowsubmissionsfromdate'=>$module->allowsubmissionsfromdate,
                                              'grade'=>$module->grade,
                                              'timemodified'=>$module->timemodified,
                                              'configs'=>$configarray
                    );
                }
            }

            $coursearray[]= array('id'=>$courses[$id]->id,
                                  'fullname'=>$courses[$id]->fullname,
                                  'shortname'=>$courses[$id]->shortname,
                                  'timemodified'=>$courses[$id]->timemodified,
                                  'assignments'=>$assignmentarray
            );
        }

        $result = array();
        $result['courses'] = $coursearray;

        if (count($unavailablecourseids)>0) {
            foreach ($unavailablecourseids as $unavailablecourseid) {
                $warning = array();
                $warning['item'] = 'course';
                $warning['itemid'] = $unavailablecourseid;
                $warning['warningcode'] = '2';
                $warning['message'] = 'User is not enrolled or does not have requested capability';
                $warnings[] = $warning;
            }
        }
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Creates an assignment external_single_structure
     * @return external_single_structure
     * @since  Moodle 2.3
     */
    private static function assignment() {
        return new external_single_structure(
            array(
                'id'        => new external_value(PARAM_INT, 'assignment id'),
                'course'    => new external_value(PARAM_INT, 'course id'),
                'name'      => new external_value(PARAM_TEXT, 'assignment name'),
                'preventlatesubmissions' => new external_value(PARAM_INT, 'prevent late submissions'),
                'submissiondrafts' => new external_value(PARAM_INT, 'submissions drafts'),
                'sendnotifications' => new external_value(PARAM_INT, 'send notifications'),
                'duedate'   => new external_value(PARAM_INT, 'assignment due date'),
                'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'allow submissions from date'),
                'grade'     => new external_value(PARAM_INT, 'grade type'),
                'timemodified'     => new external_value(PARAM_INT, 'last time assignment was modified'),
                'configs'   => new external_multiple_structure(self::config(), 'list of configuration settings')
            ), 'assignment information object');
    }

    /**
     * Creates an assign_plugin_config external_single_structure
     * @return external_single_structure
     * @since  Moodle 2.3
     */
    private static function config() {
        return new external_single_structure(
            array(
                'id'         => new external_value(PARAM_INT, 'assign_plugin_config id'),
                'assignment' => new external_value(PARAM_INT, 'assignment id'),
                'plugin'     => new external_value(PARAM_TEXT, 'plugin'),
                'subtype'    => new external_value(PARAM_TEXT, 'subtype'),
                'name'       => new external_value(PARAM_TEXT, 'name'),
                'value'      => new external_value(PARAM_TEXT, 'value')
            ), 'assignment configuration object');
    }

    /**
     * Creates a course external_single_structure
     * @return external_single_structure
     * @since  Moodle 2.3 
     */
    private static function course() {
        return new external_single_structure(
            array(
                'id'        => new external_value(PARAM_INT, 'course id'),
                'fullname'  => new external_value(PARAM_TEXT, 'course full name'),
                'shortname' => new external_value(PARAM_TEXT, 'course short name'),
                'timemodified' => new external_value(PARAM_INT, 'last time modified'),
                'assignments'  => new external_multiple_structure(self::assignment(), 'list of assignment information')
              ), 'course information object' );
    }

    /** 
     * Describes the return value for get_assignments
     * @return external_single_structure
     * @since  Moodle 2.3
     */
    public static function get_assignments_returns() {
        return new external_single_structure(
            array(
                'courses'   => new external_multiple_structure(self::course(), 'list of courses containing assignments'),
                'warnings'  => external_warnings()
            )
        );
    }

}
