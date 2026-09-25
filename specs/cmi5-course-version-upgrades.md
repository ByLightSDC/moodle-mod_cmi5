# cmi5 Course Version Upgrades

Status: Draft for product review

## Summary

Allow authorized instructors and library managers to find library-linked cmi5 activities that are behind the latest package version, review the changes, and upgrade one or many activities safely.

This feature builds on the existing package version history, activity `packageversionid` references, per-activity version selector, update banner, and `content_library::sync_activity_to_version()` operation.

## Terminology

- **Library course/package**: one cmi5 course in the content library, with one or more versions.
- **Moodle course**: a Moodle course containing cmi5 activity instances.
- **Activity**: one `mod_cmi5` course-module instance linked to a library package version.
- **Upgrade**: move an activity from its current package version to a later version of the same package. Downgrades and cross-package changes are not upgrades.
- **Latest**: the version referenced by `cmi5_packages.latestversion`.

Using these terms consistently is important because “cmi5 course” can otherwise mean either a library package or a Moodle course.

## Goals

1. Show which library packages have activities with updates available.
2. From a package, show every activity using an older version and allow a target version to be chosen.
3. Allow an instructor to upgrade several cmi5 activities in one Moodle course.
4. Provide one global **Upgrades** page for library managers.
5. Show an update alert in the content library that links to the filtered Upgrades page.
6. Preserve learner records and make the result of every attempted upgrade clear.

## Non-goals for the first release

- Automatically upgrading activities when a version is uploaded.
- Scheduled or unattended upgrades.
- Downgrading to an older version.
- Switching an activity to another library package.
- Upgrading standalone/upload-based activities that have no `packageid` and `packageversionid`.
- Rolling back an upgrade through this interface.
- Resetting learner attempts, registrations, grades, or completion.
- Editing package metadata or changelogs.
- Sending email or messaging notifications.

## Recommended product decisions

These defaults make the first release predictable. They should be confirmed before implementation.

### Version choices

- An activity may target any active version of the same package whose version number is greater than its current version.
- The latest version is selected by default.
- If selected activities are on different versions, each row is validated independently against the common target.
- A target that is not newer for a particular activity makes that row ineligible; it is not silently downgraded or left looking successful.

### Learner data

Use the behavior of the existing sync operation:

- An AU with the same IRI is updated in place, preserving its database identity and associated learner history.
- A removed AU is retired and hidden, while its historical learner data remains.
- A new AU begins with no learner progress.
- Registrations, sessions, statements, grades, and course completion are not reset.

The confirmation screen must explain this behavior. A changed AU IRI is treated as one removed AU and one new AU, so its prior progress does not transfer.

### Bulk behavior

- Each activity upgrade is atomic.
- A bulk request is **best effort**, not all-or-nothing across activities.
- Processing continues after an activity fails.
- The result page lists upgraded, skipped, and failed activities with a reason for every non-success.
- Re-submitting the same request is safe: activities already at or beyond the target are skipped.

This avoids holding a cross-course transaction or lock for the entire batch and gives useful results when permissions or state change between review and submission.

## Permissions

Authorization is evaluated for every activity at display time and again at execution time.

- The global Upgrades page and content-library alert require the system capability `mod/cmi5:managelibrary`.
- Upgrading an activity additionally requires `mod/cmi5:managecontent` in that activity's module context.
- A Moodle-course-scoped upgrades page is available to users who can manage at least one affected cmi5 activity in that course. It only exposes activities for which they have `mod/cmi5:managecontent`.
- Hidden courses and activities are included when the user has permission to manage them and are visibly marked as hidden.
- Links to course and activity pages are shown only when the user can access the destination.
- Direct requests must not reveal package, course, activity, or changelog data outside the user's capabilities.

## User experience

### Content library list

Add update status derived from current activity references, not the cached `usagecount` field.

For each package show:

- Number of activities with an update available.
- A clear **Updates available** badge when the count is greater than zero.
- A link to the Upgrades page filtered to that package.

Add a summary alert above the list when any upgrade is available:

> 12 activities across 4 library courses have updates available. Review upgrades.

Add an **Updates** filter/tab alongside the existing All, In use, and Unused filters. Search and pagination continue to apply.

Do not count an activity as upgradeable merely because it is not on `latestversion`. Its current version and the latest version must both exist, belong to the same package, be active as required, and the latest version number must be greater.

### Global Upgrades page

Route: a dedicated server-rendered page such as `upgrades.php`, linked from `library.php`.

Filters:

- Library package.
- Moodle course.
- Current version.
- Search by package, course, or activity name.

Group results by library package, then current version. Each activity row shows:

- Selection checkbox.
- Moodle course and activity name.
- Visibility state.
- Current version.
- Latest version.
- Target-version selector containing only valid newer versions.
- Concise cumulative change summary from the current version through the target version.
- Eligibility or blocking reason.

The page supports selecting individual rows, all eligible rows in a group, or all eligible rows on the current filtered result set. “Select all” must state whether it covers only the current page or the full filtered result set; for the first release, use **current page only**.

### Package-scoped upgrades

The package detail/version area links to the same Upgrades page with `packageid` fixed. This view answers: “Which Moodle activities use an older version of this library package, and which version should each use?”

### Moodle-course-scoped upgrades

Provide a course navigation/action link such as **cmi5 content upgrades**. It opens the same page with `courseid` fixed and lists only manageable cmi5 activities in that Moodle course.

This satisfies the instructor workflow without granting access to the system content library.

### Review and confirmation

Submitting selected rows first opens a review step. It shows:

- Each activity's current and target versions.
- The cumulative changelog for the selected version range.
- Counts of AUs added, changed, and removed where available.
- The learner-data behavior described above.
- Any item that has become ineligible since selection.

Confirmation is a POST request with a valid session key. The request sends explicit `(cmid, expected current version id, target version id)` tuples. Do not trust a package ID, course ID, or target version supplied by the browser without resolving and validating the activity again.

### Results

After execution, show a persistent summary such as:

> 8 upgraded, 2 skipped, 1 failed.

Each row reports its final status. A failed row can be retried after the cause is corrected. Results must not claim that the whole batch succeeded when only some activities changed.

### Low-fidelity wireframes

Content library alert and filter:

```text
┌ Updates available ───────────────────────────────────────────────┐
│ 12 activities across 4 library courses can be upgraded.         │
│                                             [Review upgrades →]  │
└──────────────────────────────────────────────────────────────────┘

[All 18] [In use 12] [Unused 6] [Updates 4]

Aviation Safety                         Latest v4
6 activities • 3 with updates           [Review 3 updates]
```

Global or course-scoped upgrades list:

```text
cmi5 content upgrades
[Package: All ▾] [Moodle course: All ▾] [Search____________]

Aviation Safety                                      Target: [v4 ▾]
Current v2 • 2 activities • 9 cumulative changes

[✓] Flight Basics       Pilot Training      v2 → [v4 ▾] [View changes]
[ ] Emergency Response Pilot Training      v2 → [v4 ▾] [View changes]

Aviation Safety                                      Target: [v4 ▾]
Current v3 • 1 activity • 3 cumulative changes

[✓] Annual Refresher    Compliance 2026     v3 → [v4 ▾] [View changes]

2 selected                                             [Review upgrades]
```

Review and results:

```text
Review 2 upgrades
Flight Basics       v2 → v4   +2 AUs, 5 changed, 1 removed
Annual Refresher    v3 → v4   3 changed

Learner history for unchanged AU IRIs is preserved. Removed AUs are
retired; new or renamed AU IRIs begin without prior progress.

[Back] [Confirm 2 upgrades]

Result: 1 upgraded, 1 skipped, 0 failed
✓ Flight Basics       Upgraded to v4
– Annual Refresher    Already upgraded by another user
```

## Data and business rules

### Authoritative update query

An activity has an update available only when:

1. It has a valid `packageid` and `packageversionid`.
2. Its version belongs to its package.
3. The package has a valid `latestversion` belonging to that package.
4. The latest version number is greater than the current version number.
5. The target version is active under the chosen status policy.

Legacy activities with a package but no version reference should be reported separately as **Needs repair**, not assumed to be safely upgradeable. The existing version-usage compatibility rule that resolves such records through the latest version is useful for deletion safety, but it cannot establish which version should be treated as their upgrade baseline.

Add one reusable query/service that returns package, current version, latest version, course-module, course, visibility, and authorization-relevant identifiers. Use it for counts, filters, rows, and execution preflight so the screens do not disagree.

### Target validation

Immediately before each upgrade:

1. Resolve the course module and activity.
2. Recheck `mod/cmi5:managecontent` in the module context.
3. Verify that the submitted expected-current-version still matches the activity.
4. Verify that the target exists, is active, belongs to the same package, and has a greater version number.
5. For a single-AU activity, map the selected AU to the target by IRI. If no match exists, fail that row with a clear reason; do not expand it silently to the full package.
6. Acquire the required locks, revalidate, and perform the existing transactional sync.

### Concurrency and locking

The current sync locks the target version. Bulk upgrades also need a per-activity lock so two users cannot upgrade the same activity concurrently and corrupt usage counters or overwrite each other's choice.

Use a consistent lock order for all assignment paths:

1. Activity lock.
2. Target-version lock.
3. Re-read and validate activity and version state.
4. Transactional structure copy and reference update.

Version deletion already shares the version lock. Apply the activity lock to single-activity form updates and the external sync service as well, so the bulk path is not uniquely protected.

### Changelog semantics

The review must show cumulative changes from the activity's current version, exclusive, through its target version, inclusive. Do not show only the target version's immediate changelog when one or more intermediate versions are skipped.

Escape all package-provided titles and descriptions before rendering. If changelog JSON is absent or invalid, show “Change details unavailable”; this must not block an otherwise valid upgrade.

### Audit events

Add an `activity_version_upgraded` Moodle event for each successful activity with:

- Activity/course-module context and acting user.
- Package ID.
- From-version ID and number.
- To-version ID and number.
- Whether the action was single or bulk and a batch correlation ID when bulk.

Optionally add one batch-completed event containing aggregate counts, but per-activity events are required for a reliable audit trail.

No new persistent database table is required for the first release unless durable batch history is desired. The result page can be generated from the current request and Moodle events provide the audit record.

## Error and edge-case handling

Provide specific, localizable reasons for at least:

- Activity was already upgraded by another user.
- Activity or course module was deleted or is pending deletion.
- Permission was lost after the review page loaded.
- Current or target version is missing, disabled, or belongs to another package.
- Package latest-version pointer is missing or inconsistent.
- Single selected AU does not exist in the target version.
- Lock could not be obtained because another operation is in progress.
- Structure copy or database update failed.

A page refresh must recompute counts. Cached `usagecount` may be maintained but must not determine update availability or authorization.

## Accessibility and interaction requirements

- Use a real table or equivalently labeled row structure for bulk selection.
- Associate every checkbox and target selector with the course and activity name.
- Announce selection counts and batch results through an accessible status region.
- Support keyboard-only selection, review, confirmation, and retry.
- Do not communicate current/latest/failed state by color alone.
- Use Moodle paging and preserve active filters in navigation and return URLs.

## Implementation plan

### Phase 1: domain services and hardening

1. Add an authoritative update-availability query with optional package and Moodle-course filters.
2. Add a reusable method that returns valid newer target versions and cumulative changelogs.
3. Split sync into preflight/validation and mutation, or extend it to require an expected current version.
4. Add a per-activity lock and use a consistent lock order across bulk sync, the edit form, restore/assignment paths where applicable, and the external sync service.
5. Make single-AU mapping failure explicit.
6. Add the per-activity upgrade event.

Likely files:

- `classes/content_library.php`
- `lib.php`
- `classes/external/library_sync_activity.php`
- `classes/event/activity_version_upgraded.php`
- `lang/en/cmi5.php`

### Phase 2: read-only discovery UI

1. Add authoritative update counts and an Updates filter to the library list.
2. Add package-scoped and Moodle-course-scoped links.
3. Implement the paginated Upgrades page, filters, eligibility messages, and cumulative change previews.
4. Keep the existing activity-page update banner, but link it into the same review workflow for consistent validation and copy.

Likely files:

- `library.php`
- New `upgrades.php`
- New output/renderable classes if the page data becomes complex
- New Mustache templates for the list and review screens
- `templates/library.mustache`
- `templates/library_package_detail.mustache`
- `templates/view.mustache`
- `styles.css`
- `lang/en/cmi5.php`

### Phase 3: bulk execution

1. Add the POST-only review and execute actions with session-key validation.
2. Process explicit rows independently using the hardened sync service.
3. Capture successful, skipped, and failed outcomes without leaking exception details.
4. Render a retryable results page and return links preserving filters.
5. If JavaScript is added for selection ergonomics, preserve a functional server-rendered submission path.

Prefer an internal page action for the first release. Add an external/AJAX service only if the final interaction requires it; do not expose a new web service merely to implement server-rendered bulk submission.

### Phase 4: verification and documentation

1. Add PHPUnit coverage for queries, validation, sync semantics, locking boundaries, events, and partial failures.
2. Add Behat or Playwright coverage for manager and instructor workflows, bulk selection, confirmation, and accessibility-critical behavior.
3. Document learner-progress behavior and operational recovery.
4. Render and review the interface at narrow and wide viewport sizes.

## Test plan

### Unit and integration tests

- No update when current equals latest.
- Multiple skipped versions produce the correct cumulative changelog.
- Only newer active versions of the same package are valid targets.
- Forged cross-package and downgrade requests are rejected.
- Missing and inconsistent references appear as repair cases and are not upgraded.
- Hidden courses and activities are counted correctly.
- A user can only see and mutate activities allowed by contextual capabilities.
- Expected-current-version prevents a stale review from overwriting a newer choice.
- Concurrent sync attempts serialize through the activity lock.
- Concurrent target deletion and sync cannot create a dangling reference.
- Same-IRI AUs retain identity and learner history; removed AUs are retired; new AUs have no prior progress.
- A single-AU selection maps by IRI and fails safely when absent.
- One failed row does not roll back successful rows in the same batch.
- Usage counters remain non-negative and agree with authoritative references after success and failure.
- Each successful change emits the correct event; failed and skipped rows do not.

### End-to-end tests

- A library manager sees the alert, filters to updates, reviews several rows, and receives accurate results.
- A teacher sees only manageable activities in one Moodle course and can bulk upgrade them.
- A teacher without system library permission cannot access global library/package data.
- Selection, target choice, validation feedback, and confirmation work without client-side JavaScript.
- A state change between review and confirmation produces a skipped/failed result rather than an incorrect upgrade.
- Direct GET requests cannot perform upgrades; invalid session keys and unauthorized POST requests fail.

## Acceptance criteria

- Authorized users can find every valid library-linked activity with a newer version available within their permitted scope.
- Library package rows and the summary alert show accurate, authoritative update counts.
- Users can filter upgrades by library package and Moodle course.
- Users can upgrade one or several selected activities to a valid newer version.
- Every activity is reauthorized and revalidated at execution time.
- Bulk execution clearly reports upgraded, skipped, and failed items and safely supports retry.
- Downgrades, cross-package targets, forged activity IDs, stale submissions, and invalid single-AU mappings are rejected.
- Concurrent upgrades and version deletion cannot create dangling references or corrupt usage counters.
- Existing learner history is preserved according to AU IRI; removed AUs are retired and new AUs start without progress.
- Successful upgrades are auditable with from/to version details.
- The workflow is usable with keyboard navigation and without JavaScript.
- Existing single-activity update and edit workflows use the same validation rules.

## Open decisions before development

1. Should inactive target versions be completely unavailable (recommended), or selectable with a warning?
2. Where should the Moodle-course-scoped **cmi5 content upgrades** link live: course navigation, course reports, or a plugin-specific page linked from each activity?
3. Is current-page-only bulk selection acceptable for the first release (recommended), or must “select all” span all filtered pages?
4. Should activities with active learner sessions be upgradeable immediately (recommended, because content is resolved per launch), or blocked until those sessions end?
5. Is event-based audit history sufficient, or is a durable, browsable batch-history page required?

## Pre-development deliverables

1. Confirm the five open decisions and learner-data policy.
2. Produce wireframes for the library alert/filter, global upgrades list, review step, and results state.
3. Validate the wireframes with both a system library manager and an editing teacher.
4. Convert the phases above into implementation tickets, each with tests and migration impact noted.
