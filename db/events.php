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
 * Event observers for block_qcategory_exporter.
 *
 * @package   block_qcategory_exporter
 * @copyright 2026 Innowell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// A carried-over grade is held in place by an override, and has to compete with the grade the
// student's own attempts earn, the higher of the two winning.
// The observer runs at every point where mod_quiz recalculates a grade from attempts.
$observers = [
    [
        // Automatic grading, immediately after the student submits an attempt.
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback' => '\block_qcategory_exporter\observer::attempt_graded',
    ],
    [
        // A teacher marking a question by hand, which can raise the grade much later.
        'eventname' => '\mod_quiz\event\question_manually_graded',
        'callback' => '\block_qcategory_exporter\observer::question_manually_graded',
    ],
    [
        // A regrade, which recalculates the grade the same way.
        'eventname' => '\mod_quiz\event\attempt_regraded',
        'callback' => '\block_qcategory_exporter\observer::attempt_regraded',
    ],
];
