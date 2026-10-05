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

use block_qcategory_exporter\exporter;

/**
 * Block definition class for the block_qcategory_exporter plugin.
 *
 * @package   block_qcategory_exporter
 * @copyright 2026 Innowell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_qcategory_exporter extends block_base
{
    /**
     * Initialises the block.
     * Block displays the categories which are about to be imported.
     *
     * @return void
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_qcategory_exporter');
    }

    /**
     * Gets the block contents.
     *
     * @return stdClass Gets the block templating.
     */
    public function get_content() {
        global $OUTPUT, $COURSE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        if (!$this->user_is_admin()) {
            $this->content->text = '';
            return $this->content;
        }

        // Collect unique categories referenced by all quizzes in the course.
        $exporter = new exporter();
        $categoriesmap = $exporter->collect_categories_for_course($COURSE->id);

        // Prepare template shown to user containing overview of the categories about to be imported.
        $categories = array_values($categoriesmap);
        $data = [
            'actionurl' => (new moodle_url('/blocks/qcategory_exporter/copy_to_course.php'))->out(false),
            'sesskey' => sesskey(),
            'courseid' => $COURSE->id,
            'categoryids' => implode(',', array_column($categories, 'id')),
            'categories' => $categories,
        ];
        $this->content->text = $OUTPUT->render_from_template('block_qcategory_exporter/content', $data);
        return $this->content;
    }

    /**
     * Defines in which pages this block can be added.
     * Should only be added to courses as this is where we want to export questions from
     * @return array of the pages where the block can be added.
     */
    public function applicable_formats() {
        return [
            'admin' => false,
            'site-index' => false,
            'course-view' => true,
            'mod' => false,
            'my' => false,
        ];
    }

    /**
     * Hide the block completely from non-admin users.
     *
     * @param core_renderer $output
     * @return block_contents|null
     */
    public function get_content_for_output($output) {
        if (!$this->user_is_admin()) {
            return null;
        }

        return parent::get_content_for_output($output);
    }

    /**
     * Convenience wrapper to determine admin-only access.
     *
     * @return bool
     */
    private function user_is_admin(): bool {
        return has_capability('moodle/site:config', \context_system::instance());
    }
}
