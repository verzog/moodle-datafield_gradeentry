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
 * Event observers for the datafield_gradeentry plugin.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    {@link https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later}
 */

namespace datafield_gradeentry;

/**
 * Handles Moodle events fired by datafield_gradeentry and reacts accordingly.
 */
class observer {
    /**
     * Sync a saved grade to the Moodle gradebook when an entry is graded.
     *
     * @param \datafield_gradeentry\event\entry_graded $event  The grading event.
     */
    public static function sync_to_gradebook(\datafield_gradeentry\event\entry_graded $event): void {
        global $DB;

        $data = $event->get_data();
        $cmid = $data['contextinstanceid'];

        $cm = get_coursemodule_from_id('data', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $datarecord = $DB->get_record('data', ['id' => $cm->instance], 'id, name, course', IGNORE_MISSING);
        if (!$datarecord) {
            return;
        }

        $datarecord->_maxgrade = $data['other']['maxgrade'];

        $gradeobject = (object) [
            'userid' => $data['relateduserid'],
            'rawgrade' => $data['other']['grade'],
        ];

        datafield_gradeentry_grade_item_update($datarecord, $gradeobject);
    }
}
