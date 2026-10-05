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
 * Focus: functions in block_qcategory_exporter.php
 *
 * @package    block_qcategory_exporter
 * @copyright  2025 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace block_qcategory_exporter;
use block_qcategory_exporter\exporter;
use advanced_testcase;
use context_system, context_course, context_module;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * PHPUnit tests for the block_qcategory_exporter plugin.
 *
 * This class contains unit tests for the block_qcategory_exporter plugin,
 * focusing on the functions in block_qcategory_exporter.php
 *
 * @package    block_qcategory_exporter
 * @copyright  2025 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversMethod(\block_qcategory_exporter\exporter::class, 'collect_categories_for_course')]
#[CoversMethod(\block_qcategory_exporter\exporter::class, 'get_category_from_normal_slot')]
#[CoversMethod(\block_qcategory_exporter\exporter::class, 'get_category_from_random_slot')]
#[CoversMethod(\block_qcategory_exporter\exporter::class, 'should_include_category')]
#[CoversMethod(\block_qcategory_exporter\exporter::class, 'add_category')]
final class qcategory_export_test extends advanced_testcase {
    /**
     * Tests the collect_categories_for_course method. Tests three scenarios
     * 1) A slot pointing to a system category with questions - Should be included
     * 2) A slot pointing to a system category without questions - No inclusion
     * 3) A slot pointing to a course category - No inclusion (The category is already on the course level)
     * This test also checks if we have two questions for the same system category, the category is only inlcuded once
     *
     */
    public function test_collect_categories_for_course(): void {
        // Arrange.
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create course and quiz.
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
        ]);

        // Create categories.
        $sysctx = context_system::instance();
        $coursectx = context_course::instance($course->id);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $syscat = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'System Category A',
        ]);
        $syscatnoquestion = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'System Category No Entries',
        ]);
        $coursecat = $qgen->create_question_category([
            'contextid' => $coursectx->id,
            'name' => 'Course Category B',
        ]);

        // Create questions: one in system category (to ensure entries), one in course category.
        $sysq = $qgen->create_question('shortanswer', null, ['category' => $syscat->id]);
        $courseq = $qgen->create_question('shortanswer', null, ['category' => $coursecat->id]);

        // Add normal slots: one from system cat (included), one from course cat (excluded by context).
        quiz_add_quiz_question($sysq->id, $quiz);
        quiz_add_quiz_question($courseq->id, $quiz);

        // Add random slots.
        // One referencing system cat with entries (included).
        // One referencing system cat without entries (excluded).
        $quizsettings = \mod_quiz\quiz_settings::create($quiz->id);
        $structure = \mod_quiz\structure::create_for_quiz($quizsettings);
        $mkfilter = function (int $catid): array {
            return [
                'filter' => [
                    'category' => [
                        'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                        'values' => [$catid],
                        'filteroptions' => ['includesubcategories' => false],
                    ],
                ],
            ];
        };
        $structure->add_random_questions(0, 1, $mkfilter((int) $syscat->id));
        $structure->add_random_questions(0, 1, $mkfilter((int) $syscatnoquestion->id));

        // Act.
        $exporter = new exporter();
        $result = $exporter->collect_categories_for_course($course->id);

        // Assert.
        $this->assertIsArray($result);
        $this->assertArrayHasKey($syscat->id, $result);
        $this->assertSame(['id' => (int) $syscat->id, 'name' => (string) $syscat->name], $result[$syscat->id]);
        $this->assertArrayNotHasKey($coursecat->id, $result);
        $this->assertArrayNotHasKey($syscatnoquestion->id, $result);
    }
    /**
     * Tests the retrieval of a normal question having the question_reference in a Moodle quiz.
     * We have the following path to the questions: quiz -> quizctx -> question_references (slot) -> category
     */
    public function test_get_category_from_normal_slot(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create a course and quiz.
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $quizctx = context_module::instance($cm->id);

        // Create a question category in the system context and a question within it.
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $sysctx = context_system::instance();
        $category = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'System Category',
        ]);
        $question = $qgen->create_question('shortanswer', null, ['category' => $category->id]);

        // Add the question to the quiz as a normal (non-random) slot.
        quiz_add_quiz_question($question->id, $quiz);

        // Fetch the created slot and its normal reference.
        $slot = $DB->get_record('quiz_slots', ['quizid' => $quiz->id], '*', MUST_EXIST);
        $normalref = $DB->get_record('question_references', [
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'usingcontextid' => $quizctx->id,
            'itemid' => $slot->id,
        ], '*', MUST_EXIST);

        // ACT.
        $exporter = new exporter();
        $retrievedcategory = $exporter->get_category_from_normal_slot($normalref);

        // ASSERT.
        $this->assertNotNull($retrievedcategory);
        $this->assertEquals((int) $category->id, (int) $retrievedcategory->id);
        $this->assertEquals($category->name, $retrievedcategory->name);

        // Missing questionbankentryid -> null.
        $this->assertNull($exporter->get_category_from_normal_slot((object) []));
    }

    /**
     * Tests the retrieval of a random question having the question_set_reference in a Moodle quiz.
     * We have the following path to the questions: quiz -> quizctx -> question_set_references (slot) -> category
     */
    public function test_get_category_from_random_slot(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create a course and quiz.
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $quizctx = context_module::instance($cm->id);

        // Create a question category in the system context.
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $sysctx = context_system::instance();
        $category = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'System Category',
        ]);

        // Let Moodle create the random slot and its filtercondition.
        // Use mod_quiz structure to add a random question with a category filter.
        $quizsettings = \mod_quiz\quiz_settings::create($quiz->id);
        $structure = \mod_quiz\structure::create_for_quiz($quizsettings);
        $filtercondition = [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [(int) $category->id],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ];
        $structure->add_random_questions(0, 1, $filtercondition);

        // Retrieve the question_set_references record created for this quiz.
        $randomref = $DB->get_record('question_set_references', [
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'usingcontextid' => $quizctx->id,
        ], '*', MUST_EXIST);

        // ACT.
        $exporter = new exporter();
        $retrievedcategory = $exporter->get_category_from_random_slot($randomref);

        // ASSERT.
        // We resolved back to the same category via Moodle-generated filtercondition.
        $this->assertNotNull($retrievedcategory);
        $this->assertEquals((int) $category->id, (int) $retrievedcategory->id);
        $this->assertEquals($category->name, $retrievedcategory->name);
    }

    /**
     * Tests the should_include_category method for all code paths.
     * 1) Method is called without a category -> No inclusion
     * 2) Method is called with a cateogry, but he category has no entries -> No inclusion
     * 3) Method is called with a category, but is from the same context as the course -> No inclusion
     * 4) Method is called with a category, and is different from the course context -> Should be included
     *
     */
    public function test_should_include_category(): void {
        // ARRANGE.
        // Stub $DB->record_exists to control "has entries".
        $DB = new class {
            /**
             * Indicates whether the record exists.
             *
             * @var bool
             */
            public $exists = true;
            /**
             * Checks if a record exists in the database.
             *
             * @return bool Always returns the value of the $exists property.
             */
            public function record_exists($a, $b) {
                return (bool) $this->exists;
            }
        };
        // Add DB to global variables.
        // phpcs:ignore 
        $GLOBALS['DB'] = $DB;
        $coursectxid = 99;

        // ACT.
        $exporter = new exporter();
        // Category is null -> false.
        $nullcategory = $exporter->should_include_category(null, $coursectxid);
        // phpcs:ignore
        $GLOBALS['DB']->exists = false;
        $notentriescategory = $exporter->should_include_category((object) ['id' => 1, 'contextid' => 50], $coursectxid);
        // Has entries but same context as course -> false.
        // phpcs:ignore 
        $GLOBALS['DB']->exists = true;
        $hasentriessamecategory = $exporter->should_include_category(
            (object) ['id' => 2, 'contextid' => $coursectxid],
            $coursectxid
        );
        // Has entries and different context -> true.
        $hasentriesdifferentcategory = $exporter->should_include_category((object) ['id' => 3, 'contextid' => 12345], $coursectxid);

        // ASSERT.
        $this->assertFalse($nullcategory);
        $this->assertFalse($notentriescategory);
        $this->assertFalse($hasentriessamecategory);
        $this->assertTrue($hasentriesdifferentcategory);
    }

    /**
     * Tests the add_category method to ensure categories are added correctly to the map.
     *
     */
    public function test_add_category(): void {
        $this->resetAfterTest(true);
        $exporter = new exporter();

        $map = [];
        $exporter->add_category($map, (object) ['id' => 10, 'name' => 'Alpha']);
        $exporter->add_category($map, (object) ['id' => 20, 'name' => 'Beta']);

        $this->assertSame([
            10 => ['id' => 10, 'name' => 'Alpha'],
            20 => ['id' => 20, 'name' => 'Beta'],
        ], $map);

        // Overwrite existing id should replace value.
        $exporter->add_category($map, (object) ['id' => 20, 'name' => 'Beta2']);
        $this->assertSame('Beta2', $map[20]['name']);
    }
}
