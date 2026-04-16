# mod_cmi5 — cmi5 Activity Module for Moodle

A self-contained cmi5 activity module for Moodle 4.1+ with a built-in xAPI proxy/LRS, content library, and metrics dashboard. No external LRS or player required.

## Features

- **Full cmi5 spec compliance** — parses cmi5.xml manifests, UUID-based registrations/sessions, satisfaction evaluation, moveOn criteria, and LMS-defined statements (Satisfied, Abandoned)
- **Built-in xAPI proxy & LRS** — stores statements locally with optional forwarding to an external LRS; supports xAPI 1.0.3 resources (statements, state, activity/agent profiles)
- **Content library** — site-wide package management with versioning; activities can reference library packages and sync to new versions
- **Metrics dashboard** — overview KPIs, stacked timeline charts (by verb or user), verb distribution, per-learner drill-down, per-AU analytics, and CSV export
- **Gradebook integration** — multiple grade methods (highest, average, first, last) with configurable max grade
- **Activity completion** — custom completion rule based on cmi5 course satisfaction
- **Backup & restore** — full Moodle 2 backup/restore support
- **Privacy API** — GDPR-compliant data export and deletion
- **Scheduled tasks** — automatic session abandonment detection and expired token cleanup

## Requirements

| Requirement | Version |
|---|---|
| Moodle | 4.1+ (2022112800) |
| PHP | 7.4+ (per Moodle 4.1 requirements) |

### PHP Extensions

These are standard with most PHP installations and are already required by Moodle itself:

- `curl` — HTTP communication with external LRS
- `json` — xAPI statement encoding/decoding
- `zip` (`ZipArchive`) — cmi5 package extraction

### External Dependencies

**None.** The plugin is fully self-contained with no Composer packages or npm runtime dependencies.

An external LRS is **optional** — the plugin can operate in three modes:

| Mode | Description |
|---|---|
| Local only | Statements stored in Moodle database only (default) |
| Forward | Store locally + push to external LRS |
| LRS-only | Forward to external LRS, no local storage |

## Installation

### Option A: From ZIP

1. Download or build a ZIP of the plugin directory
2. In Moodle, go to **Site administration > Plugins > Install plugins**
3. Upload the ZIP file and follow the prompts
4. Complete the upgrade process

### Option B: Git clone

```bash
cd /path/to/moodle
git clone git@github.com:bylightsdc/moodle-mod_cmi5.git mod/cmi5
```

Then visit your Moodle site as an admin to trigger the database upgrade, or run:

```bash
php admin/cli/upgrade.php --non-interactive
```

### Option C: Manual copy

```bash
# Copy plugin files into Moodle's mod directory
cp -r mod_cmi5/ /path/to/moodle/mod/cmi5/
chown -R www-data:www-data /path/to/moodle/mod/cmi5/

# Run upgrade
php /path/to/moodle/admin/cli/upgrade.php --non-interactive
```

## Configuration

### Site-Level Settings

Navigate to **Site administration > Plugins > Activity modules > cmi5**.

| Setting | Description | Default |
|---|---|---|
| Default LRS endpoint | External LRS URL (optional) | — |
| Default LRS key | LRS Basic auth username | — |
| Default LRS secret | LRS Basic auth password | — |
| Default statement mode | Local / Forward / LRS-only | Local |
| Session timeout | Seconds before a session is marked abandoned | 3600 |
| Default launch method | New window or iframe | New window |
| Token expiry | Auth token validity in seconds | 3600 |
| Standalone LRS enabled | Enable the built-in LRS endpoint at `/mod/cmi5/lrs.php` | Off |

### Per-Activity Settings

When adding a cmi5 activity to a course:

- **Package source** — upload a cmi5 ZIP directly or select from the content library
- **Grade method** — highest, average, first, or last AU score
- **Max grade** — maximum grade value (default 100)
- **Launch method** — new window or iframe
- **Session timeout** — override the site default
- **Launch parameters** — custom JSON/query string passed to AUs
- **LRS settings** — override site defaults per activity

## Content Library

The content library provides site-wide package management:

1. Go to **Site administration > Plugins > Activity modules > cmi5 > Content Library**
2. Upload cmi5 ZIP packages (parsed for AUs, blocks, and course structure)
3. When creating activities, select packages from the library instead of re-uploading
4. Sync activities to new package versions as they are published

Requires the `mod/cmi5:managelibrary` capability (granted to managers by default).

## Database Schema

The plugin creates 16 database tables:

- **Core**: `cmi5`, `cmi5_aus`, `cmi5_blocks`, `cmi5_launch_profiles`
- **Library**: `cmi5_packages`, `cmi5_package_versions`, `cmi5_package_aus`, `cmi5_package_blocks`
- **Tracking**: `cmi5_registrations`, `cmi5_sessions`, `cmi5_au_status`, `cmi5_block_status`, `cmi5_tokens`
- **xAPI**: `cmi5_statements`, `cmi5_state_documents`, `cmi5_agent_profiles`, `cmi5_activity_profiles`

## Capabilities

| Capability | Description | Default roles |
|---|---|---|
| `mod/cmi5:addinstance` | Create and edit cmi5 activities | Manager, Teacher |
| `mod/cmi5:view` | View activity and metrics overview | All authenticated |
| `mod/cmi5:launch` | Launch AUs | Student |
| `mod/cmi5:viewreports` | View detailed learner analytics | Manager, Teacher |
| `mod/cmi5:managecontent` | Upload packages, manage registrations | Manager, Teacher |
| `mod/cmi5:managelibrary` | Manage site-wide content library | Manager |

## Scheduled Tasks

| Task | Schedule | Description |
|---|---|---|
| Abandon stale sessions | Every 5 minutes | Marks sessions past the timeout as abandoned and issues Abandoned statements |
| Clean up expired tokens | Every 6 hours | Removes expired single-use authentication tokens |

## Web Services API

The plugin exposes 13 AJAX-enabled web service functions for frontend interaction:

- `mod_cmi5_get_launch_url` — build and return launch URL for an AU
- `mod_cmi5_get_au_status` — get current AU completion/score status
- `mod_cmi5_get_metrics_overview` — KPIs, timeline, verb distribution
- `mod_cmi5_get_learner_progress` — per-learner summary or drill-down
- `mod_cmi5_get_au_analytics` — per-AU completion rates, scores, durations
- `mod_cmi5_delete_registration` — delete a learner's registration and all data
- `mod_cmi5_reset_registration_state` — clear sessions/state without deleting registration
- `mod_cmi5_library_*` — content library management (upload, list, get, delete, sync, register external AU)

## License

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

Copyright 2026 Bylight
