import { Page } from '@playwright/test';

/**
 * Call a Moodle AJAX web service as the currently logged-in user.
 *
 * The caller must already be logged in and on a Moodle page (e.g. straight
 * after loginAs(), which lands on /my/) so window.M.cfg.sesskey is available.
 *
 * Returns the first result envelope: { error: boolean, data?: any, exception?: any }.
 */
export async function callWs(
  page: Page,
  methodname: string,
  args: Record<string, unknown>,
): Promise<{ error: boolean; data?: any; exception?: any }> {
  const sesskey = await page.evaluate(() => (window as any).M.cfg.sesskey as string);
  const res = await page.request.post(
    `/lib/ajax/service.php?sesskey=${sesskey}&info=${methodname}`,
    { data: [{ index: 0, methodname, args }] },
  );
  const body = await res.json();
  return body[0];
}
