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
use xmldb_field;
use xmldb_table;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/local/catquiz/db/upgrade.php');

/**
 * The upgrade to 2026100913 adds the frozen range and invents none for older results (issue #137).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_catquiz_upgrade
 */
final class upgrade_range_snapshot_test extends advanced_testcase {
    /**
     * From a site before 2026100913: the columns come, older rows stay without a range, and the step can
     * run again.
     */
    public function test_upgrade_adds_the_range_and_reconstructs_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_catquiz_personparams');

        // A result finalised before the upgrade, on a scale whose range is known today.
        $scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'name' => 'Range', 'parentid' => 0, 'minscalevalue' => -3, 'maxscalevalue' => 3,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $rowid = (int) $DB->insert_record('local_catquiz_personparams', (object) [
            'userid' => 2, 'catscaleid' => $scaleid, 'contextid' => 1, 'attemptid' => 5, 'ability' => 0.5,
            'isvalid' => 1, 'resultsource' => 'current', 'status' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        // The schema of 2026100912.
        foreach (['rangeupper', 'rangelower'] as $name) {
            $dbman->drop_field($table, new xmldb_field($name));
        }
        $this->assertFalse($dbman->field_exists($table, 'rangelower'));
        set_config('version', 2026100912, 'local_catquiz');

        $this->assertTrue(xmldb_local_catquiz_upgrade(2026100912));

        $this->assertTrue($dbman->field_exists($table, 'rangelower'));
        $this->assertTrue($dbman->field_exists($table, 'rangeupper'));
        $row = $DB->get_record('local_catquiz_personparams', ['id' => $rowid]);
        $this->assertNull($row->rangelower, 'The range of an older result is not taken from today\'s scale.');
        $this->assertNull($row->rangeupper);
        $this->assertSame('2026100913', (string) get_config('local_catquiz', 'version'));

        // Run again on a site where the columns exist: nothing breaks.
        set_config('version', 2026100912, 'local_catquiz');
        $this->assertTrue(xmldb_local_catquiz_upgrade(2026100912));
    }
}
