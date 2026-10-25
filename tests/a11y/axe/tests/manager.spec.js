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
 * A11Y-CQ-01 - the tabs of the CAT manager and its dialogues (issues #30, #32).
 *
 * Every tab: no WCAG 2.1 A/AA violation in the main region, no id twice, no Bootstrap 4 markup left,
 * the open tab marked for assistive technology. The questions tab: the dialogue to add test items
 * and the question preview take the focus, keep it, close with Escape and give it back.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {env, loginAs} = require('../../support/env');
const {scan, duplicateIds, bootstrap4Leftovers, applyTheme, holdsFocus} = require('../support/checks');

const TABS = ['summary', 'catscales', 'questions', 'testsandtemplates', 'quizattempts', 'calculations', 'versioning', 'importer'];

/**
 * The CAT manager on a tab, with the scale of the fixture selected.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} tab
 */
const openTab = async(page, tab) => {
    const params = new URLSearchParams({tab, scaleid: env.scaleId, contextid: env.contextId});
    await page.goto('/local/catquiz/manage_catscales.php?' + params.toString());
    await expect(page.locator('#local-catquiz-manager-tabs')).toBeVisible();
    await page.waitForLoadState('networkidle');
};

test.describe('A11Y-CQ-01 - the CAT manager', () => {
    test.beforeEach(async({page}, testInfo) => {
        await applyTheme(page, testInfo);
        await loginAs(page, env.manager, env.password);
    });

    for (const tab of TABS) {
        test('tab ' + tab + ': no WCAG violation, no id twice, no Bootstrap 4 markup', async({page}, testInfo) => {
            await openTab(page, tab);
            const current = page.locator('#local-catquiz-manager-' + tab + '-tab');
            await expect(current).toHaveAttribute('aria-current', 'page');
            await expect(page.locator('#local-catquiz-manager-' + tab)).toBeVisible();
            expect(await duplicateIds(page)).toEqual([]);
            expect(await bootstrap4Leftovers(page, testInfo, 'manager-' + tab)).toEqual([]);
            // The tabs are links to pages: Tab walks along them in their order.
            await current.focus();
            await page.keyboard.press('Tab');
            const next = TABS[TABS.indexOf(tab) + 1];
            if (next) {
                await expect(page.locator('#local-catquiz-manager-' + next + '-tab')).toBeFocused();
            }
            await testInfo.attach('tab-' + tab + '.png', {body: await page.screenshot({fullPage: true}), contentType: 'image/png'});
            expect(await scan(page, testInfo, 'manager-' + tab, '#region-main')).toEqual([]);
        });
    }

    test('questions tab: the dialogue to add test items is named, holds and returns the focus', async({page}, testInfo) => {
        await openTab(page, 'questions');
        const button = page.locator('[data-action="local_catquiz-addtestitems"]');
        await button.click();
        const dialog = page.getByRole('dialog', {name: /add test items/i});
        await expect(dialog).toBeVisible();
        await expect.poll(() => holdsFocus(dialog)).toBe(true);
        for (let i = 0; i < 5; i++) {
            await page.keyboard.press('Tab');
            expect(await holdsFocus(dialog), 'Tab ' + (i + 1)).toBe(true);
        }
        expect(await duplicateIds(page)).toEqual([]);
        expect(await scan(page, testInfo, 'manager-addtestitems-dialog', '.modal.show .modal-dialog')).toEqual([]);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(button).toBeFocused();
        // A second time: the table is still in it.
        await button.press('Enter');
        await expect(dialog).toBeVisible();
        await expect(dialog.locator('table').first()).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(button).toBeFocused();
    });

    test('questions tab: the question preview is named, holds and returns the focus', async({page}, testInfo) => {
        await openTab(page, 'questions');
        const trigger = page.locator('#local-catquiz-manager-questions a[data-action="catquiz-question-preview"]').first();
        await expect(trigger).toBeVisible();
        await trigger.focus();
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAccessibleName(/.+/);
        await expect.poll(() => holdsFocus(dialog)).toBe(true);
        await page.keyboard.press('Tab');
        expect(await holdsFocus(dialog)).toBe(true);
        expect(await scan(page, testInfo, 'manager-questionpreview-dialog', '.modal.show .modal-dialog')).toEqual([]);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
    });
});
