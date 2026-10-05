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
 * Version metadata for the block_qcategory_exporter plugin.
 *
 * @package   block_qcategory_exporter
 * @copyright 2026 Innowell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// What is the version of the plugin.
$plugin->version = 2026100500;
// What moodle version is required. 2025041403 is Moodle 5.0.3, the first release with the
// question bank module structure this plugin builds on.
$plugin->requires = 2025041403;
// The Moodle branches the plugin was used on: Moodle 5.0 and 5.1
$plugin->supported = [500, 501];
$plugin->component = 'block_qcategory_exporter';
$plugin->maturity = MATURITY_STABLE;
// Version string.
$plugin->release = 'v1.3';

$plugin->dependencies = [];
