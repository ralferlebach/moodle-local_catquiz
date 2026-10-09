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
 * Feedback tabs: log which tab is opened, and load a tab's content when it is opened (issue #85).
 *
 * @module     local_catquiz/feedback
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {addIconToContainer} from 'core/loadingicon';
import {getString} from 'core/str';

const SELECTORS = {
    LAZY: '[data-region="local_catquiz-lazyfeedback"]',
};

/**
 * Loads the content of one lazy region; on failure shows a message with a retry button.
 *
 * Failures stay inside the tab: the rest of the page keeps working.
 *
 * @param {HTMLElement} region
 */
const loadRegion = async(region) => {
    if (region.dataset.state === 'loading' || region.dataset.state === 'loaded') {
        return;
    }
    region.dataset.state = 'loading';
    region.innerHTML = '';
    const icon = addIconToContainer(region);
    try {
        const response = await Ajax.call([{
            methodname: 'local_catquiz_render_feedback_tab',
            args: {
                attemptid: parseInt(region.dataset.attemptid, 10),
                generatorname: region.dataset.generatorname,
                audience: region.dataset.audience,
            },
        }])[0];
        await icon;
        await Templates.replaceNodeContents(region, response.html, response.javascript);
        region.dataset.state = 'loaded';
    } catch (error) {
        region.dataset.state = 'failed';
        const [message, retry] = await Promise.all([
            getString('feedbacktabloadfailed', 'local_catquiz'),
            getString('retry', 'core'),
        ]);
        region.innerHTML = '';
        const alert = document.createElement('div');
        alert.className = 'alert alert-warning';
        alert.setAttribute('role', 'alert');
        alert.textContent = message + ' ';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link p-0';
        button.textContent = retry;
        button.addEventListener('click', () => {
            region.dataset.state = '';
            loadRegion(region);
        });
        alert.appendChild(button);
        region.appendChild(alert);
        window.console.error(error);
    }
};

/**
 * Loads the lazy regions inside the pane a tab shows.
 *
 * @param {HTMLElement} tab
 */
const loadPaneOf = (tab) => {
    const target = tab.getAttribute('href');
    if (!target || !target.startsWith('#')) {
        return;
    }
    const pane = document.getElementById(target.substring(1));
    if (pane) {
        pane.querySelectorAll(SELECTORS.LAZY).forEach(loadRegion);
    }
};

/**
 * Add event listeners to feedback tabs in order to call a webservice.
 */
export const init = () => {
    const tabs = document.querySelectorAll('a.feedbacktab');
    tabs.forEach(tab => {
        if (tab.initialized) {
            return;
        }
        tab.initialized = true;

        tab.addEventListener('click', e => {
            e.preventDefault();
            loadPaneOf(tab);
            const feedback = e.target.dataset.feedbackname;
            const feedbacktranslated = e.target.dataset.feedbacknameTranslated;
            if (!feedback) {
                return;
            }

            // Try to get the attemptid from the data-attemptid attribute. Use the query parameters attempt and attemptid as
            // fallback values.
            const attemptid = e.target.dataset.attemptid
            || (new URLSearchParams(window.location.search)).get('attempt')
            || (new URLSearchParams(window.location.search)).get('attemptid');
            Ajax.call([{
                methodname: 'local_catquiz_feedback_tab_clicked',
                args: {attemptid, feedback, feedbacktranslated}
            }]);
        });
    });
};
