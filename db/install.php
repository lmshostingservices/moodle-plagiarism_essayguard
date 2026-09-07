<?php
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
 * Post-installation hook for plagiarism_essayguard.
 *
 * Automatically enables Moodle's global plagiarism support so admins do not
 * need to manually visit Site Administration → Advanced features and tick
 * "Enable plagiarism plugins" before the plugin becomes accessible.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Post-installation hook for plagiarism_essayguard.
 *
 * Deliberately does not switch Moodle's core plagiarism subsystem on: that is a
 * site-wide setting affecting every plagiarism plugin, so the administrator is
 * told about it on the settings page instead.
 *
 * @return bool Always true.
 */
function xmldb_plagiarism_essayguard_install() {
    global $CFG;

    // V1.2.219: set_config('enableplagiarism', 1) REMOVED.
    //
    // enableplagiarism is a CORE site setting (Site administration > Advanced features).
    // Installing this plugin used to switch Moodle's entire plagiarism subsystem on for
    // the whole site — which also activates every OTHER plagiarism plugin installed on
    // that site, and overrides a deliberate administrator decision to leave it off. A
    // plugin may read a core setting; writing one is out of bounds, and on a client site
    // it is a change nobody authorised and nobody was told about.
    //
    // Instead we raise a visible admin notification telling the administrator what to
    // switch on and where. The plugin's own settings page repeats the message.
    //
    // MIGRATION CONSEQUENCE: on a fresh install the plugin does nothing until an
    // administrator ticks "Enable plagiarism plugins" in Advanced features. That tick was
    // previously done for them, silently.
    if (empty($CFG->enableplagiarism)) {
        // Try/catch because install can run from CLI or from the middle of a bulk
        // upgrade, where the notification stack may not be usable. Failing to show a
        // notice must never fail the install.
        try {
            \core\notification::add(
                get_string('notice_enableplagiarism', 'plagiarism_essayguard'),
                \core\output\notification::NOTIFY_WARNING
            );
        } catch (\Throwable $e) {
            mtrace('Essay Guard: enable "Enable plagiarism plugins" in Advanced features to activate this plugin.');
        }
    }
    return true;
}
