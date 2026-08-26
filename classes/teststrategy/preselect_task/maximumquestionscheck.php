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
 * Class maximumquestionscheck.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy\preselect_task;

use local_catquiz\local\result;
use local_catquiz\local\status;
use local_catquiz\teststrategy\preselect_task;

/**
 * Test strategy maximumquestionscheck.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class maximumquestionscheck extends preselect_task {
    /**
     * Run preselect task.
     *
     * @param array $context
     *
     * @return result
     *
     */
    public function run(array &$context): result {
        $maxquestions = $context['maximumquestions'];
        if ($maxquestions == -1) {
            return result::ok($context);
        }

        // Count the questions this attempt has actually played, taken from our own
        // progress record rather than from the externally maintained
        // `questionsattempted` counter on the adaptivequiz attempt. That counter can
        // drift when an attempt is resumed (the pending item is re-rendered without
        // being counted again), and the drift let the test administer one item more
        // than configured - the known "does not stop at the maximum" defect.
        // Pilot items do not count towards the productive test length either.
        $played = $context['questionsattempted'];
        if (isset($context['progress'])) {
            $played = max(
                $played,
                count($context['progress']->without_pilots()->get_playedquestions())
            );
        }

        if ($played >= $maxquestions) {
            return result::err(status::ERROR_REACHED_MAXIMUM_QUESTIONS);
        }

        return result::ok($context);
    }
}
