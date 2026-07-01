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
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace datafield_gradeentry\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use datafield_gradeentry\grade_manager;
use mod_data\privacy\datafield_provider;

/**
 * Implements the Privacy API for datafield_gradeentry.
 *
 * All data this plugin stores lives in mod_data's {data_content} table: the
 * grade value in the content column and the grading metadata (feedback,
 * grader, release state, submission status, rubric scores) as JSON in
 * content1. Export and deletion are therefore driven by mod_data's own
 * privacy provider, which calls the {@see datafield_provider} methods below.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    datafield_provider {
    /**
     * Describe the data stored by this plugin within mod_data's content table.
     *
     * @param collection $collection  Metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('data_content', [
            'content'  => 'privacy:metadata:data_content:content',
            'content1' => 'privacy:metadata:data_content:content1',
        ], 'privacy:metadata:data_content');

        return $collection;
    }

    /**
     * Export the content stored by this field for a single data record.
     *
     * mod_data hands us a pre-populated value object holding the grade
     * (content) and the raw metadata JSON (content1). We replace the raw JSON
     * with human-readable grading fields and write the export ourselves.
     *
     * @param \context  $context      Module context.
     * @param \stdClass $recordobj    The data_records row.
     * @param \stdClass $fieldobj     The data_fields row.
     * @param \stdClass $contentobj   The data_content row.
     * @param \stdClass $defaultvalue Pre-populated value object from mod_data.
     */
    public static function export_data_content($context, $recordobj, $fieldobj, $contentobj, $defaultvalue) {
        $meta = grade_manager::get_metadata((int) $fieldobj->id, (int) $recordobj->id);

        // Replace the raw content1 JSON with readable grading metadata.
        unset($defaultvalue->content1);
        $defaultvalue->grade            = $contentobj->content;
        $defaultvalue->feedback         = $meta['feedback'];
        $defaultvalue->released         = transform::yesno($meta['released']);
        $defaultvalue->submissionstatus = $meta['submission_status'];
        if ($meta['graderid'] !== null) {
            $defaultvalue->graderid = $meta['graderid'];
        }
        if ($meta['rubric_scores'] !== null && $meta['rubric_scores'] !== '') {
            $defaultvalue->rubricscores = $meta['rubric_scores'];
        }

        writer::with_context($context)->export_data([$recordobj->id, $contentobj->id], $defaultvalue);
    }

    /**
     * Delete the content stored by this field for a single data record.
     *
     * The grade value and content1 metadata both live in the data_content row,
     * which mod_data's privacy provider deletes; nothing extra is needed here.
     *
     * @param \context  $context    Module context.
     * @param \stdClass $recordobj  The data_records row.
     * @param \stdClass $fieldobj   The data_fields row.
     * @param \stdClass $contentobj The data_content row.
     */
    public static function delete_data_content($context, $recordobj, $fieldobj, $contentobj) {
        // Deletion of the data_content row is handled by mod_data's privacy provider.
    }
}
