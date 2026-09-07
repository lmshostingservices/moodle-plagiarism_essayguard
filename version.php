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
 * plagiarism_essayguard file.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'plagiarism_essayguard';
$plugin->version   = 2026090703;
$plugin->release   = '1.2.233';
// V1.2.219: The full release history used to be appended to the $plugin->release
// assignment above as a single 39,645-character '//' comment, plus a second
// 1,152-character one on this line. version.php is parsed on every page load and
// read by every plugin-management tool; a 39KB single line broke editors, diffs,
// phpcs line-length checks and any human trying to read the file. Both comments are
// now archived verbatim in CHANGELOG.md under 'Archived inline changelog'.

// V1.0.85 / v1.2.225 FIX-REQUIRES-UNDERSTATED: this declared 2022041900 (Moodle 4.0) and
// supported = [400, 501]. Both numbers were wrong, in opposite directions.
//
// TOO LOW. Moodle 4.0's minimum PHP is 7.3, and this plugin uses arrow functions
// (`fn($x) => ...`), which are PHP 7.4. On a Moodle 4.0 or 4.1 site running PHP 7.3 the
// parse error is fatal in lib.php, which the plagiarism dispatcher loads on EVERY page -
// so the whole site goes white, not just this plugin. Declaring 4.0 invited exactly that.
//
// The real floor is Moodle 4.4: the three output hook classes this plugin registers in
// db/hooks.php - before_standard_head_html_generation,
// before_standard_top_of_body_html_generation and before_footer_html_generation - do not
// exist before 4.4. Below it the registrations are inert, silently: in Essay Guard that
// costs the floating report button AND the risk badges on the quiz grading overview, with
// no error and no log line.
//
// Set to 4.5 LTS rather than 4.4, deliberately. 4.5 is the lowest branch still receiving
// any support (security fixes to October 2027), so it is the only sub-5.0 branch worth
// testing; and 4.5 is where core deleted plagiarism_update_status() and
// plagiarism_plugin::update_status(), which is what the legacy no-op callbacks and the
// standalone plugin class in this plugin exist to satisfy. Requiring 4.5 makes that
// compatibility scaffolding dead code rather than something to keep reasoning about.
//
// TOO HIGH at the other end. supported = [400, 501] excludes branch 502, so on a Moodle
// 5.2 site - which is what the author runs in production - both plugins were listed as
// unsupported in Site administration > Plugins, and the plugin directory would not
// advertise 5.2 compatibility.

// V1.2.233 / v1.0.91 FLOOR LOWERED TO 4.4, reversing part of the decision above.
//
// The 4.5 floor was a POLICY choice, not a technical one. The comment above says so
// explicitly: "The real floor is Moodle 4.4". 4.5 was picked for two reasons - it is the
// lowest branch still receiving security fixes, and it is where core deleted
// plagiarism_update_status(), which would have made this plugin's legacy scaffolding dead
// code. Neither reason is a code requirement, and the scaffolding was never actually
// deleted, so it still works on 4.4.
//
// What that policy choice cost: it silently made the plugin un-upgradeable on Moodle 4.4,
// which is what the author's own site runs. Moodle's dependency check reports the
// `supported` range as a hard requirement and refuses the install - "Moodle 405 - 502
// Fails" - with no indication that the floor is a preference rather than a constraint.
//
// Verified before lowering: all three output hooks registered in db/hooks.php
// (before_standard_head_html_generation, before_standard_top_of_body_html_generation,
// before_footer_html_generation) exist in 4.4; the legacy update_status() scaffolding and
// the standalone plugin class are both still present; and 4.4's minimum PHP is 8.1, so the
// arrow functions that forced the floor up off Moodle 4.0 are not a problem here.
//
// NOTE FOR THE SITE OWNER: Moodle 4.4 is out of general support. Lowering this floor
// unblocks the upgrade; it does not make 4.4 a good place to stay.
$plugin->requires  = 2024042200;   // Moodle 4.4.
$plugin->maturity  = MATURITY_STABLE;
$plugin->supported = [404, 502];
