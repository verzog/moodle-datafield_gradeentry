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
 * @package    local_datagrading
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_datagrading\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;

/**
 * Privacy provider for local_datagrading (Australian Privacy Principles compliant).
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_datagrading_grades', [
            'userid'    => 'privacy:metadata:local_datagrading_grades:userid',
            'graderid'  => 'privacy:metadata:local_datagrading_grades:graderid',
            'grade'     => 'privacy:metadata:local_datagrading_grades:grade',
            'feedback'  => 'privacy:metadata:local_datagrading_grades:feedback',
            'released'  => 'privacy:metadata:local_datagrading_grades:released',
        ], 'privacy:metadata:local_datagrading_grades');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_datagrading_grades} lg
               JOIN {data} d ON d.id = lg.dataid
               JOIN {course_modules} cm ON cm.instance = d.id AND cm.module = (
                        SELECT id FROM {modules} WHERE name = 'data'
                   )
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
              WHERE lg.userid = :userid OR lg.graderid = :graderid",
            ['contextlevel' => CONTEXT_MODULE, 'userid' => $userid, 'graderid' => $userid]
        );
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $userlist->add_from_sql('userid',
            "SELECT lg.userid
               FROM {local_datagrading_grades} lg
               JOIN {data} d ON d.id = lg.dataid
               JOIN {course_modules} cm ON cm.instance = d.id AND cm.id = :cmid",
            ['cmid' => $context->instanceid]
        );
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('data', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $rows = $DB->get_records_select(
                'local_datagrading_grades',
                'dataid = :dataid AND (userid = :userid OR graderid = :graderid)',
                ['dataid' => $cm->instance, 'userid' => $userid, 'graderid' => $userid]
            );
            foreach ($rows as $row) {
                \core_privacy\local\request\writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_datagrading')],
                    (object) [
                        'recordid' => $row->recordid,
                        'grade'    => $row->graderid ? $row->grade ?? '' : '',
                        'feedback' => $row->graderid ? $row->feedback : '',
                        'released' => (bool) $row->released,
                    ]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('data', $context->instanceid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $DB->delete_records('local_datagrading_grades', ['dataid' => $cm->instance]);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('data', $context->instanceid, 0, false, IGNORE_MISSING);
            if ($cm) {
                $DB->delete_records('local_datagrading_grades', ['dataid' => $cm->instance, 'userid' => $userid]);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('data', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            'local_datagrading_grades',
            "dataid = :dataid AND userid {$insql}",
            array_merge(['dataid' => $cm->instance], $inparams)
        );
    }
}
