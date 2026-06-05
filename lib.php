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
 * Plugin library functions for datafield_gradeentry.
 *
 * The page-footer markup that this plugin used to emit via the legacy
 * local_datagrading_before_footer() callback is now produced by the
 * \datafield_gradeentry\hook_callbacks::before_footer_html_generation()
 * hook callback (see db/hooks.php).
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    {@link https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later}
 */

/**
 * Register or update the grade item for a database activity in the gradebook.
 *
 * @param  stdClass $data    Record from mdl_data; may carry a _maxgrade property.
 * @param  mixed    $grades  Grade object, array of grade objects, or 'reset'.
 * @return int  GRADE_UPDATE_OK or an error constant.
 */
function datafield_gradeentry_grade_item_update(stdClass $data, $grades = null): int {
    global $CFG;

    require_once($CFG->libdir . '/gradelib.php');

    $scaleid = (int) ($data->_scaleid ?? 0);
    if ($scaleid > 0) {
        $params = [
            'itemname'  => $data->name,
            'gradetype' => GRADE_TYPE_SCALE,
            'scaleid'   => $scaleid,
        ];
    } else {
        $params = [
            'itemname'  => $data->name,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax'  => (float) ($data->_maxgrade ?? 100),
            'grademin'  => 0,
        ];
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/data/field/gradeentry', $data->course, 'mod', 'data', $data->id, 0, $grades, $params);
}
