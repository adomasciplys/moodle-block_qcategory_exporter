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
 *
 * Carries each active student's grade for a quiz over to the duplicate of that quiz.
 *
 * The system locks a passing grade with an override.
 * Without the override, the system deletes the copied passing grade when the student makes a new attempt.
 * The system does not lock a failing grade.
 * The observer removes the override from the passing grade when the student submits a new attempt.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_copier {
    /** @var override_register Remembers which overrides are this plugin's own. */
    private override_register $register;

    /**
     * Wire up the record of which overrides are this plugin's own.
     *
     * @param override_register|null $register Remembers which overrides are this plugin's own.
     */
    public function __construct(?override_register $register = null) {
        $this->register = $register ?? new override_register();
    }

    /**
     * Give the duplicate the grade the original holds, for each of the given students.
     *
     * @param int $sourcequizid Quiz instance id of the original.
     * @param int $newquizid Quiz instance id of the copy.
     * @param int $newcmid Course module id of the copy.
     * @param array<int, int> $students User ids to carry grades over for.
     * @return int How many grades were copied.
     */
    public function copy_all(int $sourcequizid, int $newquizid, int $newcmid, array $students): int {
        global $CFG, $DB;

        // No active students, just skip.
        if (!$students) {
            return 0;
        }

        // Defines grade_update().
        require_once($CFG->libdir . '/gradelib.php');
        $studentset = array_flip($students);

        $gradebook = [];
        foreach ($DB->get_records('quiz_grades', ['quiz' => $sourcequizid]) as $grade) {
            $userid = (int) $grade->userid;

            // A grade held for someone who has left the course is not carried over.
            if (!isset($studentset[$userid])) {
                continue;
            }

            // The quiz's own record of the grade, which its reports read.
            unset($grade->id);
            $grade->quiz = $newquizid;
            $DB->insert_record('quiz_grades', $grade);

            $gradebook[$userid] = (object) [
                'userid' => $userid,
                'rawgrade' => $grade->grade,
                'dategraded' => $grade->timemodified,
            ];
        }

        // No active student has a grade on the original, so there is nothing to write.
        if (!$gradebook) {
            return 0;
        }

        // Write the grades into the gradebook for the copy.
        $courseid = (int) $DB->get_field('quiz', 'course', ['id' => $newquizid], MUST_EXIST);
        grade_update('mod/quiz', $courseid, 'mod', 'quiz', $newquizid, 0, $gradebook);

        // Override the passing grades, so a worse attempt on the copy cannot replace them.
        $this->pin_passing_grades($courseid, $newquizid, $newcmid, $gradebook);

        return count($gradebook);
    }

    /**
     * Hold the passing grades in place with an override, so an attempt cannot wipe them.
     *
     * @param int $courseid Course the copy belongs to.
     * @param int $newquizid Quiz instance id of the copy.
     * @param int $newcmid Course module id of the copy.
     * @param array<int, \stdClass> $gradebook The grades just handed to the gradebook.
     * @return void
     */
    private function pin_passing_grades(int $courseid, int $newquizid, int $newcmid, array $gradebook): void {
        global $CFG;

        // Defines grade_item.
        require_once($CFG->libdir . '/grade/grade_item.php');

        $item = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $newquizid,
            'itemnumber' => 0,
        ]);

        // No grade item, or no pass mark to fall short of.
        if (!$item || (float) $item->gradepass <= 0) {
            return;
        }

        foreach ($gradebook as $userid => $grade) {
            // A student who failed the original must still be able to pass the copy, so
            // their grade is left where an attempt of their own can replace it.
            if ((float) $grade->rawgrade < (float) $item->gradepass) {
                continue;
            }

            // Override the student's grade in the gradebook, so a new attempt cannot replace it.
            $item->update_final_grade($userid, $grade->rawgrade, 'block_qcategory_exporter');

            // Save the override to the override register, so the observer can remove it
            // once the student submits an attempt with a higher grade.
            $this->register->ensure_exists($newquizid, $newcmid, (int) $userid, (float) $grade->rawgrade);
        }
    }
}
