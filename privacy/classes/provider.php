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
 * Privacy provider for the datafield_gradeentry plugin.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace datafield_gradeentry\privacy;

use core_privacy\local\metadata\collection;

/**
 * Implements the Privacy API for datafield_gradeentry (Australian Privacy Principles).
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \mod_data\privacy\datafield_provider {
    /**
     * Describe the data stored by this plugin.
     *
     * @param collection $collection  Metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_plugintype_link('mod_data', [], 'privacy:metadata');

        $collection->add_database_table('datafield_gradeentry_grades', [
            'userid' => 'privacy:metadata:datafield_gradeentry_grades:userid',
            'graderid' => 'privacy:metadata:datafield_gradeentry_grades:graderid',
            'grade' => 'privacy:metadata:datafield_gradeentry_grades:grade',
            'feedback' => 'privacy:metadata:datafield_gradeentry_grades:feedback',
            'released' => 'privacy:metadata:datafield_gradeentry_grades:released',
        ], 'privacy:metadata:datafield_gradeentry_grades');

        return $collection;
    }

    /**
     * Export the content stored by this field for a single data record.
     *
     * The grade value is a plain number stored in data_content.content.
     * We return it as-is; mod_data's provider handles the surrounding context.
     *
     * @param \context  $context      Module context.
     * @param \stdClass $recordobj    The data_records row.
     * @param \stdClass $fieldobj     The data_fields row.
     * @param \stdClass $contentobj   The data_content row.
     * @param mixed     $defaultvalue Unused fallback value.
     * @return string|null  The stored grade as a string, or null if empty.
     */
    public static function export_data_content(
        \context $context,
        \stdClass $recordobj,
        \stdClass $fieldobj,
        \stdClass $contentobj,
        $defaultvalue
    ): ?string {
        if ($contentobj->content === null || $contentobj->content === '') {
            return null;
        }
        return (string) $contentobj->content;
    }

    /**
     * Delete the content stored by this field for a single data record.
     *
     * Deletion of the data_content row is handled by mod_data's privacy
     * provider; no additional data needs to be removed here.
     *
     * @param \context  $context    Module context.
     * @param \stdClass $recordobj  The data_records row.
     * @param \stdClass $fieldobj   The data_fields row.
     * @param \stdClass $contentobj The data_content row.
     */
    public static function delete_data_content(
        \context $context,
        \stdClass $recordobj,
        \stdClass $fieldobj,
        \stdClass $contentobj
    ): void {
        // Deletion of data_content rows is handled by mod_data's privacy provider.
    }
}
