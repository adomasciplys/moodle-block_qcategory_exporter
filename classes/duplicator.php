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

use block_qcategory_exporter\duplicator\active_students;
use block_qcategory_exporter\duplicator\override_register;
use block_qcategory_exporter\duplicator\completion_copier;
use block_qcategory_exporter\duplicator\grade_copier;
use block_qcategory_exporter\duplicator\question_resolver;
use block_qcategory_exporter\duplicator\quiz_builder;
use block_qcategory_exporter\duplicator\slot_copier;

/**
 * Orchestrates duplication of every quiz in a course into a new empty copy,
 * with slots repointed at categories listed in the supplied category map.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class duplicator {
    /** @var quiz_builder Creates the empty destination quiz module. */
    private quiz_builder $builder;

    /** @var slot_copier Copies slots and their questions across. */
    private slot_copier $slotcopier;

    /** @var grade_copier Carries each student's grade over to the copy. */
    private grade_copier $gradecopier;

    /** @var completion_copier Carries each student's completion over to the copy. */
    private completion_copier $completioncopier;

    /** @var active_students Determines who are the active students. */
    private active_students $activestudents;

    /** @var exporter Resolves which category a quiz slot draws from. */
    private exporter $exporter;

    /** @var array<int, array{cmid: int, name: string}> Unfinished copies still in the course. */
    private array $leftover = [];

    /**
     * Wire up the helper classes.
     * Pass overrides for testing.
     *
     * @param quiz_builder|null $builder Creates the empty destination quiz module.
     * @param slot_copier|null $slotcopier Copies slots and their questions across.
     * @param exporter|null $exporter Resolves which category a quiz slot draws from.
     * @param completion_copier|null $completioncopier Carries each student's completion over to the copy.
     * @param grade_copier|null $gradecopier Carries each student's grade over to the copy.
     * @param active_students|null $activestudents Determines who are the active students.
     */
    public function __construct(
        ?quiz_builder $builder = null,
        ?slot_copier $slotcopier = null,
        ?exporter $exporter = null,
        ?completion_copier $completioncopier = null,
        ?grade_copier $gradecopier = null,
        ?active_students $activestudents = null
    ) {
        $this->builder = $builder ?? new quiz_builder();
        $this->slotcopier = $slotcopier ?? new slot_copier(new question_resolver());
        $this->exporter = $exporter ?? new exporter();
        $this->completioncopier = $completioncopier ?? new completion_copier();
        $this->gradecopier = $gradecopier ?? new grade_copier();
        $this->activestudents = $activestudents ?? new active_students();
    }

    /**
     * Recreate all quiz activities in the given course without questions, then
     * copy slots across from the originals using the category map.
     *
     * Requires the current user to have capability to manage activities in the course.
     *
     * @param int $courseid
     * @param int[] $categorymap Source category id => imported category id.
     * @return array{duplicated: int, failed: array<int, array>, skipped: array<int, array>,
     *      leftover: array<int, array>, quizmap: array<int, int>}
     */
    public function duplicate_quizzes(int $courseid, array $categorymap = []): array {
        $course = get_course($courseid);
        require_capability('moodle/course:manageactivities', \context_course::instance($courseid));

        // Ask for override permissions up front.
        $this->completioncopier->require_override_capability($course);

        // Same students for every quiz in the course, worked out once.
        $students = $this->activestudents->get_ids($course);

        // Close the session early so this long operation doesn't block other requests.
        \core\session\manager::write_close();

        $quizmap = [];
        $failed = [];

        // Slots that could not be copied, across all the quizzes.
        $skipped = [];

        // Filled in by delete_partial_copy() when a copy could not be cleaned up.
        $this->leftover = [];

        // Duplicate each quiz on its own, so one failure does not stop the rest.
        foreach (get_all_instances_in_course('quiz', $course) as $quiz) {
            // A hidden quiz is not part of the running course, so it is not copied.
            if (empty($quiz->visible)) {
                continue;
            }

            // A quiz drawing only on the course's own categories has nothing to repoint.
            if (!$this->quiz_uses_mapped_category($quiz, $categorymap)) {
                continue;
            }

            try {
                $copy = $this->duplicate_single_quiz($course, $quiz, $categorymap, $students);
                $quizmap[(int) $quiz->id] = $copy['quizid'];
                $skipped = array_merge($skipped, $this->describe_skipped_slots($quiz, $copy['skipped']));
            } catch (\Throwable $e) {
                $failed[] = $this->describe_failure($quiz, $e);
            }
        }

        // The new quizzes are not in modinfo yet.
        rebuild_course_cache($courseid, true);

        return [
            'duplicated' => count($quizmap),
            'failed' => $failed,
            'skipped' => $skipped,
            'leftover' => $this->leftover,
            'quizmap' => $quizmap,
        ];
    }

    /**
     * Does this quiz draw questions from any of the categories being imported?
     *
     * @param \stdClass $quiz Source quiz row.
     * @param int[] $categorymap Source category id => imported category id.
     * @return bool True when at least one slot draws from a mapped category.
     */
    private function quiz_uses_mapped_category(\stdClass $quiz, array $categorymap): bool {
        global $DB;

        // Slot references are stored against the quiz's own context.
        $quizcontextid = \context_module::instance((int) $quiz->coursemodule)->id;

        // Every question entry in the quiz.
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], '', 'id');

        foreach ($slots as $slot) {
            // Find the category this slot takes its questions from.
            $category = $this->exporter->get_category_for_slot((int) $slot->id, $quizcontextid);

            // One match is enough, the quiz is worth copying.
            if ($category && isset($categorymap[(int) $category->id])) {
                return true;
            }
        }

        // No slot draws from an imported category.
        return false;
    }

    /**
     * Duplicate one quiz: build an empty copy, then copy its slots over.
     *
     * A quiz activity is two records.
     *   1) The course module says where it sits in the course: section, visibility, group mode, completion.
     *   2) The quiz record holds the quiz's own settings: time limit, attempts, grading, review options.
     * The copy needs both.
     *
     * @param \stdClass $course Course the quiz belongs to.
     * @param \stdClass $quiz Source quiz row
     * @param int[] $categorymap Source category id => imported/destination category id.
     * @param int[] $students User ids to carry grades and completion over for.
     * @return array{quizid: int, skipped: array<int, array{slot: int, reason: string}>} The new quiz
     *      instance id, and the slots that could not be copied into it.
     */
    private function duplicate_single_quiz(
        \stdClass $course,
        \stdClass $quiz,
        array $categorymap,
        array $students
    ): array {
        global $DB;

        // Find the course module instance of the quiz we are trying to duplicate.
        $cmid = (int) ($quiz->coursemodule ?? 0);
        $cm = get_coursemodule_from_id('quiz', $cmid, $course->id, false, MUST_EXIST);

        // Read the quiz's own settings, without the course module fields merged in.
        $quizrecord = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);

        // Build the copy: same settings, no questions yet.
        $newmoduleinfo = $this->builder->build($course, $cm, $quizrecord);
        $newquizid = (int) $newmoduleinfo->instance;

        // Fill the copy with the original's questions, pointed at the imported categories,
        // then carry the students' work over to it.
        $skipped = [];
        try {
            // Copy the questions. Slots that cannot be copied are collected in $skipped.
            if (!empty($categorymap)) {
                $skipped = $this->slotcopier->copy_all(
                    (int) $quizrecord->id,
                    $newquizid,
                    (int) $cm->id,
                    $categorymap
                );
            }

            // Copy the active students' grades, and override the passing ones.
            $this->gradecopier->copy_all(
                (int) $quizrecord->id,
                $newquizid,
                (int) $newmoduleinfo->coursemodule,
                $students
            );

            // Copy the active students' completion states.
            $this->completioncopier->copy_all(
                $course,
                (int) $cm->id,
                (int) $newmoduleinfo->coursemodule,
                $newquizid,
                $students
            );
        } catch (\Throwable $e) {
            // Any step failed: delete the half-built copy, then let duplicate_quizzes() report the failure.
            $this->delete_partial_copy((int) $newmoduleinfo->coursemodule, $quiz);
            throw $e;
        }

        return ['quizid' => $newquizid, 'skipped' => $skipped];
    }

    /**
     * Remove a copy that was created but never finished.
     *
     * course_delete_module() takes the quiz record, the course module, the slots and the
     * files with it.
     *
     * The deletion can fail as well, most likely for the same reason the copying did.
     *
     * @param int $cmid Course module id of the unfinished copy.
     * @param \stdClass $quiz The source quiz this copy was made from.
     * @return void
     */
    private function delete_partial_copy(int $cmid, \stdClass $quiz): void {
        global $CFG, $DB;

        // Needed for course_delete_module().
        require_once($CFG->dirroot . '/course/lib.php');

        // The copy may already have been marked as holding carried-over work.
        $DB->delete_records(override_register::TABLE, ['cmid' => $cmid]);

        try {
            course_delete_module($cmid);
        } catch (\Throwable $e) {
            $this->leftover[] = [
                'cmid' => $cmid,
                'name' => isset($quiz->name) ? (string) $quiz->name : '',
            ];
        }
    }

    /**
     * Name the quiz each skipped slot came from, so the caller can report it.
     *
     * @param \stdClass $quiz The source quiz whose slots were skipped.
     * @param array[] $skipped What copy_all() reported: one array per skipped slot, with the keys slot and reason.
     * @return array<int, array{quizid: int, cmid: int, name: string, slot: int, reason: string}>
     */
    private function describe_skipped_slots(\stdClass $quiz, array $skipped): array {
        $described = [];

        foreach ($skipped as $entry) {
            $described[] = [
                'quizid' => isset($quiz->id) ? (int) $quiz->id : 0,
                'cmid' => isset($quiz->coursemodule) ? (int) $quiz->coursemodule : 0,
                'name' => isset($quiz->name) ? (string) $quiz->name : '',
                'slot' => $entry['slot'],
                'reason' => $entry['reason'],
            ];
        }

        return $described;
    }

    /**
     * Shape a thrown exception into a flat array for the caller's failure report.
     *
     * @param \stdClass $quiz The source quiz that failed to duplicate.
     * @param \Throwable $e The exception raised during duplication.
     * @return array Structured failure entry (quizid, cmid, name, exception, message, debuginfo).
     */
    private function describe_failure(\stdClass $quiz, \Throwable $e): array {
        return [
            'quizid' => isset($quiz->id) ? (int) $quiz->id : null,
            'cmid' => isset($quiz->coursemodule) ? (int) $quiz->coursemodule : 0,
            'name' => isset($quiz->name) ? (string) $quiz->name : '',
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'debuginfo' => ($e instanceof \moodle_exception) ? $e->debuginfo : null,
        ];
    }
}
