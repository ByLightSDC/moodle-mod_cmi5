cmi5 Content Library Management — Implementation Spec
Status: Draft pending the decisions below.
Objective
Extend the existing Content Library so library managers can:
- Delete unused older versions of cmi5 content.
- Download the original ZIP for a particular version.
- See which courses and cmi5 activities currently use each version.
Build on the existing package/version tables, stored ZIP files, and activity-to-version references.
Scope and defaults
The proposed first release uses these defaults:
- Management requires the existing system-level mod/cmi5:managelibrary capability.
- Usage includes hidden courses and activities.
- Only unused versions older than the latest version can be deleted.
- Deletion is permanent and requires confirmation.
- Downloads return the original uploaded ZIP.
- Uploading a new version does not automatically update existing activities.
- Existing automatic version numbering remains unchanged.
Bulk operations, automatic retention, custom version labels, metadata exports, and historical learner-to-version reporting are outside this release.
User experience
Library listing
Keep the existing package listing and provide:
- Package title and link to its management page.
- Latest version number.
- Number of retained versions.
- Total number of activities currently using the package.
Usage totals must reflect actual activity references.
Package management page
Place version history prominently above detailed AU and block information.
Field	Behavior
Version	Existing v1, v2, etc.; links to version details
Latest	Marks the package’s latest version
Viewing	Marks the version currently displayed
Uploaded	Version creation date
Uploaded by	Uploader’s display name
Used by	Activity count; links to usage details
Actions	Download original ZIP, View usage, Delete


Display the selected version’s upload date rather than the package’s original creation date. Retain existing change summaries.
Version usage
Provide a paginated usage view containing:
- Course name.
- Activity name.
- Course and activity visibility.
- Links where the manager has permission to access the destination.
- Pending-deletion status where applicable.
The report describes current activity assignments, not historical learner usage.
Hidden activities and courses still count as usage. Incomplete or pending-deletion references must also block version deletion, even when a normal activity link cannot be displayed.
Download
“Download original ZIP” downloads the archive stored for the selected version.
- Require login and library-management permission.
- Validate the version and its package.
- Preserve the original filename.
- Force download.
- Explain when an external/API version has no uploaded ZIP.
- Show a clear error if an expected archive is missing.
Downloading does not regenerate content or include Moodle course settings or learner data.
Delete
Show Delete only for an eligible older version. For protected versions, explain why deletion is unavailable:
- “This is the latest version.”
- “This version is used by N activities.”
The confirmation identifies the package and version and states that its uploaded ZIP, extracted content, and version details will be permanently removed.
Deletion requires a POST request and valid session key. Revalidate eligibility on submission; eligibility shown earlier on the page is not sufficient.
After success, return to the package page with a confirmation message.
Data and safety rules
Authoritative usage
Use actual cmi5.packageversionid references to calculate usage and decide deletion eligibility.
The existing usagecount field may remain as a cache, but it must not authorize deletion. Account conservatively for legacy package references that resolve content through the latest version.
Deletion eligibility
A version is eligible only when:
1. It belongs to the requested package.
2. It is not the package’s latest version.
3. No activity depends on it.
4. The requesting user has library-management permission.
No force-delete option is added for individual versions.
Concurrent operations
Coordinate deletion with operations that assign or change version references, including activity creation, synchronization, and restore.
Use shared locking and validation so an activity cannot acquire a reference while that version is being deleted. A database transaction alone is insufficient.
Records and files removed
Delete only the selected version’s:
- Original archive in library_package.
- Extracted files in library_content.
- Package AU records.
- Package block records.
- Version record.
Preserve other versions, package identity, activity records, grades, registrations, sessions, and learner progress.
Do not renumber retained versions. Uploading after deletion continues the existing version sequence.
Existing whole-package deletion
Apply authoritative usage checks to whole-package deletion and correct the existing reference-clearing bug.
Any change to the existing force-delete API contract must be explicitly resolved before implementation.
Backup and restore
Verify behavior when restoring an activity after its former library version has been deleted, including recycle-bin recovery.
Restore must not silently attach content to an unrelated version or leave a broken version reference. Where backup content is available, verify the existing standalone-content fallback.
Audit trail
Record version deletion through a Moodle event containing the acting user, package identity, and version identity.
Implementation approach
Implement in this order:
1. Authoritative usage queries and shared version-reference validation.
2. Version history and paginated usage interface.
3. Protected ZIP download handling.
4. Coordinated locking and safe version deletion.
5. Whole-package deletion safeguards and restore verification.
6. Automated tests and interface verification.
Primary changes are expected in:
- classes/content_library.php
- library.php
- templates/library.mustache
- templates/library_package_detail.mustache
- lib.php
- lang/en/cmi5.php
- New event and test files
Assignment and restore paths may also require changes for coordinated locking.
Prefer the existing server-rendered interface. Add external/AJAX services only if the chosen interaction requires them. No new database fields are expected under the proposed defaults.
Acceptance criteria
- Managers can see all retained versions and distinguish the latest from the selected version.
- Each version shows accurate usage across courses, including hidden activities.
- Authorized managers can download the correct original ZIP.
- Unauthorized requests cannot download archives or delete versions.
- An unused older version can be deleted after confirmation.
- Latest and in-use versions cannot be deleted, including through direct requests.
- Stale usage counters cannot bypass deletion protections.
- Concurrent activity assignment and deletion cannot create dangling references.
- Deleting one version leaves other versions and learner data intact.
- Restore behavior remains valid after deletion.
- Missing archives and incomplete activity references produce understandable results.
Questions to finalize the spec
1. Deletion recovery: Is permanent deletion with confirmation sufficient, or do you need an archive/restore feature?
    Answer: Yes deletion with confirmation is enough

1. Permissions: Should all three functions remain limited to system-level library managers, or should teachers receive download or usage access?
   Answer: library managers only for this release.
2. Existing force-delete API: Does any integration currently use whole-package deletion with force=true? Can we change it to reject deletion when activities depend on the package?
   Answer: reject in-use deletion; changing the existing API needs compatibility confirmation.
3. Download meaning: Does “download a version” mean the original uploaded ZIP, with no generated export for external content?
   Answer: yes.
4. Version identity: Are automatic labels such as v1, v2, and v3 sufficient?
   Answer: Yes