# TBT Results 0.1.0 — One results page for teachers and students

A teacher opens **Results** and sees everything their students have finished on The Blue Tree: every deck, game, drill and (later) test and homework, with the score. They can search for a student or an activity, filter by tool, class and period, and open one student or one activity. A student opens **My results** and sees their own.

The design is `docs/prototypes/results.html` (approved 13 Nov 2026). Where this spec and the prototype disagree, the spec wins; where the spec is silent, the prototype decides.

## Source of the data

TBT Results stores nothing. It reads the activity log kept by **TBT Notes 1.22.0+**, table `{prefix}tbt_activity` (`TBT_Notes_DB::table_activity()`):

`id, user_id, class_id, teacher_id, student_name, tool, object_ref, object_title, post_id, event, score, score_max, duration_seconds, meta, created_at (UTC)`

- One row per finished sitting. **Every sitting is shown as its own row** (decided: if Kat plays a deck three times, there are three rows).
- `score` NULL means "done, no score" (Translation Drill). 0 is a real score.
- `class_id` 0 means the student was in no class when they finished. Only administrators see those rows.
- An activity is identified by `tool` + `object_ref`. Its name is `object_title` from the newest row.

Tools reporting today, and their slugs (checked against live rows, 14 Nov 2026):

| Slug | Tool | Score |
|---|---|---|
| `swipe` | Swipe | none, by design: finishing a deck means every card was known, so it shows "✓ Done" |
| `matching` | Matching Game | yes |
| `dragdrop` | Drag & Drop | yes |
| `drill` | Translation Drill | none: "✓ Done" |
| `test` | Test Maker | yes (`score` / `score_max` from the server's result) |

Coming in later releases of their own plugins: `fill` (Fill 1.8.0), `homework` (Homework 0.5.0). Results must show any slug, known or not.

## Scope

| 0.1.0 | Later |
|---|---|
| New plugin: bootstrap, deploy workflow, `CLAUDE.md` already in the repo | "Open ↗" links to a test result or a marked homework (needs the attempt reference in the row; later releases) |
| Teacher page `[tbt_results]`: all results, one student, one activity | "Homework waiting for marking" tile and "Waiting for marking" pill (Homework 0.5.0) |
| Student page `[tbt_my_results]` | CSV export |
| REST routes, owner/visibility rules | TBT Points |
| Hub registration | Phone layout (not wanted: tablet and desktop only) |

## Files

```
tbt-results.php
includes/class-tbtr-access.php      who may see which rows
includes/class-tbtr-query.php       every read of the activity table
includes/class-tbtr-format.php      pure helpers (labels, percent, period bounds) — unit-tested
includes/class-tbtr-rest.php
includes/class-tbtr-pages.php       the two shortcodes and their assets
assets/css/tbt-tokens.css           vendored copy of Hub's tokens (copy Test Maker's, byte-identical)
assets/css/fonts.css, assets/fonts/ copy Test Maker's approach and files
assets/css/results.css
assets/js/results.js
tests/test-format.php
.github/workflows/deploy.yml        copy Test Maker's; secrets FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_SERVER_DIR (Mariusz has added them)
```

Prefixes: classes and constants `TBTR_`, functions and options `tbtr_`, CSS classes `tbtr-`, REST namespace `tbt-results/v1`, text domain `tbt-results`. Never use unprefixed `.tbt-*` class names (Hub claims them).

---

## Phase 0 — read-only inventory (stop and report)

The repo has no plugin code yet. Read the sibling code instead:

1. **Notes.** In `aparatofan/tbt_notes` (main, 1.22.0): confirm the activity table's columns and indexes as listed above; confirm these exist and report their return shapes: `TBT_Notes_DB::table_activity()`, `get_class()`, `get_all_classes()`, `get_classes_for_teacher()`, `get_student_ids_for_class()`, `get_students_for_class()`, `TBT_Notes_Capabilities::user_can_manage()`, `::user_can_manage_all()`, constant `TBT_NOTES_CAP`.
2. **Test Maker as the pattern.** In `aparatofan/tbt-test-maker`: how it registers and enqueues tokens and fonts (`class-tbttm-assets.php`), how the teacher page boots its JS (localized data, nonce, REST base), how it checks for Notes (`TBTTM_Classes::available()`), the Hub item, and the deploy workflow. Results copies these patterns; report anything that does not transfer.
3. **Tool slugs.** Confirm the slugs the five reporting tools send: Swipe `swipe`, Matching Game `matching`, Drag & Drop `dragdrop`, Translation Drill `drill`, Test Maker `test`.
4. **Volume.** If you can, say how the queries below use the existing indexes (`user_created`, `class_created`, `teacher_created`). No new index is planned; say so if one is clearly needed.

**Hard stop** if anything here contradicts the spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Bootstrap (`tbt-results.php`)

- Header: `Plugin Name: TBT Results`, `Version: 0.1.0`, `Requires PHP: 8.0`, text domain `tbt-results`.
- Constants: `TBTR_VERSION` `0.1.0`, `TBTR_FILE`, `TBTR_DIR`, `TBTR_URL`.
- No database table, no `TBTR_DB_VERSION`, no activation routine.
- `TBTR_Access::notes_ready()`: true only when `TBT_Notes_DB` has `table_activity()` and the Notes version constant is at least `1.22.0`. When false, both shortcodes print one line: "Results need TBT Notes 1.22.0 or later." and the routes return 503. The plugin never fatals without Notes.
- Hub item (filter `tbt_hub_items`): slug `tbt-results`, title `TBT Results`, description "Everything students finish on The Blue Tree, in one place.", capability `TBT_NOTES_CAP` when defined, else `manage_options`.

## Task 2 — Who sees what (`class-tbtr-access.php`)

One place decides. Every query goes through it.

| User | Sees |
|---|---|
| Administrator (`TBT_Notes_Capabilities::user_can_manage_all()`) | Every row, including `class_id` 0 |
| Teacher (`user_can_manage()`, not admin) | Rows whose `class_id` is one of **their own** classes (`get_classes_for_teacher()`), whoever recorded them |
| Logged-in student | Only rows with their own `user_id` |
| Logged out | Nothing |

- `TBTR_Access::is_teacher( $user_id )`, `is_admin( $user_id )`.
- `TBTR_Access::visible_class_ids( $user_id )`: the teacher's class IDs; for an admin, `null` meaning "no class filter".
- `TBTR_Access::class_titles( $user_id )`: `[id => title]` for the filter. An admin also gets `0 => "No class"`.
- A teacher with no classes sees an empty page with: "Results appear here once you have a class in TBT Notes and your students finish something."
- Student and activity views (Task 4) never widen this. A teacher opening another teacher's student by ID gets only the rows in their own classes. If there are none: "This student is not in your classes."

## Task 3 — Reading (`class-tbtr-query.php`)

Every query uses `$wpdb->prepare()`, the access scope from Task 2, and `ORDER BY id DESC`.

Filters, shared by every list:

| Filter | Values | Rule |
|---|---|---|
| `search` | free text, max 100 chars | Teacher: `student_name LIKE` or `object_title LIKE` (`%…%`, `$wpdb->esc_like`). Student: `object_title` only |
| `tool` | a slug or empty | exact match |
| `class` | class ID, `0` (admin only) or empty | must be in the visible set, else ignored |
| `period` | `7`, `30` (default), `all` | days back from now, **in the site's time zone** (`wp_timezone()`), converted to UTC for `created_at` |

Paging: 100 rows per page, `page` from 1, response carries `has_more`.

Each row returned to the browser:

```
{ id, user_id, student, tool, tool_label, ref, title, class_id, class_title,
  when (ISO 8601 in the site time zone), score, score_max, percent }
```

- `student`: `student_name`, or the user's current `display_name` when it is empty.
- `percent`: `round( score / score_max * 100 )` when both are set and `score_max` > 0, else null.
- `class_title`: from `TBTR_Access::class_titles()`; "No class" for 0.

## Task 4 — REST (`class-tbtr-rest.php`)

All `GET`, all `permission_callback`s real, nonce `wp_rest`, no-cache headers. Namespace `tbt-results/v1`.

| Route | Who | Returns |
|---|---|---|
| `/teacher` | teacher | `stats`, `rows`, `has_more`, `classes` (for the filter), `tools` (slugs present in the visible rows, with labels) |
| `/teacher/student/{id}` | teacher | `student` {id, name, class_title, last_active}, `stats`, `by_tool`, `rows`, `has_more` |
| `/teacher/activity` (`tool`, `ref`) | teacher | `activity` {tool, tool_label, ref, title, class_titles, score_max}, `stats`, `not_done`, `rows`, `has_more` |
| `/me` | logged-in | `stats`, `rows`, `has_more`, `tools` |

Stats:

- **`/teacher`** (always the last 7 days, not the period filter, as in the prototype): `finished` (rows), `active` (distinct students) with `of` = number of students in the visible classes (admin: all students in all classes, plus nobody for class 0), `average` (mean `percent` of scored rows, null when none).
- **Student**: `finished` (all time), `average` (all time, scored rows), `active_days_30` (distinct site-time-zone dates in the last 30 days), `last_active`.
- **`by_tool`**: per tool, `count` and `average` (null for tools whose rows have no score).
- **Activity**: `done` (distinct students who did it) and `of` (students in the classes those rows belong to, within the visible set), `average`, `best` (null when unscored).
- **`not_done`**: names of students in those same classes with no row for this activity. Sorted by name. Empty for an admin looking at class-0 rows only.
- **`/me`**: last 30 days: `finished`, `average`, `active_days`.

## Task 5 — The pages (`class-tbtr-pages.php`, `results.js`, `results.css`)

Build from the prototype. **No hero:** Divi supplies the page header. Start at the Admin Bar.

**Teacher, `[tbt_results]`** — for a non-teacher: "This page is for teachers." For a logged-out visitor: the usual login prompt line used by Test Maker.

1. **All results** (default): Admin Bar with title "Results" and search ("Search a student or an activity…"); three stat tiles (finished this week, students active this week "N of M", average score this week); filter row (All tools / All classes / Last 30 days) with the result count on the right; the table Student · Activity (title + tool pill) · Class · When · Result; "Show more" under the table when `has_more`.
2. **One student** (`?tbtr_student={id}`): "← All results", name, class and last active; three tiles; "By tool" cards; their activities with the tool and period filters.
3. **One activity** (`?tbtr_tool={slug}&tbtr_ref={ref}`): "← All results", title, tool and class, "out of N" or "no score, done or not done"; tiles; "Not done yet" (hidden when empty); "Who did it".
4. The view lives in the URL (as Test Maker's builder does), so Back and a refresh keep it. Filters and search are kept in the URL too.

**Student, `[tbt_my_results]`** — logged out: login prompt line. Admin Bar "My results" with search ("Search your activities…"); three tiles; tool and period filters; table Activity · When · Result. Empty: "Nothing here yet. Finish a test, a deck or a drill and it appears here." No class column, no names.

**Shared rules**

- Result cell: score → `7 / 10 · 70%` plus the meter; no score → green pill "✓ Done".
- When: "Today 18:40", "Yesterday 09:12", otherwise "11 Nov" (add the year when it is not this year). Site time zone.
- Tool labels (filterable through `tbtr_tool_labels`): `swipe` Swipe · `matching` Matching Game · `dragdrop` Drag & Drop · `drill` Translation Drill · `test` Test · `fill` Fill · `homework` Homework. An unknown slug shows as itself with a capital first letter.
- Search waits 300 ms after typing stops. A failed request shows "Could not load results. Try again." with a retry button and keeps what is on screen.
- Layout for tablet and desktop only: minimum width 768 px. No phone layout.
- Tokens and fonts as Test Maker. No colour values in `results.css`; tokens only (`color-mix()` allowed).
- Accessibility: real buttons for name and activity links, labelled selects, table headers, visible focus.

## Task 6 — Tests (`tests/test-format.php`)

Pure PHP, no WordPress: tool labels (known, unknown, filter not applied), `percent` (normal, 0 score, null score, `score_max` 0), period bounds (7, 30, all; Warsaw time zone across a DST change), and the "When" text (today, yesterday, this year, last year).

## Validation

```bash
for f in tbt-results.php includes/*.php; do php -l "$f"; done
node --check assets/js/results.js
php tests/test-format.php
```

State what still needs a live check.

---

## Acceptance checklist (live site, after merge and deploy)

Mariusz creates two Divi pages: **Results** under Teacher Tools with `[tbt_results]`, and **My results** in the Learning Tools menu with `[tbt_my_results]`.

- [ ] As admin: Results lists recent tests, decks, games and drills from every class, plus the class-less student's rows marked "No class".
- [ ] Searching a student's name and an activity title both narrow the list; tool, class and period filters work together.
- [ ] Clicking a student opens their page; "By tool" matches their rows; Back returns to the list with the filters kept.
- [ ] Clicking an activity shows who did it, the average and best, and a "Not done yet" list that matches the class.
- [ ] A drill and a Swipe deck show "✓ Done", never a score; a test shows its score.
- [ ] A deck played three times shows three rows.
- [ ] As Madzia (teacher, not admin): only her classes' rows; no "No class" option; opening a student from another teacher's class by URL shows "This student is not in your classes."
- [ ] As a student: My results shows only their own rows, no names or classes; search and filters work.
- [ ] Logged out: both pages show the login line, and `/wp-json/tbt-results/v1/teacher` and `/me` refuse.
- [ ] Hard refresh shows `?ver=0.1.0` on Results assets.
