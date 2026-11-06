### Releases ###

#### v5.5.2 ####

- Bugfix: TypeError when editing activities with breakout rooms #724 (thanks @ks19rl, @jrchamp)
- Bugfix: Delete module task fails when Zoom API delete fails #725 (thanks @michael-hughes-strath, @jrchamp)
- Bugfix: Use pagination for registrants API responses #727 (thanks @chuspi)
- Bugfix: Require well-formed recording API responses #732 (thanks @nexterday, @RafaelFormaggini)
- Hardening: MDL Shield cleanups (security and more) #733 (thanks @jrchamp)
- Code quality: MDL Shield cleanups (privacy and more) #723 (thanks @jrchamp)

#### v5.5.1 ####

- Bugfix: Remove legacy FEATURE_GROUPMEMBERSONLY #715 (thanks @michael-hughes-strath, @University-of-Strathclyde-LTE-Team)
- Bugfix: Calendar events should respect group restrictions #717 (thanks @RafaelFormaggini, @jrchamp)
- Bugfix: MDL Shield improvements #720, #722 (thanks @jrchamp)
- Code quality: Settings cleanup and modernization #713 (@thanks jrchamp)
- Documentation: Moodle Playground support #718 (thanks @erseco)
- Marketplace: Add required LICENSE file

#### v5.5.0 ####

- Feature: Add generator for Behat tests #687 (thanks @armccoy)
- Bugfix: Fix duplicate string syntax with recording messages #695 (thanks @jrchamp)
- Bugfix: Change from legacy to archetypes for capability types in access file #694 (thanks @jrchamp)
- Bugfix: Prioritize Moodle capabilities over assumed access of hosts #703 (thanks @jrchamp)
    - Removed string `mod_zoom/start_meeting`
- Bugfix: Role switching now properly uses capabilities instead of assuming hosts have all access #697 (thanks @cbounphengsy)
- Feature: Added setting for pre-assigned breakout rooms #699 (thanks @cbounphengsy)
    - New setting `zoom/preassignbreakoutrooms`
    - New string `mod_zoom/setting_breakoutroom`
    - New string `mod_zoom/setting_breakoutroom_help`
- Bugfix: Remove debug message from admin settings page #705 (thanks @karenliulll)
- CI: Only use requested images; enable opcache #706 (thanks @jrchamp)
- Bugfix: Use ESM format for form.js and add Bootstrap 5 support #701 (thanks @jrchamp)
- Bugfix: Call grade reset when Zoom reset userdata is checked #700 (thanks @smbader)
- Bugfix: Only notify active enrollments of iCal events #708 (thanks @paulandm)
- Hardening: Provide more context with get_recording_url_list #709 (thanks @jrchamp)
- Bugfix: configuration setting moved from generator to Behat feature file #710 (thanks @jrchamp)
- Feature: Update module language string for 5.2 support #711 (thanks @jrchamp)
    - New string `mod_zoom/modulename_summary`
    - New string `mod_zoom/modulename_tip`

#### v5.4.5 ####

- Bugfix: Case-insensitive comparisons for alternative host emails #677
- Hardening: Verify zoomid when toggling recording visibility #681
- CI: add Moodle 5.1 checks #679

#### v5.4.4 ####

- Hardening: Shell arguments should always be escaped #671 (thanks @OshratLuski, @nadavkav)

#### v5.4.3 ####

- Bugfix: Rename string to avoid flawed database upgrade step #668 (thanks @samwitzig)

#### v5.4.2 ####

- Bugfix: Fix SQL compatibility in upgrade step #664 (thanks @izendegi, @SimonThornett)

#### v5.4.1 ####

- Bugfix: Fix stringid capitalization in upgrade step #659 (thanks @Ethan-DevIT)
- Bugfix: Dashboard API was not working for get_meeting_reports #662 (thanks @Caspiofri)
- Code quality: Apply moodle-cs for line wrapping in send_ical_notifications

#### v5.4.0 ####

- Feature: Send iCal Notifications #623 (thanks @paulandm, @OpenCollabZA)
  - New setting `zoom/sendicalnotifications`
  - New message `mod_zoom/ical_notifications`
  - New table `zoom_ical_notifications`
  - New task `mod_zoom\task\send_ical_notifications`
- Bugfix: Create Zoom user webservice method used outdated action. #655 (thanks @yedidiaklein)
- Bugfix: Detailed error messages when editing activities #656 (thanks @Chatharina)
- Code quality: Check Moodle 5.0 #654

#### v5.3.1 ####

- Regression: Tracking fields task missing variable #648 (thanks @lorenzogreco, @phette23, @izendegi)
  - Introduced in v5.3.0 when checking necessary scopes for tasks.

#### v5.3.0 ####

- Feature: Protect groups from license reassignment #630 (thanks @izendegi, @tsostef, @lcollong)
  - New setting `zoom/protectedgroups`
  - Optional OAuth scope `group:read:list_groups:admin` or `group:read:admin`
- Bugfix: Incorrect rendering of HTML characters in recording passcodes #637 (thanks @FrancescoTerrosi)
- Bugfix: More detailed error messages #639 (thanks @ahmadalwa)
- Bugfix: Check a task's necessary scopes prior to API calls #642 (thanks @syedaawais)
- Bugfix: Remove calendar events for deleted meeting occurrences #645 (thanks @mei-cberry)
- Code quality: Forward compatibility for PHPUnit #640
- Regression: Moodle 3.7 support #638 (thanks @gzvol)
  - Introduced in v5.2.0 when adding attendance-based grading.
- Regression: Moodle 3.8+ support #644 (thanks @cybernotic, @bobopinna)
  - Introduced in unreleased code when fixing Moodle 3.7 support.

#### v5.2.5 ####

- Bugfix: Validation for registration #622 (thanks @paulandm)
- Code quality: Check Moodle 4.5; skip Moodle 4.2 #631
- Code quality: Remove legacy reference to local_mobile service #632

#### v5.2.4 ####

- Bugfix: Apply Moodle filters to the Zoom topic and description #615 (thanks @behanw)
- Bugfix: Temporarily use string 'null' when `recording_type` is null #620 (thanks @smangancap)
- Accessibility: Migrate Bootstrap `.label` to `.badge` (Bootstrap 3 -> 4/5) #608 (thanks @sgrandh3)
- Accessibility: Add table headers and IDs to activity details sections and rows #612 (thanks @a-kempka)
- Code quality: Check Moodle 4.4 and PHP 8.3 #611

#### v5.2.3 ####

- Bugfix: Duration language string was incorrectly specifying units #250 (thanks @abias)
- Bugfix: Stop using deprecated core-course-module-description for mobile app v4.4.0 #602 (thanks @dpalou)
- Code quality: Align with moodle-cs v3.4.9 #605
- Regression: `recordingtype` column was too small for some type strings #605 (thanks @samwitzig, @acquaalta)
  - Introduced in v5.2.1 when fixing recording type language strings.

#### v5.2.2 ####

- Bugfix: Keep recordings even if the API errors #593 (thanks @LGPoly, @mjleblanc)

#### v5.2.1 ####

- Bugfix: Set icon size to something reasonable on Moodle 4.3 #581 (thanks @haietza)
- Bugfix: Save Zoom data (e.g. join_url) when updating instance #585 (thanks @selimmeziti)
- Bugfix: Form sections can now toggle independently #587 (thanks @kiratskitizing)
- Bugfix: Differentiate between multiple recording types #578 (thanks @welegionsr)
- Bugfix: Granular OAuth scopes work now #590 (thanks @amendezinserver, @jport500, @haietza, Kohei SHIRAHAMA)
- Code quality: Move function from view page to locallib #584
- Code quality: Freshen GitHub Action to match moodle-plugin-ci #584
- Code quality: Align with moodle-cs v3.4.6 #584

#### v5.2.0 ####

- Feature: Grading based on attendance duration #477 (thanks @fmido88)
  - New settings `zoom/gradingmethod`, `zoom/unamedisplay`
  - New per activity setting `grading_method`

#### v5.1.6 ####

- Bugfix: Update NULL registration values to fix upgrade step #574 (thanks @michael-milette)
- Code quality: Move changelog to CHANGES.md and upgrade.txt #572

#### v5.1.5 ####

- Bugfix: Add PNG/SVG calendar icon for Moodle 4.3 #558 (thanks @ScottVerbeek)
- Bugfix: Display user fullname in breakout room participant list #562 (thanks @mofetdanielsmolkin)
- Bugfix: Sort session report by start time #567
- Code quality: Namespace CSS identifiers #560 (thanks @danielcifuentesopen)
- Code quality: Optimize SVGs #561 (thanks @jakearchibald for SVGOMG)
- Code quality: Remove long-forgotten todo PHPDoc tags causing warnings in moodle-cs v3.3.13
- Regression: Registration field default was accidentally upgrading to null #565 (thanks @michael-milette)
  - Introduced in v5.1.0 when fixing recording field definition.

#### v5.1.4 ####

- Bugfix: Avoid breaking completion defaults form in Moodle 4.3 #555 (thanks @opitz)
- Regression: 'Use' missing classes required for Moodle app #554 (thanks ramprakash k)
  - Introduced in v5.1.1 when moving classes into namespaces.

#### v5.1.3 ####

- Bugfix: Allow editing a past Zoom meeting without changing the time #545 (thanks @davefoord, @tlock)
- Bugfix: Remove unused start_url field from the database #546 (thanks @ShilVita)
- Regression: "Recurring No Time" admin setting was defaulting to "Daily" #544 (thanks @easegill)
  - Introduced in v4.9.0 when adding support for meeting registration.

#### v5.1.2 ####

- Bugfix: Skip redundant calendar permissions check #535 (thanks @danowar2k)
- Bugfix: Initialize scopes from cache to avoid TypeError #542 (thanks @foxlapinou)
- Regression: Restore exceptions were not being caught #537
  - Introduced in v5.1.1 when moving classes into namespaces.
- Code quality: Void test return types in moodle-cs v3.3.10 #536

#### v5.1.1 ####

- Bugfix: Get all meeting recordings, not just the last occurrence #517 (thanks @LGPoly)
- Bugfix: Choose meeting reports API based on OAuth permissions #525 (thanks @xmontana)
- Bugfix: Get meeting reports based on end time #514 (thanks @xmontana)
- Bugfix: Stop showing dates for 'No Fixed Time' meetings #529 (thanks @Melle-Amu)
- Bugfix: Fix external class namespace #530 (thanks @danmarsden)
- Bugfix: Store recording types as language keys, not translated strings #516
- Bugfix: Define testcase class properties (PHP 8.2) #522
- Code quality: Align with Moodle's new moodle-extra ruleset #521
- Code quality: Array syntax updates in moodle-cs v3.3.7 #524
- Code quality: Test against Moodle 4.3 and PHP 8.2 #531

#### v5.1.0 ####

- Feature: Show activity date/time directly on course page #509 (thanks @cdipe)
- Regression: Auto recording was forced off by default #505 (thanks @emmarichardson)
  - Introduced in v4.7.0 when adding automatic recording settings.
- Bugfix: Validate meeting name length using Zoom's 200 character limit #512 (thanks @lcollong)
- Bugfix: Resolve database inconsistencies #505 (thanks @fabianbatioja, @foxlapinou)
- Bugfix: Skip grading/completion during pre-registration #507 (thanks @tbeachy)
- Bugfix: Correct error message handling #503 (thanks @jwalits)
- Bugfix: Provide prescribed Promise parameters #499 (thanks @fmido88)

#### v5.0.0 ####

- Backward incompatible: Drop support for JWT authentication (thanks @aspark21)
  - Zoom requires everyone to use Server-to-Server OAuth by September 1, 2023
- Backward incompatible: Require PHP 7.1+ (Moodle 3.7+) (thanks @rlaneIT)
- Backward incompatible: Drop Moodle 3.4 mobile support

#### v4.10.3 ####

- Bugfix: Also use proxy settings for OAuth token request #494 (thanks @adnbes)
- Bugfix: Clean up exception handling to avoid notice #482 (thanks @andremenrath)
- Bugfix: Avoid course/activity completion form overhead #481 (thanks @phette23)
- Regression: PHP 7.0 class constant visibility errors #495 (thanks @rlaneIT)
  - Introduced in v4.10.1 when aligning with PSR-12 coding standards.

#### v4.10.2 ####

- Regression: Instructors were unable to edit Zoom activity completion defaults #479 (thanks @phette23)
  - Introduced in v4.6.0 when adding breakout room support.
- Bugfix: Course reset now verifies that the Zoom checkbox is checked #483 (thanks @carlosalal)

#### v4.10.1 ####

- Bugfix: Stop showing finished events in My Overview block #451 (thanks @nstefanski)
- Bugfix: Automatically retry on TLS connection error #466 (thanks @lcollong)
- Bugfix: Allow restoring activiting that are missing `option_auto_recording` #470 (thanks @lexxkoto)
- Bugfix: Document that each Moodle install needs its own OAuth app #475 (thanks @DLM-unipd, @haietza)
- Bugfix: Check required scopes before caching OAuth token #475 (thanks @tbeachy)
- Code quality: Align with Moodle-compatible PSR-1 and PSR-12 rules #465
- Special thanks to @rickbeasley for his contributions to this plugin and to the team.

#### v4.10.0 ####

- Feature: Option for redefine licenses to only affect users on 'this' Moodle server #436 (thanks @KepaUrzelai)
  - New setting `zoom/instanceusers`
- Bugfix: Process recordings deletes one meeting at a time #439 (thanks @juanbrunetmf)
- Code quality: Use short array syntax (MDLSITE-4776) #447
- Code quality: One space around assignment operators (MDLSITE-6594) #457

#### v4.9.2 ####

- Bugfix: New meetings did not know which user to check for security settings #438 (thanks @haietza)
- Bugfix: Use select field so registration option saves correctly #448 (thanks @paulandm)
- Compatibility: grunt rebuild against Moodle 4.1 #446

#### v4.9.1 ####

- Regression: Administrators without Zoom account were unable to edit #422 (thanks @juanbrunetmf)
  - Introduced in v4.7.0 when adding automatic recording options.
- Bugfix: Respect host settings for meeting options and reduce unnecessary API calls #422
- Bugfix: Always request JSON API responses and show error details #426 (thanks @sascha-serwe)
- Bugfix: Default start time should be in the future and be a multiple of 5 minutes #427

#### v4.9.0 ####

- Feature: Allow Registration #412 (thanks @paulandm, @haietza, @MoleLR, @lcollong, @louisaoc)
  - New setting `zoom/defaultregistration`
  - New per activity setting `registration`
- Bugfix: Update meetings task was throwing an exception #421 (thanks @lexxkoto)
- Bugfix: Add missing cache definition language string #408 (thanks @aspark21)
- Bugfix: Use user-level meeting security configuration #408
  - Removed OAuth scope: `account:read:admin`
- Regression: Moodle < 3.4 does not support hideIf()
  - Introduced in v3.5 of this plugin while tidying the form UI.
  - Minimum required Moodle version officially increased to 3.4.

#### v4.8.1 ####

- Bugfix: Moodle 4 was displaying the activity description twice #417 (thanks @Laur0r, @haietza)
- Bugfix: Avoid HTTP/2 error when using Server-to-Server OAuth #418 (thanks @phette23)

#### v4.8.0 ####

- Feature: Support Server-to-Server OAuth app #387 (thanks @haietza, @mhughes2k)
  - New settings `zoom/accountid`, `zoom/clientid`, `zoom/clientsecret`
  - Reminder: You must [switch from JWT to Server-to-Server OAuth by June 2023](https://developers.zoom.us/docs/internal-apps/jwt-faq/).
- Regression: Locked settings were not being applied #407 (thanks @krab-stik)
  - Introduced in v4.7.0 while adding support for automatic recording.

#### v4.7.0 ####

- Feature: Allow automatic recording #390 (thanks @aduranterres, @lcollong)
  - New settings `zoom/recordingoption`, `zoom/allowrecordingchangeoption`
  - New per activity setting `option_auto_recording`
  - Known issue: Causes pre-existing events to turn off automatic recording when edited.
- Performance: Static caching of repeated API calls #402 (thanks @aduranterres)

#### v4.6.2 ####

- Regression: Rename mustache templates for backward compatibility #398 (thanks @PhilipBeacon)
  - Introduced in v4.6.0 by new mustache templates in sub-directories (a Moodle 3.8 feature).
- Bugfix: Recognize the Webinar capabilities of a Zoom Events license #338 (thanks @dottbarbieri)
- Bugfix: Avoid PHP Warning when restoring Zoom activities without breakout room data #399

#### v4.6.1 ####

- Bugfix: Avoid JavaScript error when 'Show More' button does not exist #392 (thanks @mwithheld)
- Bugfix: Add missing privacy coverage for breakout rooms; fix privacy data deletion #395 (thanks @hdagheda)

#### v4.6.0 ####

- Feature: Pre-assign Breakout Rooms #371 (thanks @annouarf, @levemar, University of Montreal, @mhughes2k)
- Bugfix: Validate start times and duration for timed recurring meetings #389 (thanks @nchan31, @jwalits)

#### v4.5.3 ####

- Bugfix: Allow plugin settings to update without a configuration exception #386 (thanks @acquaalta)

#### v4.5.2 ####

- Regression: Avoid requiring paid accounts for meeting default settings #383 (thanks @nstefanski, @nickchen, @valeriy67, @obook)
  - Introduced in v3.5 to determine passcode requirements.
- Bugfix: Allow course restore to complete even when Zoom is not fully configured #378
- Code quality: Require PHPUnit to pass without warnings #379

#### v4.5.1 ####

- Regression: Fix Zoom activity icon visibility #375 (thanks @foxlapinou)
- Compatibility: Fix PHPUnit deprecation warnings #373

#### v4.5.0 ####

- Feature: Support for Reset course functionality #370 (thanks @izendegi)
- Compatibility: Improved support for Moodle 4.0 #369

#### v4.4.0 ####

- Feature: Allow configuration of section visibility on the view page #363 (thanks @aduranterres, @rayjbarrett1)
  - New settings `zoom/defaultshowschedule`, `zoom/defaultshowsecurity`, `zoom/defaultshowmedia`
  - New per activity settings `show_schedule`, `show_security`, `show_media`
- Feature: Allow administrator to set webinar by default (when available) #367 (thanks @marcellobarile)
  - New setting `zoom/webinardefault`
- Code quality: specify code coverage for tests #367

#### v4.3.4 ####

- Privacy: Add tests, support recordings, fix existing code #345 (thanks @jwalits, @tuanngocnguyen, @mattporritt, @marcghaly)
- Compatibility: grunt rebuild for MDL-73915 #364

#### v4.3.3 ####

- Fix recording table database schema definitions #358 (thanks @jwalits)
- Compatibility: Moodle upstream upgraded to php-jwt v6.0 #359
- Renamed primary branch in GitHub to `main` #353

#### v4.3.2 ####

- Only cache successful Zoom user ID values #350 (thanks @merrill-oakland)
- Code quality: Align with moodle-local_codechecker v3.0.5 #351

#### v4.3.1 ####

- Fix database schema alignment and associated code #335 (thanks @TomoTsuyuki)
- Run "Update Meetings" task once per day by default #342 (thanks @deraadt for reporting)
  - Note: You may need to manually adjust your task schedule on existing installs.

#### v4.3 ####

- Add support for Zoom Cloud Recordings #292 (thanks @jwalits, @nstefanski, @abias, ETH Zürich)
  - New setting `zoom/viewrecordings`
  - New per activity setting `recordings_visible_default`
- Fix tracking field PHP notices #337 (thanks @alina-kiz, @ndunand, @haietza)

#### v4.2.1 ####

- Fix PHP 8 deprecation warning #332 (thanks @ndunand)
- Fix duplicate column name on "All Meetings" page #330

#### v4.2 ####

- Add support for Zoom Tracking Fields #308 (thanks @haietza, @porcospino)
  - New setting `zoom/defaulttrackingfields`
- Send plaintext version of Moodle intro to Zoom #290 (thanks @Ottendahl, @abias, @yanus for reporting)
  - Note: To avoid losing Moodle's rich text, we no longer synchronize Zoom's topic back to Moodle.
- Reduce zoom_refresh_events overreach; fix 'quick edit' issue #320 (thanks @alina-kiz, @jwalits for testing)
- Add error handling and improve consistency in Zoom activity restore #328 (thanks @jonof)

#### v4.1.3 ####

- Always use a fresh copy of start_url #316 (thanks @ShilVita for reporting)
- Synchronize calendar events consistently #319 (thanks @martinoesterreicher for reporting)
- Update JWT library to v5.4.0 #312

#### v4.1.2 ####

- Make loadmeeting consistent via web and mobile (event, completion, grade, etc) #307 (thanks @nstefanski)

#### v4.1.1 ####

- Fix invitation class not found exception #296 (thanks @byvamo for reporting)

#### v4.1 ####

- Allow configuration of Zoom identifier #280 (thanks @jwalits, @abias, @jonlan)
  - New setting `zoom/apiidentifier`
- Allow configuration of Zoom API endpoint #293 (thanks @abias, @didier63)
  - New setting `zoom/apiendpoint`
- Use case-insensitive email comparison for schedule_for #295 (thanks @stopfstedt, @briannwar)

#### v4.0 ####

- Fully support recurring meetings #258 (thanks @abias, @jwalits, ETH Zürich)
  - New setting `zoom/invitationremoveicallink`
  - Backward incompatible change: exported iCal events now match Moodle's uid format
- Retroactively fix database schema defaults #291 (thanks @foxlapinou for reporting)

#### v3.8.1 ####

- Only allow real host to use start_url #285 (thanks @abias for reporting)

#### v3.8 ####

- Add support for Ionic 5 #269 (thanks @dpalou)
- Improve update_meetings scheduled task #263 (thanks @abias)
- Re-enable mustache continuous integration #276
- Treat alternative hosts as a possible host #275
- Update `exists_on_zoom` consistently #273 (thanks @abias for reporting)
- Update `timemodified` only when needed #279 (thanks @abias for reporting)
- Fix meeting invitation issues #267, #274 (thanks @abias, @nstefanski, @andrewmadden for feedback)

#### v3.7 ####

- Allow administrators to selectively remove Meeting Invitation details #235 (thanks @andrewmadden)
  - New capabilities `mod/zoom:viewjoinurl` and `mod/zoom:viewdialin`
- Track completion for mobile users #238 (thanks @nstefanski, @tzerafnx)
- Fix backup and restore of several zoom activity-level fields #247 (thanks @abias)
- Fix meeting reports task for some already-numeric end times #236 (thanks @lcollong)
- Fix list of alternative hosts to only include active users #252 (thanks @abias)
- Fix PHP 7.1 compatibility issue #243
- Fix encryption type validation #232 (thanks @abias)
- Clean up error messages / efficiency on the view page #245 (thanks @abias)

#### v3.6 ####

- Fixed fatal regression on settings.php for Moodle < 3.7 (Thanks abias)
- Fixed debugging messages that occur for users without webinar licenses
- Various string improvements

#### v3.5 ####

- Removed language translations. Please submit language translations to AMOS (https://lang.moodle.org/)
- Fixed bug causing downloading of meeting participation reports to fail
- Added new settings for E2EE, Webinars, Alternative hosts, Download iCal,
  Meeting capacity warning, and Enable meeting links (Thanks abias)
- Improved UI for admin and module settings (Thanks abias)
- Support for admins to update Zoom meeting participation reports
- Quick editing Zoom meeting name will now update calendar event
- Support for more advanced passcode requirements
- This will be the last supported release by UCLA. This plugin will now be maintained by jrchamp and NC State DELTA.

#### v3.4 ####

- Used Dashboard API to improve get_meeting_reports task
- Added meeting invite text to calendar and meeting page to provide phone details
- Zoom meetings now appear in Timeline block (Thanks nstefanski)
- Added basic Analytic indicators (Thanks danmarsden)
- Fixed calendar icon not showing up for non-Boost themes (Thanks danowar2k)
- Added support for Moodle 3.10
- Allow privileged users without Zoom to edit meetings (Thanks jrchamp)
- Fixed bugs related to scheduler support (Thanks jrchamp)
- Fixed participant count for meeting sessions so it only counts unique users
- Zoom descriptions keep HTML formatting (Thanks mhughes2k)
- Fixed failing DB schema checks (Thanks dvdcastro)
- Requiring passcodes is now a site wide configuration

#### v3.3 ####

- Fixed problems with error handling (Thanks kbowlerarden and jrchamp)
- Added language translations for uk, pl, and ru (Thanks mkikets99)
- Thanks to kubilayagi for all his work on the Zoom plugin these past 2.5 years and good luck on future endeavors

#### v3.2 ####

- Password/Passcode changes
  - Renamed passwords to passcodes
  - Added passcodes to Webinars (Thanks jrchamp)
  - Passcodes are now required
- Implement completion viewed when user joins meeting (Thanks nstefanski)
- License recycling improvement (Thanks mrvinceo)
- Added scheduler support (Thanks mhughes2k)
- Added support for Zoom API changes related to next_page_token and rate limiting
- Fixed error handling for non-English Zoom deployments
- Added Travis CI support

#### v3.1 ####

- Added site config to mask participant data form appearing in reports (useful for sites that mask participant data, e.g., for HIPAA) (Thanks stopfstedt)

#### v3.0 ####

- Support Retry-After header in Zoom API
- Supports longer Zoom meeting ids
- Added more meeting options: Mute upon entry, Enable waiting room, Only authenticated users.
- Changed to be Host/Participant video to off by default
- Meeting have passwords set by default
- Improvements to "Get meeting report" task to better handle data errors
- Removed "Attendee attention" column in participant report, because it has been removed by Zoom
- Added a new setting 'proxyurl' that can be used to set a proxy as hostname:port. This will be used for communication with the Zoom API (but not anywhere else in Moodle). (Thanks pefeigl)
- Fixed meeting dates during restore (Thanks nstefanski)
- Added German translation (Thanks pefeigl)

#### v2.2 ####

- Resized svg icon (Thanks stopfstedt)
- Fixed error handling for 'User not found on this account' (Thanks nstefanski and tzerafnx)
- Incorrect return value for zoom_update_instance (Thanks jrchamp)
- Added global search support
- Fixed inconsistent "start_time" column (Thanks tuanngocnguyen)

#### v2.1 ####

- Moodle 3.7 support (Thanks danmarsden)
- Privacy API support
- Moodle mobile support fixed for 3.5 (Thanks nstefanski)
- iCal generation
- Various bug fixes/improvements.

#### v2.0.1 ####

- Fixing conflicts with Firebase\JWT library. If more conflicts are found,
  please contact plugin maintainer to add to list in classes/webservice.php.

#### v2.0 ####

- Updated to support Zoom API V2
- Added SVG icon for resolution independence (Thanks rrusso)
- Additional logging
- License recycling (Thanks tigusigalpa)
- Participant reports improved (local storage and added attentiveness score)
- GDPR compliance
- Support for alternative hosts

#### v1.7 ####

- Lang string BOM fix (Thanks roperto/tonyjbutler)
- Support for proxy servers (Thanks jonof)
- Improved handling of meetings not found on Zoom
- Exporting of session participants to xls
- Improved participants report
- Fixing coding issues

#### v1.6 ####

- Addressed coding issues brought up by a MoodleRooms review done for CSUN.

#### v1.5 ####

- Fixed upgrade issues with PostgreSQL

#### v1.4 ####

- Added missing lang string for cache.
- Updated activity chooser help text.
- Added support for webinars.
- Fixing Unicode issues.

#### v1.3 ####

- Fixed join before host option.
- Added Zoom user reports.
- Added connection status checking on settings page.

#### v1.2 ####

- Allowing Zoom users to be found by other login types than just SSO.

#### v1.1 ####

- Issue #1: allow underscores in API key and secret.
- Issue #2: Fix language strings to not use concatenation.
- Added support for "group members only".

#### v1.0 ####

- Initial release
