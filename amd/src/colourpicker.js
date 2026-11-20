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
 * Colour circles beside the colour select of a feedback range: a quick pick with the mouse.
 *
 * The select stays the control (re-audit 19.11.2026, P1 accessibility): visible, labelled by the form, reachable and
 * operable with the keyboard and announced by screen readers. The circles repeat it for the eye and the mouse only -
 * hidden from assistive technology - and follow the select when it changes. Before, the select was hidden and the
 * circles, spans with a click handler, were the only way to choose: not with the keyboard, not with a screen reader
 * (axe: aria-label on a span without a role).
 *
 * @module     local_catquiz/colourpicker
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    SELECTCOLOURPICKER: 'select[name^="wb_colourpicker_"]',
    COLOURPICKER: 'span.wb_colourpick',
    COLOUR: 'span.colourpickercircle',
};

/**
 * Add event listener to form.
 */
export const init = () => {

    const selectcolors = document.querySelectorAll(SELECTORS.SELECTCOLOURPICKER);

    selectcolors.forEach(selectcolor => {
        if (selectcolor.dataset.initialized) {
            return;
        }
        selectcolor.dataset.initialized = 'true';

        addClickListener(selectcolor);
    });
};

/**
 * Add Click Listener to element.
 *
 * @param {mixed} selectcolor
 */
async function addClickListener(selectcolor) {
    const colours = await Promise.all([...selectcolor.querySelectorAll('option')].map(async el => {

        const colour = el.value;
        let colourname = el.textContent;
        try {
            colourname = await getString('color_' + el.value + '_name', 'local_catquiz');
        } catch (err) {
            // Nothing to do: we already have a default value.
        }

        return {
            colour,
            colourname,
            colourvalue: el.innerHTML,
            selected: selectcolor.value == el.value,
            id: selectcolor.name
        };
    }));

    const colourobject = colours.filter(e => e.selected).pop();

    const data = {
        colours,
        colour: colourobject.colour,
        id: selectcolor.name
    };

    Templates.renderForPromise('local_catquiz/colour_picker', data).then(({html}) => {

        selectcolor.insertAdjacentHTML('afterend', html);
        const colourpicker = document.querySelector('span[data-id=wb_colourpick_id_' + selectcolor.name + ']');
        const colourselectnotify = document.querySelector('span[data-id=wb_colourselectnotify_id_' + selectcolor.name + ']');
        const colours = colourpicker.querySelectorAll(SELECTORS.COLOUR);

        // The circles show what the select holds.
        const show = () => {
            colours.forEach(el => el.classList.toggle('selected', el.dataset.colour == selectcolor.value));
            const chosen = [...colours].find(el => el.dataset.colour == selectcolor.value);
            colourselectnotify.textContent = chosen ? chosen.dataset.colourname : '';
        };

        colours.forEach(el => {
            el.addEventListener('click', e => {
                selectcolor.value = e.target.dataset.colour;
                // As if chosen in the select: the form and everything listening learn of it.
                selectcolor.dispatchEvent(new Event('change', {bubbles: true}));
            });
        });
        selectcolor.addEventListener('change', show);
        show();
        return true;
      }).catch((e) => {
          // eslint-disable-next-line no-console
          console.log(e);
      });
}
