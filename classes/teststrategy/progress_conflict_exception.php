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

namespace local_catquiz\teststrategy;

/**
 * A save found the working state stored by somebody else in the meantime (issue #177, AK3).
 *
 * The working state of a request is that of its session cache. When another session went on with the same attempt -
 * a second device, a second browser - the stored state is ahead of it. Before #177 the save overwrote it with the older
 * state of this request: an update of the other session was lost. Now nothing is written, the cached state is dropped,
 * and the next request continues from what is stored.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_conflict_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param int $catattemptid local_catquiz_attempts.id
     * @param int $expected The last event this request knew.
     * @param int $stored The last event stored.
     */
    public function __construct(int $catattemptid, int $expected, int $stored) {
        parent::__construct(
            'progressconflict',
            'local_catquiz',
            '',
            null,
            "CAT attempt $catattemptid: expected $expected, stored $stored"
        );
    }
}
