// OpenRiC Workspace - the browser checks (roadmap R1 hardening): WCAG 2.2 AA with axe-core and a keyboard-only
// walkthrough, after Heratio's tests/e2e (heratio#1532), against a running Workspace with the demonstration dataset
// (php artisan workspace:demo). WORKSPACE_URL names it; the demonstration accounts sign in.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/browser',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.WORKSPACE_URL || 'http://127.0.0.1:8000',
    trace: 'off',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
