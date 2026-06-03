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
 * External function to release grades to students in a database activity.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace datafield_gradeentry\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Releases one or all grades in a database activity so students can view them.
 */
class release_grades extends external_api {
    /**
     * Declare the expected input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course-module ID of the database activity'),
            'recordids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Record ID'),
                'Specific record IDs to release; omit or pass empty array to release all graded entries.',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Release grades for the specified entries, or all entries if none specified.
     *
     * @param int   $cmid       Course-module ID.
     * @param int[] $recordids  Specific record IDs, or empty to release all.
     * @return array{released: int}
     */
    public static function execute(int $cmid, array $recordids = []): array {
        [
            'cmid' => $cmid,
            'recordids' => $recordids,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'recordids' => $recordids,
        ]);

        $cm = get_coursemodule_from_id('data', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('datafield/gradeentry:grade', $context);

        $released = \datafield_gradeentry\grade_manager::release(
            $cm->instance,
            count($recordids) > 0 ? array_map('intval', $recordids) : null
        );

        return ['released' => $released];
    }

    /**
     * Declare the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'released' => new external_value(PARAM_INT, 'Number of entries whose grades were released'),
        ]);
    }
}
