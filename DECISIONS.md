# Decisions

The code comments cite finding codes (`EC4`, `G9`, `PR2`...) and rules Pierre gave on a date. This file
says what each stands for, so a maintainer without the project folder can tell why a rule is the way it
is. One line per code: the rule, and the date it was decided. Where a comment says "beta29, F3" the code is
from the maintainability audit; a bare `F` code is from the support map audit or the performance review,
as the sections say.

Sources, by series: **A** dev architecture audit (2026-10-06); **B** support map audit of beta22 and the rc6
test checklist (2026-10-05, codes carried from rc6); **CS** coursework schema at Exeter (2026-10-28); **D** logic overhaul design v2 (2026-10-02); **EC** edge
case review (2026-10-03); **F** support map audit fixes (2026-10-06), performance review (2026-10-04) and
maintainability audit (2026-10-22); **G** workflow gaps review (2026-10-03); **P** rc6 performance review
(2026-09-30); **PR** production readiness review (2026-10-05); **R** Pierre's answers to the v1 design
review (2026-10-02).

## A: dev architecture audit (2026-10-06)

- **A1** The four backfill writers (install, settings, rebuild, reconcile) queue one backfill and only one
  ever waits to start; a running one has read its data, so a change made meanwhile queues another.
- **A3** A backfill queued while one runs waits behind it rather than doubling it.
- **A6** A submission's own update, and a student's Refresh, wait at most 2 seconds for the activity's lock;
  past that the queued task does the work.
- **A7** If marking rows fail, each student is tried alone; only the units of a student who still fails keep
  their last indexed rows.
- **A9** The Refresh throttle is per view and is set only after the refresh did its work.
- **A11** The academic year form shows the year's last day, as the list does, and stores the day after it.
- **A12** A role assigned or unassigned on a category queues a re-index of every indexed course under it.

## B: support map audit of beta22, rc6 test checklist

- **B1** A teacher's "Prevent submission changes" on a student who hasn't submitted closes the work: the
  student can't submit (2026-10-05).
- **B4** A teacher's lock or unlock is about the student it names (the event's relateduserid), whose rows are
  updated at once (2026-10-05).
- **B9** No "You can still submit for N hours" countdown on an assignment without a real cut-off (beta23,
  2026-10-05).
- **B42** A cleared plagiarism case no longer flags the coursework: rc6 counted statuses 0 to 3, and 2 is
  "cleared" (rc6 checklist; Pierre's rc7 commit, 2026-10-07). The other statuses are still inferred from rc6.
- **B43** The plagiarism flag shows in every section, not only Live (rc6 checklist).

## CS: coursework schema at Exeter (2026-10-28)

Pierre's mod_coursework schema reference, checked against ele-test, and his rulings on it.

- **CS0** The coursework adapter reads Exeter's coursework by the reference's names, one name per fact
  (use_groups, finalised, stage_identifier, personal_deadline, coursework_mitigations). Coursework is not a
  public plugin: there is no other build to tolerate, and the guesses between builds were wrong as often as right.
- **CS1** A coursework whose only submission type is marks entry takes nothing online (Pierre: marks entry is
  attendance). Attendance is its first marking stage, taken for a student once coursework has a submission row for
  them, as marks entry creates one; then coursework's own stages. A coursework with no submission types recorded is
  standard. The signal that attendance was taken is inferred from Exeter's data, not confirmed.
- **CS2** Coursework is observed by the events Exeter's event log shows firing: the file sub-plugin's
  assessable_uploaded, and coursework_mitigation_added and _removed for a self-certification made or taken back
  through block_rhb. Each names the student, so their rows update at once (F4). extension_created, _updated and
  _deleted are gone: they belong to a coursework_extensions table Exeter's build doesn't have. Every coursework
  event is handled after its transaction commits (PR2), coursework_deadline_changed included.
- **CS3** Plagiarism flag statuses as Pierre gave them: 0 is referred to the academic conduct officer, still to
  mark and counted in the flag hint; 1 and 2 are cleared, as if there were no flag; 3 withholds the results, so
  markers owe nothing more and the unit is in no marking count, though the student's row and the Submitted bar
  are unchanged. Any other status, or none, reads as 0. The latest flag row of a submission counts. Withheld
  units keep their marking rows (counted = 0) because the case can reopen: the one academic conduct flag counts
  them with the open ones and says in its own sentence that they left the workload, and a marker whose units
  are all withheld keeps the assessment under Completed marking with that flag (Pierre, 16:13 and 16:17). One
  flag, not two: both counts come from the same coursework table. Settles B42.
- **CS4** A coursework mitigation of type permanent (exemption) or temporary (deferral) means nothing is expected of
  the student there (Pierre: "can be treated as complete", and for staff "drop off the denominator altogether").
  They leave every count of who should hand in (stu.expected = 0) and every marking count (counted = 0), with or
  without a submission, and are in no hint. The student sees "Exempt" by the date alone (Upcoming, then Past,
  never Overdue), one icon whose tip says a mitigation applies, and no actions, not even Mitigate. An exemption
  or deferral wins over an extension; on a group it applies to every member, as extensions do.
- **CS5** A student's deadline moved by an extension or a self-certification is labelled "Extended deadline" on
  their row, not "Due" (Pierre: "should trigger 'extended deadline' instead of due"). Any activity type, since the
  index records the mitigation the same way for all. Staff rows are unchanged: their extensions hint already
  names the latest extended deadline.

## D: logic overhaul design v2 (2026-10-02)

- **D2** A personal deadline replaces the default deadline, even when earlier; a mitigation extension can
  only move it later.
- **D4** Released means the agreed or final grade was published to the student, dated by publish time.
  Individual markers' feedback never counts.
- **D6** Unallocated work is shown to convenors only.
- **D8** Mitigate is offered on summative assessments only (2026-10-04). Quiz "needs marking" counts only
  finished attempts that count for the grade.
- **D9** A student's course scope is their active enrolments in courses they can see
  (enrol_get_all_users_courses(me, true)).

## EC: edge case review (2026-10-03)

- **EC1** Work allocated to a marker who left the module (no active enrolment with a staff or convenor role)
  is unallocated while unmarked, so it falls to the convenor; work they marked stays theirs.
- **EC2** An assessment with no due date is listed under Undated, with a hint for convenors, rather than
  vanishing.
- **EC3** The staff and convenor views show an assessment in the selected year when its default deadline or the
  latest deadline any student has (an extension, override or personal deadline) falls in the year, or when its
  course belongs to the year (EC3b). Nothing outside it is shown or counted, outstanding marking included; All
  years shows everything. Changed by Pierre on 2026-10-28, replacing the exception that kept outstanding marking
  of other years in view with its course's year in brackets.
- **EC4** Students whose enrolment ended or was suspended keep their rows, flagged inactive, so their
  submitted work is still marked; unenrolled students leave.
- **EC5** An assignment with no online submission behaves like the generic tier for students (no Submitted
  bar, never Overdue), with marking ready at the deadline.
- **EC7** An activity being deleted is skipped in the staff and convenor queries until core's task removes it.
- **EC8** Marked work is credited to whoever marked it, whatever the allocation.
- **EC9** With allocation, only the allocated markers' marks count towards agreement, as assign's
  get_marks(); an agreement or moderation stage not yet open says nothing rather than "0 of 0".
- **EC10** Each student row records when the student joined the course, so a late joiner past the grace
  period is Past, not submitted, with no overdue icon.
- **EC11** A graded attempt reverted to draft reads as a reopened attempt.

## F: support map audit fixes (2026-10-06)

- **F1** A released grade counts as the outcome, submitted or not: the row goes to Past with "Feedback
  released", never Overdue.
- **F2** Upcoming and Overdue hold only work the student can still hand in (Pierre, 2026-10-06 22:06); past
  the cut-off, a quiz's close or its last attempt, the row goes to Past.
- **F3** The convenor's In progress follows My marking: past the deadline, an assessment with nothing left to
  mark or release goes to Past.
- **F4** "New work" shows only when every unit handed in before the last mark had been marked, and the last
  mark came after the deadline; marking done before the deadline never finished anything.
- **F5** A backfill queued by a settings change re-indexes every course, ignoring the live window.
- **F6** Generic types and assignments with no submission plugin show "Closed" in Past, with no "Not
  submitted" icon: the block can't see those submissions.
- **F8** The quiz marker is whoever saved the last manual grade on the attempt's questions.
- **F10** A quiz whose review never shows marks never releases: a fully marked attempt is finished, Past as
  "Submitted", with no grade claimed (coordinator default, 2026-10-06).
- **F11** With marks shown after close only, each student is released at the later of the gradebook's
  release and their own close.

## F: performance review (2026-10-04)

- **F1** A re-index asked for while the same one is already waiting is not queued twice; one may wait while
  another runs, since re-indexing is idempotent.
- **F2** One re-index of an assessment at a time, under a lock per activity; one failing activity doesn't
  stop the others.
- **F3** Showing a hidden grade item releases its grades, so grade item changes are observed.
- **F4** The actor's own change shows at once: a student's own action rewrites their rows inline, everyone
  else's in the queued task; a student's Refresh rewrites only their own rows.
- **F5** The reconcile covers live courses only (an indexed deadline within the live window, ahead or
  unset, or a tagged activity not yet indexed); older courses keep their rows.
- **F6** Observer work during regrades is memoised, since a regrade fires user_graded per student per item.
- **F7** Lookups repeated on first load (course scopes, the default year, grade item to activity) are
  memoised per request.
- **F9** Refresh has a rate limit (a second press within a minute re-indexes nothing) and "Refreshing"
  clears by itself.

## F: maintainability audit (2026-10-22), cited as "beta29, F"

- **F1** "Mine" is defined once (marking_rules), feeding both the marking queries and the extensions count.
- **F2** Each SQL rule has one PHP twin, and a test asserts they agree.
- **F3** One builder makes the staff and convenor rows, with the hints in one fixed order.
- **F4** The marking aggregates are said once.
- **F5** The indexer is three classes (enrolment, writer, queue) behind one facade.
- **F7** What an activity type does is the adapter's to say; no other code names a type.
- **F9** Every test seam sits behind one class with one gate and named fault points.
- **F10** This file: codes and dated rules resolve in the repository.
- **F13** README and privacy metadata describe the current build.
- **F14** Feature files are named by behaviour, not by delivery batch.

## G: workflow gaps review (2026-10-03)

- **G3** Unallocated work on an activity with allocation falls to the module convenor's My marking.
- **G4** Mitigate shows from 3 months before the deadline to 24 hours after the student's own deadline, both
  ends configurable (Pierre's answer to Q1).
- **G5** Student rows carry plain lines under the dates: time left, lateness and the cap, each at the
  precision its size needs.
- **G6** Work that comes back after marking was finished is said so ("New work"), in My marking and the
  convenor view.
- **G7** With several markers and no allocation, each marker's share is their own marker stage: the one they
  marked, else the first open one; a marker who has given their mark is done with that unit.
- **G8** On assign with allocation, the agreed stage goes to the student's first allocated marker, as
  coursework does.
- **G9** No early marking: work is ready once the student's deadline has passed; an extensions line shows
  students who can still submit, and a student stops counting once their deadline plus the grace period
  has passed (Pierre's answer to Q3).
- **G10** Work unmarked past the marking deadline is called out for convenors.
- **G12** The "Feedback expected" date stays once it has passed, with a hint saying who to contact (Pierre's
  answer to Q2).
- **G13** A reopened attempt not yet resubmitted is Upcoming with Submit and no pill.
- **G15** Bars whose units are groups say so, since submissions count students.

## P: rc6 performance review (2026-09-30)

- **P2** Deadline rush: locks that students' submissions wait on are short, and a filter change doesn't
  reload the page.
- **P6** Within a web request, re-indexes are collected and each queued once at the end of the request.
- **P9** Quiz "needs marking" never scans question_attempt_steps: a finished attempt that counts for the
  grade with a question needing grading.
- **P10** The student's course scope is computed once, not rebuilt in SQL for every query.

## PR: production readiness review (2026-10-05)

- **PR1** Production is MySQL-family: MariaDB is the main test lane; removal runs under the activity's lock
  so a re-index already reading it can't put the rows back.
- **PR2** A write that fails inside index_rows is rolled back before anyone catches the error, so later
  writes in the process aren't silently undone.
- **PR3** Names are escaped once: format_string() values go into templates with escaping off.
- **PR4** Refresh also looks for tagged activities in the viewer's courses the index doesn't have yet.
- **PR5** Saving a tag or role setting queues a full re-index and shows Preparing.
- **PR7** "Refreshing" can't stick: it expires, and the button comes back.
- **PR9** Bootstrap 4 and 5 classes are paired (sr-only visually-hidden, mr-1 me-1).
- **PR13** A marker's privacy request covers the student rows that name them as allocated marker.
- **PR14** Indexing errors are kept in memory during a run and stored once per failing type, with a count,
  when the run ends; tasks also print them to the task log.

## R: Pierre's answers to the design review (2026-10-02)

- **R1** Activities blocked by any restriction are hidden from the student, dates included.
- **R2** Past the cut-off and unsubmitted: Past, "Not submitted".
- **R3** Staff and convenors see hidden activities, flagged.
- **R4** The dashboard is a cached value with a refresh control: the index, and Refresh.
- **R5** Any activity type with gradebook items can be switched on through the generic adapter; generic
  types can't know about submissions, so they are never Overdue.
- **R8** LTI is out of scope for now.

## Dated rules

Rules Pierre gave in review, by date. Code comments state the rule; the date is here.

- **2026-10-03** G4: Mitigate from 3 months before the deadline to 24 hours after. G9: no early marking. G12:
  keep the Feedback expected date, with a contact hint once it passes.
- **2026-10-04** Statuses of the ordinary case show no pill; work handed in with no deadline and no grade
  stays in Undated as Received; finished work goes to Past whatever its date; undated work in Past sorts by
  when feedback was given; with no deadline, all my marking done is finished. Late is a clock, so due soon
  is an hourglass. Mitigate is for summative work only (D8).
- **2026-10-06** Upcoming and Overdue hold only work the student can still hand in (F2). A reopened attempt
  is ordinary upcoming work, with no pill; an extension is ordinary upcoming work, with no note. Nothing to
  hand in online: the row says so while the date is ahead. Hints open their description on a click, keyboard
  focus or a short hover. Convenors get a hint listing compatible activities nobody tagged, in the selected
  year's courses.
- **2026-10-07** Staff are flagged when a tagged assessment is missing a setting ("Check settings"), each
  problem on its own line; convenors get a line listing them. Finished marking shows where the whole
  assessment stands, one status over the whole row, with the bars and markers behind Show marking. A
  cleared plagiarism case isn't open (B42, as in Pierre's rc7 commit).
- **2026-10-08** An assessment that can't be indexed is still listed for staff, marked as not loaded with an
  honest note in place of bars that would read 0 of 0.
- **2026-10-09** "All times ... should look like the server was in China": dates show in the viewer's own
  time zone. Work handed in early stays in Upcoming until the deadline even when it can't be changed any
  more (reversing 2026-10-06): the row says why instead of offering Submit ("Can't resubmit"); the same
  for a quiz once an attempt was finished. Mitigate: option A of the Mitigate options, block_rhb's links
  under our labels; the request form is formal mitigation; a self-certified extension already given has
  its own red Delete item with a bin, confirmed by a second click in place; the menu counts down as the
  row does. Each hint has a guidance box under its factual line; "Guidance and support" settings hold
  the text. Marker names link to the marker's email; the course name in the untagged list links to the
  course. Students an access restriction shuts out leave the marking counts; lifting it brings them back.
- **2026-10-17** The generic name hint: an assessment named only with generic words gets a hint for staff.
  The missing brief flag is a half-dashed file. An admin notice can sit above the tabs.
- **2026-10-19** The index task runs hourly: it carries on a first fill at any hour, so the index fills where
  ad hoc tasks don't run, and does the nightly check between 02:00 and 06:00; each run stops at a time
  limit and the next carries on ("gracefully fail after a while, and pickup where it left off"). "Site
  admin can choose whether to expose internal marking deadlines to students as is, with a specified
  delay, or not at all." Each optional hint has an on/off switch with a neutral default on a new site; an
  upgrade turns each one on (beta27 hints audit). The convenor's two tagging lines each have an admin
  switch. An assessment with no marking deadline gets the default marking period after its deadline,
  whatever its type. The plugin draws its own half-dashed file icon on 4.5 "UNTIL WE MOVE TO 5.3". UAT:
  activity icons are monologo in the purpose colour; a row reads "Opens ..., Due ..."; a quiz that hasn't
  opened offers no Submit.
- **2026-10-20** After the deadline, a student row's dates come in the order things happen, each label on
  its own line so the dates line up (replacing D6's wording); the hint sits beside the date it explains.
- **2026-10-21** Completed marking holds only my own workload: work allocated to me or that I marked; work
  without allocation that colleagues marked counts as mine to mark but isn't my own once done. My marking
  has no Upcoming section, and Undated leaves out what is already listed. Rows sort soonest first while
  there's work, latest first once done, undated by name, ties by name; the convenor's Upcoming sorts by
  submission deadline, the rest by marking deadline, else deadline. Release is outstanding until every
  marked unit is released (reversing 2026-10-07). Past its deadline with nothing to mark or release, an
  assessment goes to the convenor's Past at once, and late work brings it back. Work locked by a teacher,
  or every quiz attempt used with none finished, stays in Upcoming saying why there's no Submit.
- **2026-10-22** The Mitigate menu has no greyed item: a line says why self-certification can't be used.
  A colleague's marking isn't my own.
