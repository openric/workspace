// OpenRiC Workspace - the V0.1 demonstration scenario's main steps by keyboard alone (roadmap R1 hardening; WCAG 2.1.1
// keyboard, 2.1.2 no keyboard trap, 2.4.1 bypass blocks, 2.4.7 focus visible): sign in, skip to the content, search,
// open a record, change a value of its description and save it, publish it, and sign out, with no mouse; at every
// stop the focused element is visible and shows a focus indicator. Choosing a file to upload is left out: it opens the
// system's own dialog, which a test cannot drive by keyboard.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect, Page } from '@playwright/test';

const PASSWORD = process.env.DEMO_PASSWORD || 'demonstration-password';

/** Tab (or Shift+Tab) until the focused element matches, at most a hundred stops; each stop must show its focus. */
async function tabTo(page: Page, matches: (el: Element) => boolean, back = false) {
  const test = matches.toString();
  for (let i = 0; i < 100; i++) {
    await page.keyboard.press(back ? 'Shift+Tab' : 'Tab');
    // Read the focus once the browser has drawn it.
    await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
    const state = await page.evaluate((src) => {
      const el = document.activeElement as HTMLElement | null;
      if (!el || el === document.body) return { found: false, visible: true, indicated: true, what: 'body' };
      const style = getComputedStyle(el);
      const box = el.getBoundingClientRect();
      // eslint-disable-next-line no-new-func
      const fn = new Function('return ' + src)();
      return {
        found: fn(el),
        visible: box.width > 0 && box.height > 0,
        indicated: style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0 || style.boxShadow !== 'none',
        what: el.outerHTML.slice(0, 120),
      };
    }, test);
    expect(state.visible, `focus is on something visible: ${state.what}`).toBe(true);
    expect(state.indicated, `focus is shown: ${state.what}`).toBe(true);
    if (state.found) return;
  }
  throw new Error('not reached by keyboard within a hundred stops: ' + test);
}

/** Enter on what has the focus, waiting for the page it leads to (load state alone resolves on the page still showing). */
async function enter(page: Page) {
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.keyboard.press('Enter')]);
}

test('the main steps of the demonstration scenario need no mouse', async ({ page }) => {
  await page.goto('/sign-in', { waitUntil: 'domcontentloaded' });
  await tabTo(page, (el) => el.getAttribute('name') === 'email');
  await page.keyboard.type('archivist@demo.invalid');
  await tabTo(page, (el) => el.getAttribute('name') === 'password');
  await page.keyboard.type(PASSWORD);
  await enter(page);
  expect(await page.locator('form[action$="/sign-out"]').count(), 'signed in').toBeGreaterThan(0);

  // Past the header and the menu (WCAG 2.4.1).
  await page.goto('/records', { waitUntil: 'domcontentloaded' });
  await page.keyboard.press('Tab');
  expect(await page.evaluate(() => document.activeElement?.getAttribute('href')), 'the skip link comes first').toBe('#content');
  await page.keyboard.press('Enter');

  // Search, and open what it finds.
  await page.goto('/search', { waitUntil: 'domcontentloaded' });
  await tabTo(page, (el) => el.tagName === 'INPUT' && (el as HTMLInputElement).name === 'q');
  await page.keyboard.type('Minutes, January to June 1952');
  await enter(page);
  await tabTo(page, (el) => el.tagName === 'A' && /\/records\/[0-9a-f-]{36}$/.test(el.getAttribute('href') || ''));
  await enter(page);
  const record = page.url();

  // Change the scope and content, and save.
  await page.goto(record + '/edit', { waitUntil: 'domcontentloaded' });
  await tabTo(page, (el) => el.tagName === 'TEXTAREA' && /scope-and-content/.test(el.getAttribute('name') || ''));
  await page.keyboard.press('Control+A');
  await page.keyboard.type('Changed by keyboard alone.');
  await tabTo(page, (el) => el.tagName === 'BUTTON' && (el as HTMLButtonElement).type === 'submit' && !!el.closest('form[method="post"]'));
  await enter(page);
  await page.goto(record, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('main')).toContainText('Changed by keyboard alone.');

  // Publish it: the record's Publication page, where publishing is chosen already, then Apply.
  await tabTo(page, (el) => el.tagName === 'A' && /\/publication$/.test(el.getAttribute('href') || ''));
  await enter(page);
  await tabTo(page, (el) => el.tagName === 'BUTTON' && (el as HTMLButtonElement).type === 'submit' && /apply/i.test(el.textContent || ''));
  await enter(page);
  const id = record.split('/').pop();
  await page.goto('/discover/records/' + id, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('main'), 'published, so the public sees it').toContainText('Changed by keyboard alone.');

  // And out (no keyboard trap on the way: every stop above was reached by Tab alone). Log out is in the account's menu
  // (phase 26): Tab to its initial, Enter opens it, then Tab to Log out.
  await tabTo(page, (el) => el.tagName === 'SUMMARY' && !!el.closest('details.user-menu'));
  await page.keyboard.press('Enter');
  await tabTo(page, (el) => el.tagName === 'BUTTON' && !!el.closest('form[action$="/sign-out"]'));
  await enter(page);
  expect(await page.locator('form[action$="/sign-out"]').count(), 'signed out').toBe(0);
});
