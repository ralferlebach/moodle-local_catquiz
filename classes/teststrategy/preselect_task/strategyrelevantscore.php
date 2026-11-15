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
 * Class strategyrelevantscore.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy\preselect_task;

use stdClass;

/**
 * Add a score to each question and sort questions descending by score
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class strategyrelevantscore extends strategyscore {
    /**
     * Returns the scale term
     *
     * @param float $testinfo
     * @param float $abilitydifference
     * @return mixed
     */
    protected function get_question_scaleterm(float $testinfo, float $abilitydifference) {
        return 1;
    }

    /**
     * Returns the item term
     *
     * B to the power of n_s, the number of productive questions answered in the scale (issue #162, decision of
     * 15.11.2026; before: max(1, n_s - n_min + 1)). n_s = 0 gives 1, a neutral term.
     *
     * @param float $testinfo
     * @param float $fraction
     * @param mixed $difficulty
     * @param mixed $scaleability
     * @param mixed $scalecount
     * @param int $minattemptsperscale Not used since issue #162; kept for the common signature.
     * @return mixed
     */
    protected function get_question_itemterm(
        float $testinfo,
        float $fraction,
        $difficulty,
        $scaleability,
        $scalecount,
        int $minattemptsperscale
    ) {
        return (
            1 / (
                1 + exp($testinfo * 2 * (0.5 - $fraction) * ($difficulty - $scaleability)))
            ) ** $scalecount;
    }

    /**
     * Returns the score for the given question and scaleid
     *
     * @param stdClass $question
     * @param int $scaleid
     * @return mixed
     */
    protected function get_score(stdClass $question, int $scaleid) {
                return $question->fisherinformation[$scaleid]
                    * $question->processterm
                    * $question->scaleterm
                    * $question->itemterm
                    * $question->lasttimeplayedpenaltyfactor;
    }
}
