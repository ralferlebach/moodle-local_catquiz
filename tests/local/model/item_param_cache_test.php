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

namespace local_catquiz\local\model;

use advanced_testcase;

/**
 * The cache of the item parameter lists has no static acceleration (issue #161).
 *
 * A selection step reads the lists of several scales in turn. With an acceleration of size 1, each read pushed the
 * previous list out and serialized the new one into the acceleration again - 18 % of a follow-up question at 2,420
 * items, without a hit to show for it. The list a caller gets must still be its own copy: callers filter it in place
 * (filter_for_responses()).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\model\model_item_param_list
 */
final class item_param_cache_test extends advanced_testcase {
    /**
     * The definition: application cache, no static acceleration.
     */
    public function test_definition_has_no_static_acceleration(): void {
        $definition = \cache_config::instance()->get_definition_by_id('local_catquiz/catquiz_item_params');
        $this->assertNotEmpty($definition);
        $this->assertEmpty($definition['staticacceleration'] ?? false);
    }

    /**
     * Two reads of the same list give two lists: filtering one in place leaves the other and the cache whole.
     */
    public function test_each_read_is_a_copy(): void {
        global $DB;
        $this->resetAfterTest();
        $scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => 0, 'name' => 's', 'label' => 's', 'contextid' => 1, 'minscalevalue' => -5,
            'maxscalevalue' => 5, 'timecreated' => 0, 'timemodified' => 0,
        ]);
        foreach ([801, 802, 803] as $questionid) {
            $itemid = (int) $DB->insert_record('local_catquiz_items', (object) [
                'componentname' => 'question', 'componentid' => $questionid, 'catscaleid' => $scaleid,
                'contextid' => 1, 'lastupdated' => 0, 'status' => 0,
            ]);
            $paramid = (int) $DB->insert_record('local_catquiz_itemparams', (object) [
                'itemid' => $itemid, 'componentid' => $questionid, 'componentname' => 'question', 'contextid' => 1,
                'model' => 'raschbirnbaum', 'difficulty' => 0.5, 'discrimination' => 1.2, 'guessing' => 0,
                'json' => '{}', 'status' => 4, 'timecreated' => 0, 'timemodified' => 0,
            ]);
            $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);
        }
        \cache_helper::purge_all();

        $first = model_item_param_list::get(1, null, [$scaleid]);
        $second = model_item_param_list::get(1, null, [$scaleid]);
        $this->assertNotSame($first, $second);
        $first->filter_for_responses(model_responses::create_from_array([1 => ['component' => [801 => ['fraction' => 1]]]]));
        $this->assertCount(1, $first);
        $this->assertCount(3, $second);
        $this->assertCount(3, model_item_param_list::get(1, null, [$scaleid]));
    }
}
