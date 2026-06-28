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
 * External function (web service) definitions for datafield_gradeentry.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'datafield_gradeentry_save_grade' => [
        'classname'   => \datafield_gradeentry\external\save_grade::class,
        'description' => 'Save a grade and optional feedback for a database entry.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'datafield/gradeentry:grade',
    ],

    'datafield_gradeentry_release_grades' => [
        'classname'   => \datafield_gradeentry\external\release_grades::class,
        'description' => 'Release one or all grades in a database activity to students.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'datafield/gradeentry:grade',
    ],

    'datafield_gradeentry_get_progress' => [
        'classname'   => \datafield_gradeentry\external\get_progress::class,
        'description' => 'Return grading progress counts for a database activity.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'datafield/gradeentry:grade',
    ],

    'datafield_gradeentry_save_submission_status' => [
        'classname'   => \datafield_gradeentry\external\save_submission_status::class,
        'description' => 'Save the submission status (draft or submitted) for a student\'s entry.',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],

    'datafield_gradeentry_require_resubmission' => [
        'classname'   => \datafield_gradeentry\external\require_resubmission::class,
        'description' => 'Set or clear the require-resubmission flag on an entry.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'datafield/gradeentry:grade',
    ],

];
