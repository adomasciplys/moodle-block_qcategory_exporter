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
 * Identifies the active students whose work is copied over to the duplicate quizzes.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class active_students {
    /**
     * Users holding a student role in the course on an enrolment that is active now.
     *
     * The copy includes work (grades and completion) for active students only.
     * The copy excludes suspended students and teachers.
     *
     * @param \stdClass $course
     * @return array<int, int> User ids.
     */
    public function get_ids(\stdClass $course): array {
        $context = \context_course::instance((int) $course->id);

        // Everyone enrolled in the course right now, with an active enrollment ( $onlyactive = true ).
        $enrolled = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);

        // Everyone holding a student role in the course, whatever their enrolment.
        $students = [];
        foreach (get_archetype_roles('student') as $role) {
            // Add the user ID of every user with this student role to the $students array.
            foreach (get_role_users((int) $role->id, $context, false, 'u.id', 'u.id') as $user) {
                $students[(int) $user->id] = true;
            }
        }

        return array_map('intval', array_intersect(array_keys($enrolled), array_keys($students)));
    }
}
