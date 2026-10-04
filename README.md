# My assessments (block_my_assessments) 5.0.0-beta10

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
- Cron must run. Indexing happens in ad hoc tasks and a nightly reconcile.

## Install

1. Unzip into `blocks/my_assessments` and visit Site administration > Notifications.
2. In Site administration > Plugins > Blocks > My assessments > Settings, set the summative and
   formative tags and the student, staff and module convenor roles.
3. Tag activities `summative` or `formative` (or the tags you chose). Only tagged activities that
   have a due date are listed.
4. Activity types lets you choose which types appear. Academic years sets the year filter for staff
   and convenors.

When the block is installed or upgraded it queues a backfill of the index. Saving the tag or role
settings queues another, so steps 2 and 3 can come in either order. Until a backfill finishes the
dashboard says it is being prepared.

## How it works

- Three block-owned tables form an index: assessments (`block_my_assessments_cm`), students per
  assessment (`_stu`) and marking units per stage (`_mark`). Each type's adapter fills them through
  that plugin's own rules (`classes/local/adapter/`).
- The index is updated by events, by the Refresh button (inline for small scopes, queued for large
  ones) and by a nightly reconcile that catches anything events missed. Refresh also looks for
  tagged activities in the viewer's courses that the index doesn't have yet. One re-index of an
  activity runs at a time (a lock per activity), and one failing activity doesn't stop the others.
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
`format::ICONS` (`classes/local/output/format.php`); without one it gets the fallback icon. `tests/events_test.php` fails if
an adapter's events are not observed.

## Styling

Edit `scss/styles.scss`, then compile it to `styles.css` with Dart Sass. The palette and tones are
custom properties at the top of the file. Which state gets which colour is one map,
`$ma-state-tones`; PHP and templates only emit state names. Do not edit `styles.css` by hand.
`stylelint` with the rules in the rc6 handover (no duplicate selectors) passes on the compiled CSS.

## Tests

- PHPUnit: `vendor/bin/phpunit --filter block_my_assessments`.
- CI: `.github/workflows/ci.yml` runs moodle-plugin-ci on Moodle 4.5 and 5.2 with PostgreSQL and
  MariaDB.
- Behat: `--tags @block_my_assessments`. Scenarios that need mod_coursework skip on sites without
  it. The `*-assign.feature` files run the same coursework rules with Assignment instead.
