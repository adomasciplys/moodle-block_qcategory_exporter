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
 * Builds a new empty quiz module from an existing quiz record.
 *
 * Core's duplicate_module() is no use here: it copies the slots still pointing at the
 * original categories. We build the quiz empty so slot_copier can add the slots itself,
 * aimed at the imported categories.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_builder {
    /** @var string[] Quiz fields copied from the source record without changes. */
    private const SKIP_QUIZ_FIELDS = ['id', 'course', 'timecreated', 'timemodified'];

    /**
     * @var string[] Review settings, named without their "review" prefix.
     */
    private const REVIEW_FIELDS = [
        'attempt', 'correctness', 'maxmarks', 'marks',
        'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback',
    ];

    /**
     * Create a new quiz instance that mirrors the source quiz but has no questions.
     *
     * @param \stdClass $course
     * @param \stdClass $cm
     * @param \stdClass $quiz
     * @return \stdClass The moduleinfo returned by create_module().
     */
    public function build(\stdClass $course, \stdClass $cm, \stdClass $quiz): \stdClass {
        global $CFG;

        // Needed for create_module().
        require_once($CFG->dirroot . '/course/lib.php');

        $moduleinfo = $this->base_moduleinfo($course, $cm);
        $this->copy_quiz_fields($moduleinfo, $cm, $quiz);
        $this->populate_review_form_fields($moduleinfo, $quiz);
        $this->copy_grade_settings($moduleinfo, $course, $quiz);

        $newmoduleinfo = create_module($moduleinfo);
        $this->copy_overall_feedback($cm, $quiz, $newmoduleinfo);

        return $newmoduleinfo;
    }

    /**
     * Copy the overall feedback bands onto the new quiz.
     *
     * Overall feedback is a set of rows in quiz_feedback rather than a field on the quiz, so
     * nothing on the moduleinfo carries it.
     *
     * @param \stdClass $cm Course module of the source quiz.
     * @param \stdClass $quiz Source quiz record.
     * @param \stdClass $newmoduleinfo The moduleinfo create_module() returned.
     * @return void
     */
    private function copy_overall_feedback(\stdClass $cm, \stdClass $quiz, \stdClass $newmoduleinfo): void {
        global $DB;

        $bands = $DB->get_records('quiz_feedback', ['quizid' => $quiz->id]);
        if (!$bands) {
            return;
        }

        $fs = get_file_storage();
        $oldcontextid = \context_module::instance((int) $cm->id)->id;
        $newcontextid = \context_module::instance((int) $newmoduleinfo->coursemodule)->id;

        foreach ($bands as $band) {
            $oldbandid = (int) $band->id;

            unset($band->id);
            $band->quizid = (int) $newmoduleinfo->instance;
            $newbandid = $DB->insert_record('quiz_feedback', $band);

            $oldfiles = $fs->get_area_files($oldcontextid, 'mod_quiz', 'feedback', $oldbandid, 'id', false);
            foreach ($oldfiles as $oldfile) {
                $fs->create_file_from_storedfile([
                    'contextid' => $newcontextid,
                    'itemid' => $newbandid,
                ], $oldfile);
            }
        }
    }

    /**
     * Copy the settings kept on the gradebook item rather than on the quiz.
     *
     * The grade to pass is stored against the quiz's grade item, so copying the quiz record
     * never picks it up.
     *
     * @param \stdClass $moduleinfo Target moduleinfo.
     * @param \stdClass $course Course the quiz belongs to.
     * @param \stdClass $quiz Source quiz record.
     */
    private function copy_grade_settings(\stdClass $moduleinfo, \stdClass $course, \stdClass $quiz): void {
        global $CFG;

        // Defines grade_item.
        require_once($CFG->libdir . '/gradelib.php');

        // The quiz's own grade item, which is always item number 0.
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
            'courseid' => $course->id,
        ]);

        if (!$item) {
            return;
        }

        $moduleinfo->gradepass = $item->gradepass;
    }

    /**
     * Build the moduleinfo skeleton covering course/section placement and the
     * course-module-level fields (visibility, completion, grouping, availability).
     *
     * @param \stdClass $course Destination course.
     * @param \stdClass $cm Source course_module to mirror.
     * @return \stdClass Partially filled moduleinfo ready for quiz-level fields.
     */
    private function base_moduleinfo(\stdClass $course, \stdClass $cm): \stdClass {
        global $DB;

        $sectionnum = (int) $DB->get_field('course_sections', 'section', ['id' => $cm->section], MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = $cm->visible;
        $moduleinfo->visibleoncoursepage = $cm->visibleoncoursepage;
        $moduleinfo->groupmode = $cm->groupmode;
        $moduleinfo->groupingid = $cm->groupingid;
        $moduleinfo->completion = $cm->completion;
        $moduleinfo->completionview = $cm->completionview;
        $moduleinfo->completionexpected = $cm->completionexpected;
        $moduleinfo->completionpassgrade = $cm->completionpassgrade ?? 0;
        $moduleinfo->completiongradeitemnumber = $cm->completiongradeitemnumber ?? null;
        $moduleinfo->showdescription = $cm->showdescription ?? 0;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->availability = $cm->availability ?? null;
        $moduleinfo->lang = $cm->lang ?? null;

        return $moduleinfo;
    }

    /**
     * Copy quiz fields onto the moduleinfo
     *
     * @param \stdClass $moduleinfo Target moduleinfo being assembled.
     * @param \stdClass $cm Course module of the source quiz.
     * @param \stdClass $quiz Source quiz record.
     */
    private function copy_quiz_fields(\stdClass $moduleinfo, \stdClass $cm, \stdClass $quiz): void {
        foreach ((array) $quiz as $field => $value) {
            if (in_array($field, self::SKIP_QUIZ_FIELDS, true)) {
                continue;
            }
            $moduleinfo->{$field} = $value;
        }

        $moduleinfo->name = get_string('duplicatedmodule', 'moodle', $quiz->name);
        $moduleinfo->sumgrades = 0;
        $moduleinfo->introeditor = $this->intro_editor($cm, $quiz);
        $moduleinfo->quizpassword = $quiz->password ?? '';
    }

    /**
     * Build the description field for the moduleinfo, with its files.
     *
     * The description text only names its images with an @@PLUGINFILE@@ link.
     * The images themselves sit in the source quiz's own file area.
     *
     * @param \stdClass $cm Course module of the source quiz.
     * @param \stdClass $quiz Source quiz record.
     * @return array{text: string, format: int, itemid: int} The introeditor field.
     */
    private function intro_editor(\stdClass $cm, \stdClass $quiz): array {
        global $CFG;

        // Defines file_prepare_draft_area().
        require_once($CFG->libdir . '/filelib.php');

        // A new draft area, holding the source quiz's description files.
        $draftitemid = 0;
        $intro = file_prepare_draft_area(
            $draftitemid,
            \context_module::instance((int) $cm->id)->id,
            'mod_quiz',
            'intro',
            0,
            // Subdirectories, because a resized copy of an image is stored in one.
            ['subdirs' => true],
            $quiz->intro
        );

        return [
            'text' => $intro,
            'format' => $quiz->introformat,
            'itemid' => $draftitemid,
        ];
    }

    /**
     * Copy the review settings across, one form field per moment
     *
     * Each review setting is stored as one number holding all four moments at once,
     * because every moment owns its own bit:
     *
     *     during the attempt      65536
     *     immediately after        4096
     *     later while open          256
     *     after the quiz closes      16
     *
     * The number is the sum of whichever moments are switched on. Showing marks
     * immediately after and later while open gives reviewmarks = 4096 + 256 = 4352:
     *
     *      4096   0001 0000 0000 0000
     *       256   0000 0001 0000 0000
     *      4352   0001 0001 0000 0000
     *
     * Separate bits mean no two combinations reach the same total, and & tests one moment
     * on its own: 4352 & 4096 is 4096 (on), 4352 & 16 is 0 (off).
     *
     * @param \stdClass $moduleinfo Target moduleinfo.
     * @param \stdClass $quiz Source quiz record.
     */
    private function populate_review_form_fields(\stdClass $moduleinfo, \stdClass $quiz): void {
        // The four moments a student may review, and the value each one holds in the number.
        $moments = [
            'during' => \mod_quiz\question\display_options::DURING,
            'immediately' => \mod_quiz\question\display_options::IMMEDIATELY_AFTER,
            'open' => \mod_quiz\question\display_options::LATER_WHILE_OPEN,
            'closed' => \mod_quiz\question\display_options::AFTER_CLOSE,
        ];

        foreach (self::REVIEW_FIELDS as $field) {
            // The one number holding all four settings at once.
            $setting = $quiz->{'review' . $field} ?? 0;

            foreach ($moments as $moment => $value) {
                // Switch the matching form field on.
                $moduleinfo->{$field . $moment} = ($setting & $value) ? 1 : 0;
            }
        }
    }
}
