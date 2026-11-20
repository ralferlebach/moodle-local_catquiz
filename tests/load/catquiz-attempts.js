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
 * k6: CAT attempts over HTTP, as a browser plays them (re-audit 19.11.2026, load P0; mod_adaptivequiz #17).
 *
 * Every virtual user is one participant of the site seeded by seed_attempt_load.php: it logs in, opens the attempt
 * page and answers every question until the result page - GET the attempt page, POST the response form - in one
 * iteration. The client side measures, per kind of request (start, answer, final):
 *   catquiz_ttfb       time to the first byte of the answer (http_req_waiting)
 *   catquiz_roundtrip  the whole question-answer roundtrip: the POST, its redirects and the next page read
 * and counts HTTP 5xx, pages that show an exception or the missing progress, responses of attempt.php without the
 * request id of the host (X-ALiSe-Request-Id) and attempts that reached their result page. The server side - next_item, lock
 * waits, queries, memory, sessions, integrity - is read from the traces of the same run by analyse_attempt_load.php.
 *
 * Environment: LOAD_BASE_URL, LOAD_CMID, LOAD_USERS (comma separated), LOAD_PASSWORD, LOAD_ITEMS, VUS, OFFSET (the
 * first participant of this run in LOAD_USERS - every run takes fresh people), LOAD_OUT (summary JSON).
 *
 * No answer text is recorded; answers are random, as the CAT model follows them either way.
 */

import http from 'k6/http';
import {check} from 'k6';
import exec from 'k6/execution';
import {parseHTML} from 'k6/html';
import {Counter, Trend} from 'k6/metrics';

const BASE = (__ENV.LOAD_BASE_URL || '').replace(/\/$/, '');
const CMID = __ENV.LOAD_CMID;
const USERS = (__ENV.LOAD_USERS || '').split(',').filter((u) => u);
const PASSWORD = __ENV.LOAD_PASSWORD;
const ITEMS = parseInt(__ENV.LOAD_ITEMS || '26', 10);
const VUS = parseInt(__ENV.VUS || '1', 10);
const OFFSET = parseInt(__ENV.OFFSET || '0', 10);

const ttfb = new Trend('catquiz_ttfb', true);
const roundtrip = new Trend('catquiz_roundtrip', true);
const serverErrors = new Counter('catquiz_server_errors');
const pageExceptions = new Counter('catquiz_page_exceptions');
const progressMissing = new Counter('catquiz_progress_missing');
const missingRequestIds = new Counter('catquiz_missing_request_ids');
const completed = new Counter('catquiz_attempts_completed');
const answers = new Counter('catquiz_answers');

// What a page shows of an exception - not the words in the strings of its JavaScript (as attempt_load.py).
const EXCEPTION = /class="[^"]*\berrorbox\b|class="errormessage"|data-rel="fatalerror"|Fatal error<\/b>|Uncaught (?:TypeError|Error|Exception)|"exception":\{|"errorcode":"/i;
const MISSING = 'The progress of this attempt was not found.';

export const options = {
    scenarios: {
        attempts: {executor: 'per-vu-iterations', vus: VUS, iterations: 1, maxDuration: '90m'},
    },
    thresholds: {
        'catquiz_server_errors': ['count==0'],
        'catquiz_page_exceptions': ['count==0'],
        'catquiz_progress_missing': ['count==0'],
        'catquiz_attempts_completed': [`count==${VUS}`],
        'http_req_failed': ['rate==0'],
        // Kept for the summary per kind of request (k6 reports tagged sub-metrics only with a threshold).
        'catquiz_ttfb{kind:start}': ['max>=0'],
        'catquiz_ttfb{kind:answer}': ['max>=0'],
        'catquiz_ttfb{kind:final}': ['max>=0'],
        'catquiz_roundtrip{kind:start}': ['max>=0'],
        'catquiz_roundtrip{kind:answer}': ['max>=0'],
        'catquiz_roundtrip{kind:final}': ['max>=0'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max', 'count'],
};

if (USERS.length < OFFSET + VUS) {
    throw new Error(`LOAD_USERS has ${USERS.length} people; this run needs ${OFFSET + VUS} (OFFSET + VUS).`);
}

/**
 * Records one response.
 *
 * @param {object} res
 * @param {string} kind
 * @param {number} started Date.now() before the request
 */
function record(res, kind, started) {
    if (res.status >= 500) {
        serverErrors.add(1, {kind});
    }
    // The host sends its request id from attempt.php; after the last answer the redirect ends on the result page,
    // another script without one.
    const fromattempt = (res.url || '').includes('/mod/adaptivequiz/attempt.php');
    if (fromattempt && !res.headers['X-Alise-Request-Id'] && !res.headers['X-ALiSe-Request-Id']) {
        missingRequestIds.add(1, {kind});
    }
    ttfb.add(res.timings.waiting, {kind});
    roundtrip.add(Date.now() - started, {kind});
    const body = res.body || '';
    if (EXCEPTION.test(body)) {
        pageExceptions.add(1, {kind});
    }
    if (body.includes(MISSING)) {
        progressMissing.add(1, {kind});
    }
}

/**
 * The fields of the response form - hidden ones, one choice per radio group, text fields - or null at the end.
 *
 * @param {string} body
 * @returns {object|null}
 */
function responseForm(body) {
    const doc = parseHTML(body);
    const form = doc.find('form#responseform');
    if (form.size() === 0) {
        return null;
    }
    const fields = {};
    const radios = {};
    form.find('input').each((i, el) => {
        const name = el.attributes().name ? el.attributes().name.value : '';
        if (!name) {
            return;
        }
        const type = (el.attributes().type ? el.attributes().type.value : 'text').toLowerCase();
        const value = el.attributes().value ? el.attributes().value.value : '';
        if (type === 'radio') {
            (radios[name] = radios[name] || []).push(value);
        } else if (type === 'hidden' || type === 'submit') {
            fields[name] = value;
        } else if (type === 'text') {
            fields[name] = '1';
        }
    });
    for (const [name, values] of Object.entries(radios)) {
        const choices = values.filter((v) => v !== '' && v !== '-1');
        const pool = choices.length ? choices : values;
        fields[name] = pool[Math.floor(Math.random() * pool.length)];
    }
    return fields;
}

/**
 * Logs in with the login token of the form.
 *
 * @param {string} username
 */
function login(username) {
    const page = http.get(`${BASE}/login/index.php`, {tags: {kind: 'login'}});
    const token = /name="logintoken" value="([^"]+)"/.exec(page.body || '');
    const res = http.post(`${BASE}/login/index.php`, {
        username, password: PASSWORD, logintoken: token ? token[1] : '', anchor: '',
    }, {tags: {kind: 'login'}});
    if (!check(res, {'logged in': (r) => !r.url.includes('/login/index.php')})) {
        throw new Error(`Login failed for ${username}`);
    }
}

export default function() {
    const username = USERS[OFFSET + exec.vu.idInTest - 1];
    login(username);
    let started = Date.now();
    let res = http.get(`${BASE}/mod/adaptivequiz/attempt.php?cmid=${CMID}`, {tags: {kind: 'start'}});
    record(res, 'start', started);
    for (let number = 1; number <= ITEMS + 5; number++) {
        const fields = responseForm(res.body || '');
        if (fields === null) {
            break;
        }
        const kind = number >= ITEMS ? 'final' : 'answer';
        started = Date.now();
        res = http.post(`${BASE}/mod/adaptivequiz/attempt.php`, fields, {tags: {kind}});
        record(res, kind, started);
        answers.add(1);
    }
    const ended = responseForm(res.body || '') === null && res.status === 200;
    if (check(res, {'reached the result page': () => ended})) {
        completed.add(1);
    }
}

/**
 * The summary as JSON (LOAD_OUT) and a short text on the console.
 *
 * @param {object} data
 * @returns {object}
 */
export function handleSummary(data) {
    const pick = (name) => {
        const metric = data.metrics[name];
        return metric ? metric.values : null;
    };
    const byKind = {};
    for (const [name, metric] of Object.entries(data.metrics)) {
        const m = /^(catquiz_ttfb|catquiz_roundtrip)\{kind:(\w+)\}$/.exec(name);
        if (m) {
            byKind[m[2]] = byKind[m[2]] || {};
            byKind[m[2]][m[1]] = metric.values;
        }
    }
    const summary = {
        vus: VUS, items: ITEMS, offset: OFFSET,
        ttfb: pick('catquiz_ttfb'), roundtrip: pick('catquiz_roundtrip'), bykind: byKind,
        serverErrors: pick('catquiz_server_errors'), pageExceptions: pick('catquiz_page_exceptions'),
        progressMissing: pick('catquiz_progress_missing'), missingRequestIds: pick('catquiz_missing_request_ids'),
        completed: pick('catquiz_attempts_completed'), answers: pick('catquiz_answers'),
        httpFailed: pick('http_req_failed'), iterations: pick('iterations'),
        thresholds: Object.fromEntries(Object.entries(data.metrics)
            .filter(([, m]) => m.thresholds)
            .map(([n, m]) => [n, Object.values(m.thresholds).every((t) => t.ok)])),
    };
    const out = {};
    if (__ENV.LOAD_OUT) {
        out[__ENV.LOAD_OUT] = JSON.stringify(summary, null, 2);
    }
    const f = (v) => (v === undefined || v === null ? '-' : Math.round(v));
    out.stdout = `CAT attempts: ${VUS} VU x ${ITEMS} items - completed ${summary.completed ? summary.completed.count : 0}, `
        + `5xx ${summary.serverErrors ? summary.serverErrors.count : 0}, `
        + `exceptions ${summary.pageExceptions ? summary.pageExceptions.count : 0}\n`
        + `  TTFB      p50 ${f(summary.ttfb && summary.ttfb.med)} ms  p95 ${f(summary.ttfb && summary.ttfb['p(95)'])} ms  `
        + `p99 ${f(summary.ttfb && summary.ttfb['p(99)'])} ms\n`
        + `  roundtrip p50 ${f(summary.roundtrip && summary.roundtrip.med)} ms  `
        + `p95 ${f(summary.roundtrip && summary.roundtrip['p(95)'])} ms  p99 ${f(summary.roundtrip && summary.roundtrip['p(99)'])} ms\n`;
    return out;
}
