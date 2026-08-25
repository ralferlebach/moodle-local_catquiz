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
 * Temporary diagnostic tracing (Behat only) for the resume/reload triage.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\local;

/**
 * Temporary diagnostic tracing (Behat only) for the resume/reload triage.
 *
 * This is a throwaway helper used to capture the played-question bookkeeping
 * across a resume/reload in a CI Behat run. It writes NOTHING unless the Behat
 * test site is running, so it is inert in production and in normal PHPUnit runs.
 * Remove once the resume issue is fixed.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debugtrace {
    /**
     * Appends one trace line to a temp file, but only while Behat runs.
     *
     * @param string $msg
     * @return void
     */
    public static function resume(string $msg): void {
        if (!defined('BEHAT_SITE_RUNNING') || !BEHAT_SITE_RUNNING) {
            return;
        }
        $file = sys_get_temp_dir() . '/catquiz_resume_trace.log';
        @file_put_contents($file, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    }
}
