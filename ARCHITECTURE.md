# block_le_dashboard: architecture and how to add to it

Written after beta29 (2026-10-23) from the maintainability audit and the beta29 refactors it led to (DECISIONS.md, series F). The point of this file is the one lesson of that audit: the duplication that crept in between beta16 and beta27 was not copy-paste, it was the same rule written again in a new place because the builder did not know, or did not look for, the place that already owned it. So this file says who owns what, and the process says: find the owner first, change it there, and let the tests that guard each boundary tell you when you have strayed.

## The shape in one paragraph

Activity plugins are read only by **adapters**, which turn their tables into three kinds of row. The **index** (three tables: cm, stu, mark) is written only by the **writer**, which asks the adapters. The **views** read only the index, classify its rows with pure **rules**, and shape them with shared **formatters** into one **template set**. Everything else (settings, scope, years, tags, guidance, hints, mitigation, testing) is a small module with one job. Nothing above the adapters names an activity type; nothing below the views decides what a user sees.

```
mod_assign / mod_quiz / mod_coursework / mod_peerwork / anything else
        │  (plugin tables, read only here)
        ▼
classes/local/adapter/*      adapter contract: cm_row, student_rows, marking_rows, events,
                             brief_areas, takes_submissions, takes_selfcert, selfcert_*
        │
        ▼
classes/local/index/writer   the re-index (rows → cm / stu / mark tables, in a transaction)
classes/local/index/enrolment, queue, pass, indexer (facade), task/*, observer
        │
        ▼                    block_le_dashboard_cm / _stu / _mark
classes/local/view/classifier     sections and statuses (pure functions)
classes/local/view/marking_rules  "mine", ready, marking deadline, shared marking (SQL + PHP twins)
classes/local/view/staff_row      one row builder for staff and convenor
classes/local/output/format       dates, bars, hints, actions, row shell
        │
        ▼
classes/local/view/student, staff, convenor   export(): query the index, classify, build rows
templates/section.mustache, row.mustache       one template set for all three views
scss/styles.scss (one state-tone map), amd/src
```

## Who owns what

Each line is a concern, the one file that owns it, and the test that fails when the rule is restated somewhere else. "Owner" means: if the concern changes, that file changes, and callers do not.

| Concern | Owner | Guarded by |
|---|---|---|
| What an activity type is: its deadline, students, marking units, submission and self-cert behaviour, which events touch it | `adapter/<modname>.php` behind `adapter/adapter.php` | `adapter_contract_test` (no code outside the adapters names assign, quiz, coursework or peerwork), `events_test` |
| Shared adapter mechanics: gradebook release, group membership, overrides, chunking | `adapter/base.php` | |
| Which adapter serves which type; which types the site enables | `adapter/registry.php` | |
| Who is in a course (students, markers, enrol start, availability) | `index/enrolment.php` | `enrolment_test` |
| Writing one assessment to the three tables, and the transaction, locks and retries around it | `index/writer.php` | `writer_test` |
| Background re-indexes: what is queued, how it coalesces, when it runs | `index/queue.php` | `queue_test` |
| The timed walk over courses that carries on where it stopped | `index/pass.php` | `pass_test` |
| The one entry point callers use for any index operation | `index/indexer.php` (facade only, no rules) | `indexer_test` |
| Which event means "re-index what" | `observer.php` | `events_test` |
| Which section a row goes in and what its status word is | `view/classifier.php` | `classifier_test`, golden master |
| "Mine", ready to mark, marking deadline, shared (multi-marker) units, pending extensions, returned work | `view/marking_rules.php`, each rule in an SQL form and a PHP form | `marking_rules_test` (the twins agree over every row shape), `marking_mine_test` (C1–C5) |
| A staff or convenor row: dates, bars, hints in one order, actions | `view/staff_row.php` | golden master |
| A student row (stacked meta, overlays, Mitigate) | `view/student.php::row()` | golden master |
| Formatting any date, count, bar, hint, action, row shell | `output/format.php` | golden master |
| Who sees which view and over which courses | `scope.php`, `years.php` | `scope_test` |
| Settings, read in one place | `config.php` | `config_test` |
| Self-certification and the Mitigate menu (block_rhb link handling only; coursework facts come from the adapter) | `mitigation.php` | `mitigation_test` |
| Every test seam: the one gate and the named fault points | `testing.php` | `testing_test` (no other class reads a testfail or behat key) |
| Why a rule is what it is | `DECISIONS.md`, keyed by finding code | `decisions_test` (every code cited in a comment resolves; no dated comments) |
| What every view shows for a sample course at a fixed time | `tests/fixtures/golden_master.json` | `golden_master_test` |

Not yet given an owner (left as is by the audit, see DECISIONS F6, F11, F12): the two checklist clones (`untagged.php`, `setupchecks.php`), the CSV-typed `stages` and `allocatedto` columns, and the config namespace. Adding a third checklist, renaming a stage, or adding a runtime state key is the moment to give each its owner, not before.

## How to add a feature without duplicating

The audit's four high findings were all the same mistake four times: a view needed a rule, the rule existed in another view, and the builder wrote it again. So the process is mostly about looking before writing.

1. **Name the rule in words before touching code.** "Which units are mine", "when is a unit ready", "what does a row's dates line contain". If the sentence is one a maintainer could ask about, it is a rule and it has, or needs, an owner.
2. **Find the owner in the table above.** If a rule is already there, the change is to that file, and the scope should name it ("marking_rules::mine_sql() also counts…"). If the rule is not owned anywhere, the scope says which file will own it, and the feature commit creates it there, not inside the view that needs it first.
3. **A rule that needs both an SQL and a PHP form gets both in the owner, side by side, and a test that they agree.** `marking_rules_test` shows the pattern: build every combination of the row's fields, run the SQL and the PHP, assert the same answer. Never write the PHP twin inside a view "for now".
4. **A new hint, date or bar goes through `staff_row::build()` (staff, convenor) or `student::row()` (student), with the view passing a flag.** Do not add a branch to a view's export() that appends to `$row['hints']`. If the hint's order matters, it is the order in `staff_row::hints()`, once.
5. **Anything that knows a plugin's tables, classes, column names or settings goes in that plugin's adapter.** If the adapter contract has no method for the fact you need, add the method to `adapter.php` with a default in `base.php` or `generic.php`, implement it where it matters, and let `adapter_contract_test` confirm nothing else names the plugin. Never test `$cm->modname === 'x'` above the adapter layer.
6. **A new query over the index that aggregates "ready", "marked", "released", "unallocated" or "mine" uses the fragments from `marking_rules`** (`ready_sql()`, `MARKER_SQL`, `SHARED_SQL`, `mine_sql()`), not a hand-written `CASE WHEN`.
7. **A new index concern (who to index, what to write, when) goes in the class that owns that concern, and `indexer` gains at most a delegating method.** `indexer.php` is a facade; if a change makes it hold logic, the change is in the wrong file.
8. **Test behaviour the plugin needs to see goes behind `testing.php`.** A new fault point is a new named method there, read through `testing::enabled()`. Production code never reads a test config key directly.
9. **Why the rule is so goes in `DECISIONS.md`** under its finding code (or a new dated rule), and the code comment cites the code only. No dates in comments.
10. **Before the version bump, run the duplication check** (section below). Then run the golden master: if it changed and the scope did not say it would, the build changed something the scope did not ask for.

## The duplication check

Cheap, mechanical, done before the version bump and quoted in NOTES.md:

- `grep -rn "allocatedto\|markedby\|timeready\|unitdue" classes/local/view/` lists every place the marking columns are read. Each hit outside `marking_rules.php` must be either a column passed through (selected and handed on) or a call to a `marking_rules` function. A `CASE WHEN` or an `if` on those columns in a view is a restated rule.
- `grep -rn "'hints'\]\[\]\|\$hints\[\] =" classes/local/view/` must hit only `staff_row.php` and `student.php`.
- `grep -rln "assign\|quiz\|coursework\|peerwork" classes/ | grep -v adapter/` must list only the exemptions in `adapter_contract_test::EXEMPT`.
- `phpcpd --min-lines 6 classes/` (or a 6-line sliding-window scan) stays at zero new hits.
- The guard tests above all pass: they are the automated half of this list.

## Tests: who owns what on the write side

The tests mirror the production shape, so the same "find the owner" rule applies to them.

| Concern | Owner | Guarded by |
|---|---|---|
| Writing one activity type: create it, date it, submit, allocate, mark, release, extend, lock, flag plagiarism | `tests/generator/fixture/<modname>.php` behind `fixture/fixture.php`, the write-side twin of the adapter contract; `fixture/generic.php` for a type without its own | `fixture_contract_test` (every adapter has a fixture; no step, rule test or view test names a plugin's tables or classes) |
| Which fixture writes which type | `fixture/registry.php` | |
| The words tests use for times ("in 3 days", "12 October 2026 10:00 Europe/London") | `fixture/phrases.php` | |
| Every sentence a scenario can say that needs no browser, and what it does: the data it writes, the clock it moves, the index it runs or checks | `fixture/steps.php`, one method per sentence, run by Behat and PHPUnit alike | the Behat context's annotations are the only list of sentences; `view_testcase` reads them at run time, so a sentence with no method fails in both |
| The sentence-to-regex map, and the browser-only sentences (hover, open a view, read a popover) | `tests/behat/behat_block_le_dashboard.php`: a regex and a one-line stub per sentence, browser steps in full | |
| Reading the block the way a scenario would, without a browser: render the dashboard, apply the same selectors the Behat steps use to the DOM | `tests/view_testcase.php`, one twin per "I should see" sentence | the twins are named after the Behat steps; a `Then` with no twin keeps its scenario in Behat |
| A rule scenario as a PHPUnit test | `tests/rules/<feature>_test.php`, generated by `tests/rules/convert.py` from the feature file, one `$this->step('…')` per sentence | the feature keeps at least one scenario; `convert.py` prints the moved table |

What stays in Behat: anything with `@javascript` (hover, popovers, the Mitigate menu, view switching in the page), the block_rhb scenarios, and one scenario per feature so the feature still runs end to end in a browser. A new sentence goes in the context (regex) and in `steps` (what it does); a new "I should see" goes in the context (browser) and in `view_testcase` (DOM) if PHPUnit tests are to use it. A scenario that reaches a rule and needs no browser belongs in `tests/rules`, not in a new feature.

## Reading order for a new builder

1. This file. 2. `DECISIONS.md` for the rules' reasons. 3. `classes/local/adapter/adapter.php` (the contract) and `classes/local/view/marking_rules.php` (the rules). 4. `tests/golden_master_test.php` and its fixture, which show what every view puts on the page. 5. The view you are changing. 6. `tests/generator/fixture/steps.php` when you need a scenario: what each sentence writes. Not the Behat context for production rules; it holds regexes and browser steps and will mislead about where the rules live.
