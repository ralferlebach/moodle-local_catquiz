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
 * Class strategydeficitscore.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy\preselect_task;

use local_catquiz\catscale;
use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\result;
use local_catquiz\teststrategy\preselect_task;
use local_catquiz\teststrategy\progress;
use stdClass;

/**
 * Add a score to each question and sort questions descending by score
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class strategyscore extends preselect_task {
    /**
     * Returns the scale term
     *
     * @param float $testinfo
     * @param float $abilitydifference
     * @return mixed
     */
    abstract protected function get_question_scaleterm(float $testinfo, float $abilitydifference);

    /**
     * Returns the item term
     *
     * @param float $testinfo
     * @param float $fraction
     * @param mixed $difficulty
     * @param mixed $scaleability
     * @param mixed $scalecount
     * @param int $minattemptsperscale
     * @return mixed
     */
    abstract protected function get_question_itemterm(
        float $testinfo,
        float $fraction,
        $difficulty,
        $scaleability,
        $scalecount,
        int $minattemptsperscale
    );

    /**
     * Returns the score for the given question and scaleid
     *
     * @param stdClass $question
     * @param int $scaleid
     * @return mixed
     */
    abstract protected function get_score(stdClass $question, int $scaleid);

    /**
     * Returns the active scales
     *
     * @return array
     */
    protected function get_scales(): array {
        return $this->progress->get_active_scales();
    }

    /**
     * Checks if a scale should be ignored
     *
     * @param int $scaleid
     * @return bool
     */
    protected function ignore_scale(int $scaleid): bool {
        return !$this->progress->is_active_scale($scaleid);
    }

    /**
     * Return the ability for the given scale.
     *
     * @param int $scaleid
     * @return ?float
     */
    protected function get_scale_ability(int $scaleid): ?float {
        if (!array_key_exists($scaleid, $this->progress->get_abilities())) {
            return null;
        }
        return $this->progress->get_abilities()[$scaleid];
    }

    /**
     * @var progress
     */
    protected progress $progress;

    /**
     * Run preselect task.
     *
     * @param array $context
     *
     * @return result
     *
     */
    public function run(array &$context): result {
        $this->context = $context;
        $this->progress = $context['progress'];
        $userresponses = $this->progress->get_user_responses();
        $scalefractions = [];
        $scalecount = [];
        // Issue #162: the exponent of the item term is n_s, the productive questions answered in the scale - pilot
        // questions do not count. The other terms keep the count of all questions played.
        $productivecount = [];

        foreach ($this->get_scales() as $scaleid) {
            $played = $this->progress->get_playedquestions(true, $scaleid);
            $scalecount[$scaleid] = count($played);
            $productivecount[$scaleid] = count(array_filter($played, fn($q) => empty($q->is_pilot)));
            if ($scalecount[$scaleid] === 0) {
                $scalefractions[$scaleid] = 0.5;
            } else {
                $scalefractions[$scaleid] = array_sum(
                    array_map(
                        fn ($q) => $userresponses[$q->id]['fraction'],
                        $played
                    )
                ) / $scalecount[$scaleid];
            }
        }

        // Issue #27/#161: what depends only on the scale - its ability, the test information of the questions
        // played in it, the path from the root - is the same for every candidate. It used to be computed again for
        // each candidate and each of its scales: the item parameters of the scale and its subscales read from the
        // cache, filtered by the played questions and summed up, pool size times depth times. That grew with the
        // square of the pool (60 s per question at 2,420 items, 82 s in the load test). Now once per scale and step,
        // in the same order and with the same functions, so every score stays bit for bit what it was.
        $scalestate = [];
        $ancestry = [];
        foreach ($context['questions'] as $question) {
            $affectedscales = $ancestry[$question->catscaleid]
                ??= array_reverse([$question->catscaleid, ...catscale::get_ancestors($question->catscaleid)]);

            foreach ($affectedscales as $scaleid) {
                if (!array_key_exists($scaleid, $scalestate)) {
                    $scalestate[$scaleid] = $this->scale_state($scaleid);
                }
                if ($scalestate[$scaleid] === null) {
                    continue;
                }
                [$scaleability, $testinfo] = $scalestate[$scaleid];
                $question->processterm = max(0.1, $testinfo) / max(1, $scalecount[$scaleid]);
                $abilitydifference = ($scaleability - $this->progress->get_abilities()[$context['catscaleid']]);
                $question->scaleterm = $this->get_question_scaleterm($testinfo, $abilitydifference);
                $question->itemterm = $this->get_question_itemterm(
                    $testinfo,
                    $scalefractions[$scaleid],
                    $question->difficulty,
                    $scaleability,
                    $productivecount[$scaleid],
                    $context['min_attempts_per_scale']
                );

                $score = $this->get_score($question, $scaleid);
                if (! property_exists($question, 'score') || $score > $question->score) {
                    $question->score = $score;
                }
            }
        }

        // Only the best question is needed (issue #27): it is kept while going through the candidates instead of
        // sorting all of them. The order is the one the sort used - the higher score first, for exactly the same
        // score the lower question id -, and as the sort was stable, of two scores that compare equal without being
        // identical the earlier candidate stays.
        $selectedquestion = false;
        foreach ($context['questions'] as $question) {
            if (!property_exists($question, 'score')) {
                continue;
            }
            if ($selectedquestion === false || self::ranks_before($question, $selectedquestion)) {
                $selectedquestion = $question;
            }
        }

        return result::ok($selectedquestion);
    }

    /**
     * Whether a candidate comes before another in the order of the selection.
     *
     * The higher score first; for exactly the same score the lower question id. The comparison the sort used before
     * issue #27, unchanged.
     *
     * @param stdClass $q1
     * @param stdClass $q2
     * @return bool
     */
    public static function ranks_before(stdClass $q1, stdClass $q2): bool {
        if (!($q2->score === $q1->score)) {
            return ($q2->score <=> $q1->score) < 0;
        }
        return ($q1->id <=> $q2->id) < 0;
    }

    /**
     * What a scale contributes to every candidate in this step: its ability and the test information of the
     * questions played in it, or null when the scale is left out.
     *
     * Computed once per scale and step (issue #27). Depends on the scale, the abilities and the questions played -
     * never on the candidate.
     *
     * @param int $scaleid
     * @return array|null [ability, test information]
     */
    protected function scale_state(int $scaleid): ?array {
        if (
            $this->ignore_scale($scaleid)
            || is_null($scaleability = $this->get_scale_ability($scaleid))
        ) {
            return null;
        }
        $scaleitems = model_item_param_list::get(
            $this->context['contextid'],
            null,
            [$scaleid, ...catscale::get_subscale_ids($scaleid)]
        )
            ->filter_by_componentids(array_keys($this->progress->get_playedquestions()));
        $standarderrorplayed = catscale::get_standarderror($scaleability, $scaleitems, INF);
        $testinfo = $standarderrorplayed === INF ? 0 : 1 / $standarderrorplayed ** 2;
        return [$scaleability, $testinfo];
    }

    /**
     * Helper function for debugging
     *
     * @param int $scaleid
     * @param string $lastquestionlabel Only print if the last question has that label
     * @param stdClass $question The current question
     * @param array $debuglabels Only print output for questions with that label
     * @param float $testinfo Testinfo for the selected question's scale
     * @param float $scaleability Ability for the selected question's scale
     * @param float $standarderror
     * @param float $fraction
     * @param int $minattemptsperscale
     */
    private function print_debug_info(
        int $scaleid,
        string $lastquestionlabel,
        stdClass $question,
        array $debuglabels,
        float $testinfo,
        float $scaleability,
        float $standarderror,
        float $fraction,
        int $minattemptsperscale
    ) {
        $lastq = $this->progress->get_last_question();
        if ($lastq && $lastq->label === $lastquestionlabel) {
            if (in_array($question->label, $debuglabels)) {
                printf(
                    "%d %s: score: %f, testinfo: %f, ability: %f, processterm: %f - scaleterm: %f
                     - itemterm: %f - standarderror: %f - fraction: %f - minattempts: %f\n",
                    $scaleid,
                    $question->label,
                    $question->score,
                    $testinfo,
                    $scaleability,
                    $question->processterm,
                    $question->scaleterm,
                    $question->itemterm,
                    $standarderror,
                    $fraction,
                    $minattemptsperscale
                );
            }
        }
    }
}
