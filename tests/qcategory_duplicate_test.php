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

/**
 * Unit tests for the block_qcategory_exporter plugin.
 *
 * Focus: functions in qcategory_exporter/classes/duplicator.php and its helpers.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_qcategory_exporter;

use advanced_testcase;
use context_module, context_system;
use block_qcategory_exporter\duplicator\question_resolver;
use block_qcategory_exporter\duplicator\slot_copier;
use core_question\local\bank\question_bank_helper;
use block_qcategory_exporter\duplicator\override_register;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversMethod;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// Defines completion_info and the COMPLETION_ constants.
require_once($CFG->libdir . '/completionlib.php');
// Defines quiz_create_attempt() and the rest of the attempt helpers.
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * PHPUnit tests for duplicating quizzes onto imported question categories.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversMethod(\block_qcategory_exporter\duplicator\slot_copier::class, 'copy_random_slot')]
#[CoversMethod(\block_qcategory_exporter\duplicator\slot_copier::class, 'copy_normal_slot')]
#[CoversMethod(\block_qcategory_exporter\duplicator::class, 'quiz_uses_mapped_category')]
#[CoversMethod(\block_qcategory_exporter\duplicator\quiz_builder::class, 'build')]
#[CoversMethod(\block_qcategory_exporter\duplicator\completion_copier::class, 'copy_all')]
#[CoversMethod(\block_qcategory_exporter\duplicator\grade_copier::class, 'copy_all')]
#[CoversMethod(\block_qcategory_exporter\observer::class, 'attempt_graded')]
#[CoversMethod(\block_qcategory_exporter\observer::class, 'question_manually_graded')]
final class qcategory_duplicate_test extends advanced_testcase {
    /**
     * A copied random slot must not keep any reference to the shared bank it came from.
     *
     * Beside the filter that picks the questions, Moodle stores the state of the question
     * picker: the category being browsed ('cat'), the quiz it was opened from ('cmid') and
     * the course ('courseid'). All of them must point at the copy, not the source.
     */
    public function test_duplicate_quizzes_repoints_random_slot_bookkeeping(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');

        // The shared bank the questions are being moved away from.
        $sourcecat = $qgen->create_question_category([
            'contextid' => context_system::instance()->id,
            'name' => 'Shared: Contraindications',
        ]);

        // The course's own bank, holding the imported copy of that category.
        $bank = question_bank_helper::get_default_open_instance_system_type($course, true);
        $bankcontext = context_module::instance($bank->id);
        $targetcat = $qgen->create_question_category([
            'contextid' => $bankcontext->id,
            'parent' => question_get_top_category($bankcontext->id, true)->id,
            'name' => 'Course: Contraindications',
        ]);

        // A quiz with one random slot drawing from the shared bank, shaped the way the
        // question picker stores it.
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $sourcecmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $sourcefilter = [
            'filter' => [
                'category' => [
                    'name' => 'category',
                    'jointype' => 1,
                    'values' => [(int) $sourcecat->id],
                    'filteroptions' => ['includesubcategories' => true],
                ],
            ],
            'cmid' => $sourcecmid,
            'courseid' => (int) $course->id,
            'jointype' => 2,
            'qpage' => 0,
            'qperpage' => 100,
            'sortdata' => [],
            'cat' => $sourcecat->id . ',' . $sourcecat->contextid,
            'tabname' => 'questions',
        ];
        quiz_settings::create($quiz->id)->get_structure()->add_random_questions(0, 1, $sourcefilter);

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $newquizid = $result['quizmap'][(int) $quiz->id];
        $newcmid = (int) get_coursemodule_from_instance('quiz', $newquizid)->id;

        $copied = json_decode($this->random_slot_filtercondition($newquizid), true);
        $this->assertEquals([(int) $targetcat->id], $copied['filter']['category']['values']);
        $this->assertSame($targetcat->id . ',' . $bankcontext->id, $copied['cat']);
        $this->assertSame($newcmid, $copied['cmid']);
        $this->assertSame((int) $course->id, $copied['courseid']);

        // Untouched keys are still carried across, and the source quiz is left alone.
        $this->assertSame(100, $copied['qperpage']);
        $original = json_decode($this->random_slot_filtercondition((int) $quiz->id), true);
        $this->assertSame($sourcecat->id . ',' . $sourcecat->contextid, $original['cat']);
        $this->assertSame($sourcecmid, $original['cmid']);
    }

    /**
     * A slot holding one specific question must end up on the course's copy of that
     * question, not the shared bank's original, and keep its mark.
     */
    public function test_duplicate_quizzes_copies_specific_question_slots(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        // The same question in both banks: the shared original, and the course's copy.
        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );
        $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $targetcat->id, 'name' => 'Shared Q1']
        );

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz, 1, 3.0);

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $newquizid = $result['quizmap'][(int) $quiz->id];

        $sql = "SELECT qbe.questioncategoryid, s.maxmark
                  FROM {quiz_slots} s
                  JOIN {question_references} qr ON qr.itemid = s.id
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                 WHERE s.quizid = :quizid";
        $copied = $DB->get_record_sql($sql, ['quizid' => $newquizid], MUST_EXIST);

        $this->assertEquals($targetcat->id, $copied->questioncategoryid);
        $this->assertEquals(3.0, (float) $copied->maxmark);
    }

    /**
     * Three slots holding three different questions that happen to share one name must
     * copy across as three slots, each on its own question.
     *
     * Matching on name alone cannot tell such questions apart: all three slots resolve to
     * the same target question, and Moodle refuses to put one question in a quiz twice, so
     * two of the three slots are silently dropped.
     */
    public function test_duplicate_quizzes_copies_slots_sharing_one_question_name(): void {
        // ARRANGE.
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        // Three questions under one name, told apart only by their text.
        $texts = ['<p>The bench height is fixed</p>', '<p>What if the bench is too high?</p>', '<p>What if it will not move?</p>'];
        $sourcequestions = [];
        foreach ($texts as $text) {
            $sourcequestions[] = $qgen->create_question('shortanswer', null, [
                'category' => $sourcecat->id,
                'name' => 'Bench height',
                'questiontext' => ['text' => $text, 'format' => FORMAT_HTML],
            ]);
        }

        // The imported copies, held in a different order than the quiz asks them in.
        foreach (array_reverse($texts) as $text) {
            $qgen->create_question('shortanswer', null, [
                'category' => $targetcat->id,
                'name' => 'Bench height',
                'questiontext' => ['text' => $text, 'format' => FORMAT_HTML],
            ]);
        }

        // One quiz asking all three, in the order they were written.
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        foreach ($sourcequestions as $sourcequestion) {
            quiz_add_quiz_question((int) $sourcequestion->id, $quiz, 1);
        }

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $copied = $this->quiz_slot_questions($result['quizmap'][(int) $quiz->id]);

        // Every slot came across, in the order the original asked them.
        $this->assertCount(3, $copied);
        $this->assertSame($texts, array_column($copied, 'questiontext'));

        // On the course's copies of the questions, not the shared originals.
        foreach ($copied as $slot) {
            $this->assertEquals($targetcat->id, $slot['categoryid']);
        }
    }

    /**
     * A quiz drawing only on categories that were never imported is not copied at all,
     * rather than being copied into an empty shell.
     */
    public function test_duplicate_quizzes_skips_quiz_when_category_is_not_mapped(): void {
        // ARRANGE.
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $sourcecmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $filter = $this->random_filter_condition($sourcecat, $sourcecmid, (int) $course->id);
        quiz_settings::create($quiz->id)->get_structure()->add_random_questions(0, 1, $filter);

        // ACT.
        // The map covers some other category, so this slot's category is not in it.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [-1 => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $this->assertSame(0, $result['duplicated']);
        $this->assertArrayNotHasKey((int) $quiz->id, $result['quizmap']);
    }

    /**
     * The copy is a new quiz carrying the source's settings, named "... (copy)".
     */
    public function test_duplicate_quizzes_copies_quiz_settings(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Original quiz',
            'timelimit' => 600,
            'attempts' => 3,
        ]);

        // The quiz needs a question from the source category, or it is not copied at all.
        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );
        $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $targetcat->id, 'name' => 'Shared Q1']
        );
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz);

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame(1, $result['duplicated']);
        $copy = $DB->get_record('quiz', ['id' => $result['quizmap'][(int) $quiz->id]], '*', MUST_EXIST);

        $this->assertSame(get_string('duplicatedmodule', 'moodle', 'Original quiz'), $copy->name);
        $this->assertEquals(600, $copy->timelimit);
        $this->assertEquals(3, $copy->attempts);
        $this->assertEquals($course->id, $copy->course);
        $this->assertNotEquals($quiz->id, $copy->id);
    }

    /**
     * Create the two categories every test needs: one in the shared system bank, and the
     * course's own imported copy of it.
     *
     * @param \stdClass $course
     * @return array{0: \stdClass, 1: \stdClass, 2: \core\context\module} Source, target, bank context.
     */
    /**
     * The grade to pass lives on the gradebook item rather than the quiz record, so it has
     * to be carried over deliberately.
     */
    public function test_duplicate_quizzes_copies_grade_to_pass(): void {
        // ARRANGE.
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );
        $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $targetcat->id, 'name' => 'Shared Q1']
        );

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz);

        // Give the source quiz a grade to pass.
        $sourceitem = $this->quiz_grade_item((int) $course->id, (int) $quiz->id);
        $sourceitem->gradepass = 5.0;
        $sourceitem->update();

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $copyitem = $this->quiz_grade_item((int) $course->id, $result['quizmap'][(int) $quiz->id]);

        $this->assertNotNull($copyitem);
        $this->assertEquals(5.0, (float) $copyitem->gradepass);
    }

    /**
     * The gradebook item for a quiz.
     *
     * @param int $courseid Course the quiz is in.
     * @param int $quizid Quiz instance id.
     * @return \grade_item|null
     */
    private function quiz_grade_item(int $courseid, int $quizid): ?\grade_item {
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'itemnumber' => 0,
            'courseid' => $courseid,
        ]);

        return $item ?: null;
    }

    /**
     * Overall feedback lives in its own table rather than on the quiz record, so it has to
     * be carried over deliberately.
     */
    public function test_duplicate_quizzes_copies_overall_feedback(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );
        $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $targetcat->id, 'name' => 'Shared Q1']
        );

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10]);
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz);

        // Two grade bands of overall feedback on the source.
        $DB->delete_records('quiz_feedback', ['quizid' => $quiz->id]);
        $DB->insert_record('quiz_feedback', (object) [
            'quizid' => $quiz->id,
            'feedbacktext' => '<p>Well done</p>',
            'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => 5.0,
            'maxgrade' => 10.00001,
        ]);
        $DB->insert_record('quiz_feedback', (object) [
            'quizid' => $quiz->id,
            'feedbacktext' => '<p>Keep going</p>',
            'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => 0.0,
            'maxgrade' => 5.0,
        ]);

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $copied = $DB->get_records(
            'quiz_feedback',
            ['quizid' => $result['quizmap'][(int) $quiz->id]],
            'mingrade ASC',
            'feedbacktext, mingrade, maxgrade'
        );

        $this->assertCount(2, $copied);

        $bands = array_values($copied);
        $this->assertSame('<p>Keep going</p>', $bands[0]->feedbacktext);
        $this->assertEquals(0.0, (float) $bands[0]->mingrade);
        $this->assertEquals(5.0, (float) $bands[0]->maxgrade);
        $this->assertSame('<p>Well done</p>', $bands[1]->feedbacktext);
        $this->assertEquals(5.0, (float) $bands[1]->mingrade);
    }

    /**
     * Files embedded in the quiz description live in the quiz's own module context, and the
     * description text only names them with an @@PLUGINFILE@@ link. Copying the text alone
     * leaves the copy pointing at files that are not there.
     */
    public function test_duplicate_quizzes_copies_intro_files(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );
        $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $targetcat->id, 'name' => 'Shared Q1']
        );

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'intro' => '<p>Study this first.</p><p><img src="@@PLUGINFILE@@/diagram.png" alt=""></p>',
            'introformat' => FORMAT_HTML,
        ]);
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz);

        // The image itself, kept in the quiz's module context. The second copy sits in a
        // subdirectory, the way an image resizer stores its smaller version.
        $sourcecontextid = context_module::instance(
            (int) get_coursemodule_from_instance('quiz', $quiz->id)->id
        )->id;
        $this->create_intro_file($sourcecontextid, '/', 'diagram.png');
        $this->create_intro_file($sourcecontextid, '/imageopt/800/', 'diagram.png');

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $newquizid = $result['quizmap'][(int) $quiz->id];
        $newcontextid = context_module::instance(
            (int) get_coursemodule_from_instance('quiz', $newquizid)->id
        )->id;

        $copied = get_file_storage()->get_area_files(
            $newcontextid,
            'mod_quiz',
            'intro',
            0,
            'filepath, filename',
            false
        );

        $paths = [];
        foreach ($copied as $file) {
            $paths[] = $file->get_filepath() . $file->get_filename();
        }
        sort($paths);
        $this->assertSame(['/diagram.png', '/imageopt/800/diagram.png'], $paths);

        // The link in the description still names the file, so it resolves against the copy.
        $copyintro = $DB->get_field('quiz', 'intro', ['id' => $newquizid], MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/diagram.png', $copyintro);
    }

    /**
     * Put one file in a quiz's description file area.
     *
     * @param int $contextid Module context of the quiz.
     * @param string $filepath Directory inside the area, such as '/'.
     * @param string $filename
     */
    private function create_intro_file(int $contextid, string $filepath, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'mod_quiz',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => $filepath,
            'filename' => $filename,
        ], 'not really a png');
    }

    /**
     * A slot whose question has no counterpart in the imported category cannot be copied.
     * The copy then holds fewer questions than the original, so the caller is told which
     * slots were left out instead of the quiz simply counting as duplicated.
     */
    public function test_duplicate_quizzes_reports_slots_it_could_not_copy(): void {
        // ARRANGE.
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        // The imported category is left empty, so nothing in it can match this question.
        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz);

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $this->assertSame(1, $result['duplicated']);

        $this->assertCount(1, $result['skipped']);
        $skipped = $result['skipped'][0];
        $this->assertSame((int) $quiz->id, $skipped['quizid']);
        $this->assertSame(1, $skipped['slot']);
        $this->assertSame('nomatchingquestion', $skipped['reason']);

        // The copy really is missing the question the report names.
        $this->assertSame([], $this->quiz_slot_questions($result['quizmap'][(int) $quiz->id]));
    }

    /**
     * A slot drawing from a category that is not being imported is left out of the copy.
     * Keeping it would point the copy straight back at the category the import exists to
     * break away from. This holds for both kinds of slot: one specific question, or random.
     */
    public function test_duplicate_quizzes_skips_slots_from_unmapped_categories(): void {
        // ARRANGE.
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        // A second shared category, left out of the import.
        $othercat = $qgen->create_question_category([
            'contextid' => context_system::instance()->id,
            'name' => 'Shared: Not imported',
        ]);
        $otherquestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $othercat->id, 'name' => 'Other Q1']
        );

        // Slot 1 draws from the imported category, so the quiz is copied at all. Slot 2 holds
        // one question from the category nobody imported, and slot 3 draws from it at random.
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $sourcecmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $structure = quiz_settings::create($quiz->id)->get_structure();
        $structure->add_random_questions(
            0,
            1,
            $this->random_filter_condition($sourcecat, $sourcecmid, (int) $course->id)
        );
        quiz_add_quiz_question((int) $otherquestion->id, $quiz);
        quiz_settings::create($quiz->id)->get_structure()->add_random_questions(
            0,
            1,
            $this->random_filter_condition($othercat, $sourcecmid, (int) $course->id)
        );

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $newquizid = $result['quizmap'][(int) $quiz->id];

        // Both slots from the category that was not imported are reported.
        $this->assertCount(2, $result['skipped']);
        $this->assertSame([2, 3], array_column($result['skipped'], 'slot'));
        $this->assertSame(
            ['categorynotimported', 'categorynotimported'],
            array_column($result['skipped'], 'reason')
        );

        // And neither is in the copy: one random slot, drawing from the imported category.
        $copied = $this->random_slot_filterconditions($newquizid);
        $this->assertCount(1, $copied);
        $this->assertEquals([(int) $targetcat->id], $copied[0]['filter']['category']['values']);
        $this->assertSame([], $this->quiz_slot_questions($newquizid));
    }

    /**
     * Every random slot's stored filter, in slot order.
     *
     * @param int $quizid
     * @return array<int, array> Decoded filterconditions.
     */
    private function random_slot_filterconditions(int $quizid): array {
        global $DB;

        $sql = "SELECT s.slot, qsr.filtercondition
                  FROM {question_set_references} qsr
                  JOIN {quiz_slots} s ON s.id = qsr.itemid
                 WHERE s.quizid = :quizid
                   AND qsr.component = 'mod_quiz'
                   AND qsr.questionarea = 'slot'
              ORDER BY s.slot ASC";

        $filters = [];
        foreach ($DB->get_records_sql($sql, ['quizid' => $quizid]) as $row) {
            $filters[] = json_decode($row->filtercondition, true);
        }

        return $filters;
    }

    /**
     * The copy is created before its questions are copied into it, so a failure half way
     * through leaves a quiz in the course holding some of the questions or none. That copy
     * is removed again, rather than left on the course page looking like a real quiz.
     */
    public function test_duplicate_quizzes_removes_the_copy_when_slot_copying_fails(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );
        $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $targetcat->id, 'name' => 'Shared Q1']
        );

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz);

        // A slot copier that fails after the copy has already been created.
        $failing = new class (new question_resolver()) extends slot_copier {
            #[\Override]
            public function copy_all(int $oldquizid, int $newquizid, int $oldcmid, array $categorymap): array {
                throw new \coding_exception('slot copying failed');
            }
        };

        // ACT.
        $duplicator = new duplicator(null, $failing);
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame(0, $result['duplicated']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame((int) $quiz->id, $result['failed'][0]['quizid']);
        $this->assertStringContainsString('slot copying failed', $result['failed'][0]['message']);

        // Only the original quiz is left, both as a quiz and as an activity in the course.
        $this->assertCount(1, $DB->get_records('quiz', ['course' => $course->id]));
        $this->assertCount(1, get_all_instances_in_course('quiz', get_course($course->id)));
    }

    /**
     * Deleting the unfinished copy can fail too. The copy is then really left in the course,
     * so the caller is told which one it is instead of the fact going nowhere.
     */
    public function test_duplicate_quizzes_reports_a_copy_it_could_not_delete(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );
        $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $targetcat->id, 'name' => 'Shared Q1']
        );

        $quiz = $this->getDataGenerator()->create_module(
            'quiz',
            ['course' => $course->id, 'name' => 'Original quiz']
        );
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz);
        $sourcecmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;

        // A slot copier that fails, and takes the copy's quiz record with it. Deleting the
        // course module then fails as well, because its quiz is already gone.
        $failing = new class (new question_resolver()) extends slot_copier {
            #[\Override]
            public function copy_all(int $oldquizid, int $newquizid, int $oldcmid, array $categorymap): array {
                global $DB;

                $DB->delete_records('quiz', ['id' => $newquizid]);
                throw new \coding_exception('slot copying failed');
            }
        };

        // ACT.
        $duplicator = new duplicator(null, $failing);
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame(0, $result['duplicated']);
        $this->assertCount(1, $result['failed']);

        // The copy that is still in the course, named so it can be deleted by hand.
        $this->assertCount(1, $result['leftover']);
        $leftover = $result['leftover'][0];
        $this->assertSame('Original quiz', $leftover['name']);
        $this->assertNotSame($sourcecmid, $leftover['cmid']);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $leftover['cmid']]));
    }


    /**
     * Completion of the original is carried over only for users who hold a student role
     * in the course on an enrolment that is active.
     *
     * A suspended student and a teacher may both have completed the original, and neither
     * belongs on the copy.
     */
    public function test_duplicate_quizzes_copies_completion_for_active_students_only(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);
        $quiz = $this->create_mapped_quiz($course, $sourcecat, $targetcat, [
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $sourcecm = get_coursemodule_from_instance('quiz', $quiz->id, (int) $course->id, false, MUST_EXIST);

        // Three people in the course, told apart by role and by enrolment.
        $active = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($active->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($suspended->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // All three completed the original.
        $completion = new \completion_info($course);
        foreach ([$active, $suspended, $teacher] as $user) {
            $completion->update_state($sourcecm, COMPLETION_COMPLETE, (int) $user->id);
        }

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $newcmid = (int) get_coursemodule_from_instance('quiz', $result['quizmap'][(int) $quiz->id])->id;

        $marked = $DB->get_records_menu(
            'course_modules_completion',
            ['coursemoduleid' => $newcmid],
            '',
            'userid, completionstate'
        );

        $this->assertSame([(int) $active->id], array_map('intval', array_keys($marked)));
        $this->assertEquals(COMPLETION_COMPLETE, $marked[$active->id]);
    }

    /**
     * The copy has no attempts and no grades, so automatic completion would work out that
     * nobody has completed it. The mark is therefore written as an override.
     *
     * "Completed, passed" has to survive as it stands: a restriction asking for "complete
     * with pass grade" is satisfied by that state alone.
     */
    public function test_duplicate_quizzes_marks_completion_on_the_copy_as_an_override(): void {
        // ARRANGE.
        global $DB, $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);
        $quiz = $this->create_mapped_quiz($course, $sourcecat, $targetcat, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $sourcecmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // The student passed the original.
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $sourcecmid,
            'userid' => (int) $student->id,
            'completionstate' => COMPLETION_COMPLETE_PASS,
            'overrideby' => null,
            'timemodified' => time(),
        ]);

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $newcmid = (int) get_coursemodule_from_instance('quiz', $result['quizmap'][(int) $quiz->id])->id;

        $marked = $DB->get_record(
            'course_modules_completion',
            ['coursemoduleid' => $newcmid, 'userid' => (int) $student->id],
            '*',
            MUST_EXIST
        );

        $this->assertEquals(COMPLETION_COMPLETE_PASS, $marked->completionstate);
        $this->assertEquals($USER->id, $marked->overrideby);
    }


    /**
     * A student who passed the original must come out of the copy passed as well, with the
     * grade behind it, and without an override.
     */
    public function test_duplicate_quizzes_copies_the_grade_and_the_pass_that_follows_it(): void {
        // ARRANGE.
        global $DB, $CFG;

        // Defines grade_update().
        require_once($CFG->libdir . '/gradelib.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);

        // A quiz that is complete only once it is passed, at half marks.
        $quiz = $this->create_mapped_quiz($course, $sourcecat, $targetcat, [
            'grade' => 100,
            'gradepass' => 50,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
        ]);
        $sourcecm = get_coursemodule_from_instance('quiz', $quiz->id, (int) $course->id, false, MUST_EXIST);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // The student sat the original and passed it.
        $DB->insert_record('quiz_grades', (object) [
            'quiz' => (int) $quiz->id,
            'userid' => (int) $student->id,
            'grade' => 80.0,
            'timemodified' => time(),
        ]);
        grade_update('mod/quiz', (int) $course->id, 'mod', 'quiz', (int) $quiz->id, 0, [
            (int) $student->id => (object) ['userid' => (int) $student->id, 'rawgrade' => 80.0],
        ]);

        $completion = new \completion_info($course);
        $completion->update_state($sourcecm, COMPLETION_UNKNOWN, (int) $student->id);

        // The original is where the copy is expected to land.
        $this->assertEquals(
            COMPLETION_COMPLETE_PASS,
            $DB->get_field(
                'course_modules_completion',
                'completionstate',
                ['coursemoduleid' => (int) $sourcecm->id, 'userid' => (int) $student->id]
            )
        );

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, [(int) $sourcecat->id => (int) $targetcat->id]);

        // ASSERT.
        $this->assertSame([], $result['failed']);
        $newquizid = $result['quizmap'][(int) $quiz->id];
        $newcmid = (int) get_coursemodule_from_instance('quiz', $newquizid)->id;

        // The quiz's own record of the grade.
        $this->assertEquals(80.0, (float) $DB->get_field(
            'quiz_grades',
            'grade',
            ['quiz' => $newquizid, 'userid' => (int) $student->id]
        ));

        // And the gradebook's.
        $grades = grade_get_grades((int) $course->id, 'mod', 'quiz', $newquizid, (int) $student->id);
        $this->assertEquals(80.0, (float) $grades->items[0]->grades[(int) $student->id]->grade);

        // Passed, and worked out by Moodle rather than written over the top of it.
        $marked = $DB->get_record(
            'course_modules_completion',
            ['coursemoduleid' => $newcmid, 'userid' => (int) $student->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(COMPLETION_COMPLETE_PASS, $marked->completionstate);
        $this->assertNull($marked->overrideby);
    }

    /**
     * A passing grade is pinned in the gradebook, so that an attempt cannot wipe it.
     *
     */
    public function test_duplicate_quizzes_pins_a_carried_over_passing_grade(): void {
        // ARRANGE.
        global $DB;

        [$course, $quiz, $student] = $this->course_with_graded_quiz(80.0);

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, $this->categorymap);

        // ASSERT.
        $newquizid = $result['quizmap'][(int) $quiz->id];

        $this->assertTrue($this->gradebook_grade_is_overridden($course, $newquizid, $student));

        // And the plugin remembers that the override is its own.
        $this->assertTrue($DB->record_exists(override_register::TABLE, [
            'quizid' => $newquizid,
            'userid' => (int) $student->id,
        ]));
    }

    /**
     * A failing grade is left unpinned, so the student can still pass the copy.
     *
     */
    public function test_duplicate_quizzes_does_not_pin_a_carried_over_failing_grade(): void {
        // ARRANGE.
        global $DB;

        [$course, $quiz, $student] = $this->course_with_graded_quiz(20.0);

        // ACT.
        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, $this->categorymap);

        // ASSERT.
        $newquizid = $result['quizmap'][(int) $quiz->id];

        // The grade is still carried over.
        $carriedgrade = $DB->get_field('quiz_grades', 'grade', [
            'quiz' => $newquizid,
            'userid' => (int) $student->id,
        ]);
        $this->assertEquals(20.0, (float) $carriedgrade);

        // But nothing is holding it in place.
        $this->assertFalse($this->gradebook_grade_is_overridden($course, $newquizid, $student));
        $this->assertFalse($DB->record_exists(override_register::TABLE, [
            'quizid' => $newquizid,
            'userid' => (int) $student->id,
        ]));
    }

    /**
     * An attempt that beats the carried-over grade takes over: the pin comes off.
     *
     */
    public function test_a_better_attempt_takes_over_from_the_carried_over_grade(): void {
        // ARRANGE. The student carries 40 out of 100 from the original.
        global $DB;

        [$course, $quiz, $student] = $this->course_with_graded_quiz(40.0, 30.0);

        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, $this->categorymap);
        $newquizid = $result['quizmap'][(int) $quiz->id];
        $newcmid = (int) get_coursemodule_from_instance('quiz', $newquizid)->id;

        $this->assertTrue($this->gradebook_grade_is_overridden($course, $newquizid, $student));

        // ACT. The student sits the copy and gets the question right, for full marks.
        $this->submit_attempt($newquizid, $newcmid, $student, true);

        // ASSERT. Their own grade stands, and the plugin is out of the way.
        $this->assertEquals(100.0, $this->gradebook_grade($course, $newquizid, $student));
        $this->assertEquals(100.0, $this->quiz_grade($newquizid, $student));
        $this->assertFalse($this->gradebook_grade_is_overridden($course, $newquizid, $student));
        $this->assertFalse($DB->record_exists(override_register::TABLE, [
            'quizid' => $newquizid,
            'userid' => (int) $student->id,
        ]));
    }

    /**
     * An attempt that does worse than the carried-over grade does not take it away.
     *
     */
    public function test_a_worse_attempt_does_not_take_away_the_carried_over_grade(): void {
        // ARRANGE. The student carries a pass of 80 out of 100 from the original.
        global $DB;

        [$course, $quiz, $student] = $this->course_with_graded_quiz(80.0);

        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, $this->categorymap);
        $newquizid = $result['quizmap'][(int) $quiz->id];
        $newcmid = (int) get_coursemodule_from_instance('quiz', $newquizid)->id;

        // ACT. The student sits the copy and gets the question wrong, scoring nothing.
        $this->submit_attempt($newquizid, $newcmid, $student, false);

        // ASSERT. The carried-over grade still stands, in both places a quiz grade lives.
        $this->assertEquals(80.0, $this->gradebook_grade($course, $newquizid, $student));
        $this->assertEquals(80.0, $this->quiz_grade($newquizid, $student));

        // The pass it earned them stands too.
        $state = $DB->get_field('course_modules_completion', 'completionstate', [
            'coursemoduleid' => $newcmid,
            'userid' => (int) $student->id,
        ]);
        $this->assertEquals(COMPLETION_COMPLETE_PASS, $state);

        // And the pin is still on, ready for the next attempt.
        $this->assertTrue($this->gradebook_grade_is_overridden($course, $newquizid, $student));
        $this->assertTrue($DB->record_exists(override_register::TABLE, [
            'quizid' => $newquizid,
            'userid' => (int) $student->id,
        ]));
    }

    /**
     * A failed attempt and then a better one: the better one wins, from a pin still in place.
     *
     */
    public function test_the_carried_over_grade_survives_until_an_attempt_beats_it(): void {
        // ARRANGE. The student carries 40 out of 100, and the quiz keeps the highest grade.
        [$course, $quiz, $student] = $this->course_with_graded_quiz(40.0, 30.0);

        $duplicator = new duplicator();
        $result = $duplicator->duplicate_quizzes((int) $course->id, $this->categorymap);
        $newquizid = $result['quizmap'][(int) $quiz->id];
        $newcmid = (int) get_coursemodule_from_instance('quiz', $newquizid)->id;

        // ACT. A wrong answer first, then a right one.
        $this->submit_attempt($newquizid, $newcmid, $student, false);
        $this->assertEquals(40.0, $this->gradebook_grade($course, $newquizid, $student));

        $this->submit_attempt($newquizid, $newcmid, $student, true, 2);

        // ASSERT.
        $this->assertEquals(100.0, $this->gradebook_grade($course, $newquizid, $student));
        $this->assertFalse($this->gradebook_grade_is_overridden($course, $newquizid, $student));
    }

    /**
     * A quiz the plugin never touched must not cost anything when an attempt is submitted.
     *
     * The observer runs for every attempt submitted on the site, so it has to find nothing
     * and leave a teacher's own override alone.
     */
    public function test_submitting_an_attempt_leaves_an_unrelated_override_alone(): void {
        // ARRANGE.
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');

        [$course, $quiz, $student] = $this->course_with_graded_quiz(80.0);
        $cmid = (int) get_coursemodule_from_instance('quiz', (int) $quiz->id)->id;

        // A teacher overrides the grade on the ORIGINAL quiz, which the plugin never pins.
        $item = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $quiz->id,
            'itemnumber' => 0,
        ]);
        $item->update_final_grade((int) $student->id, 90.0, 'test');

        // ACT: the student sits the original.
        $this->submit_attempt((int) $quiz->id, $cmid, $student, false);

        // ASSERT: the teacher's override still stands.
        $this->assertTrue($this->gradebook_grade_is_overridden($course, (int) $quiz->id, $student));
        $grades = grade_get_grades((int) $course->id, 'mod', 'quiz', (int) $quiz->id, (int) $student->id);
        $this->assertEquals(90.0, (float) $grades->items[0]->grades[(int) $student->id]->grade);
    }

    /** @var array<int, int> The category map the helper course was built around. */
    private array $categorymap = [];

    /**
     * A course holding one quiz that a student has already been graded on.
     *
     * The quiz is complete only once it is passed, at half marks, so the pass mark is what
     * decides whether the carried-over grade gets pinned.
     *
     * @param float $grade The grade the student holds on the original, out of 100.
     * @param float $gradepass The mark the quiz is passed at, out of 100.
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Course, quiz, student.
     */
    private function course_with_graded_quiz(float $grade, float $gradepass = 50.0): array {
        global $CFG, $DB;

        // Defines grade_update().
        require_once($CFG->libdir . '/gradelib.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        [$sourcecat, $targetcat] = $this->create_source_and_target_categories($course);
        $this->categorymap = [(int) $sourcecat->id => (int) $targetcat->id];

        $quiz = $this->create_mapped_quiz($course, $sourcecat, $targetcat, [
            'grade' => 100,
            'sumgrades' => 1,
            'gradepass' => $gradepass,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $DB->insert_record('quiz_grades', (object) [
            'quiz' => (int) $quiz->id,
            'userid' => (int) $student->id,
            'grade' => $grade,
            'timemodified' => time(),
        ]);
        grade_update('mod/quiz', (int) $course->id, 'mod', 'quiz', (int) $quiz->id, 0, [
            (int) $student->id => (object) ['userid' => (int) $student->id, 'rawgrade' => $grade],
        ]);

        return [$course, $quiz, $student];
    }

    /**
     * Is the gradebook holding this user's quiz grade as an override?
     *
     * @param \stdClass $course Course the quiz belongs to.
     * @param int $quizid Quiz instance id.
     * @param \stdClass $user The student.
     * @return bool
     */
    private function gradebook_grade_is_overridden(\stdClass $course, int $quizid, \stdClass $user): bool {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $item = \grade_item::fetch([
            'courseid' => (int) $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'itemnumber' => 0,
        ]);

        if (!$item) {
            return false;
        }

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => (int) $user->id]);

        return $grade && $grade->is_overridden();
    }

    /**
     * The gradebook's grade for this user on this quiz.
     *
     * @param \stdClass $course Course the quiz belongs to.
     * @param int $quizid Quiz instance id.
     * @param \stdClass $user The student.
     * @return float|null
     */
    private function gradebook_grade(\stdClass $course, int $quizid, \stdClass $user): ?float {
        $grades = grade_get_grades((int) $course->id, 'mod', 'quiz', $quizid, (int) $user->id);
        $grade = $grades->items[0]->grades[(int) $user->id]->grade;

        return is_null($grade) ? null : (float) $grade;
    }

    /**
     * The quiz's own record of the grade for this user, which its reports read.
     *
     * @param int $quizid Quiz instance id.
     * @param \stdClass $user The student.
     * @return float|null
     */
    private function quiz_grade(int $quizid, \stdClass $user): ?float {
        global $DB;

        $grade = $DB->get_field('quiz_grades', 'grade', [
            'quiz' => $quizid,
            'userid' => (int) $user->id,
        ]);

        return $grade === false ? null : (float) $grade;
    }

    /**
     * Have the student sit the quiz once, answering its single question.
     *
     * @param int $quizid Quiz instance id.
     * @param int $cmid Course module id of that quiz.
     * @param \stdClass $user The student.
     * @param bool $correct Answer the question correctly.
     * @param int $attemptnumber Which attempt at the quiz this is.
     * @return void
     */
    private function submit_attempt(
        int $quizid,
        int $cmid,
        \stdClass $user,
        bool $correct,
        int $attemptnumber = 1
    ): void {
        $quizobj = quiz_settings::create($quizid, (int) $user->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $timenow = time();
        // No attempt is built on the last one: these quizzes do not set attemptonlast.
        $attempt = quiz_create_attempt($quizobj, $attemptnumber, false, $timenow, false, (int) $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($timenow, false, [
            1 => ['answer' => $correct ? 'frog' : 'wrong answer'],
        ]);

        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $attemptobj->process_submit($timenow, false);
        $attemptobj->process_grade_submission($timenow);
    }

    /**
     * Build a quiz asking one question that the given category map covers, so the
     * duplicator picks it up.
     *
     * @param \stdClass $course Course to build the quiz in.
     * @param \stdClass $sourcecat Category the quiz draws its question from.
     * @param \stdClass $targetcat The course's own copy of that category.
     * @param array $options Extra quiz settings.
     * @return \stdClass The quiz record the generator made.
     */
    private function create_mapped_quiz(
        \stdClass $course,
        \stdClass $sourcecat,
        \stdClass $targetcat,
        array $options = []
    ): \stdClass {
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');

        $quiz = $this->getDataGenerator()->create_module('quiz', $options + ['course' => $course->id]);

        // The same question in both banks: the shared original, and the course's copy.
        $sourcequestion = $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $sourcecat->id, 'name' => 'Shared Q1']
        );
        $qgen->create_question(
            'shortanswer',
            null,
            ['category' => $targetcat->id, 'name' => 'Shared Q1']
        );
        quiz_add_quiz_question((int) $sourcequestion->id, $quiz);

        return $quiz;
    }

    /**
     * Create a shared question category, and a question category in the course's own question bank.
     *
     * @param \stdClass $course
     * @return array The shared category, the course category and the context of the course's own question bank.
     */
    private function create_source_and_target_categories(\stdClass $course): array {
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');

        $sourcecat = $qgen->create_question_category([
            'contextid' => context_system::instance()->id,
            'name' => 'Shared: Contraindications',
        ]);

        $bank = question_bank_helper::get_default_open_instance_system_type($course, true);
        $bankcontext = context_module::instance($bank->id);
        $targetcat = $qgen->create_question_category([
            'contextid' => $bankcontext->id,
            'parent' => question_get_top_category($bankcontext->id, true)->id,
            'name' => 'Course: Contraindications',
        ]);

        return [$sourcecat, $targetcat, $bankcontext];
    }

    /**
     * The questions a quiz's slots point at, in slot order.
     *
     * @param int $quizid
     * @return array<int, array{questiontext: string, categoryid: int}>
     */
    private function quiz_slot_questions(int $quizid): array {
        global $DB;

        $sql = "SELECT s.slot, q.questiontext, qbe.questioncategoryid
                  FROM {quiz_slots} s
                  JOIN {question_references} qr ON qr.itemid = s.id
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE s.quizid = :quizid
              ORDER BY s.slot ASC";

        $slots = [];
        foreach ($DB->get_records_sql($sql, ['quizid' => $quizid]) as $row) {
            $slots[] = [
                'questiontext' => $row->questiontext,
                'categoryid' => (int) $row->questioncategoryid,
            ];
        }

        return $slots;
    }

    /**
     * A random slot's stored filter, shaped the way the question picker saves it.
     *
     * @param \stdClass $category Category the slot draws from.
     * @param int $cmid Quiz the slot was added from.
     * @param int $courseid
     * @return array
     */
    private function random_filter_condition(\stdClass $category, int $cmid, int $courseid): array {
        return [
            'filter' => [
                'category' => [
                    'name' => 'category',
                    'jointype' => 1,
                    'values' => [(int) $category->id],
                    'filteroptions' => ['includesubcategories' => true],
                ],
            ],
            'cmid' => $cmid,
            'courseid' => $courseid,
            'jointype' => 2,
            'qpage' => 0,
            'qperpage' => 100,
            'sortdata' => [],
            'cat' => $category->id . ',' . $category->contextid,
            'tabname' => 'questions',
        ];
    }

    /**
     * Fetch the stored filter of the one random slot in a quiz.
     *
     * @param int $quizid
     * @return string The filtercondition JSON.
     */
    private function random_slot_filtercondition(int $quizid): string {
        global $DB;

        $sql = "SELECT qsr.filtercondition
                  FROM {question_set_references} qsr
                  JOIN {quiz_slots} s ON s.id = qsr.itemid
                 WHERE s.quizid = :quizid
                   AND qsr.component = 'mod_quiz'
                   AND qsr.questionarea = 'slot'";

        return $DB->get_field_sql($sql, ['quizid' => $quizid], MUST_EXIST);
    }
}
