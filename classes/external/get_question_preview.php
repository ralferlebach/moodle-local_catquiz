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
 * Renders the preview of a single question on demand (issue #20).
 *
 * @package   local_catquiz
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Returns the rendered text of one question.
 *
 * Issue #20: the question lists used to carry the full, formatted text of every
 * row - including base64 images - inside a hidden modal. That text was held in the
 * database result, in PHP strings, in the AJAX response and in the DOM at the same
 * time. The lists now select only short values, and the preview is fetched through
 * this endpoint when a row is actually clicked.
 *
 * @package   local_catquiz
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_question_preview extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'Question id'),
        ]);
    }

    /**
     * Returns the formatted question text.
     *
     * @param int $questionid
     * @return array
     */
    public static function execute(int $questionid): array {
        global $DB, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), ['questionid' => $questionid]);
        $questionid = $params['questionid'];

        // The question bank is managed site wide, so the CAT manager capability is
        // the right gate here - the same one that guards the pages these lists live
        // on. Without it there is no reason to hand out question content.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/catquiz:manage_catscales', $context);

        $question = $DB->get_record(
            'question',
            ['id' => $questionid],
            'id, name, questiontext, questiontextformat',
            MUST_EXIST
        );

        // Rewrite pluginfile URLs so images resolve, then format. This is the work
        // that used to happen for every row of every page; now it happens once, for
        // the one question the user asked to see.
        $text = question_rewrite_question_urls(
            $question->questiontext,
            'pluginfile.php',
            $context->id,
            'question',
            'questiontext',
            [],
            $question->id
        );

        return [
            'questionid' => $question->id,
            'name' => format_string($question->name),
            'questiontext' => format_text($text, $question->questiontextformat, ['context' => $context]),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionid' => new external_value(PARAM_INT, 'Question id'),
            'name' => new external_value(PARAM_RAW, 'Question name'),
            'questiontext' => new external_value(PARAM_RAW, 'Formatted question text'),
        ]);
    }
}
