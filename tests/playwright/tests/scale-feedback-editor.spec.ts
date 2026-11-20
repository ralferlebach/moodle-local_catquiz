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
 * The feedback editors of a scale, switched in without a reload (adapter #2, issue #51), as a person with a keyboard
 * and a screen reader meets them (re-audit 19.11.2026, P1 accessibility).
 *
 * The colour of a range is chosen in its select: visible, labelled, operable with the keyboard; the colour circles
 * beside it follow the select and are hidden from assistive technology. Before, the select was hidden and the circles
 * - spans with a click handler and an aria-label without a role - were the only way to choose a colour.
 *
 * Axe checks the section of the scale. What Moodle brings itself is left out, by name: the TinyMCE editor - the body
 * of its iframe has role "document" with an aria-label (aria-prohibited-attr), and while it starts, its status bar
 * shows a resize handle and a word count button without a name (aria-prohibited-attr, button-name) - and the
 * autocomplete of the course selection, which renders an empty listbox while nothing is selected
 * (aria-required-children). These appear on any Moodle form with these elements.
 *
 * Seed: seed_cat_path.php.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import AxeBuilder from '@axe-core/playwright';
import {expect, test} from '@playwright/test';
import {catpath, keep, loginAdmin, watchErrors, WCAG} from '../support/catpath';

test.describe('Feedback editors of a scale @a11y', () => {
    test('switched in without a reload: the colour by keyboard, axe on the section', async({page}, testInfo) => {
        const errors = watchErrors(page);
        await loginAdmin(page);
        await page.goto(`/course/modedit.php?update=${catpath('CMID_FASTEST')}`);

        // The root first, then a subscale: its editors are loaded into the form without a reload.
        await page.getByText('Feedback for “Simulation”', {exact: true}).click();
        await page.getByText('Feedback for “SimA”', {exact: true}).click();
        const section = page.locator("[data-name='catquiz_feedback_header_SimA']");
        await expect(page.locator("[data-name='feedback_scale_SimA_range_1']")).toBeVisible({timeout: 30_000});

        // The colour of the first range: a visible select with a label, chosen with the keyboard.
        const select = section.locator('select[name^="wb_colourpicker_"]').first();
        await expect(select).toBeVisible();
        const id = await select.getAttribute('id');
        await expect(page.locator(`label[for="${id}"]`), 'the select has its label').toHaveCount(1);
        const before = await select.inputValue();
        await select.focus();
        await page.keyboard.press('ArrowDown');
        const after = await select.inputValue();
        expect(after, 'the arrow key chooses another colour').not.toBe(before);
        // The circles follow the select and are not announced.
        const circles = section.locator('.wb_colourpick').first();
        await expect(circles).toHaveAttribute('aria-hidden', 'true');
        await expect(circles.locator(`.colourpickercircle.selected[data-colour="${after}"]`)).toHaveCount(1);
        // A click on a circle sets the select, as a person with a mouse expects.
        const other = circles.locator('.colourpickercircle:not(.selected)').first();
        const colour = await other.getAttribute('data-colour');
        await other.click();
        await expect(select).toHaveValue(colour as string);

        const result = await new AxeBuilder({page}).withTags(WCAG)
            .include("[data-name='catquiz_feedback_header_SimA']")
            .exclude('.tox-tinymce')
            .exclude('iframe')
            .exclude('[id^="form_autocomplete_selection-"]')
            .analyze();
        await keep(testInfo, 'scale-feedback-editor', {
            violations: result.violations.map((v) => `${v.id}: ${v.nodes.length}`),
            passes: result.passes.length,
        });
        expect(result.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
        expect(errors).toEqual([]);
    });
});
