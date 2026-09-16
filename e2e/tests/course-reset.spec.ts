import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { query, t } from '../helpers/db';
import { confirmModal } from '../helpers/metrics';

/** COUNT(*) over a raw SQL fragment, parameterised. */
async function countSql(sql: string, params: unknown[]): Promise<number> {
  const rows = await query<{ n: number }>(`SELECT COUNT(*)::int AS n ${sql}`, params);
  return rows[0].n;
}

test.describe('Course reset - "Delete all learner data"', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [
        { name: 'CR Activity 1', aus: [{ title: 'AU 1' }] },
        { name: 'CR Activity 2', aus: [{ title: 'AU 1' }] },
      ],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' },
        { username: 'e2e_student2', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'CR Activity 1', au: 'AU 1', completed: true, passed: true, score: 0.8 },
        { user: 'e2e_student2', activity: 'CR Activity 1', au: 'AU 1', completed: true, passed: true, score: 0.6 },
        { user: 'e2e_student1', activity: 'CR Activity 2', au: 'AU 1', completed: true, passed: true, score: 0.9 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('wipes all cmi5 learner data for the course, leaves enrolments and content intact', async ({ page }) => {
    const courseId = seeded.courseId;
    const courseFrom = `FROM ${t('cmi5_registrations')} r JOIN ${t('cmi5')} c ON c.id = r.cmi5id WHERE c.course = $1`;
    const gradesFrom =
      `FROM ${t('grade_grades')} gg JOIN ${t('grade_items')} gi ON gi.id = gg.itemid ` +
      `WHERE gi.itemmodule = 'cmi5' AND gi.courseid = $1 AND gg.finalgrade IS NOT NULL`;
    const enrolFrom = `FROM ${t('user_enrolments')} ue JOIN ${t('enrol')} e ON e.id = ue.enrolid WHERE e.courseid = $1`;
    const ctxRow = await query<{ id: number }>(
      `SELECT id FROM ${t('context')} WHERE contextlevel = 50 AND instanceid = $1`,
      [courseId],
    );
    const courseCtxId = ctxRow[0].id;
    const rolesFrom = `FROM ${t('role_assignments')} WHERE contextid = $1`;

    // Baseline
    expect(await countSql(courseFrom, [courseId])).toBe(3);
    expect(await countSql(gradesFrom, [courseId])).toBe(3);
    const enrolBefore = await countSql(enrolFrom, [courseId]);
    const rolesBefore = await countSql(rolesFrom, [courseCtxId]);
    expect(enrolBefore).toBe(3);

    // Reset the course. The "reset_cmi5" checkbox defaults to ticked; leave the
    // core Enrolments / Roles / Gradebook options alone.
    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await page.goto(`/course/reset.php?id=${courseId}`);
    await expect(page.locator('#id_reset_cmi5')).toBeChecked();

    // Moodle's reset form default-checks "delete local roles", "unenrol students"
    // and "remove all grades". Untick them so this test exercises ONLY our
    // "Delete all learner data" checkbox.
    await page.locator('#id_reset_roles_local').uncheck();
    await page.locator('#id_reset_gradebook_grades').uncheck();
    await page.locator('#id_unenrol_users').selectOption([]);

    await page.getByRole('button', { name: 'Reset course' }).click();
    // Moodle asks to confirm in a dialog.
    await confirmModal(page, 'Reset course');

    // Results page: our component/item listed, and a Continue button.
    await expect(page.getByRole('button', { name: /continue/i })).toBeVisible();
    await expect(page.getByText(/delete all learner data/i).first()).toBeVisible();

    // DB: every cmi5 learner row for the course is gone, grades cleared.
    expect(await countSql(courseFrom, [courseId])).toBe(0);
    expect(await countSql(gradesFrom, [courseId])).toBe(0);
    expect(
      await countSql(
        `FROM ${t('cmi5_au_status')} a JOIN ${t('cmi5_registrations')} r ON r.id = a.registrationid ` +
          `JOIN ${t('cmi5')} c ON c.id = r.cmi5id WHERE c.course = $1`,
        [courseId],
      ),
    ).toBe(0);

    // DB: enrolments, role assignments and the activities themselves are untouched.
    expect(await countSql(enrolFrom, [courseId])).toBe(enrolBefore);
    expect(await countSql(rolesFrom, [courseCtxId])).toBe(rolesBefore);
    expect(await countSql(`FROM ${t('cmi5')} WHERE course = $1`, [courseId])).toBe(2);
    expect(
      await countSql(
        `FROM ${t('cmi5_aus')} au JOIN ${t('cmi5')} c ON c.id = au.cmi5id WHERE c.course = $1`,
        [courseId],
      ),
    ).toBe(2);
  });
});

test.describe('Course reset - our box AND "Remove all grades"', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'CR Both', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'CR Both', au: 'AU 1', completed: true, passed: true, score: 0.8 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('grades still clear, no error / double-processing when both options are on', async ({ page }) => {
    const courseId = seeded.courseId;
    const courseFrom = `FROM ${t('cmi5_registrations')} r JOIN ${t('cmi5')} c ON c.id = r.cmi5id WHERE c.course = $1`;
    const gradesFrom =
      `FROM ${t('grade_grades')} gg JOIN ${t('grade_items')} gi ON gi.id = gg.itemid ` +
      `WHERE gi.itemmodule = 'cmi5' AND gi.courseid = $1 AND gg.finalgrade IS NOT NULL`;

    expect(await countSql(courseFrom, [courseId])).toBe(1);
    expect(await countSql(gradesFrom, [courseId])).toBe(1);

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await page.goto(`/course/reset.php?id=${courseId}`);
    // Leave BOTH "Delete all learner data" and "Remove all grades" ticked
    // (both are on by default). Just isolate from unenrol / role deletion.
    await expect(page.locator('#id_reset_cmi5')).toBeChecked();
    await expect(page.locator('#id_reset_gradebook_grades')).toBeChecked();
    await page.locator('#id_reset_roles_local').uncheck();
    await page.locator('#id_unenrol_users').selectOption([]);

    await page.getByRole('button', { name: 'Reset course' }).click();
    await confirmModal(page, 'Reset course');

    // No error on the results page; our component still listed.
    await expect(page.getByRole('button', { name: /continue/i })).toBeVisible();
    await expect(page.getByText(/delete all learner data/i).first()).toBeVisible();
    await expect(page.locator('.alert-danger, .error')).toHaveCount(0);

    // Both did their job: cmi5 rows gone, grades cleared, activity kept.
    expect(await countSql(courseFrom, [courseId])).toBe(0);
    expect(await countSql(gradesFrom, [courseId])).toBe(0);
    expect(await countSql(`FROM ${t('cmi5')} WHERE course = $1`, [courseId])).toBe(1);
  });
});
