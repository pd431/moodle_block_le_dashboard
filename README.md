# My assessments (block_le_dashboard) 5.0.0-beta29

A dashboard block for Moodle 4.5 and 5.2. It shows students their assessments, markers their marking and
module convenors the marking across their modules. This version is the plugin-agnostic remake: it
works without mod_coursework and reads each activity type through its own adapter.

## Requirements

- Moodle 4.5 (2024100700 or later) to 5.2, tested on 4.5.14 with PostgreSQL 16 and MariaDB 10.11,
  and on 5.2.4 with PostgreSQL 16. One codebase: where Moodle
  changed (assignment marker allocation and multiple markers in 5.0), the adapter checks the
  database schema, not the version.
- No other plugins are required. Assignment and Quiz have full support. Peer work and Coursework
  get full support when they are installed. Any other activity type with grade items can be
  switched on to show its due date and grade.
- Cron must run. Indexing happens in ad hoc tasks and an hourly index task, which does its full check of
  live courses between 02:00 and 06:00.

## Install

1. Unzip into `blocks/le_dashboard` and visit Site administration > Notifications.
2. In Site administration > Plugins > Blocks > My assessments > Settings, set the summative and
   formative tags and the student, staff and module convenor roles.
3. Tag activities `summative` or `formative` (or the tags you chose). Only tagged activities are
   listed. Those without a due date appear under Undated, and convenors get a hint to add one.
4. Activity types lets you choose which types appear. Academic years sets the year filter for staff
   and convenors.

When the block is installed or upgraded it queues a backfill of the index. Saving the tag or role
settings queues another, so steps 2 and 3 can come in either order. Until a backfill finishes the
dashboard says it is being prepared.

## How it works

- Three block-owned tables form an index: assessments (`block_le_dashboard_cm`), students per
  assessment (`_stu`) and marking units per stage (`_mark`). Each type's adapter fills them through
  that plugin's own rules (`classes/local/adapter/`).
- The index is updated by events, by the Refresh button (inline for small scopes, queued for large
  ones) and by the hourly index task: it carries on an unfinished first fill at any hour, and between
  02:00 and 06:00 re-indexes live courses, which catches anything events missed. Each run stops at a
  time limit and the next carries on where it stopped. Refresh also looks for tagged activities in the
  viewer's courses that the index doesn't have yet. One re-index of an activity runs at a time (a lock
  per activity), and one failing activity doesn't stop the others.
- `classes/local/index/indexer.php` is the entry point: `enrolment` says who is in a course, `writer`
  fills an activity's rows from its adapter, `queue` holds the background re-indexes. The marking rules
  the staff and convenor views share are in `classes/local/view/marking_rules.php`, each in an SQL and a
  PHP form that a test keeps in step; `staff_row` builds the rows of both views.
- Indexing failures are printed in the task log and shown, one per activity type with a count, in
  Activity types.
- Sections are worked out on page load from the stored dates and the time, so an assessment becomes
  overdue at its deadline without re-indexing (`classes/local/view/classifier.php`).
- Views follow rc6: anyone with a staff or module convenor role (where they have an active
  enrolment) gets only My marking and/or the Convenor view; everyone else gets the Student view
  (`classes/local/scope.php`).
- Students see an assessment only if they can access it now. Group, grouping and profile
  restrictions are stored in the index. Date and completion restrictions are checked live.

## Adding an activity type

Write a class in `classes/local/adapter/` that extends `base`, add it to `registry::OWN`, list the
plugin's events in its `events()` and add them to `db/events.php`. Give it a Font Awesome icon in
`format::ICONS` (`classes/local/output/format.php`); without one it gets the fallback icon.
`tests/events_test.php` fails if an adapter's events are not observed.

Everything the dashboard knows about a type comes from its adapter; no other code may name the type
(`tests/adapter_contract_test.php` checks). Besides the rows, an adapter says:

- `events_for_related_user()`: events about the student they name (a teacher's lock), whose rows are
  updated at once.
- `brief_areas()`: the file areas a brief can sit in, besides the intro.
- `takes_submissions()`: whether students hand work in online (`null` asks the activity).
- `takes_selfcert()`, `selfcert_subject()`, `selfcert_used()`: self-certified extensions through
  block_rhb, for types that take them (coursework).

`DECISIONS.md` says what the finding codes in comments (`EC4`, `G9`, `PR2`...) stand for.

## Styling

Edit `scss/styles.scss`, then compile it to `styles.css` with Dart Sass. The palette and tones are
custom properties at the top of the file. Which state gets which colour is one map,
`$ma-state-tones`; PHP and templates only emit state names. Do not edit `styles.css` by hand.
`moodle-plugin-ci grunt` runs Moodle's own stylelint rules (no duplicate selectors, no invalid or
vendor-prefixed properties, Moodle's ordering and formatting) on the compiled CSS, with no warnings
allowed.

## Tests

- PHPUnit: `vendor/bin/phpunit --testsuite block_le_dashboard_testsuite`. `tests/golden_master_test.php`
  compares every view's export for a sample course at a fixed time with `tests/fixtures/golden_master.json`;
  a change that means to alter what a view shows rewrites it with `GOLDEN_MASTER_WRITE=1`.
- The test seams (fault points, the Behat clock, the block_rhb stand-in) are all in
  `classes/local/testing.php`; outside a test run they do nothing.
- CI: `.github/workflows/ci.yml` runs moodle-plugin-ci on Moodle 4.5 and 5.2 with PostgreSQL and
  MariaDB.
- Behat: `--tags @block_le_dashboard`. Scenarios that need mod_coursework skip on sites without
  it. The `*-assign.feature` files run the same coursework rules with Assignment instead.
