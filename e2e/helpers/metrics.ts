import { Page, expect } from '@playwright/test';

/**
 * Open an activity's Metrics tab, switch to the Learners sub-view, and wait
 * for the AJAX-loaded learner table to appear.
 *
 * @param page  Playwright page (must already be logged in as a user with
 *              mod/cmi5:viewreports)
 * @param cmId  the course-module id of the cmi5 activity
 */
export async function openLearnersTab(page: Page, cmId: number): Promise<void> {
  await page.goto(`/mod/cmi5/view.php?id=${cmId}&tab=metrics`);
  await page.locator('#cmi5-pill-learners').click();
  await expect(
    page.locator('#cmi5-metrics-learners tr.mod-cmi5-learner-row').first(),
  ).toBeVisible();
}

/** The per-row "Delete" button for a given user id (only rendered with managecontent). */
export function deleteButton(page: Page, userId: number) {
  return page.locator(`.mod-cmi5-delete-reg[data-userid="${userId}"]`);
}

/** The per-row "Reset" button for a given user id. */
export function resetButton(page: Page, userId: number) {
  return page.locator(`.mod-cmi5-reset-reg[data-userid="${userId}"]`);
}

/**
 * Click a confirm button inside the currently-open Moodle modal and wait for
 * the modal to close.
 *
 * @param label  the save-button text (e.g. "Delete", "Reset")
 */
export async function confirmModal(page: Page, label: string | RegExp): Promise<void> {
  const dialog = page.getByRole('dialog').filter({ has: page.getByRole('button', { name: label }) });
  await dialog.getByRole('button', { name: label }).click();
  await expect(dialog).toBeHidden();
}
