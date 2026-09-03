import { test, expect } from '@playwright/test';
import { seedScenario, teardownScenario } from '../helpers/seed';
import { count, findRow } from '../helpers/db';

test('seed creates the scenario, teardown removes it', async () => {
  const seeded = seedScenario({
    activities: [{ name: 'Fixture Check', aus: [{ title: 'AU 1' }] }],
    users: [
      { username: 'e2e_teacher', role: 'editingteacher' },
      { username: 'e2e_student1', role: 'student' },
    ],
    progress: [
      { user: 'e2e_student1', activity: 'Fixture Check', au: 'AU 1', completed: true, passed: true, score: 0.7 },
    ],
  });

  const cmi5Id = seeded.activities[0].cmi5Id;
  const reg = seeded.progress[0];

  try {
    // Structure
    expect(seeded.courseId).toBeGreaterThan(0);
    expect(seeded.activities).toHaveLength(1);

    // cmi5 rows
    expect(await count('cmi5_registrations', { cmi5id: cmi5Id })).toBe(1);
    expect(await count('cmi5_au_status', { registrationid: reg.registrationId })).toBe(1);
    expect(await count('cmi5_sessions', { registrationid: reg.registrationId })).toBe(1);
    expect(await count('cmi5_statements', { registration: reg.registrationUuid })).toBe(2);

    // The score reached the gradebook
    const item = await findRow<{ id: number }>('grade_items', {
      itemmodule: 'cmi5',
      iteminstance: cmi5Id,
    });
    expect(item).not.toBeNull();
    const grade = await findRow<{ finalgrade: string }>('grade_grades', {
      itemid: item!.id,
      userid: seeded.users.find((u) => u.username === 'e2e_student1')!.userId,
    });
    expect(grade).not.toBeNull();
    expect(Number(grade!.finalgrade)).toBeCloseTo(70); // 0.7 * maxgrade(100)
  } finally {
    teardownScenario(seeded.runId);
  }

  // Everything is gone after teardown
  expect(await count('course', { id: seeded.courseId })).toBe(0);
  expect(await count('cmi5', { id: cmi5Id })).toBe(0);
  expect(await count('cmi5_registrations', { id: reg.registrationId })).toBe(0);
});
