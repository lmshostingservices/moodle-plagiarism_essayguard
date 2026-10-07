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
 * Essay Guard - retroactive rescore page interactions.
 *
 * @module     plagiarism_essayguard/rescore
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    FORCE: '[data-action="plagiarism_essayguard-rescore-force"]',
};

let initialised = false;

/**
 * Submit the rescore form with force=1 so existing scores are overwritten.
 *
 * @param {MouseEvent} e The click event.
 */
const handleClick = (e) => {
    const button = e.target.closest(SELECTORS.FORCE);
    if (!button || button.disabled || !button.form) {
        return;
    }
    e.preventDefault();
    const form = button.form;
    const force = form.querySelector('input[name="force"]');
    if (force) {
        force.value = '1';
    }
    form.submit();
};

/**
 * Initialise the rescore page.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;
    document.addEventListener('click', handleClick);
};
