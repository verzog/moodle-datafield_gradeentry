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
 * External function for teachers to flag an entry as requiring resubmission.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace datafield_gradeentry\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use datafield_gradeentry\grade_manager;

/**
 * Allows a teacher to require a student to resubmit their entry.
 */
class require_resubmission extends external_api {
    /**
     * Declare the expected input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course-module ID of the database activity'),
            'recordid' => new external_value(PARAM_INT, 'ID of the data_records row'),
            'require' => new external_value(PARAM_BOOL, 'True to require resubmission, false to clear the flag'),
        ]);
    }

    /**
     * Set or clear the resubmission flag for one entry.
     *
     * @param int  $cmid
     * @param int  $recordid
     * @param bool $require
     * @return array{success: bool}
     */
    public static function execute(int $cmid, int $recordid, bool $require): array {
        global $DB;

        [
            'cmid' => $cmid,
            'recordid' => $recordid,
            'require' => $require,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'recordid' => $recordid,
            'require' => $require,
        ]);

        $cm = get_coursemodule_from_id('data', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('datafield/gradeentry:grade', $context);

        $DB->get_record(
            'data_records',
            ['id' => $recordid, 'dataid' => $cm->instance],
            'id',
            MUST_EXIST
        );

        grade_manager::set_require_resubmission((int) $cm->instance, $recordid, $require);

        return ['success' => true];
    }

    /**
     * Declare the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True if the flag was updated'),
        ]);
    }
}
