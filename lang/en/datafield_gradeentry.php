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
 * Language strings for the datafield_gradeentry plugin.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

$string['awaitinggrade'] = 'Your teacher will enter a grade for this entry.';
$string['decimals'] = 'Decimal places';
$string['decimals_help'] = 'The number of decimal places to display for grade values.';
$string['errornopermission'] = 'You do not have permission to grade this entry.';
$string['errornumeric'] = 'You must enter a numeric value.';
$string['erroroutofrange'] = 'The value must be between {$a->min} and {$a->max}.';
$string['errorsavegrade'] = 'An error occurred while saving the grade. Please try again.';
$string['feedbacklabel'] = 'Feedback';
$string['fieldtypelabel'] = 'Grade entry';
$string['grade'] = 'Grade';
$string['graded'] = 'Graded';
$string['gradedreleased'] = 'Grade released to student';
$string['gradeentry'] = 'Grade entry';
$string['gradeentry:addinstance'] = 'Add a grade entry field';
$string['gradeentry:grade'] = 'Grade database entries';
$string['gradeentry:viewgrades'] = 'View released grades';
$string['gradenotreleased'] = 'Grade not yet released to student';
$string['gradeoutof'] = '{$a->grade} / {$a->maxgrade}';
$string['gradepending'] = 'Grade pending';
$string['graderelease'] = 'Release grade to student';
$string['gradingprogress'] = 'Graded: {$a->graded} / {$a->total}';
$string['maxgrade'] = 'Maximum grade';
$string['maxgrade_help'] = 'The maximum allowable grade value for this field.';
$string['mingrade'] = 'Minimum grade';
$string['mingrade_help'] = 'The minimum allowable grade value for this field.';
$string['pluginname'] = 'Grade entry';
$string['privacy:metadata'] = 'The grade entry datafield plugin stores grade values entered by users as part of database activity entries.';
$string['privacy:metadata:datafield_gradeentry_grades'] = 'Stores grade metadata for each database activity entry, including feedback left by the grader and whether the grade has been released to the student.';
$string['privacy:metadata:datafield_gradeentry_grades:feedback'] = 'Feedback text written by the teacher for this entry.';
$string['privacy:metadata:datafield_gradeentry_grades:grade'] = 'The grade awarded for this entry.';
$string['privacy:metadata:datafield_gradeentry_grades:graderid'] = 'The user ID of the teacher who assigned the grade.';
$string['privacy:metadata:datafield_gradeentry_grades:released'] = 'Whether the grade has been released to the student.';
$string['privacy:metadata:datafield_gradeentry_grades:userid'] = 'The user ID of the student who submitted this entry.';
$string['releaseall'] = 'Release all grades';
$string['released'] = 'Released';
$string['savinggrade'] = 'Saving…';
$string['showaspercentage'] = 'Show as percentage';
$string['showaspercentage_help'] = 'Display the entered grade as a percentage of the maximum grade.';
$string['ungraded'] = 'Ungraded';
