// End-to-end QA of the Legal form (front end only; Prompt 3).
// Against the local php -S server (router.php): the real CSP applies; ping.php and
// submit.php are 404 like nginx today, and are mocked with page.route() where a
// test needs another answer. Fake data only. Expected texts come from content.json.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const C = JSON.parse(readFileSync(resolve(ROOT, 'app/forms/legal/content.json'), 'utf8'));
const DATA = JSON.parse(readFileSync(resolve(ROOT, 'app/data/form-data.json'), 'utf8'));
const fmt = (tpl, vars) => String(tpl).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
const NL = DATA.notListed;
const LOCAL = DATA.locals.find((l) => l.worksites.length > 2);         // a local with real worksites
const LOCAL2 = DATA.locals.find((l) => l !== LOCAL && l.worksites.length > 2);
const MB = 1024 * 1024;

/** Collect CSP violations and page errors; assert none at the end of each test. */
function watch(page) {
  const problems = [];
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) problems.push(m.text()); });
  page.on('pageerror', (e) => problems.push(String(e)));
  return problems;
}

async function open(page) {
  await page.goto('/legal/');
  await expect(page.locator('#legal-form')).toBeVisible();
}

async function fillValid(page) {
  await page.fill('#firstName', 'Testy');
  await page.fill('#lastName', 'McTestface');
  await page.fill('#membershipNumber', '0001234567');
  await page.fill('#street', '123 Test St');
  await page.fill('#city', 'Richmond');
  await page.fill('#zip', '23219');
  await page.fill('#phone', '8045551234');
  await page.fill('#email', 'testy@example.org');
  await page.selectOption('#local', LOCAL.name);
  await page.selectOption('#worksite', LOCAL.worksites[0]);
  await page.fill('#jobTitle', 'Teacher');
  await page.fill('#timeInPosition', '3 years');
  await page.selectOption('#issueType', C.issueTypes[1]);
  await page.fill('#incidentDate', '2026-01-15');
  await page.fill('#narrative', 'A short fake narrative for testing.');
  await page.fill('#requestedOutcome', 'Advice');
}

const words = (n) => Array.from({ length: n }, (_, i) => `w${i}`).join(' ');

test('page: banner, sections in order, signed-in line hidden, state defaults to Virginia', async ({ page }) => {
  const problems = watch(page);
  await open(page);
  await expect(page.locator('.privilege')).toHaveText(C.banner.privilege);
  await expect(page.locator('.section__title')).toHaveText([C.sections.member, C.sections.employment, C.sections.issue, C.sections.documents]);
  await expect(page.locator('#signed-in')).toBeHidden();               // ping.php is 404 until Prompt 4
  await expect(page.locator('#state')).toHaveValue(C.fields.state.default);
  await expect(page.locator('#worksite')).toBeDisabled();
  expect(problems).toEqual([]);
});

/**
 * The font face Chromium actually rendered for an element (CDP), e.g. "SegoeUI-Semibold".
 * With font-synthesis: none the browser can only use real faces: a 600 request gets the
 * family's 600 face if it has one, otherwise the nearest heavier real face (700).
 */
async function renderedFace(page, selector) {
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('DOM.enable');
  await cdp.send('CSS.enable');
  const { root } = await cdp.send('DOM.getDocument');
  const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: root.nodeId, selector });
  const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
  await cdp.detach();
  return fonts.sort((a, b) => b.glyphCount - a.glyphCount)[0];
}
const SEMIBOLD_FACE = /semi\s*-?bold|demi\s*-?bold|-600\b|w6\b/i;
const HEAVY_FACE = /semi\s*-?bold|demi\s*-?bold|-600\b|w6\b|bold|heavy|black/i;

test('header title and privilege banner: semibold 600 from a real face, no synthetic bold', async ({ page }, testInfo) => {
  const problems = watch(page);
  await open(page);
  await expect(page.locator('.banner__title')).toHaveText(C.page.title);
  await expect(page.locator('.banner__title')).toBeVisible();
  const st = await page.evaluate(() => ['.banner__title', '.privilege', 'header.banner'].map((sel) => {
    const cs = getComputedStyle(document.querySelector(sel));
    return { sel, weight: cs.fontWeight, synth: cs.fontSynthesisWeight || cs.fontSynthesis, align: cs.textAlign, size: cs.fontSize };
  }));
  expect(st[0]).toMatchObject({ weight: '600', size: page.viewportSize().width >= 576 ? '24px' : '20px' });   // sizes unchanged
  expect(st[1]).toMatchObject({ weight: '600', align: 'center' });
  for (const x of st) expect(x.synth, `${x.sel} font-synthesis`).toMatch(/^none/);

  for (const sel of ['.banner__title', '.privilege']) {
    const face = await renderedFace(page, sel);
    testInfo.annotations.push({ type: 'rendered face', description: `${sel}: ${face.familyName} / ${face.postScriptName}` });
    // A real face of weight >= 600 is used (never the regular face, never synthesized).
    expect(face.postScriptName, `${sel} rendered face`).toMatch(HEAVY_FACE);
    // Where the family ships a 600 face (Segoe UI, San Francisco, Roboto variable), it must be that one.
    // This server's Chromium only has DejaVu Sans (Regular + Bold): set E2E_REQUIRE_600=1 on a machine with a 600 face.
    if (process.env.E2E_REQUIRE_600 === '1') expect(face.postScriptName, `${sel} rendered face`).toMatch(SEMIBOLD_FACE);
  }
  expect(problems).toEqual([]);
});

const rgb = (hex) => `rgb(${[1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(', ')})`;

test('theme: header bar with logo then title, card on the textured gray page, colours from the theme', async ({ page }) => {
  const problems = watch(page);
  const assetResponses = [];
  page.on('response', (r) => { if (r.url().includes('/img/grain.png')) assetResponses.push(r); });
  await open(page);

  // texture: served under /legal/assets/ with 200 and used as the page background
  await expect.poll(() => assetResponses.length).toBeGreaterThan(0);
  const grain = assetResponses[0];
  expect(new URL(grain.url()).pathname).toBe('/legal/assets/img/grain.png');
  expect(grain.status()).toBe(200);
  expect(grain.headers()['content-type']).toContain('image/png');
  const body = await page.evaluate(() => { const cs = getComputedStyle(document.body); return { bg: cs.backgroundColor, img: cs.backgroundImage }; });
  expect(body.bg).toBe(rgb('#7B7B7B'));
  expect(body.img).toMatch(/\/legal\/assets\/img\/grain\.png/);

  const s = await page.evaluate(() => {
    const cs = (sel, pseudo) => getComputedStyle(document.querySelector(sel), pseudo || null);
    const logo = document.querySelector('.banner__logo').getBoundingClientRect();
    const title = document.querySelector('.banner__title').getBoundingClientRect();
    const bar = document.querySelector('header.banner').getBoundingClientRect();
    const card = document.querySelector('.page').getBoundingClientRect();
    return {
      header: cs('header.banner').backgroundColor, headerText: cs('.banner__title').color,
      titleInBar: title.top >= bar.top && title.bottom <= bar.bottom, logoLeftOfTitle: logo.right <= title.left,
      card: cs('.page').backgroundColor, cardRadius: cs('.page').borderTopLeftRadius, cardShadow: cs('.page').boxShadow,
      cardLeft: card.left, cardRight: window.innerWidth - card.right, cardWidth: card.width,
      privilegeBg: cs('.privilege').backgroundColor, privilegeFg: cs('.privilege').color,
      privilegeBorder: cs('.privilege').borderLeftWidth, privilegeWeight: cs('.privilege').fontWeight, privilegeAlign: cs('.privilege').textAlign,
      label: [cs('label[for="firstName"]').color, cs('label[for="firstName"]').textTransform, cs('label[for="firstName"]').fontWeight,
        cs('label[for="firstName"]').fontSize, cs('label[for="firstName"]').letterSpacing],
      worksite: [document.querySelector('#worksite').disabled, cs('#worksite').borderTopStyle, cs('#worksite').borderTopColor],
      req: cs('label[for="firstName"] .req').color,
      input: [cs('#firstName').backgroundColor, cs('#firstName').borderTopColor, cs('#firstName').borderTopLeftRadius],
      section: [cs('#sec-member-title').color, cs('#sec-member-title').fontWeight, cs('#sec-member-title').borderBottomStyle, cs('#sec-member-title').borderBottomColor, cs('#sec-member-title').backgroundColor],
      button: [cs('#review-btn').backgroundColor, cs('#review-btn').color, cs('#review-btn').borderTopLeftRadius],
      help: cs('.help') ? cs('.help').color : null,
    };
  });
  expect(s.header).toBe(rgb('#025D8C'));
  expect(s.headerText).toBe(rgb('#FFFFFF'));
  expect(s.titleInBar).toBe(true);
  expect(s.logoLeftOfTitle).toBe(true);
  expect(s.card).toBe(rgb('#FFFFFF'));
  expect(s.cardRadius).toBe('0px');
  expect(s.cardShadow).not.toBe('none');
  expect(s.privilegeBg).toBe(rgb('#E8F0F6'));
  expect(s.privilegeFg).toBe(rgb('#023E5E'));
  expect(s.privilegeBorder).toBe('0px');                                // centred text: left border dropped
  expect(s.privilegeWeight).toBe('600');
  expect(s.privilegeAlign).toBe('center');
  expect(s.label).toEqual([rgb('#444444'), 'none', '700', '15px', 'normal']);   // case, weight, size as ac99e69; only the colour changed
  expect(s.worksite).toEqual([true, 'dashed', rgb('#8A8A8A')]);          // "choose a local first" state
  expect(s.req).toBe(rgb('#B42318'));
  expect(s.input).toEqual([rgb('#FBFBFB'), rgb('#8A8A8A'), '0px']);
  expect(s.section).toEqual([rgb('#333333'), '700', 'dotted', rgb('#CCCCCC'), 'rgba(0, 0, 0, 0)']);
  expect(s.button).toEqual([rgb('#336699'), rgb('#FFFFFF'), '0px']);
  expect(s.help).toBe(rgb('#555555'));

  const vw = page.viewportSize().width;
  if (vw < 600) {                                                        // phone: full width with small margins
    expect(s.cardLeft).toBeGreaterThan(0);
    expect(s.cardLeft).toBeLessThanOrEqual(12);
    expect(s.cardRight).toBeLessThanOrEqual(12);
  } else {                                                               // centred card, 640–760px
    expect(s.cardWidth).toBeGreaterThanOrEqual(640);
    expect(s.cardWidth).toBeLessThanOrEqual(760);
    expect(Math.abs(s.cardLeft - s.cardRight)).toBeLessThanOrEqual(1);
  }
  expect(problems).toEqual([]);
});

test('errors stay distinct from the blue brand: red, icon, "Error:" prefix, heavier left border', async ({ page }) => {
  await open(page);
  await page.click('#review-btn');
  await expect(page.locator('#firstName-err')).toBeVisible();
  const st = await page.evaluate(() => {
    const err = document.querySelector('#firstName-err');
    const f = document.querySelector('#firstName').closest('.field');
    return { color: getComputedStyle(err).color, icon: getComputedStyle(err, '::before').content,
      border: [getComputedStyle(f).borderLeftWidth, getComputedStyle(f).borderLeftColor],
      summaryBorder: getComputedStyle(document.querySelector('#form-summary')).borderLeftColor };
  });
  expect(st.color).toBe(rgb('#B42318'));
  expect(st.icon).toContain('⚠');
  expect(st.border).toEqual(['4px', rgb('#B42318')]);
  expect(st.summaryBorder).toBe(rgb('#B42318'));
  await expect(page.locator('#firstName-err .err__prefix')).toHaveText(C.common.errorPrefix);
  await expect(page.locator('#firstName')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#firstName')).toHaveAttribute('aria-describedby', /firstName-err/);
});

test('no horizontal scroll, on the form and on a review page with long answers', async ({ page }) => {
  await open(page);
  const noScroll = () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
  expect(await noScroll()).toBe(true);
  await fillValid(page);
  await page.fill('#narrative', `Long ${'y'.repeat(200)}`);
  await page.fill('#jobTitle', 'z'.repeat(100));
  await page.click('#review-btn');
  await expect(page.locator('#view-review')).toBeVisible();
  expect(await noScroll()).toBe(true);
});

test('review: long answers below the label at full width, line breaks kept; short answers inline', async ({ page }) => {
  const problems = watch(page);
  await open(page);
  await fillValid(page);
  const longWord = 'x'.repeat(150);                                     // must wrap, not overflow
  const narrative = `First line of the fake narrative.\nSecond line.\n\nAfter a blank line ${longWord}`;
  await page.fill('#narrative', narrative);
  await page.fill('#requestedOutcome', 'Advice');                        // short, but always full width
  await page.fill('#jobTitle', 'Senior instructional technology resource teacher');   // > reviewInlineMaxChars
  await page.selectOption('#issueType', C.fields.issueType.otherValue);
  await page.fill('#issueTypeOther', 'Something else');
  await page.click('#review-btn');
  await expect(page.locator('#view-review')).toBeVisible();

  const geom = (label) => page.locator('.review__row').filter({ has: page.locator('dt', { hasText: label }) }).first().evaluate((r) => {
    const dt = r.querySelector('dt').getBoundingClientRect();
    const dd = r.querySelector('dd');
    const b = dd.getBoundingClientRect();
    const cs = getComputedStyle(r);
    const inner = r.getBoundingClientRect().width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    return { long: r.classList.contains('review__row--long'), below: b.top >= dt.bottom - 0.5, sameLeft: Math.abs(b.left - dt.left) < 1,
      fullWidth: Math.abs(b.width - inner) < 1, ws: getComputedStyle(dd).whiteSpace, overflow: dd.scrollWidth > dd.clientWidth + 1, text: dd.innerText };
  });

  for (const label of [C.fields.narrative.label, C.fields.requestedOutcome.label, C.fields.issueType.label, C.fields.jobTitle.label]) {
    const g = await geom(label);
    expect(g, label).toMatchObject({ long: true, below: true, sameLeft: true, fullWidth: true, ws: 'pre-wrap', overflow: false });
  }
  const n = await geom(C.fields.narrative.label);
  expect(n.text).toContain('First line of the fake narrative.\nSecond line.\n\nAfter a blank line');

  const short = await geom(C.fields.membershipNumber.label);
  expect(short.long).toBe(false);
  if (page.viewportSize().width >= 576) expect(short).toMatchObject({ below: false, sameLeft: false });   // label | value columns
  else expect(short).toMatchObject({ below: true, sameLeft: true });                                       // phone: stacked, as before
  expect(problems).toEqual([]);
});

test('cascade: local → worksites, change resets, OTHER / NOT LISTED reveals text fields', async ({ page }) => {
  const problems = watch(page);
  await open(page);
  await page.selectOption('#local', LOCAL.name);
  await expect(page.locator('#worksite')).toBeEnabled();
  const options = await page.locator('#worksite option').allTextContents();
  expect(options.slice(1)).toEqual(LOCAL.worksites);                    // after "Select…"
  expect(options.at(-1)).toBe(NL);

  await page.selectOption('#worksite', NL);
  await expect(page.locator('#worksiteOther')).toBeVisible();
  await page.fill('#worksiteOther', 'Annex');

  await page.selectOption('#local', LOCAL2.name);                       // changing the local resets the worksite
  await expect(page.locator('#worksite')).toHaveValue('');
  await expect(page.locator('#worksiteOther-wrap')).toBeHidden();

  await page.selectOption('#local', NL);
  await expect(page.locator('#localOther')).toBeVisible();
  expect((await page.locator('#worksite option').allTextContents()).slice(1)).toEqual([NL]);
  expect(problems).toEqual([]);
});

test('"Other" issue type reveals a required text field; errors are not colour-only', async ({ page }) => {
  await open(page);
  await fillValid(page);
  await page.selectOption('#issueType', C.fields.issueType.otherValue);
  await expect(page.locator('#issueTypeOther')).toBeVisible();
  await page.click('#review-btn');
  await expect(page.locator('#form-summary')).toBeVisible();
  const err = page.locator('#issueTypeOther-err');
  await expect(err).toBeVisible();
  await expect(err.locator('.err__prefix')).toHaveText(C.common.errorPrefix);   // "Error:" text, not colour alone
  await expect(err).toContainText(C.messages.otherRequired);
  await expect(page.locator('#issueTypeOther')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#issueTypeOther-wrap')).toHaveClass(/is-invalid/);
  await page.fill('#issueTypeOther', 'Something else');
  await page.click('#review-btn');
  await expect(page.locator('#view-review')).toBeVisible();
});

test('narrative: live word counter and a hard stop at the limit', async ({ page }) => {
  await open(page);
  const max = C.limits.narrativeMaxWords;
  await page.fill('#narrative', words(max));
  await expect(page.locator('#narrative-count')).toHaveText(fmt(C.fields.narrative.counter, { count: max, max }));
  await page.locator('#narrative').press('End');
  await page.locator('#narrative').pressSequentially(' more');
  expect((await page.locator('#narrative').inputValue()).trim().split(/\s+/).length).toBe(max);
  await expect(page.locator('#narrative-limit')).toHaveText(fmt(C.messages.narrativeLimit, { max }));

  // a paste that would exceed the limit is not added
  await page.fill('#narrative', words(10));
  await page.locator('#narrative').evaluate((el, text) => {
    el.value = text;
    el.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' }));
  }, words(max + 5));
  expect((await page.locator('#narrative').inputValue()).trim().split(/\s+/).length).toBe(10);
  await expect(page.locator('#narrative-limit')).toHaveText(fmt(C.messages.narrativePaste, { max }));
});

test('uploads: list with remove, count/size/type/total limits announced', async ({ page }) => {
  await open(page);
  const file = (name, size) => ({ name, mimeType: 'application/octet-stream', buffer: Buffer.alloc(size, 1) });
  await page.setInputFiles('#files', [file('a.pdf', 1000), file('b.txt', 2000)]);
  await expect(page.locator('#files-list li')).toHaveCount(2);
  await expect(page.locator('#files-status')).toContainText(fmt(C.uploads.added, { name: 'a.pdf' }));

  await page.setInputFiles('#files', [file('evil.exe', 10)]);
  await expect(page.locator('#files-err')).toContainText(fmt(C.uploads.badType, { name: 'evil.exe' }));
  await page.setInputFiles('#files', [file('big.pdf', C.limits.maxFileMb * MB + 1)]);
  await expect(page.locator('#files-err')).toContainText(fmt(C.uploads.tooBig, { name: 'big.pdf', max: C.limits.maxFileMb }));

  await page.setInputFiles('#files', [file('n1.pdf', 9 * MB), file('n2.pdf', 9 * MB), file('n3.pdf', 9 * MB)]);
  await expect(page.locator('#files-err')).toContainText(fmt(C.uploads.totalTooBig, { name: 'n3.pdf', max: C.limits.maxTotalMb }));
  await expect(page.locator('#files-list li')).toHaveCount(4);

  await page.setInputFiles('#files', [file('c.csv', 10), file('d.png', 10)]);
  await expect(page.locator('#files-list li')).toHaveCount(C.limits.maxFiles);
  await expect(page.locator('#files-err')).toContainText(fmt(C.uploads.tooMany, { name: 'd.png', max: C.limits.maxFiles }));

  await page.getByRole('button', { name: fmt(C.uploads.removeLabel, { name: 'a.pdf' }) }).click();
  await expect(page.locator('#files-list li')).toHaveCount(C.limits.maxFiles - 1);
  await expect(page.locator('#files-status')).toHaveText(fmt(C.uploads.removed, { name: 'a.pdf' }));
});

test('review and edit round trip keeps every answer', async ({ page }) => {
  const problems = watch(page);
  await open(page);
  await fillValid(page);
  await page.setInputFiles('#files', [{ name: 'evidence.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 fake') }]);
  await page.click('#review-btn');
  await expect(page.locator('#view-review')).toBeVisible();
  await expect(page.locator('#view-form')).toBeHidden();
  await expect(page.locator('#review-member')).toContainText('Testy McTestface');
  await expect(page.locator('#review-member')).toContainText('(804) 555-1234');
  await expect(page.locator('#review-member')).toContainText(C.fields.dateOfBirth.notProvided);
  await expect(page.locator('#review-employment')).toContainText(LOCAL.worksites[0]);
  await expect(page.locator('#review-issue')).toContainText('01/15/2026');
  await expect(page.locator('#review-documents')).toContainText('evidence.pdf');

  await page.getByRole('button', { name: `${C.buttons.edit}: ${C.sections.employment}` }).click();
  await expect(page.locator('#view-form')).toBeVisible();
  await expect(page.locator('#local')).toBeFocused();
  await expect(page.locator('#worksite')).toHaveValue(LOCAL.worksites[0]);
  await page.fill('#jobTitle', 'Counselor');
  await page.click('#review-btn');
  await expect(page.locator('#review-employment')).toContainText('Counselor');

  await page.goBack();                                                   // browser Back returns to the form
  await expect(page.locator('#view-form')).toBeVisible();
  await expect(page.locator('#firstName')).toHaveValue('Testy');
  expect(problems).toEqual([]);
});

test('submit until Prompt 4: "not enabled yet" and the answers are kept', async ({ page }) => {
  await open(page);
  await fillValid(page);
  await page.click('#review-btn');
  await page.click('#submit-btn');
  await expect(page.locator('#submit-error')).toHaveText(C.messages.notEnabled);
  await expect(page.locator('#submit-btn')).toBeEnabled();
  await page.click('#back-btn');
  await expect(page.locator('#firstName')).toHaveValue('Testy');
});

test('submit contract (mocked): expired session keeps data; success shows the reference and clears', async ({ page }) => {
  await open(page);
  await fillValid(page);
  await page.click('#review-btn');

  await page.route('**/legal/ping.php', (r) => r.fulfill({ status: 401, body: '' }));
  await page.click('#submit-btn');
  await expect(page.locator('#submit-error')).toContainText(C.messages.sessionExpired);
  await expect(page.locator('#submit-error a')).toHaveAttribute('target', '_blank');
  await expect(page.locator('#submit-error a')).toHaveAttribute('href', '/legal/');

  let sent = null;
  await page.unroute('**/legal/ping.php');
  await page.route('**/legal/ping.php', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"email":"staff@example.org","name":null}' }));
  await page.route('**/legal/submit.php', (r) => {
    sent = r.request();
    r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"id":"20261123-1200-TEST"}' });
  });
  await page.click('#submit-btn');
  await expect(page.locator('#confirmation')).toBeVisible();
  await expect(page.locator('#confirmation-ref')).toHaveText(fmt(C.confirmation.reference, { id: '20261123-1200-TEST' }));
  await expect(page.locator('#signed-in')).toHaveText(fmt(C.page.signedInAs, { email: 'staff@example.org' }));

  // the request matches docs/PAYLOAD.md: multipart with a JSON "payload" field
  expect(sent.method()).toBe('POST');
  expect(sent.headers()['content-type']).toMatch(/^multipart\/form-data; boundary=/);
  const body = sent.postData() || '';
  expect(body).toContain('name="payload"');
  expect(body).toContain('"membershipNumber":"0001234567"');
  expect(body).toContain('"phone":"8045551234"');
  expect(body).toContain(`"formVersion":"${C._meta.formVersion}"`);

  await page.click('#new-request');
  await expect(page.locator('#firstName')).toHaveValue('');
});

test('server field errors are mapped back and the answers are kept', async ({ page }) => {
  await open(page);
  await fillValid(page);
  await page.click('#review-btn');
  await page.route('**/legal/ping.php', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"email":"staff@example.org","name":null}' }));
  await page.route('**/legal/submit.php', (r) => r.fulfill({ status: 400, contentType: 'application/json', body: '{"ok":false,"error":"invalid_input","fields":["zip","email"]}' }));
  await page.click('#submit-btn');
  await expect(page.locator('#submit-error')).toContainText(C.fields.email.label);
  await page.click('#back-btn');
  await expect(page.locator('#email-err')).toContainText(C.messages.serverRejected);
  await expect(page.locator('#email')).toHaveValue('testy@example.org');
});

test('ping at page load shows "Signed in as <email>"; 503 unavailable and rejected files keep the answers', async ({ page }) => {
  await page.route('**/legal/ping.php', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"email":"staff@example.org","name":null}' }));
  await open(page);
  await expect(page.locator('#signed-in')).toHaveText(fmt(C.page.signedInAs, { email: 'staff@example.org' }));
  await fillValid(page);
  await page.setInputFiles('#files', [{ name: 'evidence.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 fake') }]);
  await page.click('#review-btn');

  await page.unroute('**/legal/ping.php');
  await page.route('**/legal/ping.php', (r) => r.fulfill({ status: 503, contentType: 'application/json', body: '{"ok":false,"error":"unavailable"}' }));
  await page.click('#submit-btn');
  await expect(page.locator('#submit-error')).toContainText(C.messages.submitUnavailable);

  await page.unroute('**/legal/ping.php');
  await page.route('**/legal/ping.php', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"email":"staff@example.org","name":null}' }));
  await page.route('**/legal/submit.php', (r) => r.fulfill({ status: 400, contentType: 'application/json', body: '{"ok":false,"error":"invalid_input","fields":["files"]}' }));
  await page.click('#submit-btn');
  await expect(page.locator('#submit-error')).toContainText(C.fields.documents.label);
  await page.click('#back-btn');
  await expect(page.locator('#files-err')).toContainText(C.messages.fileRejected);
  await expect(page.locator('#firstName')).toHaveValue('Testy');
});

test('rejected files: per-file messages in the list, review summary links to the file, removal clears them', async ({ page }) => {
  const problems = watch(page);
  await page.route('**/legal/ping.php', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"email":"staff@example.org","name":null}' }));
  await open(page);
  await fillValid(page);
  await page.setInputFiles('#files', [
    { name: 'letter.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 fake') },
    { name: 'Budget macro.docx', mimeType: 'application/octet-stream', buffer: Buffer.from('PK fake') },
    { name: 'locked.xlsx', mimeType: 'application/octet-stream', buffer: Buffer.from('fake') },
  ]);
  await page.click('#review-btn');
  let body = '';
  await page.route('**/legal/submit.php', (r) => r.fulfill({ status: 400, contentType: 'application/json',
    body: '{"ok":false,"error":"invalid_input","fields":["files"],"files":[{"index":1,"reason":"macro"},{"index":2,"reason":"encrypted"}]}' }));
  await page.click('#submit-btn');

  // review page: one summary line, a link (no vague "Please correct: Documentation")
  const summary = page.locator('#submit-error');
  await expect(summary).toHaveText(fmt(C.uploads.rejectedSummaryMany, { count: 2 }));
  const link = summary.locator('a');
  await expect(link).toHaveAttribute('href', '#file-item-1');
  await link.click();

  // form: back on the Documents section, focus on the first rejected file, a message next to each
  await expect(page.locator('#view-form')).toBeVisible();
  await expect(page.locator('#file-item-1')).toBeFocused();
  const vars = { max: C.limits.maxFileMb, maxFiles: C.limits.maxFiles, maxTotal: C.limits.maxTotalMb };
  await expect(page.locator('#file-item-1 .files__err')).toHaveText(`${C.common.errorPrefix} ${fmt(C.uploads.rejected.macro, { ...vars, name: 'Budget macro.docx', ext: 'DOCX' })}`);
  await expect(page.locator('#file-item-2 .files__err')).toHaveText(`${C.common.errorPrefix} ${fmt(C.uploads.rejected.encrypted, { ...vars, name: 'locked.xlsx', ext: 'XLSX' })}`);
  await expect(page.locator('#files-list li').first().locator('.files__err')).toHaveCount(0);
  await expect(page.locator('#files-err')).toContainText(fmt(C.uploads.rejectedFieldMany, { count: 2 }));
  await expect(page.locator('#files-err')).not.toContainText(C.messages.serverRejected);
  await expect(page.locator('#firstName')).toHaveValue('Testy');

  // still listed → Review is blocked until they are removed
  await page.click('#review-btn');
  await expect(page.locator('#form-summary')).toContainText(fmt(C.uploads.rejectedFieldMany, { count: 2 }));
  await page.getByRole('button', { name: fmt(C.uploads.removeLabel, { name: 'Budget macro.docx' }) }).click();
  await expect(page.locator('#files-err')).toContainText(C.uploads.rejectedFieldOne);
  await page.getByRole('button', { name: fmt(C.uploads.removeLabel, { name: 'locked.xlsx' }) }).click();
  await expect(page.locator('#files-err')).toBeHidden();
  await expect(page.locator('.files__err')).toHaveCount(0);

  // a single rejection, an unknown code falls back to a generic message
  await page.click('#review-btn');
  await expect(page.locator('#view-review')).toBeVisible();
  await page.unroute('**/legal/submit.php');
  await page.route('**/legal/submit.php', (r) => { body = r.request().postData() || ''; r.fulfill({ status: 400, contentType: 'application/json',
    body: '{"ok":false,"error":"invalid_input","fields":["files"],"files":[{"index":0,"reason":"mime_mismatch"}]}' }); });
  await page.click('#submit-btn');
  await expect(summary).toHaveText(C.uploads.rejectedSummaryOne);
  expect(body).toContain('filename="letter.pdf"');
  await summary.locator('a').click();
  await expect(page.locator('#file-item-0')).toBeFocused();
  await expect(page.locator('#file-item-0 .files__err')).toContainText(fmt(C.uploads.rejected.mime_mismatch, { ...vars, name: 'letter.pdf', ext: 'PDF' }));
  expect(problems).toEqual([]);
});

test('rejected files together with field errors, and an unknown reason code', async ({ page }) => {
  await page.route('**/legal/ping.php', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"email":"staff@example.org","name":null}' }));
  await open(page);
  await fillValid(page);
  await page.setInputFiles('#files', [{ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('fake') }]);
  await page.click('#review-btn');
  await page.route('**/legal/submit.php', (r) => r.fulfill({ status: 400, contentType: 'application/json',
    body: '{"ok":false,"error":"invalid_input","fields":["zip","files"],"files":[{"index":0,"reason":"something_new"}]}' }));
  await page.click('#submit-btn');
  const summary = page.locator('#submit-error');
  await expect(summary).toContainText(fmt(C.messages.submitInvalid, { fields: `${C.fields.address.label} (${C.fields.zip.label})` }));
  await expect(summary.locator('a')).toHaveText(C.uploads.rejectedSummaryOne);
  await summary.locator('a').click();
  await expect(page.locator('#file-item-0 .files__err')).toContainText(fmt(C.uploads.rejected.unknown, { name: 'notes.txt' }));
  await expect(page.locator('#zip-err')).toContainText(C.messages.serverRejected);
});
