# My assessments (block_my_assessments) 5.0.0-beta1

A dashboard block for Moodle 4.5. It shows students their assessments, markers their marking and
module convenors the marking across their modules. This version is the plugin-agnostic remake: it
works without mod_coursework and reads each activity type through its own adapter.

## Requirements

- Moodle 4.5 (2024100700 or later).
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

When the block is installed or upgraded it queues a backfill of the index. Until that finishes the
dashboard says it is being prepared.

## How it works

- Three block-owned tables form an index: assessments (`block_my_assessments_cm`), students per
  assessment (`_stu`) and marking units per stage (`_mark`). Each type's adapter fills them through
  that plugin's own rules (`classes/local/adapter/`).
- The index is updated by events, by the Refresh button (inline for small scopes, queued for large
  ones) and by a nightly reconcile that catches anything events missed.
- Sections are worked out on page load from the stored dates and the time, so an assessment becomes
  overdue at its deadline without re-indexing (`classes/local/view/classifier.php`).
- Students see an assessment only if they can access it now. Group, grouping and profile
  restrictions are stored in the index. Date and completion restrictions are checked live.

## Adding an activity type

Write a class in `classes/local/adapter/` that extends `base`, add it to `registry::OWN`, list the
plugin's events in its `events()` and add them to `db/events.php`. `tests/events_test.php` fails if
an adapter's events are not observed.

## Styling

Edit `scss/styles.scss`, then compile it to `styles.css` with Dart Sass. The palette and tones are
custom properties at the top of the file. Do not edit `styles.css` by hand.

## Tests

- PHPUnit: `vendor/bin/phpunit --filter block_my_assessments`.
- Behat: `--tags @block_my_assessments`. Scenarios that need mod_coursework skip on sites without
  it. The `*-assign.feature` files run the same coursework rules with Assignment instead.
