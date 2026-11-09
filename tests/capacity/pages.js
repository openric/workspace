// OpenRiC Workspace - the capacity test's pages (roadmap R1 hardening), run by k6 against a Workspace holding the
// capacity dataset (php artisan workspace:capacity:seed). Each virtual user is a reader of the public catalogue or an
// archivist signed in, each from an address of its own and pausing between pages as a person does, visiting what each
// does most: the catalogue, a search, a record and its tree, an authority record, the widest and the deepest units;
// staff also open a record's form and save it. Every page is tagged, so the summary gives p50 and p95 per page. Run by bin/capacity, which sets BASE, TARGETS, PASSWORD, VUS and DURATION.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

import http from 'k6/http';
import { check, fail, group, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = __ENV.BASE || 'http://127.0.0.1:8188';
const T = JSON.parse(open(__ENV.TARGETS));
const PASSWORD = __ENV.PASSWORD;
const VUS = Number(__ENV.VUS || 1);
const errors = new Counter('page_errors');

export const options = {
  scenarios: {
    readers: { executor: 'constant-vus', exec: 'reader', vus: Math.max(1, Math.ceil(VUS * 0.6)), duration: __ENV.DURATION || '3m' },
    staff: { executor: 'constant-vus', exec: 'archivist', vus: Math.max(1, Math.floor(VUS * 0.4)), duration: __ENV.DURATION || '3m' },
  },
  // The pass targets of the capacity test: p95 under 1 s at 10 users and 2.5 s at 25; no errors.
  thresholds: { http_req_failed: ['rate<0.001'], http_req_duration: [`p(95)<${VUS <= 10 ? 1000 : 2500}`] },
  summaryTrendStats: ['p(50)', 'p(95)', 'p(99)', 'max', 'count'],
  discardResponseBodies: false,
};

const pick = (list) => list[Math.floor(Math.random() * list.length)];

function page(name, url, params = {}) {
  const res = http.get(BASE + url, Object.assign({ tags: { page: name }, redirects: 0 }, params));
  if (!check(res, { [`${name} 200`]: (r) => r.status === 200 })) {
    errors.add(1, { page: name });
  }
  // A person reads a page before the next: 1 to 3 seconds, which also keeps each user under the catalogue's 120 pages
  // a minute for one address. bin/capacity gives every virtual user an address of its own (k6 --local-ips).
  sleep(1 + Math.random() * 2);
  return res;
}

export function reader() {
  group('public', () => {
    page('catalogue', '/discover');
    page('public search', '/discover?q=' + encodeURIComponent(pick(T.words)));
    page('public record', '/discover/records/' + pick(T.records));
    page('public tree, widest fonds', '/discover/records/' + T.wide_fonds + '/tree');
    page('public authority record', '/discover/agents/' + pick(T.agents));
    page('public record, deepest', '/discover/records/' + T.deep_unit);
    page('OAI-PMH list', '/api/ric/v1/oai?verb=ListIdentifiers&metadataPrefix=oai_dc');
  });
}

let signedIn = false;

function signIn() {
  const form = http.get(BASE + '/sign-in', { tags: { page: 'sign-in' } });
  const res = form.submitForm({ formSelector: 'form[action$="/sign-in"]', fields: { email: 'archivist@capacity.invalid', password: PASSWORD }, params: { tags: { page: 'sign-in' } } });
  if (res.url.endsWith('/sign-in')) {
    fail('sign-in refused for archivist@capacity.invalid');
  }
  signedIn = true;
}

export function archivist() {
  if (!signedIn) {
    signIn();
  }
  group('staff', () => {
    page('dashboard', '/');
    page('staff search', '/search?q=' + encodeURIComponent(pick(T.words)));
    const id = pick(T.editable);
    page('staff record', '/records/' + id);
    page('staff tree, widest fonds', '/records/' + T.wide_fonds + '/tree');
    const form = page('record form', '/records/' + id + '/edit');
    if (form.status === 200) {
      const saved = form.submitForm({ formSelector: 'form[method="post"][action$="/records/' + id + '"]', params: { tags: { page: 'save record' }, redirects: 1 } });
      if (!check(saved, { 'save record 2xx': (r) => r.status >= 200 && r.status < 400 })) {
        errors.add(1, { page: 'save record' });
      }
    }
    page('staff authority record', '/agents/' + pick(T.agents));
  });
}
