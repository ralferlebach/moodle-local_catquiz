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
use local_catquiz\teststrategy\progress;

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

        // The $context['questionsattempted'] value is the mod_adaptivequiz attempt
        // counter - a cross-plugin value that can lag by one after a resume/reload:
        // when this check runs the just-answered question's increment is not always
        // reflected yet, which let a further question slip through and produced a
        // test one question too long. progress::get_num_playedquestions() is
        // catquiz's own tally, persisted in the progress payload and therefore
        // resume-safe. Take the larger of the two so the attempt ends as soon as
        // either counter reaches the maximum. The progress tally is pilot-filtered
        // to match the scored-question limit, so in piloted attempts the
        // adaptivequiz counter still dominates and behaviour is unchanged; only
        // the resume/reload lag is corrected.
        $attempted = (int) ($context['questionsattempted'] ?? 0);
        if (isset($context['progress']) && $context['progress'] instanceof progress) {
            $played = $context['progress']->without_pilots()->get_num_playedquestions();
            $attempted = max($attempted, $played);
        }

        \local_catquiz\local\debugtrace::resume(sprintf(
            'MAXCHECK qattempted=%s progress_n=%s effective=%d max=%d -> %s',
            $context['questionsattempted'] ?? 'null',
            (isset($context['progress']) && $context['progress'] instanceof progress)
                ? $context['progress']->without_pilots()->get_num_playedquestions() : 'null',
            $attempted,
            $maxquestions,
            ($attempted >= $maxquestions) ? 'ABORT' : 'continue'
        ));

        if ($attempted >= $maxquestions) {
            return result::err(status::ERROR_REACHED_MAXIMUM_QUESTIONS);
        }

        return result::ok($context);
    }
}
