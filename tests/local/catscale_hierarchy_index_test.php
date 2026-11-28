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

namespace local_catquiz\local;

use advanced_testcase;
use cache;
use local_catquiz\catscale;
use local_catquiz\data\dataapi;

/**
 * The index of the scale hierarchy (#169) is not deleted when the list of scales is rebuilt, and still follows the list.
 *
 * CI 27.11.2026 (1.2, PHPUnit PHP 8.3 PostgreSQL, runtime_pool_test "concurrent attempts of several persons"): a request
 * rebuilding the list deleted the index while another request read it, and the file store warned "Failed to open
 * stream". The index now carries the fingerprint of the list it was built from.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_catquiz\catscale::hierarchy
 * @covers \local_catquiz\data\dataapi::get_all_catscales
 */
final class catscale_hierarchy_index_test extends advanced_testcase {
    /**
     * A scale written directly, without the event that purges the caches.
     *
     * @param int $parentid
     * @return int
     */
    private function scale(int $parentid): int {
        global $DB;
        return (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => $parentid, 'name' => 'scale ' . random_string(6), 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Rebuilding the list keeps the index; a list that differs rebuilds it.
     * RED before: the rebuild of the list deleted the index.
     */
    public function test_the_index_is_kept_on_a_rebuild_and_follows_the_list(): void {
        $this->resetAfterTest();
        $root = $this->scale(0);
        $child = $this->scale($root);
        $cache = cache::make('local_catquiz', 'catscales');
        $cache->purge();

        $this->assertSame([$child], catscale::hierarchy()['children'][$root]);
        $this->assertNotFalse($cache->get('hierarchy'));

        // The list of scales is rebuilt (its entry was evicted): the index stays where it is.
        $cache->delete('allcatscales');
        dataapi::get_all_catscales();
        $this->assertNotFalse($cache->get('hierarchy'), 'A rebuild of the list does not delete the index.');
        $this->assertSame([$child], catscale::hierarchy()['children'][$root]);

        // A scale added without the event: once the list is rebuilt, the index follows it.
        $second = $this->scale($root);
        $cache->delete('allcatscales');
        $this->assertSame([$child, $second], catscale::hierarchy()['children'][$root], 'The index follows the list.');
        $this->assertSame($root, catscale::hierarchy()['parent'][$second]);
        $this->assertArrayNotHasKey('of', catscale::hierarchy(), 'The fingerprint is not part of the index.');
    }
}
