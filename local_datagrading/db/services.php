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
 * External function (web service) definitions for local_datagrading.
 *
 * @package    local_datagrading
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'local_datagrading_save_grade' => [
        'classname' => \local_datagrading\external\save_grade::class,
        'description' => 'Save a grade and optional feedback for a database entry.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/datagrading:grade',
    ],

    'local_datagrading_release_grades' => [
        'classname' => \local_datagrading\external\release_grades::class,
        'description' => 'Release one or all grades in a database activity to students.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/datagrading:grade',
    ],

    'local_datagrading_get_progress' => [
        'classname' => \local_datagrading\external\get_progress::class,
        'description' => 'Return grading progress counts for a database activity.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/datagrading:grade',
    ],
];
