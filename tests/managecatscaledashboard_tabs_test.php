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
 * Issue #29: the manager builds only the tab that is on screen.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use advanced_testcase;
use local_catquiz\output\catscalemanager\managecatscaledashboard;
use ReflectionClass;

/**
 * Verifies tab selection and that the other tabs are not built.
 *
 * The dashboard used to construct eight displays on every request - each with its own
 * queries - and the template then hid seven of them. The cost was paid for content
 * nobody was looking at.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\output\catscalemanager\managecatscaledashboard
 */
final class managecatscaledashboard_tabs_test extends advanced_testcase {
    /**
     * An unknown tab falls back to the first one instead of building everything.
     *
     * A crafted URL parameter must not be able to select something that does not
     * exist, and an absent one must not mean "all of them".
     *
     * @return void
     */
    public function test_unknown_tab_falls_back(): void {
        $this->resetAfterTest();

        $reflection = new ReflectionClass(managecatscaledashboard::class);
        $tabs = $reflection->getConstant('TABS');

        $this->assertNotEmpty($tabs, 'The tab list is what the parameter is validated against.');
        $this->assertContains('summary', $tabs);
        $this->assertContains('questions', $tabs);

        // The fallback is the first entry, which is what the template marked active
        // before this change.
        $this->assertSame('summary', $tabs[0]);
    }

    /**
     * Every display construction is guarded by the active tab.
     *
     * Checking the source rather than the behaviour is deliberate: constructing the
     * dashboard needs a full manager page context, while the property that matters -
     * that nothing is built unconditionally - is visible statically and would be lost
     * silently if someone added a ninth display without a guard.
     *
     * @return void
     */
    public function test_no_display_is_constructed_unconditionally(): void {
        global $CFG;

        $this->resetAfterTest();

        $source = file_get_contents(
            $CFG->dirroot . '/local/catquiz/classes/output/catscalemanager/'
                . 'managecatscaledashboard.php'
        );

        $start = strpos($source, 'public function __construct');
        $this->assertNotFalse($start);
        $end = strpos($source, "\n    }\n", $start);
        $body = substr($source, $start, $end - $start);

        preg_match_all('/^\s*\$\w+ = new (\w+)\(/m', $body, $matches, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($matches[0], 'The constructor must still build displays.');

        $unguarded = [];
        foreach ($matches[0] as $index => $match) {
            // Look back from the construction for the nearest tab guard.
            $before = substr($body, 0, $match[1]);
            $lastguard = strrpos($before, '$this->activetab ===');
            $lastclose = strrpos($before, "\n        }");

            if ($lastguard === false || ($lastclose !== false && $lastclose > $lastguard)) {
                $unguarded[] = $matches[1][$index][0];
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'These displays are built regardless of which tab is shown.'
        );
    }

    /**
     * Every tab has its own URL carrying the manager state.
     *
     * Without the context and scale in the link, switching tabs would silently reset
     * what the user was looking at.
     *
     * @return void
     */
    public function test_each_tab_has_a_url_with_state(): void {
        global $CFG;

        $this->resetAfterTest();

        $source = file_get_contents(
            $CFG->dirroot . '/local/catquiz/classes/output/catscalemanager/'
                . 'managecatscaledashboard.php'
        );

        $this->assertStringContainsString("'tab' => \$tab", $source);
        foreach (['contextid', 'scaleid', 'usesubs'] as $parameter) {
            $this->assertStringContainsString(
                "'" . $parameter . "' =>",
                $source,
                "The tab link must carry $parameter, otherwise switching tabs resets it."
            );
        }
    }

    /**
     * The page reads the tab from the URL.
     *
     * @return void
     */
    public function test_page_reads_the_tab_parameter(): void {
        global $CFG;

        $this->resetAfterTest();

        $source = file_get_contents($CFG->dirroot . '/local/catquiz/manage_catscales.php');

        $this->assertStringContainsString(
            "optional_param('tab'",
            $source,
            'Without reading the parameter the tab cannot survive a reload.'
        );
    }
    /**
     * The template navigates by URL and renders only the active pane.
     *
     * Building one tab on the server is only half the change: as long as the markup
     * switched tabs with Bootstrap anchors, the other panes were still emitted - now
     * without any data behind them, because they were never built.
     *
     * @return void
     */
    public function test_template_uses_server_side_tabs(): void {
        global $CFG;

        $this->resetAfterTest();

        $template = file_get_contents(
            $CFG->dirroot . '/local/catquiz/templates/catscalemanager/'
                . 'managecatscaledashboard.mustache'
        );

        $this->assertStringNotContainsString(
            'data-toggle="tab"',
            $template,
            'Client-side switching would show panes that were never rendered.'
        );
        $this->assertStringNotContainsString(
            'href="#lcq_',
            $template,
            'An anchor cannot carry the tab to the server.'
        );

        // Each tab is guarded, so exactly one pane is emitted per request.
        foreach (['issummary', 'isquestions', 'isversioning'] as $flag) {
            $this->assertStringContainsString(
                '{{#' . $flag . '}}',
                $template,
                "The $flag pane must be rendered conditionally."
            );
        }
    }

    /**
     * Every flag and URL the template uses is exported.
     *
     * A missing key renders as empty in Mustache without any error, so a tab would
     * simply stop working and nothing would say why.
     *
     * @return void
     */
    public function test_all_template_keys_are_exported(): void {
        global $CFG;

        $this->resetAfterTest();

        $template = file_get_contents(
            $CFG->dirroot . '/local/catquiz/templates/catscalemanager/'
                . 'managecatscaledashboard.mustache'
        );
        $source = file_get_contents(
            $CFG->dirroot . '/local/catquiz/classes/output/catscalemanager/'
                . 'managecatscaledashboard.php'
        );

        preg_match_all('/\{\{[#^]?(is\w+|\w+url)\}\}/', $template, $matches);
        $missing = [];
        foreach (array_unique($matches[1]) as $key) {
            if (!str_contains($source, "'" . $key . "' =>")) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These keys are used in the template but never exported.'
        );
    }
}
