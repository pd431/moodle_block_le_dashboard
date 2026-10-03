<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings. Every visible label comes from here; templates use {{#str}} and PHP get_string().
 *
 * @package    block_my_assessments
 * @copyright  2026 onwards block_my_assessments contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['academicyear'] = 'Academic year';
$string['academicyears'] = 'Academic years';
$string['action_markers'] = 'Show markers';
$string['action_mitigate'] = 'Mitigate';
$string['action_submit'] = 'Submit';
$string['action_view'] = 'View';
$string['activity'] = 'Activity';
$string['activitytypes'] = 'Activity types';
$string['activitytypes_desc'] = 'Every activity type with gradebook entries on this site. Tick a type to list its tagged activities on the dashboard. Tags still decide which individual activities appear. External tools (LTI) are not offered.';
$string['addyear'] = 'Add an academic year';
$string['allyears'] = 'All years';
$string['bar_released'] = 'Released';
$string['bar_submitted'] = 'Submitted';
$string['bar_unallocated'] = 'Unallocated';
$string['both'] = 'Both';
$string['box_staff_duesoon'] = 'Deadlines';
$string['box_staff_marked'] = 'Marked by me';
$string['box_staff_tomark'] = 'To mark';
$string['box_student_overdue'] = 'Overdue';
$string['box_student_selfcert'] = 'Self-certified extensions';
$string['box_student_submitted'] = 'Submitted';
$string['box_student_upcoming'] = 'Upcoming';
$string['briefguidanceurl'] = 'Brief guidance link';
$string['briefguidanceurl_desc'] = 'Linked from the hint convenors see on assessments with no brief or description. Leave empty to show the hint without a link.';
$string['briefhint'] = 'No brief';
$string['briefhint_guidance'] = 'guidance';
$string['briefhint_link'] = 'See our {$a} for more info.';
$string['briefhint_text'] = 'This assessment has no brief or description attached. Including the assessment brief within the activity will help students find instructions in a consistent place, but isn\'t a requirement.';
$string['confirmdeleteyear'] = 'Delete the academic year "{$a}"? If it is the default year, the default goes back to the current year.';
$string['date_due'] = 'Due';
$string['date_feedbackexpected'] = 'Feedback expected';
$string['date_markingdue'] = 'Marking due';
$string['date_released'] = 'Released';
$string['date_submitted'] = 'Submitted';
$string['defaultacademicyear'] = 'Default academic year';
$string['defaultacademicyear_current'] = 'The year containing today';
$string['defaultacademicyear_desc'] = 'The year the marker and convenor views open on. People can pick another year, and their choice is remembered.';
$string['displayheading'] = 'Display';
$string['edityear'] = 'Edit academic year';
$string['empty_convenor'] = 'There are no tagged assessments in the modules you convene for this academic year.';
$string['empty_staff'] = 'You have no marking for this academic year.';
$string['empty_student'] = 'You have no assessments to show.';
$string['enabled'] = 'Enabled';
$string['enabletype'] = 'List {$a} activities';
$string['filtertype'] = 'Assessment type';
$string['formative'] = 'Formative';
$string['formativetags'] = 'Formative tags';
$string['formativetags_desc'] = 'Comma-separated tag names. An activity with one of these tags is listed as formative.';
$string['grade'] = 'Grade';
$string['gradeoutof'] = '{$a->grade} / {$a->max}';
$string['hiddenfromstudents'] = 'Hidden from students';
$string['individualdeadline'] = 'Your own deadline';
$string['inlinerefreshlimit'] = 'Refresh inline up to';
$string['inlinerefreshlimit_desc'] = 'Refresh updates up to this many assessments straight away. A larger refresh, such as a convenor of many modules, is queued and done by the next cron run.';
$string['lasterror'] = '{$a->time}: {$a->message}';
$string['lastindexingerror'] = 'Last indexing error';
$string['loading'] = 'Loading…';
$string['moduleconvenorrole'] = 'Module convenor roles';
$string['moduleconvenorrole_desc'] = 'People with one of these roles in a course see the convenor view for it: all marking, by every marker.';
$string['my_assessments:addinstance'] = 'Add a new My assessments block';
$string['my_assessments:myaddinstance'] = 'Add a new My assessments block to the Dashboard';
$string['noaccesstoview'] = 'You cannot open this view.';
$string['nomatches'] = 'No assessments match your search.';
$string['noyears'] = 'There are no academic years yet.';
$string['overduetopastthreshold'] = 'Days overdue before moving to Past';
$string['overduetopastthreshold_desc'] = 'An unsubmitted assessment stays in Overdue for this many days after its deadline, then moves to Past.';
$string['pluginname'] = 'My assessments';
$string['preparing'] = 'Preparing your assessments. This page will be complete once the first update has run.';
$string['privacy:metadata:mark'] = 'Marking state per student or group and marking stage, derived from the activities.';
$string['privacy:metadata:mark:allocatedto'] = 'The marker allocated to the submission.';
$string['privacy:metadata:mark:markedby'] = 'Who marked the submission.';
$string['privacy:metadata:mark:timemarked'] = 'When the submission was marked.';
$string['privacy:metadata:mark:unitid'] = 'The student or group the submission belongs to.';
$string['privacy:metadata:preference:range'] = 'The period the "Marked by me" box covers.';
$string['privacy:metadata:preference:refreshing'] = 'When the user last asked for a queued refresh.';
$string['privacy:metadata:preference:showgrades'] = 'Whether grades are shown in the student view.';
$string['privacy:metadata:preference:type'] = 'The assessment type filter.';
$string['privacy:metadata:preference:view'] = 'The view the dashboard opens on.';
$string['privacy:metadata:preference:window'] = 'The number of days the "Deadlines" box looks ahead.';
$string['privacy:metadata:preference:year'] = 'The academic year chosen in the marker and convenor views.';
$string['privacy:metadata:stu'] = 'Each student\'s deadlines, submission and release state per assessment, derived from the activities.';
$string['privacy:metadata:stu:due'] = 'The student\'s own deadline.';
$string['privacy:metadata:stu:gradedisplay'] = 'The released grade.';
$string['privacy:metadata:stu:mitigation'] = 'Whether an extension or a self-certified extension applies to the student.';
$string['privacy:metadata:stu:submitted'] = 'Whether the student has submitted.';
$string['privacy:metadata:stu:timereleased'] = 'When the grade was released.';
$string['privacy:metadata:stu:timesubmitted'] = 'When the student submitted.';
$string['privacy:metadata:stu:userid'] = 'The student.';
$string['range_1week'] = 'In the last week';
$string['range_2weeks'] = 'In the last 2 weeks';
$string['range_ever'] = 'Ever';
$string['range_month'] = 'In the last month';
$string['range_year'] = 'In the last year';
$string['refresh'] = 'Refresh';
$string['refreshing'] = 'Refreshing. Reload the page in a few minutes to see the result.';
$string['rolesheading'] = 'Roles';
$string['rolesheading_desc'] = 'Which course roles decide who is a student, a marker and a module convenor.';
$string['rowkind'] = '{$a->type} {$a->mod}';
$string['rowtype'] = '{$a->mod} · {$a->type}';
$string['search'] = 'Search assessments';
$string['searchplaceholder_convenor'] = 'Search by name, module or marker';
$string['searchplaceholder_staff'] = 'Search by name or module';
$string['searchplaceholder_student'] = 'Search by name or module';
$string['section_convenor_inprogress'] = 'In progress';
$string['section_convenor_past'] = 'Past';
$string['section_convenor_upcoming'] = 'Upcoming';
$string['section_staff_completed'] = 'Completed marking';
$string['section_staff_tomark'] = 'Marking in progress';
$string['section_staff_upcoming'] = 'Upcoming deadlines';
$string['section_student_overdue'] = 'Overdue';
$string['section_student_past'] = 'Past';
$string['section_student_upcoming'] = 'Upcoming';
$string['selfcertexemptfield'] = 'Unlimited self-certification: profile field';
$string['selfcertexemptfield_desc'] = 'Short name of the user profile field that marks students with no self-certification limit.';
$string['selfcertexemptvalue'] = 'Unlimited self-certification: value';
$string['selfcertexemptvalue_desc'] = 'Students whose profile field above holds exactly this value have no limit.';
$string['selfcertheading'] = 'Self-certification';
$string['selfcertheading_desc'] = 'Used only when block_rhb sets a self-certification limit and mod_coursework records mitigations.';
$string['selfcertnolimit'] = 'No limit';
$string['selfcertsincefield'] = 'Count self-certifications from: profile field';
$string['selfcertsincefield_desc'] = 'Short name of the user profile field holding the date to count self-certified extensions from. Empty or missing means all time.';
$string['selfcertused'] = '{$a->used} of {$a->max} used';
$string['settings'] = 'Settings';
$string['showgrades'] = 'Show grades';
$string['showless'] = 'Show fewer';
$string['showmore'] = 'Show more';
$string['staffrole'] = 'Marker roles';
$string['staffrole_desc'] = 'People with one of these roles in a course see My marking for it.';
$string['stage_agreed'] = 'Agreed';
$string['stage_marked'] = 'Marked';
$string['stage_markern'] = 'Marker {$a}';
$string['stage_moderated'] = 'Moderated';
$string['status_closed'] = 'Closed';
$string['status_due'] = 'Open';
$string['status_inmarking'] = 'In marking';
$string['status_notsubmitted'] = 'Not submitted';
$string['status_pending'] = 'Not submitted yet';
$string['status_released'] = 'Feedback released';
$string['status_submitted'] = 'Submitted';
$string['statusloaded'] = '{$a} loaded';
$string['statusrefreshed'] = 'Dashboard refreshed';
$string['strftimedue'] = '%d %b %Y, %H:%M';
$string['studentrole'] = 'Student roles';
$string['studentrole_desc'] = 'People with one of these roles in a course see its assessments in the student view.';
$string['summative'] = 'Summative';
$string['summativetags'] = 'Summative tags';
$string['summativetags_desc'] = 'Comma-separated tag names. An activity with one of these tags is listed as summative. Summative wins if an activity has both kinds of tag.';
$string['support'] = 'What the block can show';
$string['support_full'] = 'Full';
$string['support_full_desc'] = 'Deadlines with overrides and extensions, submissions, marking and release.';
$string['support_generic'] = 'Due date and grade only';
$string['support_generic_desc'] = 'The due date from the calendar and the grade from the gradebook. Submissions are unknown, so these are never shown as overdue. Activities without a due date are not listed.';
$string['tagsheading'] = 'Tags';
$string['tagsheading_desc'] = 'Only activities tagged with one of these tags appear on the dashboard.';
$string['task_backfill'] = 'Fill the My assessments index';
$string['task_reconcile'] = 'Reconcile the My assessments index';
$string['task_reindex'] = 'Update the My assessments index';
$string['testfailure'] = 'Indexing {$a} activities failed (test).';
$string['tip_duesoon'] = 'Due soon.';
$string['tip_extension'] = 'Extension granted.';
$string['tip_hidden'] = 'Hidden from students.';
$string['tip_late'] = 'Submitted late.';
$string['tip_notsubmitted'] = 'Not submitted.';
$string['tip_overdue'] = 'Overdue.';
$string['tip_selfcert'] = 'Self-certification applied.';
$string['tip_submitted'] = 'Submitted.';
$string['tipsentence'] = '{$a}.';
$string['togglesection'] = 'Show or hide {$a}';
$string['unknownuser'] = 'Unknown user';
$string['updatedhours'] = 'Updated {$a} h ago';
$string['updatedjustnow'] = 'Updated just now';
$string['updatedminutes'] = 'Updated {$a} min ago';
$string['updatednever'] = 'Not updated yet';
$string['updatedon'] = 'Updated {$a}';
$string['view_convenor'] = 'Convenor view';
$string['view_staff'] = 'My marking';
$string['view_student'] = 'Student view';
$string['withinday'] = 'Within 1 day';
$string['withindays'] = 'Within {$a} days';
$string['xofy'] = '{$a->x} of {$a->y}';
$string['yeardeleted'] = 'Academic year deleted.';
$string['yearend'] = 'End';
$string['yearend_help'] = 'The first day after the year. Courses that start on this day belong to the next year.';
$string['yearendbeforestart'] = 'The end must be after the start.';
$string['yearlabel'] = 'Name';
$string['yearlastday'] = 'Last day';
$string['yearstart'] = 'Start';
$string['yearstart_help'] = 'The first day of the year. Courses that start on or after this day, and before the end, belong to the year.';
