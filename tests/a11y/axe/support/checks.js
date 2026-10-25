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
 * The checks of the accessibility suite (issues #30, #32): axe-core, ids, Bootstrap 4 leftovers, theme.
 *
 * Every scan is attached to the test and appended to axe-summary.jsonl in the export directory, so a
 * green run documents what was checked, page by page and project by project.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const fs = require('node:fs');
const path = require('node:path');
const AxeBuilder = require('@axe-core/playwright').default;

/** The rule sets the plugin promises: WCAG 2.1 level A and AA. */
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * axe on a part of the page. The violations are attached and summarised, so a failure can be read,
 * not guessed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').TestInfo} testInfo
 * @param {string} name
 * @param {string|null} include CSS selector of the part, null for the page.
 * @returns {Promise<string[]>} The violations, one line each.
 */
const scan = async(page, testInfo, name, include) => {
    let builder = new AxeBuilder({page}).withTags(WCAG);
    if (include) {
        builder = builder.include(include);
    }
    const result = await builder.analyze();
    await testInfo.attach('axe-' + name + '.json', {
        body: JSON.stringify(result.violations, null, 2),
        contentType: 'application/json',
    });
    const lines = result.violations.map((v) => v.id + ' (' + v.impact + '): ' +
        v.nodes.map((n) => n.target.join(' ')).join(', '));
    const dir = process.env.A11Y_EXPORT_DIR || testInfo.project.outputDir;
    fs.mkdirSync(dir, {recursive: true});
    fs.appendFileSync(path.join(dir, 'axe-summary.jsonl'), JSON.stringify({
        project: testInfo.project.name, page: name, scope: include || 'page',
        violations: result.violations.length, nodes: result.violations.reduce((n, v) => n + v.nodes.length, 0),
        passes: result.passes.length, rules: result.violations.map((v) => v.id),
    }) + '\n');
    return lines;
};

/**
 * The ids used more than once on the page.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string[]>}
 */
const duplicateIds = (page) => page.evaluate(() => {
    const seen = new Map();
    document.querySelectorAll('[id]').forEach((el) => {
        if (el.id) {
            seen.set(el.id, (seen.get(el.id) || 0) + 1);
        }
    });
    return [...seen].filter(([, n]) => n > 1).map(([id, n]) => id + ' x' + n);
});

/** Markup of local_wunderbyte_table: a dependency with its own Bootstrap 4 leftovers, reported apart. */
const DEPENDENCY = '[class*="wunderbyte_table_container"]';

/**
 * Bootstrap 4 markup Moodle 5 no longer understands, in a part of the page: what CATquiz renders
 * itself, and apart from it what the table plugin it depends on renders (Moodle 5's Bootstrap 4
 * compatibility layer still maps those; they are attached and summarised, not failed).
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').TestInfo} testInfo
 * @param {string} name
 * @param {string} scope
 * @returns {Promise<string[]>} The leftovers of CATquiz's own markup.
 */
const bootstrap4Leftovers = async(page, testInfo, name, scope = '#region-main') => {
    const found = await page.evaluate(([scope, dependency]) => {
        const root = document.querySelector(scope) || document.body;
        const own = [];
        const external = [];
        for (const selector of ['[data-toggle]', '[data-dismiss]', '[data-target]', '[data-parent]', '.sr-only']) {
            root.querySelectorAll(selector).forEach((el) => {
                const what = selector + ' on ' + el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
                    (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).join('.') : '');
                (el.closest(dependency) ? external : own).push(what);
            });
        }
        return {own, external};
    }, [scope, DEPENDENCY]);
    if (found.external.length) {
        await testInfo.attach('bs4-dependency-' + name + '.json', {
            body: JSON.stringify(found.external, null, 2), contentType: 'application/json',
        });
    }
    const dir = process.env.A11Y_EXPORT_DIR || testInfo.project.outputDir;
    fs.mkdirSync(dir, {recursive: true});
    fs.appendFileSync(path.join(dir, 'bs4-summary.jsonl'), JSON.stringify({
        project: testInfo.project.name, page: name, own: found.own.length, dependency: found.external.length,
    }) + '\n');
    return found.own;
};

/**
 * The theme of the project: "dark" puts Bootstrap's dark colour mode on every page it opens.
 *
 * Moodle 5.1 has no user preference for it yet; data-bs-theme on the root element is what a dark
 * theme sets, and what the colours of the plugin have to follow.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').TestInfo} testInfo
 */
const applyTheme = async(page, testInfo) => {
    if (testInfo.project.metadata?.theme === 'dark') {
        await page.addInitScript(() => {
            document.documentElement.setAttribute('data-bs-theme', 'dark');
        });
    }
};

/**
 * Whether the focus is inside an element.
 *
 * @param {import('@playwright/test').Locator} locator
 * @returns {Promise<boolean>}
 */
const holdsFocus = (locator) => locator.evaluate((el) => el.contains(document.activeElement));

module.exports = {scan, duplicateIds, bootstrap4Leftovers, applyTheme, holdsFocus, WCAG};
