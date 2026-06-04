<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * External function for students to save their submission status (draft/submitted).
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace datafield_gradeentry\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use datafield_gradeentry\grade_manager;

/**
 * Allows a student to mark their entry as draft or submitted for grading.
 */
class save_submission_status extends external_api {
    /**
     * Declare the expected input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course-module ID of the database activity'),
            'recordid' => new external_value(PARAM_INT, 'ID of the data_records row'),
            'status' => new external_value(PARAM_ALPHA, 'Submission status: draft or submitted'),
        ]);
    }

    /**
     * Persist the student's chosen submission status.
     *
     * @param int    $cmid
     * @param int    $recordid
     * @param string $status
     * @return array{success: bool, status: string}
     */
    public static function execute(int $cmid, int $recordid, string $status): array {
        global $DB, $USER;

        [
            'cmid' => $cmid,
            'recordid' => $recordid,
            'status' => $status,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'recordid' => $recordid,
            'status' => $status,
        ]);

        $cm = get_coursemodule_from_id('data', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        // Only draft and submitted are student-settable statuses.
        $allowed = [grade_manager::STATUS_DRAFT, grade_manager::STATUS_SUBMITTED];
        if (!in_array($status, $allowed, true)) {
            throw new \invalid_parameter_exception('Invalid status. Must be draft or submitted.');
        }

        $record = $DB->get_record(
            'data_records',
            ['id' => $recordid, 'dataid' => $cm->instance],
            'id, userid',
            MUST_EXIST
        );

        // Students can only update their own entry status.
        if ((int) $record->userid !== (int) $USER->id) {
            require_capability('datafield/gradeentry:grade', $context);
        }

        $dataid = (int) $cm->instance;
        grade_manager::update_submission_status($dataid, $recordid, (int) $USER->id, $status);

        return ['success' => true, 'status' => $status];
    }

    /**
     * Declare the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True if the status was saved successfully'),
            'status' => new external_value(PARAM_ALPHA, 'The saved status'),
        ]);
    }
}
