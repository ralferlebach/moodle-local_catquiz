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
 * A11Y-CQ-02 - the statistics shortcode twice on one page (issue #30).
 *
 * Each instance has ids of its own: a tab of the second opens its own panel and leaves the first as
 * it was. The tabs follow the ARIA tabs pattern with manual activation, as Moodle implements it - the
 * arrow keys move the focus between the tabs of one list, Enter or Space opens one, Tab leaves the list
 * for the open panel.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs} = require('../../support/env');
const {scan, duplicateIds, bootstrap4Leftovers, applyTheme} = require('../support/checks');

test.describe('A11Y-CQ-02 - the statistics shortcode twice', () => {
    test.beforeEach(async({page}, testInfo) => {
        await applyTheme(page, testInfo);
        await loginAs(page, env.manager, env.password);
        await page.goto(env.statisticsUrl);
        await page.waitForLoadState('networkidle');
    });

    test('two instances, own ids, own panels; arrow keys and Tab', async({page}, testInfo) => {
        const lists = page.locator('ul[role="tablist"]:has(a[id^="local-catquiz-statistics-"])');
        await expect(lists).toHaveCount(2);
        expect(await duplicateIds(page)).toEqual([]);
        expect(await bootstrap4Leftovers(page, testInfo, 'statistics-twice')).toEqual([]);

        const first = lists.nth(0);
        const second = lists.nth(1);
        const panel = (list, name) => list.locator('xpath=following::div[contains(@class, "tab-content")][1]')
            .locator('[role="tabpanel"][id$="-' + name + '"]');

        // Shown or not by the class Bootstrap moves: a lazy chart still loading has no height, and
        // Playwright counts an empty panel as hidden although it is the one shown.
        const shown = (locator) => expect(locator).toHaveClass(/(^|\s)active(\s|$)/);
        const notshown = (locator) => expect(locator).not.toHaveClass(/(^|\s)active(\s|$)/);

        await test.step('a tab of the second instance opens its own panel only', async() => {
            await second.getByRole('tab', {name: 'Testusage'}).click();
            await shown(panel(second, 'testusage'));
            await notshown(panel(second, 'overview'));
            await shown(panel(first, 'overview'));
            await notshown(panel(first, 'testusage'));
            await expect(first.getByRole('tab', {name: 'Overview'})).toHaveAttribute('aria-selected', 'true');
            await expect(second.getByRole('tab', {name: 'Testusage'})).toHaveAttribute('aria-selected', 'true');
            await expect(second.getByRole('tab', {name: 'Overview'})).toHaveAttribute('aria-selected', 'false');
        });

        await test.step('arrow keys move the focus within the first list, Enter opens the tab', async() => {
            // Moodle's roving focus (theme_boost/aria): the arrows move the focus, Enter or Space opens.
            await first.getByRole('tab', {name: 'Overview'}).focus();
            await page.keyboard.press('ArrowRight');
            await expect(first.getByRole('tab', {name: 'Testusage'})).toBeFocused();
            await page.keyboard.press('Enter');
            await shown(panel(first, 'testusage'));
            await notshown(panel(first, 'overview'));
            await expect(first.getByRole('tab', {name: 'Testusage'})).toHaveAttribute('aria-selected', 'true');
            await expect(first.getByRole('tab', {name: 'Overview'})).toHaveAttribute('tabindex', '-1');
            await page.keyboard.press('ArrowLeft');
            await expect(first.getByRole('tab', {name: 'Overview'})).toBeFocused();
            await page.keyboard.press(' ');
            await shown(panel(first, 'overview'));
            // The second list did not move.
            await shown(panel(second, 'testusage'));
        });

        await test.step('Tab leaves the list for the open panel', async() => {
            await first.getByRole('tab', {name: 'Overview'}).focus();
            await page.keyboard.press('Tab');
            const focused = await page.evaluate(() => document.activeElement.closest('[role="tabpanel"]')?.id || '');
            expect(focused).toMatch(/-overview$/);
        });

        expect(await duplicateIds(page)).toEqual([]);
        await testInfo.attach('statistics.png', {body: await page.screenshot({fullPage: true}), contentType: 'image/png'});
        expect(await scan(page, testInfo, 'statistics-twice', '#region-main')).toEqual([]);
    });
});
