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
 * The "add test items" dialog of the scale manager as a Moodle core modal (issue #30).
 *
 * The table is rendered with the page and keeps its own JavaScript; it is moved into the modal the
 * first time the dialog opens, and stays there for every later opening.
 *
 * @module     local_catquiz/addtestitems_modal
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';

/**
 * Wires the button of one dialog.
 *
 * @param {string} rootid Id of the element holding the button and the table.
 */
export const init = (rootid) => {
    const root = document.getElementById(rootid);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';
    const button = root.querySelector('[data-action="local_catquiz-addtestitems"]');
    const content = root.querySelector('[data-region="local_catquiz-addtestitems-content"]');
    if (!button || !content) {
        return;
    }

    let modal = null;
    button.addEventListener('click', async(e) => {
        e.preventDefault();
        if (!modal) {
            modal = await Modal.create({
                title: button.dataset.title,
                body: '',
                large: true,
                removeOnClose: false,
            });
            modal.getBody()[0].appendChild(content);
            content.classList.remove('d-none');
        }
        modal.show();
    });
};
