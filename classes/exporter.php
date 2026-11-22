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

defined('MOODLE_INTERNAL') || die();

use context_course, context_module;
use stdClass;
use moodle_exception;
use core_question\local\bank\question_bank_helper;
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Works out which question categories a course's quizzes depend on,
 * and which are not in the course's own question bank already.
 *
 * Shows an admin what would be copied.
 * The importer uses it to know what to copy.
 * Side effect: Creates course's own question bank module, if none exists yet.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exporter {
    /**
     * Walks each quiz in the course, then each slot in that quiz, and resolves the category
     * behind the slot. A category is kept only when both of these hold:
     *
     *  - it holds at least one question, so that copying it would produce something
     *  - it sits outside the course's own question bank, so it is not local already
     *
     * Quizzes the current user cannot see are skipped, on the grounds that a course does not
     * depend on questions it never asks. A category used by several slots is listed once.
     *
     * @param int $courseid Course ID.
     * @return array<int, array{id: int, name: string}> Categories keyed by their category id.
     */
    public function collect_categories_for_course($courseid) {
        // Categories already in the course's own question bank need no copying.
        $bankcontextid = $this->get_course_bank_contextid($courseid);

        // Modinfo holds every activity in the course.
        $modinfo = get_fast_modinfo($courseid);

        $categoriesmap = [];
        foreach ($modinfo->instances['quiz'] ?? [] as $quizactivity) {
            // A hidden quiz is not part of the running course, so its categories are not wanted.
            if (empty($quizactivity->visible)) {
                continue;
            }
            $this->add_categories_for_quiz($categoriesmap, $quizactivity, $bankcontextid);
        }

        return $categoriesmap;
    }

    /**
     * Context id of the course's own question bank, the place the imported categories end up.
     *
     * @param int $courseid Course ID.
     * @return int Context id of the course's question bank module.
     * @throws moodle_exception When the bank can neither be found nor created.
     */
    public function get_course_bank_contextid($courseid) {
        $questionbankhelper = new question_bank_helper();

        // Passing true creates the bank when the course has none yet.
        $topmodule = $questionbankhelper->get_default_open_instance_system_type(get_course($courseid), true);
        if (!$topmodule) {
            throw new moodle_exception('noquestionbank', 'block_qcategory_exporter');
        }

        return context_module::instance($topmodule->id)->id;
    }

    /**
     * Add every category one quiz draws questions from to the running map.
     *
     * @param array $categoriesmap Reference to the map being built.
     * @param \core_course\cm_info $quizactivity The quiz activity, from modinfo.
     * @param int $bankcontextid Context id of the course's own question bank.
     * @return void
     */
    public function add_categories_for_quiz(array &$categoriesmap, $quizactivity, $bankcontextid) {
        global $DB;

        $quizcontextid = context_module::instance($quizactivity->id)->id;
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quizactivity->instance], '', 'id');

        foreach ($slots as $slot) {
            $category = $this->get_category_for_slot((int) $slot->id, $quizcontextid);
            if ($category && $this->should_include_category($category, $bankcontextid)) {
                $this->add_category($categoriesmap, $category);
            }
        }
    }

    /**
     * Resolve the category a single quiz slot draws its questions from.
     *
     * A slot is one question entry in a quiz, and comes in two kinds.
     * A normal slot points at one specific question, and is recorded in question_references.
     * A random slot points at a category to draw from, and is recorded in question_set_references.
     * A slot is never both, so once one table answers there is no reason to ask the other.
     *
     * @param int $slotid Row id from quiz_slots.
     * @param int $quizcontextid Module context id of the quiz holding the slot.
     * @return stdClass|false|null Category record, or a falsy value when the slot resolves to none.
     */
    public function get_category_for_slot($slotid, $quizcontextid) {
        global $DB;

        // Both reference tables are keyed the same way.
        $refparams = [
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'usingcontextid' => $quizcontextid,
            'itemid' => $slotid,
        ];

        $normalref = $DB->get_record('question_references', $refparams);
        if ($normalref) {
            return $this->get_category_from_normal_slot($normalref);
        }

        $randomref = $DB->get_record('question_set_references', $refparams);
        if ($randomref) {
            return $this->get_category_from_random_slot($randomref);
        }

        return null;
    }

    /**
     * Resolve the category holding the question a normal slot points at.
     *
     *     slot -> question_references -> question_bank_entries -> question_categories
     *
     * @param stdClass $normalref Row from question_references.
     * @return stdClass|false|null Category record;
     *                             false when the entry or its category is missing,
     *                             null when the slot points at no entry at all.
     */
    public function get_category_from_normal_slot($normalref) {
        global $DB;

        // Slot points at no question bank entry.
        if (empty($normalref->questionbankentryid)) {
            return null;
        }

        $sql = "SELECT qc.*
                  FROM {question_bank_entries} qbe
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qbe.id = :entryid";

        return $DB->get_record_sql($sql, ['entryid' => $normalref->questionbankentryid]);
    }

    /**
     * Resolve the category a random slot draws its questions from.
     *
     * A random slot stores the question picker's filter as JSON in filtercondition:
     *
     *     {"filter":{"category":{"jointype":1,"values":["123"],
     *      "filteroptions":{"includesubcategories":false}}}}
     *
     * "values" holds the category ids the filter matches, and the slot draws from the
     * first one.
     *
     * @param stdClass $randomref Row from question_set_references.
     * @return stdClass|false|null Category record;
     *                             false when the id matches no category,
     *                             null when the filter names no category at all.
     */
    public function get_category_from_random_slot($randomref) {
        global $DB;

        // Filter condition does not exist.
        if (empty($randomref->filtercondition)) {
            return null;
        }

        $condition = json_decode($randomref->filtercondition);
        $categoryid = (int) ($condition->filter->category->values[0] ?? 0);
        if (!$categoryid) {
            return null;
        }

        return $DB->get_record('question_categories', ['id' => $categoryid]);
    }

    /**
     * Decide whether a category should be included in the export list.
     * Only include non-course-context categories that contain entries.
     *
     * @param stdClass $category Category record.
     * @param int $coursecontextid Course context id.
     * @return bool
     */
    public function should_include_category($category, $coursecontextid) {
        global $DB;
        if (!$category) {
            return false;
        }

        // Must contain at least one entry.
        $hasentries = $DB->record_exists('question_bank_entries', ['questioncategoryid' => $category->id]);
        if (!$hasentries) {
            return false;
        }

        // Exclude categories that belong to the course context.
        return (int) $category->contextid !== (int) $coursecontextid;
    }

    /**
     * Add a category to the map keyed by ID.
     *
     * @param array $categoriesmap Reference to the map being built.
     * @param stdClass $category The category record.
     * @return void
     */
    public function add_category(array &$categoriesmap, $category) {
        $categoriesmap[(int) $category->id] = [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
        ];
    }
}
