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
 * Finds matching questions in a target category and copies their files along.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_resolver {
    /**
     * Get the latest question definition for a question bank entry.
     *
     * @param int $entryid
     * @return \stdClass|null
     */
    public function get_latest_question_for_entry(int $entryid): ?\stdClass {
        global $DB;

        $sql = "SELECT q.*, qbe.questioncategoryid AS category
                  FROM {question_versions} qv
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qv.questionbankentryid = :entryid
              ORDER BY qv.version DESC";

        // IGNORE_MULTIPLE fetches the newest row only.
        return $DB->get_record_sql($sql, ['entryid' => $entryid], IGNORE_MULTIPLE) ?: null;
    }

    /**
     * Find the question in the target category that corresponds to the source question.
     *
     * Four ways of matching, strongest first:
     *
     *  - idnumber, which a teacher sets deliberately to identify a question
     *  - stamp, unique per question across the site, but regenerated on XML import
     *  - questiontext, which is what the student reads, so it is rarely repeated
     *  - name, the last resort, since two questions may share one
     *
     *
     * @param int $categoryid Target category id.
     * @param \stdClass $oldentry Source question_bank_entries row.
     * @param \stdClass $oldquestion Source question record.
     * @param array<int, int> $usedentryids Target question_bank_entries ids already taken.
     * @return \stdClass|null Matching question record, or null when none of the four match.
     */
    public function find_matching_question(
        int $categoryid,
        \stdClass $oldentry,
        \stdClass $oldquestion,
        array $usedentryids = []
    ): ?\stdClass {
        // Each ?? falls through to the next one.
        return $this->match_by($categoryid, 'qbe.idnumber', $oldentry->idnumber ?? null, $usedentryids)
            ?? $this->match_by($categoryid, 'q.stamp', $oldquestion->stamp ?? null, $usedentryids)
            ?? $this->match_by($categoryid, 'q.questiontext', $oldquestion->questiontext ?? null, $usedentryids, true)
            ?? $this->match_by($categoryid, 'q.name', $oldquestion->name ?? null, $usedentryids);
    }

    /**
     * Resolve a question category's context id.
     *
     * @param int $categoryid
     * @return int|null
     */
    public function category_contextid(int $categoryid): ?int {
        global $DB;
        $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $categoryid], IGNORE_MISSING);
        return $contextid ? (int) $contextid : null;
    }

    /**
     * Copy a question's files onto the copy of that question.
     *
     * A question's files are kept in separate areas, such as the question text and the
     * overall feedback.
     *
     * Files already on the target are left alone, so running this twice is safe.
     *
     * @param int $oldcontextid Context of the source question's category.
     * @param int $olditemid Source question id.
     * @param int $newcontextid Context of the copied question's category.
     * @param int $newitemid Copied question id.
     * @return void
     */
    public function copy_question_item_files(
        int $oldcontextid,
        int $olditemid,
        int $newcontextid,
        int $newitemid
    ): void {
        global $DB;

        $fs = get_file_storage();

        // Which areas this question has files in, such as its text and its feedback.
        $areas = $DB->get_recordset_sql(
            "SELECT DISTINCT component, filearea
               FROM {files}
              WHERE contextid = :contextid
                AND itemid = :itemid",
            ['contextid' => $oldcontextid, 'itemid' => $olditemid]
        );

        foreach ($areas as $area) {
            // Every file in this one area of the source question.
            $oldfiles = $fs->get_area_files($oldcontextid, $area->component, $area->filearea, $olditemid, 'id', false);

            foreach ($oldfiles as $oldfile) {
                // Where the file sits inside its area.
                $filepath = $oldfile->get_filepath();
                $filename = $oldfile->get_filename();

                // Already copied by an earlier run.
                if ($fs->file_exists($newcontextid, $area->component, $area->filearea, $newitemid, $filepath, $filename)) {
                    continue;
                }

                // Same area and same name, but on the copied question in its new context.
                $fs->create_file_from_storedfile([
                    'contextid' => $newcontextid,
                    'component' => $area->component,
                    'filearea' => $area->filearea,
                    'itemid' => $newitemid,
                    'filepath' => $filepath,
                    'filename' => $filename,
                ], $oldfile);
            }
        }

        // Recordsets hold a database cursor open until closed.
        $areas->close();
    }

    /**
     * Find the newest question in a category whose given column holds the given value.
     *
     * @param int $categoryid Target category id.
     * @param string $column Column to match on
     * @param string|null $value Value to match; a blank value means there is nothing to match.
     * @param array<int, int> $usedentryids Entries to leave out, already taken by an earlier slot.
     * @param bool $longtext True when the column holds long text, which some databases cannot
     *      compare with a plain equals.
     * @return \stdClass|null Matching question record, or null when there is no match.
     */
    private function match_by(
        int $categoryid,
        string $column,
        ?string $value,
        array $usedentryids = [],
        bool $longtext = false
    ): ?\stdClass {
        global $DB;

        if (empty($value)) {
            return null;
        }

        $params = ['catid' => $categoryid, 'value' => $value];

        // Long text columns need the database's own way of comparing them.
        $comparison = $longtext
            ? $DB->sql_compare_text($column) . ' = ' . $DB->sql_compare_text(':value')
            : "{$column} = :value";

        // Entries an earlier slot already took are not on offer any more.
        $notused = '';
        if ($usedentryids) {
            [$insql, $inparams] = $DB->get_in_or_equal($usedentryids, SQL_PARAMS_NAMED, 'used', false);
            $notused = "AND qbe.id {$insql}";
            $params += $inparams;
        }

        // Newest version first, then oldest entry, so the same slot always picks the same question.
        $sql = "SELECT q.*, qbe.questioncategoryid AS category, qbe.id AS questionbankentryid
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND {$comparison}
                   {$notused}
              ORDER BY qv.version DESC, qbe.id ASC";

        // More than one question can match. IGNORE_MULTIPLE fetches the first row only.
        return $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE) ?: null;
    }
}
