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
 * Every capability the code checks has to be declared and named.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use advanced_testcase;

/**
 * Guards the consistency of capability checks and declarations.
 *
 * An undeclared capability does not raise an error: has_capability() simply returns
 * false, for every user including the administrator. Three attempt web services
 * checked local/catquiz:canaccess while it was never declared, which denied every
 * request without anything in the code looking wrong.
 *
 * This test compares the two sides mechanically, so the next such omission is caught
 * where it happens rather than by someone wondering why an endpoint refuses to work.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class capability_declaration_test extends advanced_testcase {
    /**
     * Returns the capabilities the plugin code checks.
     *
     * @return string[]
     */
    private function capabilities_in_code(): array {
        global $CFG;

        $root = $CFG->dirroot . '/local/catquiz';
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/classes')
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all(
                "/(?:has_capability|require_capability)\(\s*'(local\/catquiz:[a-z_]+)'/i",
                file_get_contents($file->getPathname()),
                $matches
            );
            foreach ($matches[1] as $capability) {
                $found[$capability] = $capability;
            }
        }

        return array_values($found);
    }

    /**
     * Returns the capabilities declared in db/access.php.
     *
     * @return string[]
     */
    private function capabilities_declared(): array {
        global $CFG;

        $capabilities = [];
        require($CFG->dirroot . '/local/catquiz/db/access.php');

        return array_keys($capabilities);
    }

    /**
     * Nothing is checked that was never declared.
     *
     * @return void
     */
    public function test_every_checked_capability_is_declared(): void {
        $this->resetAfterTest();

        $checked = $this->capabilities_in_code();
        $declared = $this->capabilities_declared();

        $this->assertNotEmpty($checked, 'The scan must find capability checks at all.');

        $missing = array_diff($checked, $declared);
        $this->assertSame(
            [],
            array_values($missing),
            'Checked but not declared: an undeclared capability denies everyone, silently.'
        );
    }

    /**
     * Every declared capability has a name shown in the roles interface.
     *
     * @return void
     */
    public function test_every_declared_capability_has_a_string(): void {
        $this->resetAfterTest();

        $missing = [];
        foreach ($this->capabilities_declared() as $capability) {
            $identifier = str_replace('local/', '', $capability);
            if (!get_string_manager()->string_exists($identifier, 'local_catquiz')) {
                $missing[] = $identifier;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Without a string the roles interface shows the raw identifier.'
        );
    }
    /**
     * Every external service checks a capability.
     *
     * Review finding: four endpoints acted without any check - among them one that
     * toggles the status of test items. Being logged in is not authorisation, and an
     * endpoint that skips the check looks no different from one that does not need
     * it. Comparing them mechanically removes that ambiguity.
     *
     * @return void
     */
    public function test_every_external_service_checks_a_capability(): void {
        global $CFG;

        $this->resetAfterTest();

        $directory = $CFG->dirroot . '/local/catquiz/classes/external';
        $unguarded = [];

        foreach (glob($directory . '/*.php') as $file) {
            $source = file_get_contents($file);
            if (!preg_match('/function execute\s*\(/', $source)) {
                continue;
            }
            if (!preg_match('/(require_capability|has_capability)\s*\(/', $source)) {
                $unguarded[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'External services without a capability check are open to any logged-in user.'
        );
    }
}
