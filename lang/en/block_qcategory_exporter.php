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
 * Languages configuration for the block_qcategory_exporter plugin.
 *
 * @package   block_qcategory_exporter
 * @copyright 2025 Innowell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['importcategories'] = 'Import question categories into course';
$string['importcomplete'] = 'Imported categories and duplicated {$a} quiz(zes).';
$string['importcompletewithfailures'] = 'Imported categories and duplicated {$a->duplicated} quiz(zes). {$a->failed} failed: {$a->firstmessage}';
$string['importcompletewithskipped'] = 'Imported categories and duplicated {$a->duplicated} quiz(zes). {$a->skipped} question(s) could not be copied, so those quizzes hold fewer questions than the originals. First: {$a->firstmessage}';
$string['leftovercopies'] = '{$a->count} unfinished copy/copies could not be removed and are still in the course, so please delete them. First: {$a->firstmessage}';
$string['leftovercopy'] = 'the copy of "{$a->name}" (course module {$a->cmid})';
$string['noquestionbank'] = 'The course\'s own question bank could not be found or created.';
$string['pluginname'] = 'Question category exporter block';
$string['privacy:metadata:block_qcategory_exporter_carried'] = 'Which students hold a grade or completion that the block carried from an original quiz onto its copy. The record is kept only so the block can release its own overrides. It is deleted when an attempt by the student earns a higher grade than the carried-over grade, when only completion was carried over and the student submits an attempt, or when the copied quiz is deleted.';
$string['privacy:metadata:block_qcategory_exporter_carried:cmid'] = 'The course module id of the copied quiz.';
$string['privacy:metadata:block_qcategory_exporter_carried:grade'] = 'The carried-over grade. Empty when only completion was carried over.';
$string['privacy:metadata:block_qcategory_exporter_carried:quizid'] = 'The quiz instance id of the copied quiz.';
$string['privacy:metadata:block_qcategory_exporter_carried:timecreated'] = 'When the grade or completion was carried over.';
$string['privacy:metadata:block_qcategory_exporter_carried:userid'] = 'The student whose grade or completion was carried over.';
$string['qcategory_exporter:addinstance'] = 'Add a new question category exporter block';
$string['qcategory_exporter:myaddinstance'] = 'Add a new question category exporter block to the My Moodle page';
$string['skippedslot'] = '{$a->name}, question {$a->slot}: {$a->reason}';
$string['skipreason_alreadyinquiz'] = 'the quiz already holds this question';
$string['skipreason_categorynotimported'] = 'the question category was not imported into the course';
$string['skipreason_missingentry'] = 'the question no longer exists';
$string['skipreason_nofilter'] = 'the random question has no stored filter';
$string['skipreason_nofiltercategory'] = 'the random question names no category';
$string['skipreason_nomatchingquestion'] = 'no matching question in the imported category';
$string['skipreason_noquestionversion'] = 'the question has no versions';
$string['skipreason_noreference'] = 'the question slot names neither a question nor a category';
$string['skipreason_unknown'] = 'unknown reason';
