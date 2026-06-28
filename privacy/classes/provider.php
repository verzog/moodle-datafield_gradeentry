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
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Implements the Privacy API for datafield_gradeentry (Australian Privacy Principles).
 *
 * Two distinct data stores are covered:
 *  - The grade value itself lives in mod_data's {data_content} table and is
 *    exported/deleted through the {@see \mod_data\privacy\datafield_provider}
 *    interface, driven by mod_data's own provider.
 *  - The grade metadata ({datafield_gradeentry_grades}: feedback, grader,
 *    released flag, submission status) is this plugin's own table, so it is
 *    exported and deleted here via the request plugin provider.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
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
            'feedback' => 'privacy:metadata:datafield_gradeentry_grades:feedback',
            'released' => 'privacy:metadata:datafield_gradeentry_grades:released',
            'submission_status' => 'privacy:metadata:datafield_gradeentry_grades:submission_status',
        ], 'privacy:metadata:datafield_gradeentry_grades');

        return $collection;
    }

    /**
     * Return the module contexts in which the given user has grade metadata.
     *
     * Covers both the student who owns an entry and the teacher who graded it.
     *
     * @param  int $userid  The user to search for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {datafield_gradeentry_grades} g
                  JOIN {data} d ON d.id = g.dataid
                  JOIN {modules} m ON m.name = :modname
                  JOIN {course_modules} cm ON cm.instance = d.id AND cm.module = m.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE g.userid = :userid OR g.graderid = :graderid";

        $contextlist->add_from_sql($sql, [
            'modname' => 'data',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
            'graderid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Return the users who have grade metadata within the given context.
     *
     * @param userlist $userlist  The userlist to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('data', $context->instanceid);
        if (!$cm) {
            return;
        }

        $params = ['dataid' => $cm->instance];
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {datafield_gradeentry_grades} WHERE dataid = :dataid",
            $params
        );
        $userlist->add_from_sql(
            'graderid',
            "SELECT graderid FROM {datafield_gradeentry_grades} WHERE dataid = :dataid AND graderid IS NOT NULL",
            $params
        );
    }

    /**
     * Export grade metadata for the approved contexts for a single user.
     *
     * @param approved_contextlist $contextlist  Approved contexts to export.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('data', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $rows = $DB->get_records_select(
                'datafield_gradeentry_grades',
                'dataid = :dataid AND (userid = :userid OR graderid = :graderid)',
                ['dataid' => $cm->instance, 'userid' => $user->id, 'graderid' => $user->id]
            );
            if (!$rows) {
                continue;
            }

            $grades = [];
            foreach ($rows as $row) {
                $grades[] = [
                    'recordid' => $row->recordid,
                    'userid' => $row->userid,
                    'graderid' => $row->graderid,
                    'feedback' => $row->feedback,
                    'released' => transform::yesno($row->released),
                    'submission_status' => $row->submission_status,
                    'timecreated' => transform::datetime($row->timecreated),
                    'timemodified' => transform::datetime($row->timemodified),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'datafield_gradeentry')],
                (object) ['grades' => $grades]
            );
        }
    }

    /**
     * Delete all grade metadata for all users in the given context.
     *
     * @param \context $context  The context to delete within.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('data', $context->instanceid);
        if (!$cm) {
            return;
        }

        $DB->delete_records('datafield_gradeentry_grades', ['dataid' => $cm->instance]);
    }

    /**
     * Delete grade metadata for a single user across the approved contexts.
     *
     * Rows the user owns as a student are removed; rows the user graded for
     * other students are anonymised by clearing the grader reference.
     *
     * @param approved_contextlist $contextlist  Approved contexts to delete within.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('data', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $DB->delete_records('datafield_gradeentry_grades', [
                'dataid' => $cm->instance,
                'userid' => $user->id,
            ]);
            $DB->set_field('datafield_gradeentry_grades', 'graderid', null, [
                'dataid' => $cm->instance,
                'graderid' => $user->id,
            ]);
        }
    }

    /**
     * Delete grade metadata for a set of users within one context.
     *
     * @param approved_userlist $userlist  Approved users to delete.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('data', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            'datafield_gradeentry_grades',
            "dataid = :dataid AND userid $insql",
            array_merge(['dataid' => $cm->instance], $inparams)
        );

        [$gradersql, $graderparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select(
            'datafield_gradeentry_grades',
            'graderid',
            null,
            "dataid = :dataid AND graderid $gradersql",
            array_merge(['dataid' => $cm->instance], $graderparams)
        );
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
