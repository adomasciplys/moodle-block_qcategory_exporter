<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace block_qcategory_exporter\privacy;

use block_qcategory_exporter\duplicator\override_register;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 *
 * Privacy provider for block_qcategory_exporter.
 *
 * This block stores personal data to track students with a carried-over grade or completion on a copied quiz.
 * This data allows the plugin to delete its own manual overrides.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the table this block stores personal data in.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            override_register::TABLE,
            [
                'quizid' => 'privacy:metadata:block_qcexp_carried:quizid',
                'cmid' => 'privacy:metadata:block_qcexp_carried:cmid',
                'userid' => 'privacy:metadata:block_qcexp_carried:userid',
                'timecreated' => 'privacy:metadata:block_qcexp_carried:timecreated',
            ],
            'privacy:metadata:block_qcexp_carried'
        );

        return $collection;
    }

    /**
     * The copied quizzes this user holds carried-over work on.
     *
     * @param int $userid The user to look for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {" . override_register::TABLE . "} c
                  JOIN {context} ctx ON ctx.instanceid = c.cmid AND ctx.contextlevel = :modulelevel
                 WHERE c.userid = :userid";

        $contextlist->add_from_sql($sql, ['modulelevel' => CONTEXT_MODULE, 'userid' => $userid]);

        return $contextlist;
    }

    /**
     * The users holding carried over work on the quiz in this context.
     *
     * @param userlist $userlist The userlist to add to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . override_register::TABLE . '} WHERE cmid = :cmid',
            ['cmid' => $context->instanceid]
        );
    }

    /**
     * Export what the block holds for this user.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $record = $DB->get_record(
                override_register::TABLE,
                ['cmid' => $context->instanceid, 'userid' => $userid]
            );

            if (!$record) {
                continue;
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'block_qcategory_exporter')],
                (object) [
                    'quizid' => (int) $record->quizid,
                    'cmid' => (int) $record->cmid,
                    'timecreated' => \core_privacy\local\request\transform::datetime($record->timecreated),
                ]
            );
        }
    }

    /**
     * Delete what the block holds for every user in this context.
     *
     * @param \context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $DB->delete_records(override_register::TABLE, ['cmid' => $context->instanceid]);
    }

    /**
     * Delete what the block holds for one user, in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $DB->delete_records(override_register::TABLE, ['cmid' => $context->instanceid, 'userid' => $userid]);
        }
    }

    /**
     * Delete what the block holds for the given users in one context.
     *
     * @param approved_userlist $userlist The approved users to delete for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $userids = $userlist->get_userids();

        if (!$userids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['cmid'] = $context->instanceid;

        $DB->delete_records_select(override_register::TABLE, "cmid = :cmid AND userid $insql", $params);
    }
}
