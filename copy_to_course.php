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
 * Copies the question categories a course's quizzes use into the course itself,
 * then rebuilds those quizzes so they draw from the copies.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_qcategory_exporter\duplicator;
use block_qcategory_exporter\importer;
use core\output\notification;

require(__DIR__ . '/../../config.php');

// Check capabilities and required params.
$courseid = required_param('courseid', PARAM_INT); // Destination course.
$categories = required_param('categories', PARAM_SEQUENCE); // Ids of the categories to import.
require_login($courseid);
require_sesskey();

// Set the page up before any work.
$PAGE->set_url(new moodle_url('/blocks/qcategory_exporter/copy_to_course.php', ['courseid' => $courseid]));
$PAGE->set_context(context_course::instance($courseid));

// Step 1: Import each category into the destination course top level question bank category.
$categorymap = (new importer())->run_import($courseid, $categories);

// Reloads the course.
rebuild_course_cache($courseid, true);

// Step 2: Rebuild every quiz in the course against the copied categories.
$result = (new duplicator())->duplicate_quizzes($courseid, $categorymap);

// Report the outcome on the course page.
if (!empty($result['failed'])) {
    // A quiz that could not be copied at all.
    $firstfailure = reset($result['failed']);
    $message = get_string('importcompletewithfailures', 'block_qcategory_exporter', (object) [
        'duplicated' => (int) $result['duplicated'],
        'failed' => count($result['failed']),
        'firstmessage' => (string) ($firstfailure['message'] ?? ''),
    ]);
    $messagetype = notification::NOTIFY_WARNING;

    // A copy that was created, could not be filled, and could not be removed either.
    if (!empty($result['leftover'])) {
        $firstleftover = get_string(
            'leftovercopy',
            'block_qcategory_exporter',
            (object) reset($result['leftover'])
        );
        // The failure message ends in an exception's own words, which may or may not
        // finish with a full stop.
        $message = rtrim($message, '. ') . '. ' . get_string('leftovercopies', 'block_qcategory_exporter', (object) [
            'count' => count($result['leftover']),
            'firstmessage' => $firstleftover,
        ]);
    }
} else if (!empty($result['skipped'])) {
    // The quizzes were copied, but some of their questions were left out.
    $firstskipped = reset($result['skipped']);
    $message = get_string('importcompletewithskipped', 'block_qcategory_exporter', (object) [
        'duplicated' => (int) $result['duplicated'],
        'skipped' => count($result['skipped']),
        'firstmessage' => describe_skipped_slot($firstskipped),
    ]);
    $messagetype = notification::NOTIFY_WARNING;
} else {
    $message = get_string('importcomplete', 'block_qcategory_exporter', (int) $result['duplicated']);
    $messagetype = notification::NOTIFY_SUCCESS;
}

// Send user back course page.
redirect(new moodle_url('/course/view.php', ['id' => $courseid]), $message, null, $messagetype);

/**
 * Put one skipped slot into words: which quiz, which question, and why it was left out.
 *
 * @param array{name: string, slot: int, reason: string} $skipped One entry from the duplicator's report.
 * @return string
 */
function describe_skipped_slot(array $skipped): string {
    // A reason code the language file does not know about is still worth reporting.
    $reasonkey = 'skipreason_' . ($skipped['reason'] ?? '');
    $reason = get_string_manager()->string_exists($reasonkey, 'block_qcategory_exporter')
        ? get_string($reasonkey, 'block_qcategory_exporter')
        : get_string('skipreason_unknown', 'block_qcategory_exporter');

    return get_string('skippedslot', 'block_qcategory_exporter', (object) [
        'name' => (string) ($skipped['name'] ?? ''),
        'slot' => (int) ($skipped['slot'] ?? 0),
        'reason' => $reason,
    ]);
}
