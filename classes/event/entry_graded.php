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
 * Event fired when a teacher saves a grade for a database activity entry.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    {@link https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later}
 */

namespace datafield_gradeentry\event;

/**
 * Represents the act of a teacher grading a single database entry.
 *
 * Required other data keys:
 *   - grade     (float)  The grade value saved.
 *   - maxgrade  (float)  The maximum possible grade for this field.
 */
class entry_graded extends \core\event\base {
    /**
     * Initialise event properties.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'data_records';
    }

    /**
     * Return the human-readable event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('gradeentry', 'datafield_gradeentry');
    }

    /**
     * Return a plain-English description of this event instance.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' graded the database entry with id '{$this->objectid}' "
            . "in course module '{$this->contextinstanceid}'.";
    }

    /**
     * Return the URL to view the graded entry.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/data/view.php', [
            'id' => $this->contextinstanceid,
            'rid' => $this->objectid,
        ]);
    }

    /**
     * Validate that required other-data keys are present.
     */
    protected function validate_data(): void {
        parent::validate_data();

        if (!isset($this->other['grade'])) {
            throw new \coding_exception('entry_graded event requires other[grade].');
        }
        if (!isset($this->other['maxgrade'])) {
            throw new \coding_exception('entry_graded event requires other[maxgrade].');
        }
    }
}
