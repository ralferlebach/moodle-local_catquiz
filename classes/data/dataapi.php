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

namespace local_catquiz\data;

use cache;
use context_system;
use local_catquiz\event\catscale_updated;

/**
 * Get and store data from db.
 */
class dataapi {

    /**
     * Get all catscales either from cache or db
     *
     * @return array
     */
    public static function get_all_catscales(): array {
        global $DB;
        $cache = cache::make('local_catquiz', 'catscales');
        $allcatscales = $cache->get('allcatscales');
        if (!$allcatscales) {
            $records = $DB->get_records('local_catquiz_catscales');
            if (!empty($records)) {
                foreach ($records as $record) {
                    $allcatscales[$record->id] = new catscale_structure((array) $record);
                }
            } else {
                $allcatscales = [];
            }
            $cache->set('allcatscales', $allcatscales);
        }
        return $allcatscales;
    }

    /**
     * Save a new catscale and invalidate cache. Checks if name is unique
     *
     * @param catscale_structure $catscale
     * @return int 0 if name already exists
     */
    public static function create_catscale(catscale_structure $catscale): int {
        global $DB;
        if (self::name_exists($catscale->name)) {
            return 0;
        }
        $id = $DB->insert_record('local_catquiz_catscales', $catscale);

        // Invalidate cache. TODO: Instead of invalidating cache, add the item to the cache.
        $cache = cache::make('local_catquiz', 'catscales');
        $cache->delete('allcatscales');
        return $id;
    }

    /**
     * Delete a catscale and invalidate cache.
     *
     * @param catscale_structure $catscale
     * @return bool true
     */
    public static function delete_catscale(int $catscaleid): bool {
        global $DB;
        $allcatscales = self::get_all_catscales();
        $catscaleids = [];
        foreach ($allcatscales as $catscale) {
            $catscaleids[] = $catscale->parentid;
        }
        if (!in_array($catscaleid, $catscaleids)) {
            $result = $DB->delete_records('local_catquiz_catscales', ['id' => $catscaleid]);
        } else {
            throw new moodle_exception('can not delete catscale which has children', 'local_catquiz');
        }

        // Invalidate cache. TODO: Instead of invalidating cache, delete the item from the cache.
        $cache = cache::make('local_catquiz', 'catscales');
        $cache->delete('allcatscales');
        return $result;
    }

    /**
     * Update a catscale and invalidate cache.
     *
     * @param catscale_structure $catscale
     * @return bool
     */
    public static function update_catscale(catscale_structure $catscale): bool {
        global $DB, $USER;
        $result = $DB->update_record('local_catquiz_catscales', $catscale);

        $context = context_system::instance();

        $event = catscale_updated::create([
            'objectid' => $catscale->id,
            'context' => $context,
            'userid' => $USER->id, // The user who did cancel.
        ]);
        $event->trigger();

        // Invalidate cache. TODO: Instead of invalidating cache, delete and add the item from the cache.
        $cache = cache::make('local_catquiz', 'catscales');
        $cache->delete('allcatscales');
        return $result;
    }

    /**
     * Check if name of catscale already exsists - must be unique
     * @param string $name catscale name
     * @return bool true if name already exists, false if not
     */
    public static function name_exists(string $name): bool {
        global $DB;
        if ($DB->record_exists('local_catquiz_catscales', ['name' => $name])) {
            return true;
        } else {
            return false;
        }
    }
}
