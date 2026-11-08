// OpenRiC Workspace - accessibility scans (roadmap R1 hardening), after Heratio's tests/e2e/05-ui-components/
// accessibility.spec.ts (heratio#1532): axe-core checks the main public and staff pages of a Workspace holding the
// demonstration dataset (php artisan workspace:demo) against WCAG 2.2 AA (axe tags wcag2a, wcag2aa, wcag21a, wcag21aa,
// wcag22aa). It fails on a regression: a critical or serious rule that a11y-baseline.json does not list for the page, or
// more failing nodes than it lists. Every violation is printed, so the baseline can be lowered as fixes land; it is
// never raised to make a run pass.
// Self-assessment only: automated tools find roughly a third of WCAG failures. A conformance claim still needs a manual
// review with a screen reader.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import * as fs from 'fs';
import * as path from 'path';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const BLOCKING = ['critical', 'serious'];
const BASELINE: Record<string, Record<string, number>> = JSON.parse(fs.readFileSync(path.resolve(__dirname, 'a11y-baseline.json'), 'utf8'));
const PASSWORD = process.env.DEMO_PASSWORD || 'demonstration-password';

async function scan(page: Page, name: string): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  for (const v of results.violations) {
    console.log(`[a11y] ${name}: ${v.impact} ${v.id} (${v.nodes.length}) ${v.helpUrl}`);
    for (const n of v.nodes.slice(0, 3)) console.log(`        ${n.target.join(' ')}`);
  }
  const known = BASELINE[name] || {};
  return results.violations
    .filter((v) => BLOCKING.includes(v.impact || ''))
    .filter((v) => v.nodes.length > (known[v.id] ?? 0))
    .map((v) => `${v.impact} ${v.id}: ${v.nodes.length} node(s), baseline ${known[v.id] ?? 0} - ${v.help}`);
}

/** The address of the first link on a page whose address matches, so the scan finds the demonstration's own records. */
async function first(page: Page, from: string, pattern: RegExp): Promise<string> {
  await page.goto(from, { waitUntil: 'domcontentloaded' });
  const hrefs = await page.locator('a[href]').evaluateAll((links) => links.map((a) => (a as HTMLAnchorElement).getAttribute('href') || ''));
  const href = hrefs.find((h) => pattern.test(h));
  expect(href, `a link matching ${pattern} on ${from}`).toBeTruthy();
  return href!;
}

export async function signIn(page: Page, email = 'archivist@demo.invalid') {
  await page.goto('/sign-in', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.locator('main button[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  expect(await page.locator('form[action$="/sign-out"]').count(), 'signed in').toBeGreaterThan(0);
}

test.describe('Accessibility (WCAG 2.2 AA, automated), public pages', () => {
  for (const [name, url] of [['catalogue', '/discover'], ['search', '/discover?q=minutes'], ['sign-in', '/sign-in'], ['accessibility statement', '/accessibility-statement']]) {
    test(`${name} has no new critical or serious violations`, async ({ page }) => {
      await page.goto(url, { waitUntil: 'domcontentloaded' });
      expect(await scan(page, name)).toEqual([]);
    });
  }

  test('a record with a scan has no new critical or serious violations', async ({ page }) => {
    await page.goto(await first(page, '/discover?q=page', /\/discover\/records\//), { waitUntil: 'domcontentloaded' });
    expect(await scan(page, 'record')).toEqual([]);
  });

  test('an authority record has no new critical or serious violations', async ({ page }) => {
    await page.goto(await first(page, '/discover?q=council', /\/discover\/agents\//), { waitUntil: 'domcontentloaded' });
    expect(await scan(page, 'authority record')).toEqual([]);
  });
});

test.describe('Accessibility (WCAG 2.2 AA, automated), staff pages', () => {
  // Signed in once for every staff page: sign-in is rate limited, as it should be.
  test.describe.configure({ mode: 'serial' });
  let session: string;
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await signIn(page);
    session = path.join(require('os').tmpdir(), 'openric-a11y-session.json');
    await page.context().storageState({ path: session });
    await page.close();
  });
  test.use({ storageState: async ({}, use) => use(session) });

  for (const [name, url] of [['dashboard', '/'], ['records', '/records'], ['authority records', '/agents'], ['import', '/imports'],
    ['export', '/records/export'], ['offline catalogues', '/offline-catalogues'], ['search', '/search?q=minutes']]) {
    test(`staff ${name} has no new critical or serious violations`, async ({ page }) => {
      await page.goto(url, { waitUntil: 'domcontentloaded' });
      expect(await scan(page, `staff ${name}`)).toEqual([]);
    });
  }

  test('staff record, its form and an authority record\'s form have no new critical or serious violations', async ({ page }) => {
    const record = await first(page, '/records', /\/records\/[0-9a-f-]{36}$/);
    await page.goto(record, { waitUntil: 'domcontentloaded' });
    expect(await scan(page, 'staff record')).toEqual([]);
    await page.goto(record + '/edit', { waitUntil: 'domcontentloaded' });
    expect(await scan(page, 'staff record form')).toEqual([]);
    const agent = await first(page, '/agents', /\/agents\/[0-9a-f-]{36}$/);
    await page.goto(agent + '/edit', { waitUntil: 'domcontentloaded' });
    expect(await scan(page, 'staff authority record form')).toEqual([]);
  });

  test('staff 3D model page has no new critical or serious violations', async ({ page }) => {
    await page.goto(await first(page, '/search?q=carved', /\/records\/[0-9a-f-]{36}$/), { waitUntil: 'domcontentloaded' });
    const model = await page.locator('a[href$="/model"]').first().getAttribute('href');
    test.skip(!model, 'the demonstration has no 3D model on this host');
    await page.goto(model!, { waitUntil: 'domcontentloaded' });
    expect(await scan(page, 'staff 3d model')).toEqual([]);
  });
});
