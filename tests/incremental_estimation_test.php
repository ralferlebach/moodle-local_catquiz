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

namespace local_catquiz;

use advanced_testcase;
use local_catquiz\local\model\model_person_param;
use local_catquiz\local\model\model_person_param_list;
use local_catquiz\local\model\model_responses;
use local_catquiz\local\model\model_strategy;

/**
 * Integration test for the incremental one-pass estimation (issue #43).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\model\model_strategy::run_incremental_estimation
 */
final class incremental_estimation_test extends advanced_testcase {
    /**
     * Build a small in-memory response set with fixed person abilities.
     *
     * @return model_responses
     */
    private function build_responses(): model_responses {
        $responses = new model_responses();
        // Four persons, two dichotomous items, a spread of correct/incorrect answers.
        $data = [
            ['P1', 'I1', 1.0], ['P1', 'I2', 0.0],
            ['P2', 'I1', 1.0], ['P2', 'I2', 1.0],
            ['P3', 'I1', 0.0], ['P3', 'I2', 0.0],
            ['P4', 'I1', 0.0], ['P4', 'I2', 1.0],
        ];
        foreach ($data as [$person, $item, $fraction]) {
            $responses->set($person, $item, $fraction);
        }
        $pplist = new model_person_param_list();
        $abilities = ['P1' => 0.5, 'P2' => 1.5, 'P3' => -1.5, 'P4' => -0.5];
        foreach ($abilities as $userid => $ability) {
            $pp = new model_person_param($userid, 1);
            $pp->set_ability($ability);
            $pplist->add($pp);
        }
        $responses->set_person_abilities($pplist);
        return $responses;
    }

    /**
     * The incremental pass runs exactly one iteration and never re-estimates PP.
     *
     * @return void
     */
    public function test_incremental_pass_keeps_person_abilities_and_runs_once(): void {
        $this->resetAfterTest(true);
        set_config('trusted_region_min_b', -10.0, 'local_catquiz');
        set_config('trusted_region_max_b', 10.0, 'local_catquiz');

        $responses = $this->build_responses();
        $before = [];
        foreach ($responses->get_person_abilities() as $pp) {
            $before[$pp->get_userid()] = $pp->get_ability();
        }

        $strategy = new model_strategy($responses, ['max_iterations' => 5]);
        [$itemdifficulties, $personabilities] = $strategy->run_incremental_estimation();

        // Exactly one item-parameter pass.
        $this->assertSame(1, $strategy->get_iterations());

        // Person abilities are returned unchanged (fixed PP).
        $after = [];
        foreach ($personabilities as $pp) {
            $after[$pp->get_userid()] = $pp->get_ability();
        }
        $this->assertEquals($before, $after);

        // Item parameters were produced.
        $this->assertNotEmpty($itemdifficulties);
    }

    /**
     * The disruptive loop seeds via an initial 1PL step and reports a stop reason.
     *
     * @return void
     */
    public function test_disruptive_estimation_bootstraps_and_reports_convergence(): void {
        $this->resetAfterTest(true);
        set_config('trusted_region_min_b', -10.0, 'local_catquiz');
        set_config('trusted_region_max_b', 10.0, 'local_catquiz');

        $responses = $this->build_responses();
        // No old item parameters are passed -> the initial 1PL/Rasch step must run.
        $strategy = new model_strategy($responses, ['max_iterations' => 3]);
        [$itemdifficulties, $personabilities] = $strategy->run_disruptive_estimation();

        // Explicit initial 1PL/Rasch bootstrap was used (no start parameters existed).
        $this->assertTrue($strategy->used_initial_rasch());

        // A definite stop reason was recorded (either convergence or the iteration limit).
        $reason = $strategy->get_convergence_reason();
        $this->assertContains($reason, ['no further improvement', 'maximum iterations reached']);

        // The iteration count never exceeds the configured maximum.
        $this->assertLessThanOrEqual(3, $strategy->get_iterations());
        $this->assertGreaterThanOrEqual(1, $strategy->get_iterations());
        $this->assertNotEmpty($itemdifficulties);
        $this->assertNotEmpty($personabilities);
    }
}
