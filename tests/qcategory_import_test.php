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
 * Focus: functions in qcategory_exporter/classes/importer.php
 *
 * @package    block_qcategory_exporter
 * @copyright  2025 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace block_qcategory_exporter;
use block_qcategory_exporter\importer;
use block_qcategory_exporter\importer\category_namer;
use block_qcategory_exporter\importer\xml_flattener;
use DOMDocument, DOMXPath, DOMElement;
use advanced_testcase;
use context_system, context_course;
use qformat_xml;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * PHPUnit tests for the block_qcategory_exporter plugin.
 *
 * This class contains unit tests for the block_qcategory_exporter plugin,
 * focusing on the functions in import.php
 *
 * @package    block_qcategory_exporter
 * @copyright  2025 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversMethod(\block_qcategory_exporter\importer::class, 'run_import')]
#[CoversMethod(\block_qcategory_exporter\importer\category_namer::class, 'find_reusable_import')]
#[CoversMethod(\block_qcategory_exporter\importer\xml_flattener::class, 'remove_parent_paths')]
#[CoversMethod(\block_qcategory_exporter\importer\category_namer::class, 'update_category_name')]
#[CoversMethod(\block_qcategory_exporter\importer::class, 'build_exporter')]
#[CoversMethod(\block_qcategory_exporter\importer::class, 'parse_category_ids')]
#[CoversMethod(\block_qcategory_exporter\importer\xml_flattener::class, 'question_element_for_category_text')]
final class qcategory_import_test extends advanced_testcase {
    /**
     * Verifies block_qce_run_export orchestrates exporting a system category and
     * importing it into the target course top category using qformat_xml, and avoids duplicates.
     * Steps:
     * - Create a course; capture its top question category.
     * - Create a system-context category with one question (export source).
     * - Call run_import by including it with required request params set
     * - Assert a new child category appears under the course's top category with the expected name.
     * - Call import again with the same payload; assert no duplicate child category is created.
     */
    public function test_run_import(): void {
        // ARRANGE.
        global $DB, $CFG;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create target course.
        // This is the course we want to import questions into.
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Target Course']);
        $coursectx = context_course::instance($course->id);

        // Create question generator used for category and question creation.
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');

        // Create a course top level category to store imported questions.
        $topcat = $qgen->create_question_category([
            'contextid' => $coursectx->id,
            'parent' => 0,
            'name' => 'top',
        ]);

        // Create a system category with the question that has to be exported from system and imported to course.
        $sysctx = context_system::instance();
        $systemcat = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'Algorithms',
        ]);

        // Create questions in the system category.
        $question1 = $qgen->create_question('shortanswer', null, ['category' => $systemcat->id]);
        $question2 = $qgen->create_question('shortanswer', null, ['category' => $systemcat->id]);
        $question3 = $qgen->create_question('shortanswer', null, ['category' => $systemcat->id]);

        $createdquestions = [];
        $createdquestions[$question1->id] = $question1;
        $createdquestions[$question2->id] = $question2;
        $createdquestions[$question3->id] = $question3;

        // Prepare request parameters as they would look in a real request.
        $_GET['courseid'] = $course->id;
        $_GET['categories'] = (string) (int) $systemcat->id;

        // Expected child category name is prefixed with the course fullname and colon.
        $expectedname = 'Target Course: Algorithms';
        $importer = new importer();

        // ACT.
        // When calling import method it print to terminal. This is disabled by the following.
        ob_start(); // Start buffering output.
        try {
            // Import question into course.
            $importer->run_import($course->id, $_GET['categories']);

            // Call again to check that it does not create duplicates.
            $importer->run_import($course->id, $_GET['categories']);
        } finally {
            ob_end_clean(); // Clean buffer.
        }

        // ASSERT.
        // Fetch child categories of the top category.
        $coursecategorychildren = $DB->get_records('question_categories', ['parent' => $topcat->id, 'name' => $expectedname]);
        $this->assertCount(1, $coursecategorychildren);
        $coursecategorychild = array_pop($coursecategorychildren);
        $importedname = $coursecategorychild->name;
        $this->assertEquals($expectedname, $importedname);
        $questioncount = $DB->count_records('question_bank_entries', ['questioncategoryid' => $coursecategorychild->id]);
        $this->assertEquals(3, $questioncount);  // We created 3 in the system category.

        // Check if the imported questions actually match the ones we expect.
        $entries = $DB->get_records('question_bank_entries', ['questioncategoryid' => $coursecategorychild->id], '', 'id');
        $entryids = array_map('intval', array_keys($entries));
        $versions = $entryids ? $DB->get_records_list('question_versions', 'questionbankentryid', $entryids, '', 'questionid') : [];
        $questionids = array_map(static fn($v) => (int)$v->questionid, $versions);
        $questions = $questionids ? $DB->get_records_list('question', 'id', $questionids, 'id ASC', 'id, name, qtype, questiontext, generalfeedback') : [];
        // Check questions are actually the ones we created.
        $this->assertCount(3, $questions);
        foreach ($questions as $q) {
            // A quick fix for fetching the created questions as they are 3 ids' behind the newly imported ones.
            $createdquestion = $createdquestions[$q->id - 3];
            $this->assertEquals('shortanswer', $q->qtype);
            $this->assertEquals($createdquestion->name, $q->name);
            $this->assertEquals($createdquestion->questiontext, $q->questiontext);
            $this->assertEquals($createdquestion->generalfeedback, $q->generalfeedback);
        }
    }

    /**
     * Two source categories whose names flatten to the same name must not be merged.
     *
     * "Track A: Contraindications" and "Track B: contraindications" both reduce to
     * "Target Course: Contraindications". Merging them leaves one source's questions out
     * of the course, which then breaks quizzes drawing random questions from both.
     * Steps:
     * - Create two system categories that flatten to the same name, with distinct questions.
     * - Import both; assert they map to two different course categories holding their own questions.
     * - Import again; assert the map is unchanged and no extra categories appear.
     */
    public function test_run_import_keeps_colliding_category_names_apart(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Target Course']);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $sysctx = context_system::instance();

        $cata = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'Track A: Contraindications',
        ]);
        $catb = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'Track B: contraindications',
        ]);
        $qgen->create_question('shortanswer', null, ['category' => $cata->id, 'name' => 'Question A1']);
        $qgen->create_question('shortanswer', null, ['category' => $cata->id, 'name' => 'Question A2']);
        $qgen->create_question('shortanswer', null, ['category' => $catb->id, 'name' => 'Question B1']);
        $qgen->create_question('shortanswer', null, ['category' => $catb->id, 'name' => 'Question B2']);

        $payload = $cata->id . ',' . $catb->id;
        $importer = new importer();
        $namer = new category_namer();

        // ACT.
        ob_start(); // The import format prints progress output.
        try {
            $firstmap = $importer->run_import($course->id, $payload);
            $secondmap = $importer->run_import($course->id, $payload);
        } finally {
            ob_end_clean();
        }

        // ASSERT.
        $this->assertCount(2, $firstmap);
        $this->assertNotEquals($firstmap[(int) $cata->id], $firstmap[(int) $catb->id]);
        $this->assertSame(
            ['Question A1', 'Question A2'],
            $namer->get_latest_question_names($firstmap[(int) $cata->id])
        );
        $this->assertSame(
            ['Question B1', 'Question B2'],
            $namer->get_latest_question_names($firstmap[(int) $catb->id])
        );

        // The second category keeps its own name rather than colliding with the first.
        $names = $DB->get_records_list('question_categories', 'id', array_values($firstmap), '', 'id, name');
        $this->assertNotEquals(
            $names[$firstmap[(int) $cata->id]]->name,
            $names[$firstmap[(int) $catb->id]]->name
        );

        // Re-importing reuses both categories instead of creating more.
        $this->assertSame($firstmap, $secondmap);
    }

    /**
     * Categories imported in separate runs must resolve the same way as ones imported
     * together: a category is reused only by the source whose questions it still holds,
     * never by an unrelated source that merely flattens to the same name.
     */
    public function test_run_import_reuses_a_category_only_for_its_own_source(): void {
        // ARRANGE.
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Target Course']);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $sysctx = context_system::instance();

        $cata = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'Track A: Contraindications',
        ]);
        $catb = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'Track B: contraindications',
        ]);
        $qgen->create_question('shortanswer', null, ['category' => $cata->id, 'name' => 'Question A1']);
        $qgen->create_question('shortanswer', null, ['category' => $catb->id, 'name' => 'Question B1']);

        $payloada = (string) (int) $cata->id;
        $payloadb = (string) (int) $catb->id;
        $importer = new importer();
        $namer = new category_namer();

        // ACT.
        // Each source is imported in its own run, then both runs are repeated.
        ob_start(); // The import format prints progress output.
        try {
            $firsta = $importer->run_import($course->id, $payloada);
            $firstb = $importer->run_import($course->id, $payloadb);
            $seconda = $importer->run_import($course->id, $payloada);
            $secondb = $importer->run_import($course->id, $payloadb);
        } finally {
            ob_end_clean();
        }

        // ASSERT.
        // The second run must not have landed in the category the first run created.
        $importeda = $firsta[(int) $cata->id];
        $importedb = $firstb[(int) $catb->id];
        $this->assertNotEquals($importeda, $importedb);
        $this->assertSame(['Question A1'], $namer->get_latest_question_names($importeda));
        $this->assertSame(['Question B1'], $namer->get_latest_question_names($importedb));

        // Repeating either run reuses that source's own category rather than adding more.
        $this->assertSame($firsta, $seconda);
        $this->assertSame($firstb, $secondb);
    }

    /**
     * Ensures removeparentpaths strips all but the deepest category question node
     * and rewrites it to the course top path with the provided category name.
     */
    public function test_remove_parent_paths(): void {
        // ARRANGE.
        $flattener = new xml_flattener();
        $xml = file_get_contents(__DIR__ . "/category_hierarchy_test.xml");
        $expected = '$course$/top/NEW CATEGORY NAME';

        // ACT.
        $actual = $flattener->remove_parent_paths($xml, 'NEW CATEGORY NAME');

        // ASSERT.
        $this->assertIsString($actual);
        $this->assertStringContainsString($expected, $actual);

        // The parent categories are gone, name and all.
        $this->assertStringNotContainsString('Top Level', $actual);

        // Only the deepest category question should remain for the target chain.
        $this->assertEquals(1, substr_count($actual, '<question type="category">'));
    }

    /**
     * Validates updatecategoryname strips any old course prefix and prefixes with current course,
     * and replaces single slashes with double slashes as expected by qformat_xml paths.
     */
    public function test_update_category_name(): void {
        // ARRANGE.
        $namer = new category_namer();

        // ACT + ASSERT.
        $this->assertEquals(
            'My Course: Algorithms',
            $namer->update_category_name('Old Course : Algorithms', 'My Course:')
        );
        $this->assertEquals(
            'Course X: A/B/C',
            $namer->update_category_name('A/B/C', 'Course X:')
        );

        // Only the first word is capitalised, so the rest of the name reads as written.
        $this->assertEquals(
            'My Course: Category name and more',
            $namer->update_category_name('Old Course: category name and more', 'My Course:')
        );

        // A name ending in a colon still has something after the prefix.
        $this->assertEquals(
            'My Course: Category Name',
            $namer->update_category_name('Category Name:', 'My Course:')
        );
    }

    /**
     * Confirms build_exporter returns a qformat_xml configured for the given context and category
     * by exporting that category and checking the category name is present in the XML.
     */
    public function test_build_exporter(): void {
        // ARRANGE.
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $importer = new importer();

        // Create a system category with one question.
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Target Course']);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $sysctx = context_system::instance();
        $cat = $qgen->create_question_category([
            'contextid' => $sysctx->id,
            'name' => 'Data Structures',
        ]);
        $qgen->create_question('truefalse', null, ['category' => $cat->id]);

        // ACT.
        // Build exporter and export.
        $exporter = $importer->build_exporter($cat);
        $xml = $exporter->exportprocess();

        // ASSERT.
        $this->assertInstanceOf(qformat_xml::class, $exporter);
        $this->assertIsString($xml);
        $this->assertStringContainsString('Data Structures', $xml);
    }

    /**
     * Ensures parse_category_ids reads a comma separated list, dropping repeats and zeroes.
     * The caller takes the field as PARAM_SEQUENCE, so only digits and commas reach here.
     */
    public function test_parse_category_ids(): void {
        // ARRANGE.
        $importer = new importer();

        // ACT.
        $ids = $importer->parse_category_ids('10,20,10,0');

        // ASSERT.
        $this->assertSame([10, 20], $ids);
        $this->assertSame([], $importer->parse_category_ids(''));
    }

    /**
     * Asserts question_element_for_category_text walks up from a <category><text> node
     * to the enclosing <question> element correctly.
     */
    public function test_question_element_for_category_text(): void {
        // ARRANGE.
        $xml = file_get_contents(__DIR__ . "/category_fetching_test.xml");
        $dom = new DOMDocument('1.0', 'UTF-8');
        $this->assertTrue($dom->loadXML($xml));
        $xpath = new DOMXPath($dom);
        $textnode = $xpath->query('//question[@type="category"]/category/text')->item(0);
        $this->assertNotNull($textnode);

        // ACT.
        $flattener = new xml_flattener();
        $el = $flattener->question_element_for_category_text($textnode);
        $this->assertInstanceOf(DOMElement::class, $el);

        // ASSERT.
        $this->assertSame('question', $el->tagName);
        $this->assertSame('category', $el->getAttribute('type'));
    }
}
