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
 * @module     local_catquiz/subscribe
 * @copyright  2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';

var SELECTORS = {
    SUBSCRIBEBUTTON: 'div.catquiz-button-subscribe',
};

export const init = (itemid, area) => {

    // eslint-disable-next-line no-console
    console.log('subscribe', itemid, area);

    const selector = SELECTORS.SUBSCRIBEBUTTON +
    '[data-id="' + itemid + '"]' +
    '[data-area="' + area + '"]';

    const buttons = document.querySelectorAll(selector);


    // eslint-disable-next-line no-console
    console.log(selector, buttons);

    if (!buttons) {
        return;
    }

    // We support more than one booking button on the same page.
    buttons.forEach(button => {

        // eslint-disable-next-line no-console
        console.log('add click listener ', button);
        if (!button.dataset.initialized) {
            button.dataset.initialized = 'true';

            button.addEventListener('click', e => {

                e.stopPropagation();

                // eslint-disable-next-line no-console
                console.log('clicked ', e.target);

                toggleSubscription(itemid, area, 0);
            });
        }
    });
};

/**
 *
 * @param {int} itemid
 * @param {string} area
 * @param {int} userid
 */
function toggleSubscription(itemid, area, userid) {
    Ajax.call([{
        methodname: "local_catquiz_subscribe",
        args: {
            userid,
            area,
            itemid
        },
        done: function(res) {

            // eslint-disable-next-line no-console
            console.log(res);

            const data = {
                id: itemid,
                area: area,
            };

            if (res.subscribed == 1) {
                data.subscribed = 'true';
            }

            // We render for promice for all the containers.
            Templates.renderForPromise('local_catquiz/button_subscribe', data).then(({html, js}) => {

                const selector = SELECTORS.SUBSCRIBEBUTTON +
                '[data-id="' + itemid + '"]' +
                '[data-area="' + area + '"]';

                // There might be more than one of these buttons.
                const buttons = document.querySelectorAll(selector);

                buttons.forEach(button => {
                    Templates.replaceNode(button, html, js);
                });

                return true;
            }).catch((e) => {
                // eslint-disable-next-line no-console
                console.log(e);
            });

        },
        fail: ex => {
            // eslint-disable-next-line no-console
            console.log("ex:" + ex);
        },
    }]);
}