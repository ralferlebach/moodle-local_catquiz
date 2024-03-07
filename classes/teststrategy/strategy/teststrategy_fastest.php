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
 * Class teststrategy_fastest.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy\strategy;

use local_catquiz\teststrategy\feedbackgenerator\comparetotestaverage;
use local_catquiz\teststrategy\feedbackgenerator\customscalefeedback;
use local_catquiz\teststrategy\feedbackgenerator\debuginfo;
use local_catquiz\teststrategy\feedbackgenerator\graphicalsummary;
use local_catquiz\teststrategy\feedbackgenerator\personabilities;
use local_catquiz\teststrategy\feedbackgenerator\questionssummary;
use local_catquiz\teststrategy\feedbacksettings;
use local_catquiz\teststrategy\preselect_task\addscalestandarderror;
use local_catquiz\teststrategy\preselect_task\checkbreak;
use local_catquiz\teststrategy\preselect_task\checkitemparams;
use local_catquiz\teststrategy\preselect_task\checkpagereload;
use local_catquiz\teststrategy\preselect_task\filterbystandarderror;
use local_catquiz\teststrategy\preselect_task\firstquestionselector;
use local_catquiz\teststrategy\preselect_task\fisherinformation;
use local_catquiz\teststrategy\preselect_task\lasttimeplayedpenalty;
use local_catquiz\teststrategy\preselect_task\maximumquestionscheck;
use local_catquiz\teststrategy\preselect_task\mayberemovescale;
use local_catquiz\teststrategy\preselect_task\maybe_return_pilot;
use local_catquiz\teststrategy\preselect_task\noremainingquestions;
use local_catquiz\teststrategy\preselect_task\remove_uncalculated;
use local_catquiz\teststrategy\preselect_task\removeplayedquestions;
use local_catquiz\teststrategy\preselect_task\strategyfastestscore;
use local_catquiz\teststrategy\preselect_task\updatepersonability;
use local_catquiz\teststrategy\strategy;

/**
 * Will select questions with the highest fisher information first
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teststrategy_fastest extends strategy {

    /**
     *
     * @var int $id // strategy id defined in lib.
     */
    public int $id = LOCAL_CATQUIZ_STRATEGY_FASTEST;

    /**
     *
     * @var stdClass $feedbacksettings.
     */
    public feedbacksettings $feedbacksettings;


    /**
     * Returns required score modifiers.
     *
     * @return array
     *
     */
    public function get_preselecttasks(): array {
        return [
            checkitemparams::class,
            checkbreak::class,
            checkpagereload::class,
            firstquestionselector::class,
            updatepersonability::class,
            fisherinformation::class,
            addscalestandarderror::class,
            maximumquestionscheck::class,
            removeplayedquestions::class,
            maybe_return_pilot::class,
            remove_uncalculated::class,
            noremainingquestions::class,
            mayberemovescale::class,
            lasttimeplayedpenalty::class,
            filterbystandarderror::class,
            strategyfastestscore::class,
        ];
    }

    /**
     * Get feedback generators.
     *
     * @param feedbacksettings $feedbacksettings
     * @return array
     *
     */
    public function get_feedbackgenerators(feedbacksettings $feedbacksettings = null): array {

        $this->apply_feedbacksettings($feedbacksettings);

        return [
            new customscalefeedback($this->feedbacksettings),
            new questionssummary($this->feedbacksettings),
            new personabilities($this->feedbacksettings),
            new comparetotestaverage($this->feedbacksettings),
            new debuginfo($this->feedbacksettings),
            new graphicalsummary($this->feedbacksettings),
        ];
    }

    /**
     * Gets predefined values and completes them with specific behaviour of strategy.
     *
     * @param feedbacksettings $feedbacksettings
     *
     */
    public function apply_feedbacksettings(feedbacksettings $feedbacksettings) {
        $this->feedbacksettings = $feedbacksettings;
    }

    /**
     * Gets predefined values and completes them with specific behaviour of strategy.
     *
     * @param object $feedbacksettings
     * @param array $personabilities
     * @param array $feedbackdata
     * @param int $catscaleid
     * @param bool $feedbackonlyfordefinedscaleid
     *
     */
    public function select_scales_for_report(
        object $feedbacksettings,
        array $personabilities,
        array $feedbackdata,
        int $catscaleid = 0,
        bool $feedbackonlyfordefinedscaleid = false
        ): array {

        $newabilities = [];
        $rootscaleid = (int) $feedbackdata['catscaleid'];

        // Only parentscale to be selected.
        foreach ($personabilities as $scaleid => $abilityvalue) {
            if ($scaleid != $rootscaleid) {
                $newabilities[$scaleid] = [
                    'value' => $abilityvalue['value'],
                    'excluded' => true,
                    ];
                $newabilities[$scaleid]['error']['rootonly'] = [
                        'rootscaleid' => $rootscaleid,
                        'currentscaleid' => $scaleid,
                ];
                continue;
            }
            $newabilities[$scaleid] = [
                'value' => $abilityvalue['value'],
                'primary' => true,
                'toreport' => true,
                'primarybecause' => 'rootscale',
            ];
        };
        // Minimum of questions per test applied.
        $newabilities = $feedbacksettings->filter_nmintest($newabilities, $feedbackdata);

        // Fraction can not be 1 (all answers correct) or 0 (all answers incorrect).
        if ($feedbacksettings->fraction >= 1 || $feedbacksettings->fraction <= 0) {
            foreach ($personabilities as $scaleid => $abilityvalue) {
                $newabilities[$scaleid] = [
                    'value' => $abilityvalue['value'],
                    'excluded' => true,
                    ];
                $newabilities[$scaleid]['error']['fraction'] = [
                        'fraction' => $feedbacksettings->fraction,
                        'expected' => '0 < f < 1',
                ];
            }
        }
        return $newabilities;
    }
}
