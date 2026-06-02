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
        return $collection;
    }

    /**
     * Export user data for a database entry that uses this field.
     *
     * Grade values are plain numeric content stored in mod_data's data_content
     * table. The mod_data privacy provider handles export; nothing extra here.
     *
     * @param \mod_data\privacy\data_fields_exporter $exporter  Exporter instance.
     */
    public static function export_data_content_for_user(\mod_data\privacy\data_fields_exporter $exporter): void {
        // No additional data beyond what mod_data already exports.
    }

    /**
     * Delete user data for a database entry that uses this field.
     *
     * @param \context $context    The context to delete data for.
     * @param array    $fieldids   Field IDs to delete.
     * @param array    $contentids Content IDs to delete.
     */
    public static function delete_data_content_for_user(
        \context $context,
        array $fieldids,
        array $contentids
    ): void {
        // Deletion of data_content rows is handled by mod_data's privacy provider.
    }
}
