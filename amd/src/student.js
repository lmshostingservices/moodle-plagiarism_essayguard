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
 * Behaviour for the Essay Guard student detail report (student.php).
 *
 * Expands/collapses the per-question cards and toggles truncated question/answer
 * text between its short and full form. Uses delegated listeners on the document,
 * driven by data-action attributes rendered in the plagiarism_essayguard/student*
 * templates.
 *
 * @module     plagiarism_essayguard/student
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    CARD_TOGGLE: '[data-action="plagiarism_essayguard-student-togglecard"]',
    TEXT_TOGGLE: '[data-action="plagiarism_essayguard-student-toggletext"]',
    TRUNCATED: '[data-region="plagiarism_essayguard-student-truncated"]',
    SHORT: '[data-region="short"]',
    FULL: '[data-region="full"]',
};

let initialised = false;

/**
 * Expand or collapse the body controlled by a per-question card header.
 *
 * @param {HTMLElement} header The header element carrying aria-controls.
 */
const toggleCard = (header) => {
    const body = document.getElementById(header.getAttribute('aria-controls'));
    if (!body) {
        return;
    }
    const expanded = header.getAttribute('aria-expanded') === 'true';
    header.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    body.hidden = expanded;
};

/**
 * Switch a truncated text block between its short and full form.
 *
 * @param {HTMLElement} link The "more" or "less" link that was activated.
 */
const toggleText = (link) => {
    const region = link.closest(SELECTORS.TRUNCATED);
    if (!region) {
        return;
    }
    const shortpart = region.querySelector(SELECTORS.SHORT);
    const fullpart = region.querySelector(SELECTORS.FULL);
    if (!shortpart || !fullpart) {
        return;
    }
    const showfull = link.dataset.show === 'full';
    shortpart.hidden = showfull;
    fullpart.hidden = !showfull;
    region.querySelectorAll(SELECTORS.TEXT_TOGGLE).forEach((toggle) => {
        toggle.setAttribute('aria-expanded', showfull ? 'true' : 'false');
    });

    // Keep keyboard focus on the counterpart link, which is now the visible one.
    const counterpart = (showfull ? fullpart : shortpart).querySelector(SELECTORS.TEXT_TOGGLE);
    if (counterpart) {
        counterpart.focus();
    }
};

/**
 * Delegated click handler.
 *
 * @param {MouseEvent} e
 */
const handleClick = (e) => {
    const textToggle = e.target.closest(SELECTORS.TEXT_TOGGLE);
    if (textToggle) {
        e.preventDefault();
        toggleText(textToggle);
        return;
    }
    const cardToggle = e.target.closest(SELECTORS.CARD_TOGGLE);
    if (cardToggle) {
        e.preventDefault();
        toggleCard(cardToggle);
    }
};

/**
 * Delegated keyboard handler: Enter/Space activate the card header (a role="button"
 * div), and Space activates the more/less links (role="button" anchors).
 *
 * @param {KeyboardEvent} e
 */
const handleKeydown = (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') {
        return;
    }
    const cardToggle = e.target.closest(SELECTORS.CARD_TOGGLE);
    if (cardToggle && e.target === cardToggle) {
        e.preventDefault();
        toggleCard(cardToggle);
        return;
    }
    const textToggle = e.target.closest(SELECTORS.TEXT_TOGGLE);
    if (textToggle && e.key === ' ') {
        e.preventDefault();
        toggleText(textToggle);
    }
};

/**
 * Attach the delegated listeners. Safe to call more than once.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;
    document.addEventListener('click', handleClick);
    document.addEventListener('keydown', handleKeydown);
};
