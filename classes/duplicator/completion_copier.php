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
 *
 * Copies student completion states from the original quiz to the duplicate quiz.
 *
 * For students without automatically calculated completion states,
 * this class copies the exact completion state (complete, complete-pass, or complete-fail from the original quiz.
 * This class saves these completion states as manual overrides.
 * Manual overrides prevent Moodle from changing the completion states automatically.
 *
 * This class saves every manual override in the override register (table block_qcategory_exporter_carried).
 * An observer uses the override register to delete the manual override
 * when the student submits a higher scoring quiz attempt on the copied quiz.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_copier {
    /** @var override_register Remembers which overrides are this plugin's own. */
    private override_register $register;

    /**
     * Wire up the record of which overrides are this plugin's own.
     *
     * @param override_register|null $register Remembers which overrides are this plugin's own.
     */
    public function __construct(?override_register $register = null) {
        $this->register = $register ?? new override_register();
    }

    /**
     * Stop early unless the current user may override completion in this course.
     *
     * @param \stdClass $course Course holding the quizzes.
     * @return void
     */
    public function require_override_capability(\stdClass $course): void {
        global $CFG;

        // Defines completion_info.
        require_once($CFG->libdir . '/completionlib.php');

        // Nothing is written when the course does not track completion at all.
        if (!(new \completion_info($course))->is_enabled()) {
            return;
        }

        require_capability('moodle/course:overridecompletion', \context_course::instance((int) $course->id));
    }

    /**
     * Mark the duplicate complete for every active student who completed the original.
     *
     * @param \stdClass $course Course holding both quizzes.
     * @param int $sourcecmid Course module id of the original quiz.
     * @param int $newcmid Course module id of the copy.
     * @param int $newquizid Quiz instance id of the copy.
     * @param int[] $students User ids to carry completion over for.
     * @return int How many students the copy was marked complete for.
     */
    public function copy_all(
        \stdClass $course,
        int $sourcecmid,
        int $newcmid,
        int $newquizid,
        array $students
    ): int {
        global $CFG;

        // No active students, just skip.
        if (!$students) {
            return 0;
        }

        // Defines completion_info and the COMPLETION_ constants.
        require_once($CFG->libdir . '/completionlib.php');

        $completion = new \completion_info($course);
        $newcm = get_fast_modinfo($course)->get_cm($newcmid);

        // Either the course or this quiz does not track completion.
        if (!$completion->is_enabled($newcm)) {
            return 0;
        }

        // Students who completed the original.
        $completed = array_intersect_key($this->completed_states($sourcecmid), array_flip($students));
        $viewed = array_flip($this->viewed_user_ids($sourcecmid));

        $copied = 0;
        foreach ($completed as $userid => $state) {
            // If the user viewed the source module, mark the copy as viewed too.
            if (isset($viewed[$userid])) {
                $completion->set_module_viewed($newcm, $userid);
            }

            // With the grade copied across, Moodle can often work the state out on its own.
            // A state it reached itself carries no override, so nothing can undo it later.
            if ($newcm->completion == COMPLETION_TRACKING_AUTOMATIC) {
                $completion->update_state($newcm, COMPLETION_UNKNOWN, (int) $userid);
            }

            // Copy the original's completion state as a manual override, if Moodle did not already reach it.
            if ($this->mark_complete($completion, $newcm, (int) $userid, (int) $state)) {
                // Save each manual override to the override register, so the observer can delete it
                // once the student submits a higher scoring attempt on the copy.
                $this->register->ensure_exists($newquizid, $newcmid, (int) $userid);
            }

            $copied++;
        }

        return $copied;
    }

    /**
     * Give the duplicate the state the original holds for this user.
     *
     * @param \completion_info $completion Completion for the course.
     * @param \cm_info $newcm The copy.
     * @param int $userid User id.
     * @param int $state The state the original holds for this user.
     * @return bool True if an override had to be written.
     */
    private function mark_complete(\completion_info $completion, \cm_info $newcm, int $userid, int $state): bool {
        global $USER;

        $data = $completion->get_data($newcm, false, $userid);

        // Moodle already worked the state out for itself, so nothing needs overriding.
        if ((int) $data->completionstate === $state) {
            return false;
        }

        // Marked as overridden by the current user.
        $data->completionstate = $state;
        $data->overrideby = $USER->id;
        $data->timemodified = time();

        // Save completion data.
        $completion->internal_set_data($newcm, $data);

        return true;
    }

    /**
     * The state the given quiz is marked with, for each user who has completed it.
     *
     * @param int $cmid Course module id of the quiz.
     * @return array<int, int> User id => completion state.
     */
    private function completed_states(int $cmid): array {
        global $DB;

        $rows = $DB->get_records_select(
            'course_modules_completion',
            'coursemoduleid = :cmid AND completionstate <> :incomplete',
            ['cmid' => $cmid, 'incomplete' => COMPLETION_INCOMPLETE],
            '',
            'userid, completionstate'
        );

        $states = [];
        foreach ($rows as $row) {
            $states[(int) $row->userid] = (int) $row->completionstate;
        }

        return $states;
    }

    /**
     * Users who have viewed the given quiz.
     *
     * @param int $cmid Course module id of the quiz.
     * @return array<int, int> User ids.
     */
    private function viewed_user_ids(int $cmid): array {
        global $DB;

        // Select all users for the course module, having an entry in the mdl_course_modules_viewed table.
        $userids = $DB->get_fieldset_select('course_modules_viewed', 'userid', 'coursemoduleid = :cmid', ['cmid' => $cmid]);

        return array_map('intval', $userids);
    }
}
