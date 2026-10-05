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

use DOMDocument, DOMNode, DOMNodeList, DOMElement, DOMXPath;

/**
 * Flattens the category structure in exported question XML.
 *
 * A category exported from Moodle carries its whole parent path.
 * Importing that would recreate the source's folder structure in the course.
 * This drops the parents and renames the category itself.
 * The copy lands directly under the course's top category.
 *
 * @package    block_qcategory_exporter
 * @copyright  2026 Innowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class xml_flattener {
    /**
     * Remove the parent categories from exported XML and rename the remaining one.
     *
     * @param string $xml Exported question XML.
     * @param string $categoryname Name the category should be imported under.
     * @return string The edited XML, or the original when it does not parse.
     */
    public function remove_parent_paths(string $xml, string $categoryname): string {
        // A single "/" reads as a path separator, so double it to keep it in the name.
        $categoryname = str_replace("/", "//", $categoryname);

        $dom = new DOMDocument('1.0', 'UTF-8');

        // Collect libxml's complaints instead of letting them reach the page.
        libxml_use_internal_errors(true);

        try {
            // Nothing we can edit, so hand back what we were given.
            if (!$dom->loadXML($xml)) {
                return $xml;
            }

            // Categories appear as <question type="category"><category><text>...</text></category></question>.
            $nodes = (new DOMXPath($dom))->query('//question[@type="category"]/category/text');
            $deepestnode = $nodes ? $this->find_deepest_category_node($nodes) : null;

            // No category in the file means there is nothing to flatten.
            if (!$deepestnode) {
                return $xml;
            }

            // Drop the parents, leaving only the category being imported.
            $this->remove_parent_categories($nodes, $deepestnode);

            // That one lands directly under the course's top category, under its new name.
            $deepestnode->nodeValue = '$course$/top/' . $categoryname;

            return $dom->saveXML();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
        }
    }

    /**
     * Pick out the category being exported.
     *
     * Every node holds a path such as "top/Parent Category/Category Name". The category
     * being exported is the deepest of them, and the rest are its parents.
     *
     * @param DOMNodeList $nodes The <text> nodes holding category paths.
     * @return DOMNode|null The node with the longest path, or null when none have one.
     */
    private function find_deepest_category_node(DOMNodeList $nodes): ?DOMNode {
        $deepestnode = null;
        $deepestdepth = -1;

        foreach ($nodes as $node) {
            $rawpath = (string) $node->nodeValue;
            if ($rawpath === '') {
                continue;
            }

            // How many steps down the path goes.
            $depth = count(array_filter(explode('/', $rawpath), static fn($step) => $step !== ''));
            if ($depth > $deepestdepth) {
                $deepestdepth = $depth;
                $deepestnode = $node;
            }
        }

        return $deepestnode;
    }

    /**
     * Drop every category except the one being exported.
     *
     * Only the deepest node is the actual exported category
     *
     * Given these three markers, keeping the last:
     *
     *     <question type="category"><category><text>top/A</text></category></question>
     *     <question type="category"><category><text>top/A/B</text></category></question>
     *     <question type="category"><category><text>top/A/B/C</text></category></question>
     *
     * The first two elements are removed and only this is left, ready to be renamed:
     *
     *     <question type="category"><category><text>top/A/B/C</text></category></question>
     *
     * @param DOMNodeList $nodes The <text> nodes holding category paths.
     * @param DOMNode|null $keepnode The one node to leave in place.
     * @return void
     */
    private function remove_parent_categories(DOMNodeList $nodes, ?DOMNode $keepnode): void {
        $toremove = [];

        // Collect first, delete after.
        foreach ($nodes as $node) {
            // The category being imported, which is the one we are keeping.
            if ($keepnode && $node === $keepnode) {
                continue;
            }

            $questionelement = $this->question_element_for_category_text($node);
            if ($questionelement) {
                // Keyed, so two text nodes under one question element only remove it once.
                $toremove[spl_object_hash($questionelement)] = $questionelement;
            }
        }

        // A node can only be removed through its parent.
        foreach ($toremove as $questionelement) {
            if ($questionelement->parentNode) {
                $questionelement->parentNode->removeChild($questionelement);
            }
        }
    }

    /**
     * Walk up from a category's <text> node to the <question> element wrapping it.
     *
     * A category is exported as a question of type "category", so the path sits two levels
     * down. Given the <text> node, this returns the <question> around it:
     *
     *     <question type="category">                                  <- returned
     *         <category>
     *             <text>top/Parent Category/Category Name</text>      <- given
     *         </category>
     *     </question>
     *
     * @param DOMNode|null $textnode The <text> node under <category>.
     * @return DOMElement|null The <question> element, or null when the node sits outside one.
     */
    public function question_element_for_category_text($textnode) {
        // Two steps up: <text> to <category> to <question>.
        $questionel = $textnode?->parentNode?->parentNode;

        // Confirm we landed on a question rather than running off the top of the document.
        if ($questionel instanceof DOMElement && $questionel->tagName === 'question') {
            return $questionel;
        }

        return null;
    }
}
