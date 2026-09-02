# mod_cmi5 end-to-end tests

Playwright tests for the learner-data cleanup feature (CCUI-3153): the per-learner
**Reset** / **Delete** buttons, the **bulk** actions, and the **Course reset**
integration.

These are real end-to-end tests. They drive a running Moodle in a browser and
assert against the real Postgres database — no mocking. Setting up state (courses,
activities, learners with progress) is done through a fast PHP seeder; only the
behaviour under test goes through the UI.

---

## One-time setup

### 1. A running Moodle with `mod_cmi5` installed

The tests target a local [`moodle-docker`](https://github.com/moodle/moodle-docker)
stack. If you already have one running with `mod_cmi5` on `http://localhost:8000`,
skip to *"Expose the database port"* below.

> **On the team?** There's a shared `moodle-docker` wrapper and a preconfigured
> Moodle checkout for local dev — grab those from the team's dev setup notes and
> jump to *"Expose the database port"*. Everything below is the from-scratch path
> for CI and outside contributors.

From scratch:

**Prerequisite:** Docker Engine + the Compose plugin (`docker compose`). That's the
only thing you install locally — the webserver (PHP/Apache), the **Postgres
database**, Selenium and mailpit all run as containers that `moodle-docker`
defines and `up -d` starts. You don't install or run Postgres yourself.

**a. Get the pieces**

```bash
# a full Moodle checkout (any supported branch; 4.1+ for this plugin)
git clone -b MOODLE_405_STABLE --depth=1 git://git.moodle.org/moodle.git ~/moodle-dev/moodle

# moodle-docker itself (the compose files + helper scripts for the containers)
git clone https://github.com/moodle/moodle-docker.git ~/moodle-dev/moodle-docker

# this plugin, inside that Moodle checkout at mod/cmi5
rsync -a --delete --exclude '.git' /path/to/moodle-mod_cmi5/ ~/moodle-dev/moodle/mod/cmi5/
```

A bind-mounted symlink won't resolve inside the container, so the plugin has to
physically live at `<moodle checkout>/mod/cmi5`. Re-run that `rsync` after code
changes (then purge caches — see *"Applying plugin changes"* below), or add a
second bind mount via a `local.yml` compose override if you want live edits.

**b. A wrapper script for the env vars**

`moodle-docker` is driven entirely by environment variables. Put them in a small
wrapper so every command is consistent — e.g. `~/moodle-dev/mdc.sh`:

```bash
#!/usr/bin/env bash
export MOODLE_DOCKER_WWWROOT=~/moodle-dev/moodle      # your Moodle checkout
export MOODLE_DOCKER_DB=pgsql                         # tests assume Postgres
export MOODLE_DOCKER_DB_VERSION=16                    # pin to your dbdata volume (see note below)
export MOODLE_DOCKER_PHP_VERSION=8.3
export MOODLE_DOCKER_WEB_PORT=8000                    # Playwright's baseURL
export MOODLE_DOCKER_DB_PORT=15432                    # <-- needed for DB assertions
cd ~/moodle-dev/moodle-docker
cp config.docker-template.php "$MOODLE_DOCKER_WWWROOT/config.php"   # only needed once
exec bin/moodle-docker-compose "$@"
```

```bash
chmod +x ~/moodle-dev/mdc.sh
```

**c. Bring it up and install the site (first time)**

`up -d` pulls and starts all the containers (webserver, `db` = Postgres,
selenium, mailpit). First run downloads a few images, so give it a minute.

```bash
~/moodle-dev/mdc.sh up -d
~/moodle-dev/moodle-docker/bin/moodle-docker-wait-for-db

~/moodle-dev/mdc.sh exec webserver php admin/cli/install_database.php \
  --agree-license --fullname="cmi5 e2e" --shortname="cmi5e2e" \
  --adminpass="Admin1234!" --adminemail="admin@example.invalid"
```

Set `E2E_ADMIN_PASSWORD` in `e2e/.env` (step 3) to whatever `--adminpass` you use.

**d. Day-to-day**

```bash
~/moodle-dev/mdc.sh up -d        # start
~/moodle-dev/mdc.sh stop         # stop, keep data
~/moodle-dev/mdc.sh down         # remove containers, KEEP the dbdata volume
~/moodle-dev/mdc.sh down -v      # nuke everything incl. the database
~/moodle-dev/mdc.sh logs -f webserver
~/moodle-dev/mdc.sh exec webserver bash      # shell inside the container
```

### Expose the database port

The DB-assertion helper connects to Postgres with `pg`, so the container's port
must be published to the host. That's the `MOODLE_DOCKER_DB_PORT=15432` line in
the wrapper above. If you added it to an already-running stack, recreate it:

```bash
~/moodle-dev/mdc.sh down          # keeps the dbdata volume
~/moodle-dev/mdc.sh up -d
~/moodle-dev/moodle-docker/bin/moodle-docker-wait-for-app
```

Verify: `nc -z 127.0.0.1 15432 && echo "db port open"`.

> **db won't start after a recreate?** If it fails with *"database files are
> incompatible with server version"*, your `dbdata` volume was created by an older
> Postgres. Set `MOODLE_DOCKER_DB_VERSION` to that version (e.g. `16`) in the
> wrapper and `up -d` again. `down` without `-v` never touches the volume, so your
> data is intact.

### Applying plugin changes

After editing plugin code, re-sync it into the Moodle checkout and refresh:

```bash
rsync -a --delete --exclude '.git' /path/to/moodle-mod_cmi5/ ~/moodle-dev/moodle/mod/cmi5/
~/moodle-dev/mdc.sh exec webserver php admin/cli/purge_caches.php
# if version.php bumped:
~/moodle-dev/mdc.sh exec webserver php admin/cli/upgrade.php --non-interactive
```

### 2. Node + Playwright

```bash
cd e2e
npm install
npx playwright install chromium          # ~150 MB browser binary, one time
# on Linux, if it complains about missing libs:
#   sudo npx playwright install-deps chromium
```

### 3. Local config

```bash
cp .env.example .env
```

Then edit `e2e/.env` and set **`E2E_ADMIN_PASSWORD`** to your Moodle admin
password. `.env` is gitignored. The other values default correctly for the
standard moodle-docker setup.

---

## Running

```bash
npm test                       # everything, headless
npm run test:headed            # watch it in a real browser
npm run test:ui                # Playwright's interactive UI mode
npm run report                 # open the HTML report from the last run

npx playwright test delete-learner.spec.ts        # one file
npx playwright test -g "bulk delete"              # by title
npx playwright test --trace on                    # force a trace for every test
```

The suite runs **serially, one worker** (`playwright.config.ts`): every test
mutates the same Moodle database, so they must not overlap. A full run is a
couple of minutes.

`docker` must be on `PATH` in the shell you run `npm test` from — the seeder
shells out to `docker exec`.

---

## Layout

```
e2e/
  playwright.config.ts     serial / 1 worker, baseURL, trace-on-failure
  .env.example             -> copy to .env
  fixtures/
    seed.php               Moodle CLI seeder, piped into the webserver container
  helpers/
    config.ts              reads .env, fails fast if a required value is missing
    auth.ts                loginAs(page, username, password)
    db.ts                  pg pool + query() / count() / findRow() / t(table)
    seed.ts                seedScenario() / teardownScenario() + typed result
    metrics.ts             openLearnersTab(), deleteButton()/resetButton(), confirmModal()
    ws.ts                  callWs(page, methodname, args) - Moodle AJAX WS as the logged-in user
  tests/
    smoke.spec.ts          Moodle is reachable
    login.spec.ts          admin can log in
    db.spec.ts             Postgres reachable, cmi5 tables present
    fixtures.spec.ts       seed -> assert -> teardown -> assert gone
    delete-learner.spec.ts per-learner Delete
    reset-learner.spec.ts  per-learner Reset
    bulk.spec.ts           select-all, bulk bar, Delete/Reset selected, isolation
    capability-gate.spec.ts editingteacher vs viewreports-only, UI + web service
    course-reset.spec.ts   /course/reset.php "Delete all learner data" (x2 scenarios)
    gradebook.spec.ts      grader report shows a cleared grade
    events.spec.ts         registration_deleted / registration_reset in the log
    edge-cases.spec.ts     empty selection, bad action, notfound, idempotent re-delete
```

---

## The fixture model

Every feature spec follows the same shape:

```ts
const seeded = seedScenario({
  activities: [{ name: 'My Activity', aus: [{ title: 'AU 1' }] }],
  users: [
    { username: 'e2e_teacher',  role: 'editingteacher' },  // has mod/cmi5:managecontent
    { username: 'e2e_student1', role: 'student' },
  ],
  progress: [
    { user: 'e2e_student1', activity: 'My Activity', au: 'AU 1',
      completed: true, passed: true, score: 0.8 },          // 0..1 -> gradebook grade
  ],
});
// ... act via the UI, assert via the DB and the UI ...
teardownScenario(seeded.runId);
```

`seedScenario` runs `fixtures/seed.php` inside the webserver container (piped to
`php` on stdin; the spec is passed as an env var — nothing is copied in). It
creates, using Moodle's own APIs where it matters:

- a category + course, tagged `idnumber = e2e-<runId>`
- the cmi5 activities and their AUs
- the users (created once, then reused across runs) and their enrolments
- per-learner progress: a registration, a session, statements (tagged with the
  registration UUID), a State doc, an `au_status` row with the score, and the
  resulting **gradebook grade**

It returns typed ids for everything (`seeded.courseId`, `seeded.activities[].cmi5Id`
/ `.cmId`, `seeded.progress[].registrationId` / `.registrationUuid` / `.sessionId`,
etc).

`teardownScenario(runId)` deletes the course + category (which cascades all cmi5
data). **Enrolled users are kept** — they're cheap and reusable. As a safety
rail, teardown only ever touches courses whose `idnumber` starts with `e2e-`.

Assert against the database with the `db.ts` helpers:

```ts
import { count, findRow, query, t } from '../helpers/db';

expect(await count('cmi5_registrations', { id: reg.registrationId })).toBe(0);
const g = await findRow('grade_grades', { itemid, userid });
```

---

## Writing a new spec

1. Copy the closest existing spec (`delete-learner.spec.ts` for a per-learner
   action, `bulk.spec.ts` for bulk, `course-reset.spec.ts` for the reset form).
2. Seed the minimum scenario you need. Include a **control learner** you don't
   touch, and assert they're unaffected.
3. Drive only the behaviour under test through the UI (`openLearnersTab`, the
   button helpers, `confirmModal`).
4. Assert exhaustively against the DB; add a UI assertion for what the user
   actually sees (row gone, toast, reloaded list).
5. `teardownScenario` in `afterAll` (or `finally` if you seed per-test).

---

## Gotchas we hit (so you don't)

- **Login race.** The Moodle login page finishes wiring the password field
  (show/hide widget) via an async template load *after* `load`. Filling +
  submitting before that lands makes Moodle reject the login as "Invalid login".
  `loginAs` waits for `networkidle` first — keep that.
- **Course reset form defaults.** `/course/reset.php` default-checks *Unenrol
  students*, *Delete local role assignments*, and *Remove all grades*. To test
  only our checkbox, `course-reset.spec.ts` unticks those. If you add a course
  reset test, do the same or your enrolment/role assertions will fail for
  reasons unrelated to cmi5.
- **Log writes are deferred.** `logstore_standard` flushes on request shutdown,
  so `events.spec.ts` uses `expect.poll(...)` when checking `logstore_standard_log`.
- **Moodle modals.** Use `confirmModal(page, 'Delete')` — it scopes to the open
  dialog and waits for it to close. `page.getByText('...')` often hits strict-mode
  violations against form legends/headers; prefer roles (`getByRole('cell', ...)`)
  or scope to a container.
- **`docker` on PATH.** The seeder needs it. If a spec fails with "could not run
  docker exec", that's why.

---

## Not yet done

- **CI.** No workflow yet. A CI job needs: a Postgres service (or the compose
  stack), Moodle with the branch's `mod_cmi5`, the plugin upgraded, `.env`
  values as workflow env, then `npm ci && npx playwright install --with-deps
  chromium && npm test`.
- **Stretch specs** that need a real AU launch: reset -> relaunch continues the
  same registration UUID; delete -> relaunch mints a new one. Also a
  live-session-during-delete race and a large-activity timeout check.
- **Transaction rollback under a mid-cascade failure** can't be proved from the
  outside — that one wants a PHPUnit test with fault injection.
