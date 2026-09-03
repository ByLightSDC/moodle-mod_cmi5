# mod_cmi5 Playwright E2E Tests

These tests drive the **real** learner-data cleanup feature the way a teacher
does: log into Moodle as an admin, open an activity's **Metrics → Learners**
tab, click **Reset** / **Delete** (or the bulk actions, or Course reset), and
then assert against **both** the page *and* the real Postgres database — did the
row actually vanish, did the grade actually clear, did anything else get touched.

State (courses, activities, learners with progress) is created by a fast PHP
seeder, not through the UI. Only the behaviour under test goes through the
browser.

---

## 1. Quick start

### First: what are you testing?

Are you simply verifying the existing tests or testing a plugin change? 

| | **A. Verifying the suite** | **B. Testing a plugin change** |
| --- | --- | --- |
| You are… | checking the tests still work, or debugging a spec | changing `mod_cmi5` code |
| Re-sync the plugin into Moodle | not needed | **required** |
| Purge caches / run the upgrade | not needed | **required** |
| Steps below | 1 → 2 → 3 | 1 → **5** → 2 → 3 |

> ⚠️ **Nothing in the test run picks up local plugin changes.** There is no
> `webServer` in the Playwright config — the tests log into the Moodle in your
> docker stack, whose `mod/cmi5` is whatever you last copied in. Edit plugin
> code, skip 5, and the suite runs against the **old** code and passes green, giving a false positive.


### Step 0 — Fresh clone only

```bash
cd e2e        # - Keep the tests in a separate folder
npm install
npx playwright install chromium          # ~150 MB browser binary, one time
#   on Linux, if it complains about missing libs:
#   sudo npx playwright install-deps chromium
```

### Step 1 — Have a Moodle stack

You need a local [`moodle-docker`](https://github.com/moodle/moodle-docker)
stack serving `mod_cmi5` at `http://localhost:8000`, with its **Postgres port
exposed to the host**.

> **On the team?** There's a shared `moodle-docker` wrapper and a preconfigured
> Moodle checkout — grab those from the team's dev setup notes, make sure
> `MOODLE_DOCKER_DB_PORT=15432` is set, and skip to Step 2.

If you're standing one up from scratch, do **6** first, then come back.

Whichever way, the DB port must be published/exposed, the tests connect to Postgres
directly to check results:

```bash
<your moodle-docker wrapper> down          # keeps the dbdata volume
<your moodle-docker wrapper> up -d    #d for detached mode if you don't want to see docker logs
<moodle-docker>/bin/moodle-docker-wait-for-app
nc -z 127.0.0.1 15432 && echo "db port open"  # waits and verifies port is open
```

### Step 2 — Create `e2e/.env`

```bash
cp .env.example .env
```

Then fill it in. `.env` is gitignored.

```bash
# --- Required for every run -------------------------------------------
E2E_BASE_URL=http://localhost:8000
E2E_ADMIN_USER=admin
E2E_ADMIN_PASSWORD=<your Moodle admin password>

# --- Postgres, for the DB assertions --------------------------------
#     needs MOODLE_DOCKER_DB_PORT=15432 exposed on the stack (Step 1)
E2E_DB_HOST=127.0.0.1
E2E_DB_PORT=15432
E2E_DB_NAME=moodle
E2E_DB_USER=moodle
E2E_DB_PASSWORD=m@0dl3ing
E2E_DB_PREFIX=m_
```

The defaults match a standard moodle-docker + pgsql setup — usually the only
line you touch is `E2E_ADMIN_PASSWORD`.

### Step 3 — Run it

```bash
npm test
```

The suite runs **serially, one worker** — every test mutates the same Moodle
database, so they can't overlap. A full run is ~2 minutes. A browser window
opens locally so you can watch (headless in CI).

**Expected result:** `25 passed`, and `git`-nothing left behind (every run
tears down its own seeded course). If you get `Missing environment variable …`,
go back to Step 2. `could not run docker exec` means `docker` isn't on your
`PATH`. Anything else → 7.

> **If you changed the plugin, stop.** A green run here proves nothing until
> you've done 5 — you just tested the previously-copied code. Do 5, then
> re-run this.

### The commands you'll actually use

| I want to… | Command |
| --- | --- |
| **Run the whole suite** | `npm test` |
| Watch it run in a browser | `npm run test:headed` |
| Interactive UI mode (pick tests, time-travel) | `npm run test:ui` |
| Run one file | `npx playwright test delete-learner.spec.ts` |
| Run by title | `npx playwright test -g "bulk delete"` |
| See the report from the last run | `npm run report` |
| Force a trace for every test (debugging) | `npx playwright test --trace on` |

You can also run and debug individual specs from the **Playwright Test
extension** in VS Code.

---

## 2. What gets tested

Every feature spec seeds its own course + cmi5 activity + learners, acts through
the UI, asserts against the database *and* the page, then tears the course down.
A handful of "plumbing" specs just check the harness itself.

| Spec | What it verifies | Kind |
| --- | --- | --- |
| `smoke.spec.ts` | Moodle is reachable at `E2E_BASE_URL` | plumbing |
| `login.spec.ts` | the admin login helper works | plumbing |
| `db.spec.ts` | Postgres is reachable, the `cmi5_*` tables exist | plumbing |
| `fixtures.spec.ts` | seed → rows + gradebook grade exist → teardown → gone | plumbing |
| `delete-learner.spec.ts` | per-learner **Delete**: row gone (and stays gone after a reload), toast, whole cascade + grade cleared, control learner untouched | UI + DB |
| `reset-learner.spec.ts` | per-learner **Reset**: registration + UUID kept, `coursesatisfied` + every child table zeroed, grade nulled, row stays in the list, control learner untouched | UI + DB |
| `bulk.spec.ts` | select-all + indeterminate + bulk-bar visibility; a row checkbox doesn't open the drill-down; **Delete selected** / **Reset selected** on a subset; bulk delete on one activity leaves the same learner's data in another activity alone | UI + DB |
| `capability-gate.spec.ts` | an editing teacher sees the controls; a `viewreports`-only teacher sees the report but **no** buttons/checkboxes, and a direct `mod_cmi5_delete_registration` web-service call is rejected | UI + WS |
| `course-reset.spec.ts` | `/course/reset.php` "Delete all learner data": whole-course cmi5 wipe + grades cleared, enrolments / roles / activities untouched. Second scenario: our box **and** Moodle's "Remove all grades" together → still clears, no error | UI + DB |
| `gradebook.spec.ts` | after a delete, the learner's cmi5 grade is gone from the **user grade report**; another learner's is unchanged | UI + DB |
| `events.spec.ts` | a delete writes `\mod_cmi5\event\registration_deleted` and a reset writes `\mod_cmi5\event\registration_reset` to `logstore_standard_log` | DB |
| `edge-cases.spec.ts` | bulk with an empty selection → nothing processed; an unrecognised `action` → rejected; single delete on a learner with no registration → rejected; bulk delete reports `notfound` for missing registrations, still processes the real ones, and a repeat call is a clean no-op | WS + DB |

---

## 3. The fixture model

The one idea to understand: **set up state the fast way, act the real way, check both
the page and the data, clean up.**

```ts
const seeded = seedScenario({
  activities: [{ name: 'My Activity', aus: [{ title: 'AU 1' }] }],
  users: [
    { username: 'e2e_teacher',  role: 'editingteacher' },   // has mod/cmi5:managecontent
    { username: 'e2e_student1', role: 'student' },
  ],
  progress: [
    { user: 'e2e_student1', activity: 'My Activity', au: 'AU 1',
      completed: true, passed: true, score: 0.8 },           // 0..1 -> a gradebook grade
  ],
});
// ... act via the UI, assert via the DB and the UI ...
teardownScenario(seeded.runId);
```

`seedScenario` runs `fixtures/seed.php` **inside the webserver container** (piped
to `php` on stdin; the spec is passed as an env var — nothing is copied in). It
creates, using Moodle's own APIs where it matters:

- a category + course, tagged `idnumber = e2e-<runId>`
- the cmi5 activities and their AUs
- the users (created once, then reused across runs) and their enrolments
- per-learner progress: a registration, a session, statements (tagged with the
  registration UUID), a State doc, an `au_status` row with the score, and the
  resulting **gradebook grade**

It returns typed ids for everything — `seeded.courseId`,
`seeded.activities[].cmi5Id` / `.cmId`, `seeded.progress[].registrationId` /
`.registrationUuid` / `.sessionId`, and so on.

`teardownScenario(runId)` deletes the course + category (which cascades all cmi5
data). **Enrolled users are kept** — they're cheap and reusable. Safety rail:
teardown only ever touches courses whose `idnumber` starts with `e2e-`.

Assert against the database with the `db.ts` helpers:

```ts
import { count, findRow, query, t } from '../helpers/db';

expect(await count('cmi5_registrations', { id: reg.registrationId })).toBe(0);
const grade = await findRow('grade_grades', { itemid, userid });
```

---

## 4. How it works

### Project layout

```text
e2e/
  playwright.config.ts     no webServer (Moodle is already running); serial, 1 worker
  .env.example             -> copy to .env
  fixtures/
    seed.php               Moodle CLI seeder, piped into the webserver container
  helpers/
    config.ts              reads .env, fails fast if a required value is missing
    auth.ts                loginAs(page, username, password)
    db.ts                  pg pool + query() / count() / findRow() / t(table)
    seed.ts                seedScenario() / teardownScenario() + typed result
    metrics.ts             openLearnersTab(), deleteButton()/resetButton(), confirmModal()
    ws.ts                  callWs(page, methodname, args) - a Moodle AJAX WS call
  tests/
    *.spec.ts              the specs (see step 2)
```

### How a spec works

```ts
import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD } from '../helpers/seed';
import { openLearnersTab, deleteButton, confirmModal } from '../helpers/metrics';
import { count } from '../helpers/db';

test.describe('per-learner Delete', () => {
  let seeded;
  test.beforeAll(() => { seeded = seedScenario({ /* … */ }); });
  test.afterAll(() => teardownScenario(seeded.runId));

  test('wipes the target learner', async ({ page }) => {
    const uid = userId(seeded, 'e2e_student1');
    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, seeded.activities[0].cmId);

    await deleteButton(page, uid).click();
    await confirmModal(page, 'Delete');

    await expect(deleteButton(page, uid)).toHaveCount(0);                       // UI
    expect(await count('cmi5_registrations', { id: seeded.progress[0].registrationId })).toBe(0); // DB
  });
});
```

`loginAs` runs the whole real login flow. `openLearnersTab` navigates to
`view.php?tab=metrics`, clicks the **Learners** pill, and waits for the
AJAX-loaded table. The button/modal helpers hide the Moodle modal quirks.

### Execution notes

- **`workers: 1`** — the tests share one Moodle database, so they're serialized.
- **`retries: 0` locally, `1` in CI** — a flaky remote page load shouldn't fail
  a CI run; locally you want to see failures immediately.
- **`trace: 'retain-on-failure'`** — a failed test drops a
  `test-results/<name>/trace.zip` (open with `npx playwright show-trace …`) plus
  an `error-context.md` with an ARIA snapshot of the page — the fastest way to
  see what it actually looked like when it broke.
- **`docker` must be on `PATH`** in the shell you run `npm test` from — the
  seeder shells out to `docker exec`.

---

## 5. Testing a plugin change

**This is the step you use when you're developing the feature**, not an
occasional chore. The tests hit the Moodle in your docker stack, and its
`mod/cmi5` is a *copy* — a symlink pointing outside the bind-mounted checkout
won't resolve inside the container. So any change to plugin code has to be
copied in and the caches refreshed before the tests can see it.

```bash
# 1. copy your working tree into the Moodle checkout
rsync -a --delete --exclude '.git' /path/to/moodle-mod_cmi5/ <moodle checkout>/mod/cmi5/

# 2. refresh Moodle
<wrapper> exec webserver php admin/cli/purge_caches.php
#    and, if you bumped version.php:
<wrapper> exec webserver php admin/cli/upgrade.php --non-interactive

# 3. now run the tests (1 Step 3)
npm test
```

> ⚠️ Skipping above steps 1 or 2 means the suite runs against the **previously-copied**
> plugin and passes green. If you edited `amd/src/*.js`, remember this repo's
> `amd/build/*.min.js` is hand-maintained, not grunt-built — the built file has
> to be updated too, or the browser loads the old JS. (This is a known issue to be addressed in CCUI-2999)

If a spec suddenly can't find a selector after a UI change, that's usually step 1
or 2 not done, or the `amd/build` file not updated.

---

## 6. Setting up the Moodle stack from scratch

Skip this if you already have a stack (or the team's shared one).

**Prerequisite:** Docker Engine + the Compose plugin (`docker compose`). That's
the only thing you install locally — the webserver (PHP/Apache), the **Postgres
database**, Selenium and mailpit all run as containers `moodle-docker` defines
and `up -d` starts. You don't install or run Postgres yourself.

### a. Get the pieces

```bash
# a full Moodle checkout (any supported branch; 4.1+ for this plugin)
git clone -b MOODLE_405_STABLE --depth=1 git://git.moodle.org/moodle.git ~/moodle-dev/moodle

# moodle-docker itself (the compose files + helper scripts for the containers)
git clone https://github.com/moodle/moodle-docker.git ~/moodle-dev/moodle-docker

# this plugin, inside that Moodle checkout at mod/cmi5
rsync -a --delete --exclude '.git' /path/to/moodle-mod_cmi5/ ~/moodle-dev/moodle/mod/cmi5/
```

### b. A wrapper script for the env vars

`moodle-docker` is driven entirely by environment variables. Put them in a small
wrapper so every command is consistent — e.g. `~/moodle-dev/mdc.sh`:

```bash
#!/usr/bin/env bash
export MOODLE_DOCKER_WWWROOT=~/moodle-dev/moodle      # your Moodle checkout
export MOODLE_DOCKER_DB=pgsql                         # the tests assume Postgres
export MOODLE_DOCKER_DB_VERSION=16                    # pin to your dbdata volume (see note)
export MOODLE_DOCKER_PHP_VERSION=8.3
export MOODLE_DOCKER_WEB_PORT=8000                    # Playwright's baseURL
export MOODLE_DOCKER_DB_PORT=15432                    # <-- needed for the DB assertions
cd ~/moodle-dev/moodle-docker
cp config.docker-template.php "$MOODLE_DOCKER_WWWROOT/config.php"   # once
exec bin/moodle-docker-compose "$@"
```

```bash
chmod +x ~/moodle-dev/mdc.sh
```

### c. Bring it up and install the site (first time)

`up -d` pulls and starts all the containers (webserver, `db` = Postgres,
selenium, mailpit). The first run downloads images, so give it a minute.

```bash
~/moodle-dev/mdc.sh up -d
~/moodle-dev/moodle-docker/bin/moodle-docker-wait-for-db

~/moodle-dev/mdc.sh exec webserver php admin/cli/install_database.php \
  --agree-license --fullname="cmi5 e2e" --shortname="cmi5e2e" \
  --adminpass="Admin1234!" --adminemail="admin@example.invalid"
```

Set `E2E_ADMIN_PASSWORD` in `e2e/.env` to whatever `--adminpass` you used.

### d. Day-to-day

```bash
~/moodle-dev/mdc.sh up -d        # start
~/moodle-dev/mdc.sh stop         # stop, keep data
~/moodle-dev/mdc.sh down         # remove containers, KEEP the dbdata volume
~/moodle-dev/mdc.sh down -v      # nuke everything incl. the database
~/moodle-dev/mdc.sh logs -f webserver
~/moodle-dev/mdc.sh exec webserver bash
```

> **db won't start after a recreate?** If it fails with *"database files are
> incompatible with server version"*, your `dbdata` volume was made by an older
> Postgres. Set `MOODLE_DOCKER_DB_VERSION` to that version (e.g. `16`) in the
> wrapper and `up -d` again. `down` without `-v` never touches the volume.

---

## 7. Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `Missing environment variable E2E_ADMIN_PASSWORD` (or similar) | `e2e/.env` doesn't exist or is missing a value. `cp .env.example .env` and fill it in. |
| `could not run docker exec` | `docker` isn't on `PATH` in the shell running `npm test`. |
| Every test fails at login | Wrong `E2E_ADMIN_PASSWORD`, or Moodle is down. Open `http://localhost:8000` and log in by hand to check. |
| `login.spec.ts` fails with "Invalid login" but the password is right | The login page's show/hide-password widget loads async; filling too early loses the value. `loginAs` waits for `networkidle` first — don't remove that. |
| `db.spec.ts` fails with `ECONNREFUSED` | The stack is down, or `MOODLE_DOCKER_DB_PORT` isn't published. `nc -z 127.0.0.1 15432` to check; recreate the stack if needed. |
| db container won't start after `up -d` | Postgres version mismatch with the `dbdata` volume — pin `MOODLE_DOCKER_DB_VERSION` (§6 note). |
| **Tests pass but your plugin change isn't there** | You skipped §5. Re-sync into the Moodle checkout, purge caches, update `amd/build` if you touched JS, re-run. |
| A UI spec suddenly can't find a selector | Same as above — §5 not done, or `amd/build/metrics.min.js` not updated for a `metrics.js` change. |
| A course-reset spec fails on an enrolment/role assertion | `/course/reset.php` default-checks *Unenrol students* / *Delete local roles* / *Remove all grades*. `course-reset.spec.ts` unticks them to isolate our checkbox — do the same in any new course-reset spec. |
| `events.spec.ts` flaky | `logstore_standard` writes on request shutdown; the spec uses `expect.poll()`. If still flaky, bump the poll timeout. |
| Leftover `e2e-*` courses after a run | A spec threw before `teardownScenario`. Clean up: `<wrapper> exec webserver php -r "…"` or delete via the UI; they're safe to remove (only ever `idnumber` `e2e-*`). |

