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

namespace block_qcategory_exporter\importer;

use stdClass;
use moodle_exception;
use core_text;

/**
 * Decides what a source category should be called once it is in the course.
 *
 * Works out the name to import under
 * Recognizes a source category that is already in the course so a second run does not copy it again.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_namer {
    /**
     * How many numbered fallbacks candidate_category_names() offers before giving up.
     */
    private const MAX_NAME_SUFFIX = 10;

    /**
     * The names this source category may be imported under, best name first.
     *
     * Importing keeps only the part of the source category name after the last colon, so
     * two source categories can end up wanting the exact same name in the same course:
     *
     *     Old Course A: Category Name  ->  Course Name: Category Name
     *     Old Course B: category name  ->  Course Name: Category Name
     *
     * The first category imported gets that name. The next one that wants it is numbered
     * instead: "Course Name: Category Name (2)", then "(3)" and so on.
     *
     * @param stdClass $sourcecategory Source category record.
     * @param string $coursename Destination course name, already suffixed with a colon.
     * @return string[] Candidate names, best first.
     */
    public function candidate_category_names(stdClass $sourcecategory, string $coursename): array {
        // The name we want: the course name, then the part after the last colon.
        $basename = $this->update_category_name((string) $sourcecategory->name, $coursename);

        $candidates = [$basename];

        // If that name is taken in the course, number it.
        for ($suffix = 2; $suffix <= self::MAX_NAME_SUFFIX; $suffix++) {
            $candidates[] = $basename . ' (' . $suffix . ')';
        }

        return $candidates;
    }

    /**
     * Fix the category name by removing any old course name prefixes and appending the current course name.
     *
     * @param string $systemcategoryname The original category path or name.
     * @param string $coursename The name of the current course to prepend.
     * @return string The fixed category name with the current course name.
     */
    public function update_category_name(string $systemcategoryname, string $coursename): string {
        // Categories are often named "Old Course Name: Category Name".
        // Only the part after the last colon is wanted.
        // Blank parts are dropped.
        $parts = array_filter(array_map('trim', explode(':', $systemcategoryname)), static fn($part) => $part !== '');
        $basename = $parts ? (string) end($parts) : trim($systemcategoryname);

        return $coursename . ' ' . $this->capitalise_first($basename);
    }

    /**
     * Upper-case the first letter, leaving the rest of the name as it was written.
     *
     * @param string $name The name to capitalise.
     * @return string The same name with its first letter upper-cased.
     */
    private function capitalise_first(string $name): string {
        if ($name === '') {
            return $name;
        }

        return core_text::strtoupper(core_text::substr($name, 0, 1)) . core_text::substr($name, 1);
    }

    /**
     * Find the category this source category was already imported into, so running the
     * import again does not create a second copy.
     *
     * Checks each candidate name in turn.
     * A category with that name is reused only if it holds the same questions as the source.
     * Different questions mean it belongs to another source category.
     * Stops at the first unused name, since nothing after it can exist.
     *
     * @param int $contextid Destination question bank module context id.
     * @param int $parentid Destination top category id.
     * @param int $sourcecategoryid Source category id.
     * @param string[] $candidates Candidate names from candidate_category_names().
     * @return stdClass|null Category record to reuse, or null if this source is not imported yet.
     */
    public function find_reusable_import(
        int $contextid,
        int $parentid,
        int $sourcecategoryid,
        array $candidates
    ): ?stdClass {
        // The source does not change as we try the names, so read its questions once.
        $sourcequestions = $this->get_latest_question_names($sourcecategoryid);

        foreach ($candidates as $candidate) {
            $existing = $this->get_first_named_category($contextid, $parentid, $candidate);

            // Nothing uses this name, so nothing after it can exist either.
            if (!$existing) {
                return null;
            }

            // Same questions means this is the copy of our source, not another one.
            if ($this->get_latest_question_names((int) $existing->id) === $sourcequestions) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * Pick the first candidate name no sibling category is using.
     *
     * @param int $contextid Destination question bank module context id.
     * @param int $parentid Destination top category id.
     * @param string[] $candidates Candidate names from candidate_category_names().
     * @return string A name no sibling category currently uses.
     */
    public function resolve_free_category_name(int $contextid, int $parentid, array $candidates): string {
        foreach ($candidates as $candidate) {
            if (!$this->category_name_taken($contextid, $parentid, $candidate)) {
                return $candidate;
            }
        }

        // We do not want to merge two source categories.
        throw new moodle_exception('cannotimport', 'question');
    }

    /**
     * Whether any sibling category under the given parent already uses this name.
     *
     * @param int $contextid Destination question bank module context id.
     * @param int $parentid Destination top category id.
     * @param string $name Candidate name.
     * @return bool
     */
    public function category_name_taken(int $contextid, int $parentid, string $name): bool {
        global $DB;
        return $DB->record_exists('question_categories', [
            'contextid' => $contextid,
            'parent' => $parentid,
            'name' => $name,
        ]);
    }

    /**
     * The name of every question in a category, sorted.
     *
     * Moodle keeps the old versions of a question, so only the current version of each one
     * counts. Repeated names are kept, and the list is sorted.
     *
     * @param int $categoryid Category id.
     * @return string[] Question names, sorted, repeats included.
     */
    public function get_latest_question_names(int $categoryid): array {
        global $DB;

        $sql = "SELECT q.id, q.name
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND qv.version = (
                           SELECT MAX(version)
                             FROM {question_versions}
                            WHERE questionbankentryid = qbe.id
                       )";
        $records = $DB->get_records_sql($sql, ['catid' => $categoryid]);

        $names = array_map(static fn($record) => (string) $record->name, $records);
        sort($names);
        return $names;
    }

    /**
     * Look up a category by name in the course's question bank. Returns null when no
     * category has that name.
     *
     * @param int $contextid Destination question bank module context id.
     * @param int $parentid Destination top category id.
     * @param string $name Category name.
     * @return stdClass|null
     */
    private function get_first_named_category(int $contextid, int $parentid, string $name): ?stdClass {
        global $DB;

        $records = $DB->get_records('question_categories', [
            'contextid' => $contextid,
            'parent' => $parentid,
            'name' => $name,
        ], 'id ASC', '*', 0, 1);

        return $records ? reset($records) : null;
    }
}
