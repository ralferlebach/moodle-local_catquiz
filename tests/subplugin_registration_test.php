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
 * The subplugin types of local_catquiz stay declared and discoverable.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use advanced_testcase;
use core_component;

/**
 * Pins that the catmodels and the central hub plugins are registered subplugins.
 *
 * A subplugin is only a subplugin because db/subplugins.json says so. Nothing in the
 * directory layout enforces it: the catmodels would still sit in catmodel/ and still
 * look complete, while Moodle no longer installed them, the classes no longer
 * autoloaded and the strategies quietly found no models.
 *
 * The same declaration is what tells the CI which version.php files are legitimate.
 * A layout guard once counted them and reported the seven catmodels as an error,
 * which is the mirror image of the same confusion.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class subplugin_registration_test extends advanced_testcase {
    /**
     * Returns the declaration as Moodle reads it.
     *
     * @return array
     */
    private function declaration(): array {
        global $CFG;

        $path = $CFG->dirroot . '/local/catquiz/db/subplugins.json';
        $this->assertFileExists($path, 'Without this file there are no subplugins at all.');

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertIsArray($decoded, 'A malformed file silently deregisters every subplugin.');

        return $decoded;
    }

    /**
     * Both subplugin types are declared, with the paths Moodle expects.
     *
     * @return void
     */
    public function test_subplugin_types_are_declared(): void {
        $this->resetAfterTest();

        $declaration = $this->declaration();
        $types = $declaration['plugintypes'] ?? $declaration['subplugintypes'] ?? [];

        $this->assertArrayHasKey('catmodel', $types, 'The IRT models are subplugins.');
        $this->assertArrayHasKey(
            'catquizcentralhub',
            $types,
            'The central hub plugins are subplugins.'
        );

        // The path decides where Moodle looks; a wrong one deregisters silently.
        $this->assertSame('local/catquiz/catmodel', $types['catmodel']);
        $this->assertSame('local/catquiz/catquizcentralhub', $types['catquizcentralhub']);
    }

    /**
     * Every catmodel directory is a complete, correctly named plugin.
     *
     * A directory without version.php is not installed; one whose component name does
     * not match its directory is installed under a name nothing refers to.
     *
     * @return void
     */
    public function test_every_catmodel_is_installable(): void {
        global $CFG;

        $this->resetAfterTest();

        $root = $CFG->dirroot . '/local/catquiz/catmodel';
        $this->assertDirectoryExists($root);

        $found = [];
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $name = $entry->getFilename();
            $found[] = $name;

            $this->assertFileExists(
                $entry->getPathname() . '/version.php',
                "The catmodel $name would not be installed without a version.php."
            );

            $plugin = new \stdClass();
            $component = null;
            include($entry->getPathname() . '/version.php');
            $component = $plugin->component ?? null;

            $this->assertSame(
                'catmodel_' . $name,
                $component,
                "The catmodel $name declares a component that does not match its directory."
            );
        }

        // The seven models the plugin is built around. A missing one would leave the
        // strategies without a model rather than raising an error.
        $this->assertCount(7, $found, 'Expected exactly the seven IRT models.');
        foreach (
            [
            'rasch',
            'raschbirnbaum',
            'mixedraschbirnbaum',
            'grm',
            'grmgeneralized',
            'pcm',
            'pcmgeneralized',
            ] as $model
        ) {
            $this->assertContains($model, $found, "The model $model is missing.");
        }
    }

    /**
     * Moodle actually discovers the catmodels under the declared type.
     *
     * The declaration and the directories could both be right while the type stays
     * unknown to core - this asks core instead of the files.
     *
     * @return void
     */
    public function test_core_discovers_the_catmodels(): void {
        $this->resetAfterTest();

        $plugins = core_component::get_plugin_list('catmodel');

        $this->assertNotEmpty(
            $plugins,
            'Moodle does not know the catmodel type - the models are not registered.'
        );
        $this->assertArrayHasKey('rasch', $plugins);
        $this->assertCount(7, $plugins);
    }

    /**
     * Both central hub plugins are discovered as subplugins.
     *
     * @return void
     */
    public function test_core_discovers_the_hub_plugins(): void {
        $this->resetAfterTest();

        $plugins = core_component::get_plugin_list('catquizcentralhub');

        $this->assertArrayHasKey('client', $plugins, 'The node side is a subplugin.');
        $this->assertArrayHasKey('host', $plugins, 'The hub side is a subplugin.');
    }
    /**
     * Each hub plugin carries its own pipeline, and that pipeline handles the trap.
     *
     * A plain clone of local_catquiz yields catquizcentralhub/host and
     * catquizcentralhub/client as EMPTY directories: the paths exist, the code does
     * not. moodle-plugin-ci then refuses to install the real plugin, because it only
     * checks whether the directory exists - not whether a plugin is in it.
     *
     * @return void
     */
    public function test_each_hub_plugin_has_its_own_ci(): void {
        global $CFG;

        $this->resetAfterTest();

        // Deliberately not tied to .gitmodules: that file is export-ignored, so a
        // released package does not contain it, and whether the directories are
        // submodules or ignored local clones is a working-copy decision. What has to
        // hold either way is that each plugin brings its own pipeline.
        //
        // The plugins have their own repositories, so their pipelines belong there:
        // a workflow in the parent repository never runs when only the subplugin
        // changes, which is most of the time.
        $base = $CFG->dirroot . '/local/catquiz/catquizcentralhub';
        foreach (['host', 'client'] as $part) {
            $workflow = $base . '/' . $part . '/.github/workflows/moodle-subplugin-ci-'
                . $part . '.yml';

            if (!is_dir($base . '/' . $part) || !glob($base . '/' . $part . '/*')) {
                // Checked out without submodule contents - nothing to assert about.
                continue;
            }

            $this->assertFileExists(
                $workflow,
                "catquizcentralhub_$part has no pipeline in its own repository."
            );

            $content = file_get_contents($workflow);

            // The parent plugin is a hard dependency; without it the subplugin cannot
            // even be installed.
            $this->assertStringContainsString(
                'moodle-local_catquiz',
                $content,
                'The parent plugin has to be installed for the subplugin to exist.'
            );

            // Installing the parent brings the empty submodule directories along, and
            // moodle-plugin-ci refuses to write into a directory that already exists.
            $this->assertStringContainsString(
                '-empty',
                $content,
                'Without removing the empty placeholders the install step aborts with '
                    . '"Plugin is already installed in standard Moodle".'
            );
        }
    }

    /**
     * The parent repository does not carry a pipeline for the subplugins.
     *
     * Two pipelines for the same component drift apart, and the one in the parent
     * would run on changes that do not concern it while missing the ones that do.
     *
     * @return void
     */
    public function test_parent_has_no_subplugin_pipeline(): void {
        global $CFG;

        $this->resetAfterTest();

        $this->assertFileDoesNotExist(
            $CFG->dirroot . '/local/catquiz/.github/workflows/catquizcentralhub.yml',
            'The subplugin pipelines live in the subplugin repositories.'
        );
    }
    /**
     * Every workflow that installs the hub plugins clears the placeholders first.
     *
     * The plugins are attached as submodules, so a checkout leaves
     * catquizcentralhub/host and catquizcentralhub/client behind as empty directories.
     * moodle-plugin-ci checks is_dir() rather than whether a plugin is present, so it
     * aborts the install with "Plugin is already installed in standard Moodle".
     *
     * The fix belongs in every workflow that installs them - it was first applied to
     * the subplugin pipelines only, and the parent ones kept failing.
     *
     * @return void
     */
    public function test_workflows_clear_the_submodule_placeholders(): void {
        global $CFG;

        $this->resetAfterTest();

        $workflows = glob($CFG->dirroot . '/local/catquiz/.github/workflows/*.yml');
        $this->assertNotEmpty($workflows);

        $unprotected = [];
        foreach ($workflows as $workflow) {
            $content = file_get_contents($workflow);

            // Only workflows that install the hub plugins are affected.
            if (!str_contains($content, 'add-plugin ralferlebach/moodle-catquizcentralhub')) {
                continue;
            }

            // The removal has to be restricted to empty directories: a checkout that
            // does contain the plugins must not have them deleted.
            if (!str_contains($content, '-type d -empty')) {
                $unprotected[] = basename($workflow);
            }
        }

        $this->assertSame(
            [],
            $unprotected,
            'These workflows install the hub plugins but leave the empty submodule '
                . 'placeholders in place, which aborts the install step.'
        );
    }
    /**
     * Both hub plugins pin the parent version they were built against.
     *
     * They are subplugins of local_catquiz and use its classes directly. Without the
     * pin Moodle installs them against any version of the parent, including one that
     * predates the interfaces they rely on - and the failure then appears at run time
     * instead of at install time.
     *
     * The pin also has to be maintained: one left at an old version stops protecting
     * anything, quietly. This test fails once the parent has moved on, which is the
     * reminder to raise it deliberately.
     *
     * @return void
     */
    public function test_hub_plugins_pin_the_parent_version(): void {
        global $CFG;

        $this->resetAfterTest();

        $parent = new \stdClass();
        $plugin = $parent;
        include($CFG->dirroot . '/local/catquiz/version.php');
        $parentversion = (int) $plugin->version;

        $base = $CFG->dirroot . '/local/catquiz/catquizcentralhub';

        foreach (['host', 'client'] as $part) {
            $file = $base . '/' . $part . '/version.php';

            if (!file_exists($file)) {
                // Checked out without submodule contents - nothing to assert about.
                continue;
            }

            $plugin = new \stdClass();
            include($file);

            $this->assertNotEmpty(
                $plugin->dependencies['local_catquiz'] ?? null,
                "catquizcentralhub_$part must declare the parent it was built against."
            );

            $this->assertSame(
                $parentversion,
                (int) $plugin->dependencies['local_catquiz'],
                "The pin in catquizcentralhub_$part is behind the parent version. "
                    . 'Raise it together with the parent, or it stops protecting anything.'
            );
        }
    }
}
