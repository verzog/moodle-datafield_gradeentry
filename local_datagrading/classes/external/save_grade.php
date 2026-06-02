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
 * External function to save a grade for a single database entry.
 *
 * @package    local_datagrading
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_datagrading\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Saves a grade value and optional feedback for a single database activity entry.
 */
class save_grade extends external_api {
    /**
     * Declare the expected input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course-module ID of the database activity'),
            'recordid' => new external_value(PARAM_INT, 'ID of the data_records row to grade'),
            'fieldid' => new external_value(PARAM_INT, 'ID of the gradeentry field'),
            'grade' => new external_value(PARAM_FLOAT, 'Numeric grade value'),
            'feedback' => new external_value(PARAM_TEXT, 'Teacher feedback', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Save the grade and sync to the gradebook.
     *
     * @param int    $cmid      Course-module ID.
     * @param int    $recordid  Database record ID.
     * @param int    $fieldid   Grade entry field ID.
     * @param float  $grade     Grade value.
     * @param string $feedback  Optional teacher feedback.
     * @return array{success: bool, graded: int, total: int}
     */
    public static function execute(
        int $cmid,
        int $recordid,
        int $fieldid,
        float $grade,
        string $feedback = ''
    ): array {
        global $DB, $USER;

        [
            'cmid' => $cmid,
            'recordid' => $recordid,
            'fieldid' => $fieldid,
            'grade' => $grade,
            'feedback' => $feedback,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'recordid' => $recordid,
            'fieldid' => $fieldid,
            'grade' => $grade,
            'feedback' => $feedback,
        ]);

        $cm = get_coursemodule_from_id('data', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/datagrading:grade', $context);

        $field = $DB->get_record(
            'data_fields',
            ['id' => $fieldid, 'dataid' => $cm->instance, 'type' => 'gradeentry'],
            '*',
            MUST_EXIST
        );

        $record = $DB->get_record(
            'data_records',
            ['id' => $recordid, 'dataid' => $cm->instance],
            'id, userid',
            MUST_EXIST
        );

        $min = (float) ($field->param1 !== '' ? $field->param1 : PHP_INT_MIN);
        $max = (float) ($field->param2 !== '' ? $field->param2 : PHP_INT_MAX);
        if ($grade < $min || $grade > $max) {
            throw new \invalid_parameter_exception('Grade value is outside the allowed range.');
        }

        $existing = $DB->get_record('data_content', ['fieldid' => $fieldid, 'recordid' => $recordid]);
        if ($existing) {
            $existing->content = $grade;
            $DB->update_record('data_content', $existing);
        } else {
            $DB->insert_record('data_content', (object) [
                'fieldid' => $fieldid,
                'recordid' => $recordid,
                'content' => $grade,
            ]);
        }

        \local_datagrading\grade_manager::save($cmid, $recordid, $grade, $feedback, (int) $USER->id);

        $event = \local_datagrading\event\entry_graded::create([
            'context' => $context,
            'objectid' => $recordid,
            'relateduserid' => $record->userid,
            'other' => [
                'grade' => $grade,
                'maxgrade' => (float) ($field->param2 ?: 100),
            ],
        ]);
        $event->trigger();

        $progress = \local_datagrading\grade_manager::progress($cm->instance);

        return [
            'success' => true,
            'graded' => $progress['graded'],
            'total' => $progress['total'],
        ];
    }

    /**
     * Declare the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True if the grade was saved successfully'),
            'graded' => new external_value(PARAM_INT, 'Number of graded entries so far'),
            'total' => new external_value(PARAM_INT, 'Total number of entries'),
        ]);
    }
}
