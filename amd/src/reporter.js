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
 * Risk-badge injector for the quiz grading overview table.
 *
 * Core does not call plagiarism_get_links() for the quiz grading overview, so badges
 * are added here: map each table row to a user id, fetch all badges in one web
 * service call, and append a badge to the student-name cell.
 *
 * @module     plagiarism_essayguard/reporter
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getStrings} from 'core/str';
import Notification from 'core/notification';

/** Risk levels that have their own badge style. Legacy values map to medium. */
const LEVELMAP = {
    low: 'low',
    medium: 'medium',
    high: 'high',
    partial: 'medium',
    mild: 'medium',
    unmeasured: 'unmeasured',
};

/**
 * Extract the numeric user id from a /user/view.php?id=X link.
 *
 * @param {String} href Link target.
 * @returns {Number} The user id, or 0.
 */
const extractUserId = (href) => {
    const m = (href || '').match(/[?&]id=(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
};

/**
 * Build one badge element.
 *
 * @param {String} state Canonical state (low, medium, high, unmeasured).
 * @param {String} text Badge text.
 * @returns {HTMLElement}
 */
const buildBadge = (state, text) => {
    const wrapper = document.createElement('span');
    wrapper.className = 'essayguard-reporter-badge';

    const badge = document.createElement('span');
    badge.className = 'essayguard-badge essayguard-badge-' + state;

    const dot = document.createElement('span');
    dot.className = 'essayguard-badge-dot essayguard-badge-dot-' + state;
    dot.setAttribute('aria-hidden', 'true');

    badge.appendChild(dot);
    badge.appendChild(document.createTextNode(text));
    wrapper.appendChild(badge);
    return wrapper;
};

/**
 * Map each grading table row to the user it belongs to.
 *
 * @returns {Object} userid => <tr>
 */
const mapRows = () => {
    const rowMap = {};
    document.querySelectorAll('table tr').forEach((tr) => {
        tr.querySelectorAll('a[href*="user/view.php"]').forEach((link) => {
            const uid = extractUserId(link.getAttribute('href'));
            if (uid && !rowMap[uid]) {
                rowMap[uid] = tr;
            }
        });
    });
    return rowMap;
};

/**
 * The cell that holds the student's profile link, or the first cell.
 *
 * @param {HTMLElement} tr Table row.
 * @returns {HTMLElement|null}
 */
const targetCell = (tr) => {
    const cells = Array.from(tr.querySelectorAll('td'));
    return cells.find((td) => td.querySelector('a[href*="user/view.php"]')) || cells[0] || null;
};

/**
 * Entry point.
 *
 * @param {Object} config
 * @param {Number} config.cmid Quiz course module id.
 */
export const init = async(config) => {
    const cmid = (config && config.cmid) ? parseInt(config.cmid, 10) : 0;
    if (!cmid) {
        return;
    }

    const rowMap = mapRows();
    const userids = Object.keys(rowMap).map(Number);
    if (!userids.length) {
        return;
    }

    try {
        const [badges, strings] = await Promise.all([
            Ajax.call([{
                methodname: 'plagiarism_essayguard_get_badges',
                args: {cmid: cmid, userids: userids},
            }])[0],
            getStrings([
                {key: 'risklow', component: 'plagiarism_essayguard'},
                {key: 'riskmedium', component: 'plagiarism_essayguard'},
                {key: 'riskhigh', component: 'plagiarism_essayguard'},
                {key: 'badgeunmeasured', component: 'plagiarism_essayguard'},
                {key: 'reporter_review', component: 'plagiarism_essayguard'},
            ]),
        ]);
        const labels = {
            low: strings[0],
            medium: strings[1],
            high: strings[2],
            unmeasured: strings[3],
        };

        badges.forEach((entry) => {
            const tr = entry.hasbadge ? rowMap[entry.userid] : null;
            const cell = tr ? targetCell(tr) : null;
            if (!cell || cell.querySelector('.essayguard-reporter-badge')) {
                return;
            }
            // An unrecognised level is shown as medium ("Review"), never as low.
            const state = LEVELMAP[entry.risklevel] || 'medium';
            const level = LEVELMAP[entry.risklevel] ? labels[state] : strings[4];
            const text = state === 'unmeasured' ? level : level + ' · ' + entry.score100 + '%';
            cell.appendChild(buildBadge(state, text));
        });
    } catch (error) {
        Notification.exception(error);
    }
};
