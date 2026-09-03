import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { seedScenario, teardownScenario, userId, SEED_USER_PASSWORD, type SeedResult } from '../helpers/seed';
import { openLearnersTab, resetButton, confirmModal } from '../helpers/metrics';
import { count, findRow, enrolmentCount, roleAssignmentCount } from '../helpers/db';

test.describe('per-learner Reset (Metrics > Learners)', () => {
  let seeded: SeedResult;

  test.beforeAll(() => {
    seeded = seedScenario({
      activities: [{ name: 'Reset Target', aus: [{ title: 'AU 1' }] }],
      users: [
        { username: 'e2e_teacher', role: 'editingteacher' },
        { username: 'e2e_student1', role: 'student' },
        { username: 'e2e_student2', role: 'student' },
      ],
      progress: [
        { user: 'e2e_student1', activity: 'Reset Target', au: 'AU 1', completed: true, passed: true, score: 0.8 },
        { user: 'e2e_student2', activity: 'Reset Target', au: 'AU 1', completed: true, passed: true, score: 0.6 },
      ],
    });
  });

  test.afterAll(() => teardownScenario(seeded.runId));

  test('clears progress but keeps the registration; control learner intact', async ({ page }) => {
    const activity = seeded.activities[0];
    const s1 = seeded.progress.find((p) => p.user === 'e2e_student1')!;
    const s2 = seeded.progress.find((p) => p.user === 'e2e_student2')!;
    const s1Id = userId(seeded, 'e2e_student1');
    const s2Id = userId(seeded, 'e2e_student2');

    const gradeItem = await findRow<{ id: number }>('grade_items', {
      itemmodule: 'cmi5',
      iteminstance: activity.cmi5Id,
    });

    const enrolBefore = await enrolmentCount(seeded.courseId);
    const rolesBefore = await roleAssignmentCount(seeded.courseId);

    // Registration exists before, with the child data.
    expect(await count('cmi5_registrations', { id: s1.registrationId })).toBe(1);
    expect(await count('cmi5_au_status', { registrationid: s1.registrationId })).toBe(1);

    await loginAs(page, 'e2e_teacher', SEED_USER_PASSWORD);
    await openLearnersTab(page, activity.cmId);

    await resetButton(page, s1Id).click();
    await expect(page.getByRole('dialog')).toContainText(/reset all sessions and progress/i);
    await confirmModal(page, 'Reset');

    await expect(page.locator('.alert-success')).toContainText(/reset progress for/i);
    // Row is NOT removed by a reset.
    await expect(resetButton(page, s1Id)).toBeVisible();

    // Reload: student1 still listed, but the score is no longer shown.
    await openLearnersTab(page, activity.cmId);
    const s1Row = page.locator(`tr.mod-cmi5-learner-row:has(.mod-cmi5-reset-reg[data-userid="${s1Id}"])`);
    await expect(s1Row).toBeVisible();
    await expect(s1Row).not.toContainText('80');

    // DB: registration + UUID kept, child data cleared, grade nulled.
    const reg = await findRow<{ id: number; registrationid: string; coursesatisfied: number }>(
      'cmi5_registrations',
      { id: s1.registrationId },
    );
    expect(reg).not.toBeNull();
    expect(reg!.registrationid).toBe(s1.registrationUuid); // same UUID
    expect(Number(reg!.coursesatisfied)).toBe(0);

    expect(await count('cmi5_sessions', { registrationid: s1.registrationId })).toBe(0);
    expect(await count('cmi5_statements', { registration: s1.registrationUuid })).toBe(0);
    expect(await count('cmi5_au_status', { registrationid: s1.registrationId })).toBe(0);
    expect(await count('cmi5_state_documents', { registrationid: s1.registrationId })).toBe(0);

    const g1 = await findRow<{ finalgrade: string | null }>('grade_grades', {
      itemid: gradeItem!.id,
      userid: s1Id,
    });
    expect(g1 === null || g1.finalgrade === null).toBe(true);

    // DB: student2 (control) untouched.
    expect(await count('cmi5_registrations', { id: s2.registrationId })).toBe(1);
    expect(await count('cmi5_au_status', { registrationid: s2.registrationId })).toBe(1);
    const g2 = await findRow<{ finalgrade: string }>('grade_grades', {
      itemid: gradeItem!.id,
      userid: s2Id,
    });
    expect(Number(g2!.finalgrade)).toBeCloseTo(60);

    // DB: no collateral — enrolments and role assignments are unchanged.
    expect(await enrolmentCount(seeded.courseId)).toBe(enrolBefore);
    expect(await roleAssignmentCount(seeded.courseId)).toBe(rolesBefore);
  });
});
