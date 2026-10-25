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
 * What the accessibility suite needs from the fixture (seed_a11y.php) and the site.
 *
 * Every value is required: a suite that ran against an empty user name would fail far away from the
 * real cause, a seed step that did not run.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

/**
 * A required environment variable.
 *
 * @param {string} name
 * @returns {string}
 */
const required = (name) => {
    const value = process.env[name];
    if (!value) {
        throw new Error('Environment variable ' + name + ' is not set. It comes from tests/a11y/seed_a11y.php; ' +
            'check that the seed ran and that its output reached the environment.');
    }
    return value;
};

const env = {
    get baseUrl() {
        return required('A11Y_BASE_URL');
    },
    get user() {
        return required('A11Y_USER');
    },
    get password() {
        return required('A11Y_PASSWORD');
    },
    get manager() {
        return required('A11Y_MANAGER');
    },
    get activityUrl() {
        return required('A11Y_ACTIVITY_URL');
    },
    get statisticsUrl() {
        return required('A11Y_STATISTICS_URL');
    },
    get feedbackUrl() {
        return required('A11Y_FEEDBACK_URL');
    },
    get scaleId() {
        return required('A11Y_SCALEID');
    },
    get contextId() {
        return required('A11Y_CONTEXTID');
    },
};

/**
 * Logs in over HTTP, not through the form: the form is not what this suite is about, and the request
 * API shares its cookies with the page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} username
 * @param {string} password
 */
const loginAs = async(page, username, password) => {
    await page.goto('about:blank');
    await page.context().clearCookies();
    const api = page.context().request;
    const form = await api.get('/login/index.php');
    const token = /name="logintoken" value="([^"]+)"/.exec(await form.text())?.[1];
    if (!token) {
        throw new Error('No login token on /login/index.php - is this a Moodle site?');
    }
    const response = await api.post('/login/index.php', {form: {anchor: '', logintoken: token, username, password}});
    if (response.url().includes('/login/index.php')) {
        throw new Error('Login rejected for "' + username + '".');
    }
};

/** Where the address of the result page is kept between the projects of one run. */
const resultUrlFile = () => path.join(process.env.A11Y_EXPORT_DIR || os.tmpdir(), 'catquiz-a11y-result-' + env.user + '.url');

/**
 * The result page of the participant's attempt: the first project that gets here takes the test - one
 * answer per question until the result is shown - the later ones (dark, small screen) reopen its page.
 *
 * @param {import('@playwright/test').Page} page
 */
const openResultPage = async(page) => {
    const file = resultUrlFile();
    if (fs.existsSync(file)) {
        await page.goto(fs.readFileSync(file, 'utf8').trim());
        return;
    }
    await page.goto(env.activityUrl);
    await page.getByRole('button', {name: /start attempt/i}).or(page.getByRole('link', {name: /start attempt/i})).first().click();
    for (let i = 0; i < 10; i++) {
        const submit = page.getByRole('button', {name: /submit answer/i});
        await Promise.race([
            submit.waitFor({timeout: 60000}),
            page.locator('.catquiz_feedback').first().waitFor({timeout: 60000}),
        ]).catch(() => null);
        if (!(await submit.count())) {
            break;
        }
        await page.locator('.que input[type="radio"], .que input[type="checkbox"]').first().check();
        await submit.click();
        await page.waitForLoadState('load');
    }
    await page.locator('.catquiz_feedback').first().waitFor();
    fs.writeFileSync(file, page.url());
};

module.exports = {env, loginAs, openResultPage};
