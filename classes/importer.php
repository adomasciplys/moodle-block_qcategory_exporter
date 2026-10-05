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

use block_qcategory_exporter\importer\category_namer;
use block_qcategory_exporter\importer\xml_flattener;
use qformat_default; // Included for PHPstan.
use qformat_xml;
use context, context_course, context_module;
use moodle_exception;
use stdClass;
use core_question\local\bank\question_bank_helper;

/**
 * Copies question categories into a course's own question bank.
 *
 * Each category is copied by exporting it to Moodle's question XML and importing that XML
 * back into the course.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var category_namer Names the copies and spots ones already imported. */
    private category_namer $namer;

    /** @var xml_flattener Strips parent categories out of the exported XML. */
    private xml_flattener $flattener;

    /**
     * Wire up the helper classes.
     * Pass overrides for testing.
     *
     * @param category_namer|null $namer Names the copies and spots ones already imported.
     * @param xml_flattener|null $flattener Strips parent categories out of the exported XML.
     */
    public function __construct(?category_namer $namer = null, ?xml_flattener $flattener = null) {
        $this->namer = $namer ?? new category_namer();
        $this->flattener = $flattener ?? new xml_flattener();
    }

    /**
     * Copy the given categories into the course's question bank.
     *
     * @param int $courseid The target course ID.
     * @param string $rawcategories Comma separated category ids (from GET/POST).
     * @return array<int, int> Map of source category id => imported category id.
     */
    public function run_import(int $courseid, string $rawcategories): array {
        global $CFG, $DB;

        // Questionlib.php defines question_get_top_category().
        // format.php defines qformat_xml.
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $course = get_course($courseid);
        $this->require_import_capabilities($courseid);

        // Where every copy goes.
        [$modulecontext, $topcategory] = $this->get_destination($course);

        // Every copy is named after the course, so "Course Name:".
        $coursename = $course->fullname . ':';

        $categorymap = [];

        foreach ($this->parse_category_ids($rawcategories) as $catid) {
            // The category may have been deleted since the block drew the page.
            $sourcecategory = $DB->get_record('question_categories', ['id' => $catid], '*', IGNORE_MISSING);
            if (!$sourcecategory) {
                continue;
            }

            // Copy it in, and note where it landed so the duplicator can find it.
            $importedid = $this->copy_category_into_course($sourcecategory, $coursename, $modulecontext, $topcategory);
            if ($importedid) {
                $categorymap[(int) $sourcecategory->id] = $importedid;
            }
        }

        return $categorymap;
    }

    /**
     * Check the user may create categories in this course and use the questions going in.
     *
     * @param int $courseid The target course ID.
     * @return void
     */
    private function require_import_capabilities(int $courseid): void {
        $coursecontext = context_course::instance($courseid);

        require_capability('moodle/question:managecategory', $coursecontext);
        require_capability('moodle/question:useall', $coursecontext);
    }

    /**
     * The course's own question bank, which is where every copy is created.
     *
     * @param stdClass $course The target course.
     * @return array{0: context_module, 1: stdClass} The bank's context and its top category.
     * @throws moodle_exception When the bank can neither be found nor created.
     */
    private function get_destination(stdClass $course): array {
        // Passing true creates the bank when the course has none yet.
        $topmodule = question_bank_helper::get_default_open_instance_system_type($course, true);
        if (!$topmodule) {
            throw new moodle_exception('Could not get/create the system question bank instance.');
        }

        $modulecontext = context_module::instance($topmodule->id);

        return [$modulecontext, question_get_top_category($modulecontext->id, true)];
    }

    /**
     * Copy one source category into the course, or reuse the copy already there.
     *
     * Decides whether the copy is needed and what it should be called.
     *
     * @param stdClass $sourcecategory Source category record.
     * @param string $coursename Destination course name, already suffixed with a colon.
     * @param context_module $modulecontext Context of the course's question bank.
     * @param stdClass $topcategory Top category of that bank.
     * @return int|null The category id in the course, or null when the import produced nothing.
     */
    private function copy_category_into_course(
        stdClass $sourcecategory,
        string $coursename,
        context_module $modulecontext,
        stdClass $topcategory
    ): ?int {
        // Where the copy goes: the course's question bank, under its top category.
        $contextid = (int) $modulecontext->id;
        $parentid = (int) $topcategory->id;

        // The names this category could take in the course, best first.
        $candidates = $this->namer->candidate_category_names($sourcecategory, $coursename);

        // Already imported by an earlier run, so nothing to do.
        $existing = $this->namer->find_reusable_import($contextid, $parentid, (int) $sourcecategory->id, $candidates);
        if ($existing) {
            return (int) $existing->id;
        }

        // The first of those names no other category has taken.
        $intendedname = $this->namer->resolve_free_category_name($contextid, $parentid, $candidates);

        // Out of the shared bank as XML, then back in under the new name.
        $xml = $this->export_category_xml($sourcecategory, $intendedname);

        return $this->import_category_xml($xml, $modulecontext, $topcategory);
    }

    /**
     * Export a category to question XML, flattened and renamed ready for import.
     *
     * @param stdClass $sourcecategory Source category record.
     * @param string $intendedname Name the category should be imported under.
     * @return string The XML, or an empty string when the category exports to nothing.
     */
    private function export_category_xml(stdClass $sourcecategory, string $intendedname): string {
        $xml = $this->build_exporter($sourcecategory)->exportprocess();

        if (empty($xml)) {
            return '';
        }

        return $this->flattener->remove_parent_paths($xml, $intendedname);
    }

    /**
     * Import category XML into the course's question bank as a new category.
     *
     * The format API reads from a file rather than a string, so the XML is written to a
     * temporary file and removed again afterward. The XML already carries the name the
     * new category should take, put there by the flattener.
     *
     * @param string $xml The XML to import.
     * @param context_module $modulecontext Context of the course's question bank.
     * @param stdClass $topcategory Top category of that bank.
     * @return int|null Id of the category created, or null when the XML created none.
     */
    private function import_category_xml(
        string $xml,
        context_module $modulecontext,
        stdClass $topcategory
    ): ?int {
        global $CFG;

        // The format API reads from a file, so the XML has to land on disk first.
        $tmpfilepath = tempnam($CFG->tempdir, 'qce_');
        file_put_contents($tmpfilepath, $xml);

        $importformatter = $this->build_importer($modulecontext, $topcategory, $tmpfilepath);

        try {
            if (!$importformatter->importprocess()) {
                throw new moodle_exception('cannotimport', 'question');
            }
        } finally {
            // Delete, we no longer need the file.
            @unlink($tmpfilepath);
        }

        // Fetch the newly created category.
        $created = $importformatter->category;
        if (!$created || (int) $created->id === (int) $topcategory->id) {
            return null;
        }

        return (int) $created->id;
    }

    /**
     * Build and configure the XML importer for a destination context and question category
     *
     * @param context $ctx Context containing the category.
     * @param stdClass $category The category record to import into.
     * @param string $filepath File the XML was written to.
     * @return qformat_xml
     */
    public function build_importer($ctx, $category, $filepath): qformat_xml {
        $formatter = new qformat_xml();
        $formatter->setContexts([$ctx->id => $ctx]); // The course context we want to import to.
        $formatter->setCategory($category); // The category we want to import to.
        $formatter->setCatfromfile(true);
        $formatter->setFilename($filepath);
        $formatter->setMatchgrades('error');
        $formatter->setStoponerror(true);
        return $formatter;
    }

    /**
     * Build and configure the XML exporter for a question category.
     *
     * @param stdClass $category The category record to export
     * @return qformat_xml
     */
    public function build_exporter($category): qformat_xml {
        $categorycontext = context::instance_by_id($category->contextid);

        $formatter = new qformat_xml();
        $formatter->setContexts([$categorycontext->id => $categorycontext]);
        $formatter->setCategory($category); // The category in that context we want to export.
        $formatter->setCattofile(true);
        $formatter->setContexttofile(false);
        return $formatter;
    }

    /**
     * Read the category ids out of the posted list.
     *
     * The caller takes the field as PARAM_SEQUENCE, so only digits and commas reach here.
     *
     *     "12,34,12,0"  ->  [12, 34]
     *
     * @param string $rawcategories Comma separated category ids.
     * @return int[] The ids, each one once, in the order they were given.
     */
    public function parse_category_ids(string $rawcategories): array {
        $ids = array_map('intval', explode(',', $rawcategories));

        // Drop zeroes, then repeats, then renumber the keys array_filter left behind.
        return array_values(array_unique(array_filter($ids)));
    }
}
