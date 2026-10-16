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

namespace datafield_gradeentry\task;

use datafield_gradeentry\grade_manager;

/**
 * Ad hoc task that brings every Grade entry activity's gradebook in line with release state.
 *
 * Queued by the 1.0.0-beta.4 upgrade. Earlier versions pushed grades to the
 * gradebook as soon as they were saved, before release; this removes those
 * unreleased grades. It runs from cron because the gradebook cannot be
 * updated while an upgrade is in progress.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resync_gradebook extends \core\task\adhoc_task {
    /**
     * Resync the gradebook for each Database activity that has a Grade entry field.
     */
    public function execute(): void {
        global $DB;

        $dataids = $DB->get_fieldset_select('data_fields', 'DISTINCT dataid', 'type = ?', ['gradeentry']);
        foreach ($dataids as $dataid) {
            grade_manager::resync_activity((int) $dataid);
        }
    }
}
