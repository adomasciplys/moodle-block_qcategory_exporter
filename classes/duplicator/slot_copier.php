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
 * Copies quiz slots (normal + random) from a source quiz to a destination quiz,
 * translating question category references via a categorymap.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slot_copier {
    /** @var string The slot names neither a question nor a category. */
    public const REASON_NO_REFERENCE = 'noreference';

    /** @var string The question bank entry the slot names is gone. */
    public const REASON_MISSING_ENTRY = 'missingentry';

    /** @var string The entry holds no version of the question. */
    public const REASON_NO_QUESTION_VERSION = 'noquestionversion';

    /** @var string Nothing in the imported category matches the question. */
    public const REASON_NO_MATCHING_QUESTION = 'nomatchingquestion';

    /** @var string The category the slot draws from was not imported into the course. */
    public const REASON_CATEGORY_NOT_IMPORTED = 'categorynotimported';

    /** @var string An earlier slot already put this question in the quiz. */
    public const REASON_ALREADY_IN_QUIZ = 'alreadyinquiz';

    /** @var string The random slot has no stored filter to draw questions with. */
    public const REASON_NO_FILTER = 'nofilter';

    /** @var string The random slot's filter names no category. */
    public const REASON_NO_FILTER_CATEGORY = 'nofiltercategory';

    /** @var question_resolver Locates questions in the target category and copies their files. */
    private question_resolver $questions;

    /**
     * Wire up the question resolver this copier leans on.
     *
     * @param question_resolver $questions Locates target-category questions and copies their files.
     */
    public function __construct(question_resolver $questions) {
        $this->questions = $questions;
    }

    /**
     * Copy every slot from the old quiz into the new quiz, remapping question
     * categories via the provided map.
     *
     * A slot that cannot be copied is reported rather than dropped in silence.
     *
     * @param int $oldquizid
     * @param int $newquizid
     * @param int $oldcmid
     * @param array<int, int> $categorymap Source category id => imported category id.
     * @return array<int, array{slot: int, reason: string}> The slots left out, in slot order.
     */
    public function copy_all(int $oldquizid, int $newquizid, int $oldcmid, array $categorymap): array {
        global $CFG, $DB;

        // Needed for quiz_add_quiz_question().
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        // Slot references are stored against the source quiz's own context.
        $oldcontextid = \context_module::instance($oldcmid)->id;

        // Normal slots are added onto the quiz record, random ones through its structure.
        $newquiz = $DB->get_record('quiz', ['id' => $newquizid], '*', MUST_EXIST);
        $quizobj = \mod_quiz\quiz_settings::create($newquizid);
        $structure = $quizobj->get_structure();

        // Questions this quiz has already taken, so no two slots land on the same one.
        $usedentryids = [];

        // The slots that could not be copied, and why.
        $skipped = [];

        // Fetched in the order the original asked them, so the copy reads the same way.
        $slots = $DB->get_records('quiz_slots', ['quizid' => $oldquizid], 'slot ASC');
        foreach ($slots as $slot) {
            $reason = $this->copy_slot($slot, $oldcontextid, $newquiz, $structure, $categorymap, $usedentryids);
            if ($reason !== null) {
                $skipped[] = ['slot' => (int) $slot->slot, 'reason' => $reason];
            }
        }

        // The quiz is only worth something once its slots exist.
        $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();

        return $skipped;
    }

    /**
     * Decide whether a slot is a normal (single question) or random reference
     * and dispatch to the matching copy routine.
     *
     * @param \stdClass $slot Source quiz_slots row.
     * @param int $oldcontextid Module context id of the source quiz.
     * @param \stdClass $newquiz Destination quiz record.
     * @param \mod_quiz\structure $structure Destination quiz structure (for random slot insertion).
     * @param array<int, int> $categorymap Source category id => imported category id.
     * @param array<int, int> $usedentryids Target entries already taken by an earlier slot; added to here.
     * @return string|null Why the slot was left out, or null when it was copied.
     */
    private function copy_slot(
        \stdClass $slot,
        int $oldcontextid,
        \stdClass $newquiz,
        \mod_quiz\structure $structure,
        array $categorymap,
        array &$usedentryids
    ): ?string {
        global $DB;

        // Both reference tables are keyed the same way.
        $refparams = [
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'usingcontextid' => $oldcontextid,
            'itemid' => $slot->id,
        ];

        // A normal slot names one question.
        $normalref = $DB->get_record('question_references', $refparams, '*', IGNORE_MISSING);
        if ($normalref) {
            return $this->copy_normal_slot($newquiz, $slot, $normalref, $categorymap, $usedentryids);
        }

        // A random slot names a category to draw from. A slot is never both kinds.
        $randomref = $DB->get_record('question_set_references', $refparams, '*', IGNORE_MISSING);
        if ($randomref) {
            return $this->copy_random_slot($structure, $slot, $randomref, $categorymap);
        }

        // The slot names neither, so there is nothing to put in the copy.
        return self::REASON_NO_REFERENCE;
    }

    /**
     * Add a single specific question to the new quiz.
     * Looks the question up in the target category (via categorymap)
     *
     * @param \stdClass $newquiz Destination quiz record.
     * @param \stdClass $slot Source quiz_slots row.
     * @param \stdClass $normalref Source question_references row for this slot.
     * @param array<int, int> $categorymap Source category id => imported category id.
     * @param array<int, int> $usedentryids Target entries already taken by an earlier slot; added to here.
     * @return string|null Why the slot was left out, or null when it was copied.
     */
    private function copy_normal_slot(
        \stdClass $newquiz,
        \stdClass $slot,
        \stdClass $normalref,
        array $categorymap,
        array &$usedentryids
    ): ?string {
        global $DB;

        // The slot names a question bank entry.
        $entry = $DB->get_record('question_bank_entries', ['id' => $normalref->questionbankentryid ?? 0], '*', IGNORE_MISSING);
        if (!$entry) {
            return self::REASON_MISSING_ENTRY;
        }

        // The question that entry currently holds.
        $oldquestion = $this->questions->get_latest_question_for_entry((int) $entry->id);
        if (!$oldquestion) {
            return self::REASON_NO_QUESTION_VERSION;
        }

        // The imported copy of the question's category. A category that was not imported is
        // left alone: pointing the copy at it would rebuild the link the import breaks.
        $sourcecategoryid = (int) $entry->questioncategoryid;
        if (!isset($categorymap[$sourcecategoryid])) {
            return self::REASON_CATEGORY_NOT_IMPORTED;
        }
        $targetcategoryid = (int) $categorymap[$sourcecategoryid];

        // The matching question inside that category, skipping the ones already taken.
        $newquestion = $this->questions->find_matching_question(
            $targetcategoryid,
            $entry,
            $oldquestion,
            $usedentryids
        );
        if (!$newquestion) {
            return self::REASON_NO_MATCHING_QUESTION;
        }

        // Add it to the copy, on the same page as the original.
        $page = isset($slot->page) ? (int) $slot->page : 0;
        $newentryid = (int) $newquestion->questionbankentryid;
        quiz_add_quiz_question((int) $newquestion->id, $newquiz, $page);

        // Moodle refuses to hold one question twice, and then adds no slot at all.
        $newslot = $this->find_slot_for_entry((int) $newquiz->id, $newentryid);
        if (!$newslot) {
            return self::REASON_ALREADY_IN_QUIZ;
        }

        // No later slot may pick this question again.
        $usedentryids[] = $newentryid;

        // Then the slot's own settings: mark, require previous, display number.
        $this->sync_slot_metadata((int) $newslot->id, $slot);

        // And any images or feedback files the question carries.
        $this->copy_question_files($oldquestion, $newquestion);

        return null;
    }

    /**
     * Insert a random-question slot into the new quiz, rewriting its filter so
     * the category points at the imported course-level category instead of the
     * original system one.
     *
     * A slot drawing from a category that was not imported is left out of the copy, the same
     * way a slot holding one specific question is. Keeping it would point the copy back at
     * the category the import needs to break away from.
     *
     * @param \mod_quiz\structure $structure Destination quiz structure.
     * @param \stdClass $slot Source quiz_slots row.
     * @param \stdClass $randomref Source question_set_references row with filtercondition JSON.
     * @param array<int, int> $categorymap Source category id => imported category id.
     * @return string|null Why the slot was left out, or null when it was copied.
     */
    private function copy_random_slot(
        \mod_quiz\structure $structure,
        \stdClass $slot,
        \stdClass $randomref,
        array $categorymap
    ): ?string {
        if (empty($randomref->filtercondition)) {
            return self::REASON_NO_FILTER;
        }

        // The category the source slot draws from.
        $filtercondition = json_decode($randomref->filtercondition, true);
        $oldcategoryid = (int) ($filtercondition['filter']['category']['values'][0] ?? 0);

        // Without a category there is nothing to draw questions from.
        if (!$oldcategoryid) {
            return self::REASON_NO_FILTER_CATEGORY;
        }

        // Only categories that were imported into the course.
        if (!isset($categorymap[$oldcategoryid])) {
            return self::REASON_CATEGORY_NOT_IMPORTED;
        }
        $newcategoryid = (int) $categorymap[$oldcategoryid];

        // Draw from the imported category, and note the copy as where the slot now lives.
        $filtercondition['filter']['category']['values'][0] = $newcategoryid;
        $filtercondition = $this->retarget_filter_bookkeeping($filtercondition, $newcategoryid, $structure);

        // Add the slot on the same page as the original, then mirror its settings.
        $page = isset($slot->page) ? (int) $slot->page : 0;
        $structure->add_random_questions($page, 1, $filtercondition);
        $this->sync_last_slot_metadata((int) $structure->get_quizid(), $slot);

        return null;
    }

    /**
     * Point the leftover notes in the filter at the copy instead of the source.
     *
     * Left alone every copied slot still names the bank it came from, and a Moodle
     * repair script would later turn that into a category and context that do not match.
     *
     * @param array $filtercondition Decoded filtercondition, category filter already remapped.
     * @param int $newcategoryid Imported category the slot now draws from.
     * @param \mod_quiz\structure $structure Destination quiz structure.
     * @return array The filtercondition with its notes pointing at the copy.
     */
    private function retarget_filter_bookkeeping(
        array $filtercondition,
        int $newcategoryid,
        \mod_quiz\structure $structure
    ): array {
        // The category the picker was browsing, written as "categoryid,contextid".
        if (array_key_exists('cat', $filtercondition)) {
            $newcontextid = $this->questions->category_contextid($newcategoryid);

            // Without the category's context there is no correct value to write.
            if ($newcontextid) {
                $filtercondition['cat'] = $newcategoryid . ',' . $newcontextid;
            }
        }

        // The quiz the picker was opened from, now the copy.
        if (array_key_exists('cmid', $filtercondition)) {
            $filtercondition['cmid'] = (int) $structure->get_cmid();
        }

        // The course that quiz sits in.
        if (array_key_exists('courseid', $filtercondition)) {
            $filtercondition['courseid'] = (int) $structure->get_courseid();
        }

        return $filtercondition;
    }

    /**
     * Copy any files attached to the source question into the destination question's context
     *
     * @param \stdClass $oldquestion Source question record.
     * @param \stdClass $newquestion Destination question record.
     */
    private function copy_question_files(\stdClass $oldquestion, \stdClass $newquestion): void {
        $oldcontextid = $this->questions->category_contextid((int) $oldquestion->category);
        $newcontextid = $this->questions->category_contextid((int) $newquestion->category);
        if (!$oldcontextid || !$newcontextid) {
            return;
        }
        $this->questions->copy_question_item_files(
            $oldcontextid,
            (int) $oldquestion->id,
            $newcontextid,
            (int) $newquestion->id
        );
    }

    /**
     * Copy the source slot's settings onto the slot that was just added.
     *
     * @param int $quizid Destination quiz id.
     * @param \stdClass $oldslot Source quiz_slots row whose settings should be mirrored.
     */
    private function sync_last_slot_metadata(int $quizid, \stdClass $oldslot): void {
        $newslot = $this->get_last_quiz_slot($quizid);
        if (!$newslot) {
            return;
        }
        $this->sync_slot_metadata((int) $newslot->id, $oldslot);
    }

    /**
     * Mirror the source slot's own settings onto the new slot
     *
     * @param int $newslotid Destination slot id.
     * @param \stdClass $oldslot Source quiz_slots row.
     */
    private function sync_slot_metadata(int $newslotid, \stdClass $oldslot): void {
        global $DB;

        // What the question is worth in this quiz.
        if (isset($oldslot->maxmark)) {
            $DB->set_field('quiz_slots', 'maxmark', (float) $oldslot->maxmark, ['id' => $newslotid]);
        }

        // Whether the student must finish the previous question first.
        if (isset($oldslot->requireprevious)) {
            $DB->set_field('quiz_slots', 'requireprevious', (int) $oldslot->requireprevious, ['id' => $newslotid]);
        }

        // The number shown beside the question, when the teacher has overridden it.
        if (property_exists($oldslot, 'displaynumber') && $oldslot->displaynumber !== null) {
            $DB->set_field('quiz_slots', 'displaynumber', (string) $oldslot->displaynumber, ['id' => $newslotid]);
        }
    }

    /**
     * Fetch the slot of a quiz that points at a given question bank entry.
     *
     * Naming the entry is exact, unlike taking the last slot, which moves when Moodle
     * renumbers the slots around an insertion.
     *
     * @param int $quizid Destination quiz id.
     * @param int $entryid Question bank entry the slot should point at.
     * @return \stdClass|null quiz_slots row, or null when no slot holds that entry.
     */
    private function find_slot_for_entry(int $quizid, int $entryid): ?\stdClass {
        global $DB;

        $sql = "SELECT s.*
                  FROM {quiz_slots} s
                  JOIN {question_references} qr ON qr.itemid = s.id
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
                 WHERE s.quizid = :quizid
                   AND qr.questionbankentryid = :entryid";

        return $DB->get_record_sql($sql, ['quizid' => $quizid, 'entryid' => $entryid], IGNORE_MISSING) ?: null;
    }

    /**
     * Fetch the slot with the highest slot number for a quiz
     *
     * @param int $quizid Destination quiz id.
     * @return \stdClass|null quiz_slots row, or null if the quiz has no slots.
     */
    private function get_last_quiz_slot(int $quizid): ?\stdClass {
        global $DB;
        $sql = "SELECT *
                  FROM {quiz_slots}
                 WHERE quizid = :quizid
              ORDER BY slot DESC";
        $slots = $DB->get_records_sql($sql, ['quizid' => $quizid], 0, 1);
        return $slots ? reset($slots) : null;
    }
}
