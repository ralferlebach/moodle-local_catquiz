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
 * Regression test for the per-model item parameter contract.
 *
 * A 2PL item with discrimination 0 is mathematically mute: P(theta) = 0.5 for
 * every ability, score and Fisher information are exactly 0, so the ability
 * estimate stops moving. Such parameters must never be played productively; the
 * item has to fall back to being a pilot item.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\model\model_strategy::validate_item_parameters
 * @covers \local_catquiz\teststrategy\context\loader\pilotquestions_loader::ispilot
 */

namespace local_catquiz\local\model;

use advanced_testcase;
use local_catquiz\teststrategy\context\loader\pilotquestions_loader;
use stdClass;

/**
 * Guards the per-model item parameter contract.
 *
 * @package    local_catquiz
 */
final class item_parameter_contract_test extends advanced_testcase {
    /**
     * Builds a raw item parameter record.
     *
     * @param string $model
     * @param mixed $difficulty
     * @param mixed $discrimination
     * @param mixed $guessing
     * @return stdClass
     */
    private function record(string $model, $difficulty, $discrimination = null, $guessing = null): stdClass {
        $r = new stdClass();
        $r->id = 1;
        $r->model = $model;
        $r->difficulty = $difficulty;
        $r->discrimination = $discrimination;
        $r->guessing = $guessing;
        return $r;
    }

    /**
     * Valid parameters must pass for every model.
     *
     * @return void
     */
    public function test_valid_parameters_pass(): void {
        $this->resetAfterTest(true);

        // Negative, zero and positive difficulties are all legal.
        $this->assertSame([], model_strategy::validate_item_parameters($this->record('rasch', -3.13)));
        $this->assertSame([], model_strategy::validate_item_parameters($this->record('rasch', 0.0)));
        $this->assertSame([], model_strategy::validate_item_parameters($this->record('raschbirnbaum', 0.0, 1.96)));
        $this->assertSame([], model_strategy::validate_item_parameters($this->record('raschbirnbaum', -1.88, 5.0)));
        $this->assertSame(
            [],
            model_strategy::validate_item_parameters($this->record('mixedraschbirnbaum', 0.14, 2.04, 0.25))
        );
    }

    /**
     * A 2PL/3PL discrimination of zero or less must be rejected.
     *
     * @return void
     */
    public function test_nonpositive_discrimination_is_rejected(): void {
        $this->resetAfterTest(true);

        $this->assertNotEmpty(model_strategy::validate_item_parameters($this->record('raschbirnbaum', -5.0, 0.0)));
        $this->assertNotEmpty(model_strategy::validate_item_parameters($this->record('raschbirnbaum', 2.0, -1.0)));
        $this->assertNotEmpty(model_strategy::validate_item_parameters($this->record('mixedraschbirnbaum', 0.5, 0.0, 0.2)));
        // The 1PL model does not use a discrimination, so a stored 0 is harmless.
        $this->assertSame([], model_strategy::validate_item_parameters($this->record('rasch', -5.0, 0.0)));
    }

    /**
     * The 3PL guessing parameter must lie in [0, 1).
     *
     * @return void
     */
    public function test_guessing_range_is_enforced(): void {
        $this->resetAfterTest(true);

        $this->assertNotEmpty(model_strategy::validate_item_parameters(
            $this->record('mixedraschbirnbaum', 0.5, 1.5, -0.1)
        ));
        $this->assertNotEmpty(model_strategy::validate_item_parameters(
            $this->record('mixedraschbirnbaum', 0.5, 1.5, 1.0)
        ));
        $this->assertSame([], model_strategy::validate_item_parameters(
            $this->record('mixedraschbirnbaum', 0.5, 1.5, 0.0)
        ));
    }

    /**
     * A missing or unknown model means there is nothing calibrated.
     *
     * @return void
     */
    public function test_missing_or_unknown_model_is_invalid(): void {
        $this->resetAfterTest(true);

        $this->assertNotEmpty(model_strategy::validate_item_parameters($this->record('', 0.5)));
        $this->assertNotEmpty(model_strategy::validate_item_parameters($this->record('doesnotexist', 0.5)));
    }

    /**
     * An item whose parameters violate its model contract becomes a pilot item,
     * even when it is flagged as calibrated.
     *
     * @return void
     */
    public function test_item_with_invalid_parameters_is_treated_as_pilot(): void {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/local/catquiz/lib.php');

        $loader = new pilotquestions_loader();

        // Mute 2PL item (discrimination 0) although it claims to be calibrated.
        $mute = $this->record('raschbirnbaum', -5.0, 0.0);
        $mute->status = \LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY;
        $mute->attempts = 100;
        $this->assertTrue($loader->ispilot($mute, 30));

        // The same item with a usable discrimination is productive.
        $usable = $this->record('raschbirnbaum', -5.0, 1.5);
        $usable->status = \LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY;
        $usable->attempts = 100;
        $this->assertFalse($loader->ispilot($usable, 30));

        // A 1PL item with a stored discrimination of 0 stays productive.
        $rasch = $this->record('rasch', -5.0, 0.0);
        $rasch->status = \LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY;
        $rasch->attempts = 100;
        $this->assertFalse($loader->ispilot($rasch, 30));
    }

    /**
     * When an item carries parameters for several models, the one with the HIGHEST
     * status must become the active parameter - not the least calibrated one.
     *
     * @return void
     */
    public function test_active_itemparam_picks_highest_status(): void {
        global $DB, $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/local/catquiz/lib.php');

        $itemid = $DB->insert_record('local_catquiz_items', (object) [
            'componentid' => 1,
            'componentname' => 'question',
            'catscaleid' => 1,
            'contextid' => 1,
            'status' => 0,
        ]);

        $stale = $DB->insert_record('local_catquiz_itemparams', (object) [
            'itemid' => $itemid,
            'componentid' => 1,
            'componentname' => 'question',
            'contextid' => 1,
            'model' => 'rasch',
            'difficulty' => 0.0,
            'discrimination' => 0.0,
            'status' => \LOCAL_CATQUIZ_STATUS_NOT_CALCULATED,
        ]);
        $calibrated = $DB->insert_record('local_catquiz_itemparams', (object) [
            'itemid' => $itemid,
            'componentid' => 1,
            'componentname' => 'question',
            'contextid' => 1,
            'model' => 'raschbirnbaum',
            'difficulty' => -1.88,
            'discrimination' => 5.0,
            'status' => \LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY,
        ]);

        \local_catquiz\catquiz::set_active_itemparam($itemid);

        $active = $DB->get_field('local_catquiz_items', 'activeparamid', ['id' => $itemid]);
        $this->assertEquals(
            $calibrated,
            $active,
            'The calibrated parameter must win over the uncalibrated one.'
        );
        $this->assertNotEquals($stale, $active);
    }
}
