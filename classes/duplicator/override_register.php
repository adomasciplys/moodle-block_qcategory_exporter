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

namespace block_qcategory_exporter\duplicator;

/**
 * Tracks students who have a carried-over grade or completion for a duplicate quiz.
 *
 * A carried-over grade lacks a real quiz attempt. To prevent the quiz from deleting the grade
 * when a student makes a new attempt, the plugin creates a gradebook override.
 *
 * The observer reads the row when the student submits an attempt, removes the override
 * once the attempt beats the carried-over grade, and deletes the row.
 *
 * Rows live in the table block_qcategory_exporter_carried.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class override_register {
    /** @var string The table holding one row per protected student. */
    public const TABLE = 'block_qcategory_exporter_carried';

    /**
     * Remember that this student's work on the copy is being held in place by an override.
     *
     * Does nothing if the student already has a row.
     *
     * @param int $quizid Quiz instance id of the copy.
     * @param int $cmid Course module id of the copy.
     * @param int $userid The student.
     * @param float|null $grade The carried-over grade to defend, or null for completion alone.
     * @return void
     */
    public function ensure_exists(int $quizid, int $cmid, int $userid, ?float $grade = null): void {
        global $DB;

        if ($DB->record_exists(self::TABLE, ['quizid' => $quizid, 'userid' => $userid])) {
            return;
        }

        $DB->insert_record(self::TABLE, (object) [
            'quizid' => $quizid,
            'cmid' => $cmid,
            'userid' => $userid,
            'grade' => $grade,
            'timecreated' => time(),
        ]);
    }

    /**
     * The row for this student on this quiz, if their work is still being protected.
     *
     * @param int $quizid Quiz instance id.
     * @param int $userid The student.
     * @return \stdClass|null The row, or null if there is nothing to release.
     */
    public function get(int $quizid, int $userid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['quizid' => $quizid, 'userid' => $userid]) ?: null;
    }

    /**
     * Forget this student: their work on the copy is no longer being held in place.
     *
     * @param int $quizid Quiz instance id.
     * @param int $userid The student.
     * @return void
     */
    public function delete(int $quizid, int $userid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['quizid' => $quizid, 'userid' => $userid]);
    }
}
