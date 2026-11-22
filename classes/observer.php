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

namespace block_qcategory_exporter;

use block_qcategory_exporter\duplicator\override_register;

/**
 * Makes a carried-over grade compete with the student's own attempts, highest grade winning.
 *
 * When a quiz is duplicated, a student's grade on the original is copied onto the duplicate and
 * pinned there with a gradebook override.
 * Without the pin, the first attempt the student submits would wipe the carried-over grade.
 *
 * This runs once the quiz has worked out the grade for the student's own attempts, and keeps
 * whichever of the two grades is higher:
 * - The student beat the carried-over grade, so the pin comes off.
 * - The student did not, so the pin stays.
 *
 * It also deletes the rows of the table block_qcategory_exporter_carried that belong to a deleted quiz.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * An attempt was graded automatically, right after the student submitted it.
     *
     * @param \mod_quiz\event\attempt_graded $event The graded attempt.
     * @return void
     */
    public static function attempt_graded(\mod_quiz\event\attempt_graded $event): void {
        self::settle((int) $event->courseid, (int) ($event->other['quizid'] ?? 0), (int) $event->relateduserid);
    }

    /**
     * An attempt was regraded, which recalculates the grade the same way.
     *
     * @param \mod_quiz\event\attempt_regraded $event The regraded attempt.
     * @return void
     */
    public static function attempt_regraded(\mod_quiz\event\attempt_regraded $event): void {
        self::settle((int) $event->courseid, (int) ($event->other['quizid'] ?? 0), (int) $event->relateduserid);
    }

    /**
     * A teacher marked a question by hand, which can raise the grade long after the attempt.
     *
     * This event names the attempt rather than the student, so the student is looked up.
     *
     * @param \mod_quiz\event\question_manually_graded $event The marked question.
     * @return void
     */
    public static function question_manually_graded(\mod_quiz\event\question_manually_graded $event): void {
        global $DB;

        $attemptid = (int) ($event->other['attemptid'] ?? 0);

        if (!$attemptid) {
            return;
        }

        $userid = (int) $DB->get_field('quiz_attempts', 'userid', ['id' => $attemptid]);

        self::settle((int) $event->courseid, (int) ($event->other['quizid'] ?? 0), $userid);
    }

    /**
     * A quiz was deleted, so the rows held for it have nothing left to protect.
     *
     * @param \core\event\course_module_deleted $event The deleted course module.
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        if (($event->other['modulename'] ?? '') !== 'quiz') {
            return;
        }

        (new override_register())->delete_for_deleted_quizzes();
    }

    /**
     * A course was emptied or deleted. That removes its quizzes without a course_module_deleted event.
     *
     * @param \core\event\course_content_deleted $event The emptied course.
     * @return void
     */
    public static function course_content_deleted(\core\event\course_content_deleted $event): void {
        (new override_register())->delete_for_deleted_quizzes();
    }

    /**
     * Keep the higher of the carried-over grade and the one the student's own attempts earned.
     *
     * @param int $courseid Course the quiz belongs to.
     * @param int $quizid Quiz instance id.
     * @param int $userid The student.
     * @return void
     */
    private static function settle(int $courseid, int $quizid, int $userid): void {
        global $DB;

        if (!$quizid || !$userid) {
            return;
        }

        $register = new override_register();

        // Stop here if a student is not affected by a carry-over grade.
        $row = $register->get($quizid, $userid);
        if ($row === null) {
            return;
        }

        // The grade the quiz has just worked out from the student's real attempts.
        $earned = $DB->get_field('quiz_grades', 'grade', ['quiz' => $quizid, 'userid' => $userid]);
        $earned = $earned === false ? null : (float) $earned;

        // Nothing was pinned, only completion. The student's own attempt settles it.
        if (is_null($row->grade)) {
            self::release($courseid, $quizid, (int) $row->cmid, $userid);
            $register->delete($quizid, $userid);
            return;
        }

        // The carried-over grade is still the higher of the two, so it stays where it is.
        if (is_null($earned) || $earned <= (float) $row->grade) {
            self::restore_quiz_grade($quizid, $userid, (float) $row->grade);
            return;
        }

        // The student has beaten it. The quiz looks after its own grade from here on.
        self::release($courseid, $quizid, (int) $row->cmid, $userid);
        $register->delete($quizid, $userid);
    }

    /**
     * Put the carried-over grade back into the quiz's own record of it.
     *
     * @param int $quizid Quiz instance id.
     * @param int $userid The student.
     * @param float $grade The carried-over grade.
     * @return void
     */
    private static function restore_quiz_grade(int $quizid, int $userid, float $grade): void {
        global $DB;

        $existing = $DB->get_record('quiz_grades', ['quiz' => $quizid, 'userid' => $userid]);

        // The quiz deletes the row when the attempts earned no grade at all, so it is made again.
        if (!$existing) {
            $DB->insert_record('quiz_grades', (object) [
                'quiz' => $quizid,
                'userid' => $userid,
                'grade' => $grade,
                'timemodified' => time(),
            ]);
            return;
        }

        // The row already holds the carried-over grade, so there is nothing to write.
        if ((float) $existing->grade === $grade) {
            return;
        }

        // Overwrite the grade the attempts earned with the higher carried-over one.
        $existing->grade = $grade;
        $existing->timemodified = time();
        $DB->update_record('quiz_grades', $existing);
    }

    /**
     * Hand the grade and the completion state back to Moodle.
     *
     * @param int $courseid Course the quiz belongs to.
     * @param int $quizid Quiz instance id.
     * @param int $cmid Course module id of the quiz.
     * @param int $userid The student.
     * @return void
     */
    private static function release(int $courseid, int $quizid, int $cmid, int $userid): void {
        self::release_grade($courseid, $quizid, $userid);
        self::release_completion($courseid, $cmid, $userid);
    }

    /**
     * Clear the gradebook override, and let the quiz push its own grade in its place.
     *
     * @param int $courseid Course the quiz belongs to.
     * @param int $quizid Quiz instance id.
     * @param int $userid The student.
     * @return void
     */
    private static function release_grade(int $courseid, int $quizid, int $userid): void {
        global $CFG;

        // Defines grade_item and grade_grade.
        require_once($CFG->libdir . '/gradelib.php');

        // The quiz's grade item in the gradebook.
        $item = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'itemnumber' => 0,
        ]);

        // The quiz has no grade item, so there is no gradebook grade to unpin.
        if (!$item) {
            return;
        }

        // The student's grade on that item, which holds the override flag.
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);

        // Nothing was pinned, or someone has already unpinned it.
        if (!$grade || !$grade->is_overridden()) {
            return;
        }
        // Hand over the item already loaded, which set_overridden() needs for its refresh.
        $grade->grade_item = $item;
        // Unblock grade: overridden = false.
        $grade->set_overridden(false, true);
    }

    /**
     *
     * Clear the completion override holding the carried-over state in place.
     *
     * @param int $courseid Course the quiz belongs to.
     * @param int $cmid Course module id of the quiz.
     * @param int $userid The student.
     * @return void
     */
    private static function release_completion(int $courseid, int $cmid, int $userid): void {
        global $CFG;

        // Defines completion_info and the COMPLETION_ constants.
        require_once($CFG->libdir . '/completionlib.php');

        $course = get_course($courseid);
        $completion = new \completion_info($course);

        // The course does not track completion, so there is no state to release.
        if (!$completion->is_enabled()) {
            return;
        }

        $cm = get_fast_modinfo($course)->get_cm($cmid);

        // Under manual tracking nothing recalculates the state, so no override stands in the way.
        if ($cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
            return;
        }

        // The student's completion record for the quiz, which holds who overrode it.
        $data = $completion->get_data($cm, false, $userid);

        // No override on the state, so Moodle is already working it out by itself.
        if (is_null($data->overrideby)) {
            return;
        }

        // Hand the state back to Moodle, then have it worked out from the grade that now stands.
        $data->overrideby = null;
        $data->timemodified = time();
        $completion->internal_set_data($cm, $data);

        // COMPLETION_UNKNOWN makes Moodle work the state out again from the grade.
        $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
    }
}
