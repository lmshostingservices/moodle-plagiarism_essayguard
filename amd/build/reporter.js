// plagiarism_essayguard/reporter
//
// FIX-EG-NO-BADGE-OVERVIEW (v1.2.60):
//
// Moodle's plagiarism_get_links() API is only invoked on individual attempt-review
// pages; the quiz grading OVERVIEW table (/mod/quiz/report.php?mode=grading) never
// calls it  -  so Essay Guard badges were permanently absent from the page where
// teachers spend most of their grading time.
//
// This AMD module is loaded by before_footer.php for teachers with viewreport on
// mod-quiz-report pages. It:
//   1. Scans every <tr> for a link to /user/view.php to map userid  ->  row.
//   2. Calls plagiarism_essayguard_get_badges (batch web-service, one round-trip).
//   3. Injects a coloured risk badge inside the student-name cell of each row.
//
// @package    plagiarism_essayguard
// @copyright  2026 EssayGraderAI
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
define('plagiarism_essayguard/reporter', ['core/ajax'], function(Ajax) {
    'use strict';

    // v1.2.112: Thresholds and key names aligned with TypeShield LTI.
    //   low    → green  (0–29)
    //   medium → amber  (30–65)  TypeShield hsl(38 100% 95%) / hsl(28 80% 32%)
    //   high   → red    (66–100)
    // Legacy DB values 'partial' and 'mild' are mapped to medium colours.
    var COLOURS = {
        low:     {bg: '#f0fdf4', text: '#166534', border: '#86efac', dot: '#22c55e'},
        medium:  {bg: '#fff7ed', text: '#7c2d12', border: '#fdba74', dot: '#f97316'},
        high:    {bg: '#fef2f2', text: '#991b1b', border: '#fca5a5', dot: '#ef4444'},
        partial: {bg: '#fff7ed', text: '#7c2d12', border: '#fdba74', dot: '#f97316'},   // legacy alias
        mild:    {bg: '#fff7ed', text: '#7c2d12', border: '#fdba74', dot: '#f97316'},   // legacy alias
    };

    var LABELS = {
        low:     'Low',
        medium:  'Medium',
        high:    'High',
        partial: 'Medium',   // legacy alias
        mild:    'Medium',   // legacy alias
    };

    /**
     * Build the inline HTML for a risk badge pill.
     */
    function buildBadgeHtml(risklevel, score100) {
        var c = COLOURS[risklevel] || COLOURS.low;
        var label = LABELS[risklevel] || 'Original';
        var wrapStyle = [
            'display:inline-flex',
            'align-items:center',
            'gap:5px',
            'padding:2px 8px',
            'border-radius:999px',
            'font-size:0.75rem',
            'font-weight:700',
            'white-space:nowrap',
            'vertical-align:middle',
            'margin-left:6px',
            'background:' + c.bg,
            'color:' + c.text,
            'border:1px solid ' + c.border,
        ].join(';');
        var dotStyle = [
            'display:inline-block',
            'width:6px',
            'height:6px',
            'border-radius:50%',
            'flex-shrink:0',
            'background:' + c.dot,
        ].join(';');
        return '<span style="' + wrapStyle + '">'
             + '<span style="' + dotStyle + '"></span>'
             + '<span>' + label + ' \u00b7 ' + score100 + '%</span>'
             + '</span>';
    }

    /**
     * Extract the numeric user id from a /user/view.php?id=X link href.
     */
    function extractUserId(href) {
        var m = (href || '').match(/[?&]id=(\d+)/);
        return m ? parseInt(m[1], 10) : 0;
    }

    /**
     * Scan the page for grading table rows, call the web service, inject badges.
     */
    function doInject(cmid) {
        if (!cmid) {
            return;
        }

        // Build userid  ->  <tr> map.  Walk every table row and pick up user links.
        var allRows = document.querySelectorAll('table tr');
        var rowMap  = {};

        allRows.forEach(function(tr) {
            var links = tr.querySelectorAll('a[href*="user/view.php"]');
            links.forEach(function(link) {
                var uid = extractUserId(link.getAttribute('href'));
                if (uid && !rowMap[uid]) {
                    rowMap[uid] = tr;
                }
            });
        });

        var userids = Object.keys(rowMap).map(Number);
        if (!userids.length) {
            return;
        }

        Ajax.call([{
            methodname: 'plagiarism_essayguard_get_badges',
            args: {cmid: cmid, userids: userids},
        }])[0].then(function(badges) {
            badges.forEach(function(entry) {
                if (!entry.hasbadge) {
                    return;
                }
                var tr = rowMap[entry.userid];
                if (!tr) {
                    return;
                }

                // Prefer the <td> that contains the user profile link.
                var cells = tr.querySelectorAll('td');
                var targetCell = null;
                cells.forEach(function(td) {
                    if (!targetCell && td.querySelector('a[href*="user/view.php"]')) {
                        targetCell = td;
                    }
                });
                if (!targetCell && cells.length) {
                    targetCell = cells[0];
                }
                if (!targetCell) {
                    return;
                }

                // Inject once only.
                if (targetCell.querySelector('.essayguard-reporter-badge')) {
                    return;
                }

                var wrapper = document.createElement('span');
                wrapper.className = 'essayguard-reporter-badge';
                wrapper.innerHTML = buildBadgeHtml(entry.risklevel, entry.score100);
                targetCell.appendChild(wrapper);
            });
            return badges;
        }).catch(function(err) {
            window.console && window.console.warn('[EssayGuard reporter]', err);
        });
    }

    /**
     * Entry point  -  called via js_call_amd('plagiarism_essayguard/reporter', 'init', [config]).
     *
     * @param {Object} config  {cmid: <int>}
     */
    var init = function(config) {
        var cmid = (config && config.cmid) ? parseInt(config.cmid, 10) : 0;
        doInject(cmid);
    };

    return {
        init: init,
    };
});
