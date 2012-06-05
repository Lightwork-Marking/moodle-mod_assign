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
     * Describes the parameters for get_submissions
     * @return external_external_function_parameters
     * @since  Moodle 2.3
     */
    public static function get_submissions_parameters() {
        return new external_function_parameters(
            array(
                'assignmentids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'assignment id'),
                    '1 or more assignment ids',
                    VALUE_REQUIRED),
                'status' => new external_value(PARAM_ALPHA, 'status', VALUE_DEFAULT, ''),
                'since' => new external_value(PARAM_INT, 'submitted since', VALUE_DEFAULT, 0),
                'before' => new external_value(PARAM_INT, 'submitted before', VALUE_DEFAULT, 0),
            )
        );
    }

    /**
     * Returns submissions for the requested assignment ids
     * @param array of ints $assignmentids
     * @param string $status only return submissions with this status
     * @param int $since only return submissions with timemodified >= since
     * @param int $before only return submissions with timemodified <= before
     * @return array of submissions for each requested assignment
     * @since  Moodle 2.3
     */
    public static function get_submissions($assignmentids, $status = '', $since = 0, $before = 0) {
        global $DB, $CFG;
        $params = self::validate_parameters(self::get_submissions_parameters(),
                        array('assignmentids' => $assignmentids,
                              'status' => $status,
                              'since' => $since,
                              'before' => $before));

        $warnings = array();
        $requestedassignmentids = $params['assignmentids'];

        // Check the user is allowed to get the submissions for the assignments requested.
        list($inorequalsql, $assignmentidparams) = $DB->get_in_or_equal($requestedassignmentids);
        $sql = "SELECT cm.id, cm.instance FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module ".
               "WHERE md.name = 'assign' AND cm.instance ".$inorequalsql;
        $cms = $DB->get_records_sql($sql, $assignmentidparams);
        foreach ($cms as $cm) {
            try {
                $context = context_module::instance($cm->id);
                self::validate_context($context);
                require_capability('mod/assign:grade', $context);
            } catch (Exception $e) {
                $requestedassignmentids = array_diff($requestedassignmentids, array($cm->instance));
                $warning = array();
                $warning['item'] = 'assignment';
                $warning['itemid'] = $cm->instance;
                $warning['warningcode'] = '1';
                $warning['message'] = 'No access rights in module context';
                $warnings[] = $warning;
            }
        }

        // Create the query and populate an array of submissions from the recordset results.
        $allsubmissions = array();
        if (count ($requestedassignmentids) > 0) {
            $placeholders = array();
            list($inorequalsql, $placeholders) = $DB->get_in_or_equal($requestedassignmentids, SQL_PARAMS_NAMED);
            $sql = "SELECT mas.id AS submissionid, mas.assignment AS assignmentid,mas.userid,".
                   "mas.timecreated,mas.timemodified,mas.status,".
                   "masf.id AS fileid,masf.numfiles,maso.id AS onlinetextid,maso.onlinetext ".
                   "FROM {assign_submission} mas ".
                   "LEFT JOIN {assign_submission_file} masf on mas.id=masf.submission ".
                   "LEFT JOIN {assign_submission_onlinetext} maso on mas.id=maso.submission ".
                   "WHERE mas.assignment ".$inorequalsql;

            if (!empty($params['status'])) {
                $placeholders['status'] = $params['status'];
                $sql = $sql." AND mas.status = :status";
            }
            if (!empty($params['before'])) {
                $placeholders['since'] = $params['since'];
                $placeholders['before'] = $params['before'];
                $sql = $sql." AND mas.timemodified BETWEEN :since AND :before";
            } else {
                $placeholders['since'] = $params['since'];
                $sql = $sql." AND mas.timemodified  >= :since";
            }
            $sql = $sql." ORDER BY mas.assignment, mas.id";
            $rs = $DB->get_recordset_sql($sql, $placeholders);
            $currentsubmissionid = null;
            $currentassignmentid = null;
            $currentsubmission = null;
            foreach ($rs as $rd) {
                if (is_null($currentsubmissionid) || ($rd->submissionid != $currentsubmissionid )) {
                    if (!is_null($currentsubmission)) {
                        $allsubmissions[$currentassignmentid][] = $currentsubmission;
                    }
                    $currentsubmission = array();
                    $currentsubmission['id'] = $rd->submissionid;
                    $currentsubmission['userid'] = $rd->userid;
                    $currentsubmission['timecreated'] = $rd->timecreated;
                    $currentsubmission['timemodified'] = $rd->timemodified;
                    $currentsubmission['status'] = $rd->status;
                }
                $currentsubmission['files'][] = array('id' => $rd->fileid, 'numfiles' => $rd->numfiles);
                $currentsubmission['onlinetexts'][] = array('id'=> $rd->onlinetextid, 'onlinetext' => strip_tags($rd->onlinetext));

                $currentsubmissionid = $rd->submissionid;
                $currentassignmentid = $rd->assignmentid;
            }
            $rs->close();
            if (!is_null($currentsubmission)) {
                $allsubmissions[$currentassignmentid][] = $currentsubmission;
            }
        }
        // Add the submissions to the assignments array.
        $assignments = array();
        foreach ($allsubmissions as $assignmentid => $submissions) {
            $assignment = array();
            $assignment['assignmentid'] = $assignmentid;
            $assignment['submissions'] = $submissions;
            $assignments[] = $assignment;
        }
        // Create warning messages if no submissions were found for a requested assignment.
        foreach ($requestedassignmentids as $assignmentid) {
            if (!array_key_exists($assignmentid, $allsubmissions)) {
                $warning = array();
                $warning['item'] = 'module';
                $warning['itemid'] = $assignmentid;
                $warning['warningcode'] = '3';
                $warning['message'] = 'No submissions found';
                $warnings[] = $warning;
            }
        }
        $result = array();
        $result['assignments'] = $assignments;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Creates an assign_submissions external_single_structure
     * @return external_single_structure
     * @since  Moodle 2.3
     */
    private static function assign_submissions() {
        return new external_single_structure(
            array (
                'assignmentid'    => new external_value(PARAM_INT, 'assignment id'),
                'submissions'   => new external_multiple_structure(new external_single_structure(
                        array(
                            'id'            => new external_value(PARAM_INT, 'submission id'),
                            'userid'        => new external_value(PARAM_INT, 'student id'),
                            'timecreated'   => new external_value(PARAM_INT, 'submission creation time'),
                            'timemodified'  => new external_value(PARAM_INT, 'submission last modified time'),
                            'status'        => new external_value(PARAM_TEXT, 'submission status'),
                            'files'         => new external_multiple_structure(
                                                   new external_single_structure(
                                                       array(
                                                           'id'   => new external_value(PARAM_INT, 'submission file id'),
                                                           'numfiles' => new external_value(PARAM_INT, 'numfiles')
                                                       ))),
                           'onlinetexts'   => new external_multiple_structure(
                                                  new external_single_structure(
                                                      array(
                                                          'id'   => new external_value(PARAM_INT, 'submission onlinetext id'),
                                                          'onlinetext' => new external_value(PARAM_TEXT, 'onlinetext')
                                                      )))
                        )
                ))
            )
        );
    }

    /** 
     * Describes the get_submissions return value
     * @return external_single_structure
     * @since  Moodle 2.3
     */
    public static function get_submissions_returns() {
        return new external_single_structure(
            array(
                'assignments' => new external_multiple_structure(self::assign_submissions(), 'list of assignment submissions'),
                'warnings'      => external_warnings()
            )
        );
    }

}
