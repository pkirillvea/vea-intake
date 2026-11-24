/* VEA Legal Assistance Request: front end (vanilla ES2020, no dependencies).
 * Structure and patterns follow vea-join's public/assets/js/app.js.
 *
 * - All state lives in memory only: no localStorage, sessionStorage or cookies.
 *   Attached files are held as File objects until submission.
 * - Every visible string comes from content.json via the build (#strings data-json);
 *   lists come from /legal/data/form-data.json. Nothing user-facing is hard-coded here.
 * - Payload keys, limits and response shapes: docs/PAYLOAD.md (the server re-checks all of it).
 */
'use strict';

(() => {
  // ------------------------------------------------------------ helpers
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const el = (tag, props = {}, children = []) => {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (k === 'text') node.textContent = v;
      else if (k === 'class') node.className = v;
      else if (v === true) node.setAttribute(k, '');
      else if (v !== false && v != null) node.setAttribute(k, String(v));
    }
    for (const c of [].concat(children)) if (c != null) node.append(c);
    return node;
  };
  const fmt = (tpl, vars) => String(tpl).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
  const trim = (v) => String(v ?? '').trim();

  const body = document.body;
  const STR = JSON.parse($('#strings').dataset.json);
  const MSG = STR.messages;
  const F = STR.fields;
  const U = STR.uploads;
  const LIM = STR.limits;
  const CFG = Object.freeze({
    formVersion: body.dataset.formVersion || '',
    dataUrl: body.dataset.formData,
    pingUrl: body.dataset.pingUrl,
    submitUrl: body.dataset.submitUrl,
  });
  const MB = 1024 * 1024;
  const OTHER_ISSUE = F.issueType.otherValue;

  // ---------------------------------------------------------- state
  const form = $('#legal-form');
  const state = {
    data: null,            // form-data.json
    view: 'form',          // 'form' | 'review'
    files: [],             // File objects, in the order attached
    busy: false,
    dirty: false,
    touched: new Set(),
    narrativeOk: '',       // last narrative value within the word limit
    pendingFocus: undefined, // field to focus after Edit returns to the form
  };
  const notListed = () => state.data.notListed;

  // ---------------------------------------- dates (mirror of Birthdate.php)
  const Dates = {
    /** Today's date in America/New_York as YYYY-MM-DD. */
    today() {
      const p = Object.fromEntries(new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit',
      }).formatToParts(new Date()).map((x) => [x.type, x.value]));
      return `${p.year}-${p.month}-${p.day}`;
    },
    /** [y, m, d] for a real calendar date in YYYY-MM-DD, else null. */
    parse(iso) {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
      if (!m) return null;
      const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
      const t = new Date(Date.UTC(2000, mo - 1, d));
      t.setUTCFullYear(y);
      return t.getUTCFullYear() === y && t.getUTCMonth() === mo - 1 && t.getUTCDate() === d ? [y, mo, d] : null;
    },
    age(birth, today) {
      const [by, bm, bd] = Dates.parse(birth);
      const [ty, tm, td] = Dates.parse(today);
      return ty - by - (tm < bm || (tm === bm && td < bd) ? 1 : 0);
    },
    /** null when acceptable, else invalid | future | out_of_range (as Birthdate::check). */
    check(iso, today, min, max) {
      if (!Dates.parse(iso)) return 'invalid';
      if (iso > today) return 'future';
      const a = Dates.age(iso, today);
      return a < min || a > max ? 'out_of_range' : null;
    },
    toUs(iso) {
      const p = Dates.parse(iso);
      if (!p) return iso;
      const z = (n) => String(n).padStart(2, '0');
      return `${z(p[1])}/${z(p[2])}/${p[0]}`;
    },
  };

  /** Words as the server counts them: runs of non-whitespace. */
  const wordCount = (text) => (trim(text) === '' ? 0 : trim(text).split(/\s+/u).length);

  // -------------------------------------------------------- field values
  const val = (id) => trim($(`#${id}`)?.value);
  const phoneDigits = () => val('phone').replace(/\D/g, '');

  const EMAIL_RE = /^(?!\.)(?!.*\.\.)[A-Za-z0-9!#$%&'*+/=?^_`{|}~.-]{1,64}(?<!\.)@(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/;

  const maxLen = (id, max) => (val(id).length > max ? fmt(MSG.maxLength, { max }) : '');
  const required = (v, msg = MSG.required) => (v === '' ? msg : '');
  const choice = (v, allowed) => (v === '' || !allowed.includes(v) ? MSG.choose : '');

  // name -> { section, validate() -> '' | message, when?() }
  const RULES = {
    firstName: { section: 'member', validate: () => required(val('firstName')) || maxLen('firstName', F.firstName.maxLength) },
    lastName: { section: 'member', validate: () => required(val('lastName')) || maxLen('lastName', F.lastName.maxLength) },
    membershipNumber: {
      section: 'member',
      validate: () => required(val('membershipNumber')) || (/^\d{10}$/.test(val('membershipNumber')) ? '' : MSG.membershipNumber),
    },
    street: { section: 'member', validate: () => required(val('street')) || maxLen('street', F.street.maxLength) },
    city: { section: 'member', validate: () => required(val('city')) || maxLen('city', F.city.maxLength) },
    state: { section: 'member', validate: () => choice(val('state'), state.data.states) },
    zip: { section: 'member', validate: () => required(val('zip')) || (/^\d{5}(-\d{4})?$/.test(val('zip')) ? '' : MSG.zip) },
    phone: { section: 'member', validate: () => required(val('phone')) || (/^[2-9]\d{2}[2-9]\d{6}$/.test(phoneDigits()) ? '' : MSG.phone) },
    email: { section: 'member', validate: () => required(val('email')) || (EMAIL_RE.test(val('email')) && val('email').length <= 254 ? '' : MSG.email) },
    dateOfBirth: {
      section: 'member',
      validate: () => {
        const input = $('#dateOfBirth');
        if (val('dateOfBirth') === '') return input.validity.badInput ? MSG.dateInvalid : '';   // optional
        const reason = Dates.check(val('dateOfBirth'), Dates.today(), LIM.dobAgeMin, LIM.dobAgeMax);
        if (reason === 'future') return MSG.dobFuture;
        if (reason === 'out_of_range') return fmt(MSG.dobRange, { min: LIM.dobAgeMin, max: LIM.dobAgeMax });
        return reason ? MSG.dateInvalid : '';
      },
    },
    local: { section: 'employment', validate: () => choice(val('local'), state.data.locals.map((l) => l.name)) },
    localOther: {
      section: 'employment',
      when: () => val('local') === notListed(),
      validate: () => required(val('localOther'), MSG.otherRequired) || maxLen('localOther', 100),
    },
    worksite: { section: 'employment', validate: () => choice($('#worksite').value, selectedLocal()?.worksites ?? []) },
    worksiteOther: {
      section: 'employment',
      when: () => $('#worksite').value === notListed(),
      validate: () => required(val('worksiteOther'), MSG.otherRequired) || maxLen('worksiteOther', 100),
    },
    jobTitle: { section: 'employment', validate: () => required(val('jobTitle')) || maxLen('jobTitle', F.jobTitle.maxLength) },
    timeInPosition: { section: 'employment', validate: () => required(val('timeInPosition')) || maxLen('timeInPosition', F.timeInPosition.maxLength) },
    issueType: { section: 'issue', validate: () => choice(val('issueType'), STR.issueTypes) },
    issueTypeOther: {
      section: 'issue',
      when: () => val('issueType') === OTHER_ISSUE,
      validate: () => required(val('issueTypeOther'), MSG.otherRequired) || maxLen('issueTypeOther', F.issueType.otherMaxLength),
    },
    incidentDate: {
      section: 'issue',
      validate: () => {
        if (val('incidentDate') === '') return $('#incidentDate').validity.badInput ? MSG.dateInvalid : MSG.required;
        if (!Dates.parse(val('incidentDate'))) return MSG.dateInvalid;
        return val('incidentDate') > Dates.today() ? MSG.incidentFuture : '';
      },
    },
    narrative: {
      section: 'issue',
      validate: () => required(val('narrative'))
        || (wordCount(val('narrative')) > LIM.narrativeMaxWords ? fmt(MSG.narrativeLimit, { max: LIM.narrativeMaxWords }) : '')
        || maxLen('narrative', LIM.narrativeMaxChars),
    },
    requestedOutcome: {
      section: 'issue',
      validate: () => required(val('requestedOutcome')) || maxLen('requestedOutcome', LIM.requestedOutcomeMaxChars),
    },
    files: { section: 'documents', validate: () => filesProblem() },
  };

  const SECTION_FIRST = { member: 'firstName', employment: 'local', issue: 'issueType', documents: 'files' };

  function labelFor(name) {
    const map = {
      localOther: F.local.otherLabel,
      worksiteOther: F.worksite.otherLabel,
      issueTypeOther: F.issueType.otherLabel,
      street: `${F.address.label} (${F.street.label})`,
      city: `${F.address.label} (${F.city.label})`,
      state: `${F.address.label} (${F.state.label})`,
      zip: `${F.address.label} (${F.zip.label})`,
      files: F.documents.label,
    };
    return map[name] || F[name]?.label || name;
  }

  // -------------------------------------------------------------- errors
  const errorEl = (name) => $(`#${name}-err`);

  function checkField(name) {
    const rule = RULES[name];
    if (rule.when && !rule.when()) return '';
    return rule.validate();
  }

  /** Errors never rely on colour: icon (CSS) + "Error:" prefix + heavier border + aria-invalid. */
  function showError(name, message) {
    const err = errorEl(name);
    if (err) {
      err.textContent = '';
      if (message) err.append(el('span', { class: 'err__prefix', text: STR.common.errorPrefix }), ` ${message}`);
      err.hidden = !message;
    }
    const input = $(`#${name}`);
    if (input) {
      if (message) input.setAttribute('aria-invalid', 'true');
      else input.removeAttribute('aria-invalid');
      input.closest('.field, .part, .reveal')?.classList.toggle('is-invalid', Boolean(message));
    }
  }

  function allErrors() {
    return Object.keys(RULES).map((name) => ({ name, message: checkField(name) })).filter((e) => e.message);
  }

  function renderSummary(errors) {
    const box = $('#form-summary');
    box.textContent = '';
    if (!errors.length) {
      box.hidden = true;
      return;
    }
    const list = el('ul');
    for (const e of errors) {
      const a = el('a', { href: `#${e.name}`, text: `${labelFor(e.name)}${STR.common.errorSeparator}${e.message}` });
      a.addEventListener('click', (ev) => {
        ev.preventDefault();
        focusField(e.name);
      });
      list.append(el('li', {}, a));
    }
    box.append(el('p', { text: STR.common.errorSummary }), list);
    box.hidden = false;
  }

  function refreshSummary() {
    if (!$('#form-summary').hidden) renderSummary(allErrors().filter((e) => state.touched.has(e.name)));
  }

  function focusField(name) {
    const target = $(`#${name}`);
    if (!target) return;
    target.focus();
    target.scrollIntoView({ block: 'center' });
  }

  function onFieldBlur(name) {
    if (!RULES[name]) return;
    state.touched.add(name);
    showError(name, checkField(name));
    refreshSummary();
  }

  function onFieldInput(name) {
    if (RULES[name] && (state.touched.has(name) || !errorEl(name)?.hidden)) showError(name, checkField(name));
    refreshSummary();
  }

  // ----------------------------------------------------------- formatting
  function formatPhone(digits) {
    const d = digits.slice(0, 10);
    if (d.length > 6) return `(${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6)}`;
    if (d.length > 3) return `(${d.slice(0, 3)}) ${d.slice(3)}`;
    if (d.length > 0) return `(${d}`;
    return '';
  }

  function onPhoneInput(e) {
    const input = e.target;
    let d = input.value.replace(/\D/g, '');
    if (d.length === 11 && d[0] === '1') d = d.slice(1);
    // While deleting, only drop stray characters so backspace is never "stuck" (as vea-join).
    if (e.inputType && e.inputType.startsWith('delete')) {
      if (/[^\d() -]/.test(input.value)) input.value = input.value.replace(/[^\d() -]/g, '');
      return;
    }
    input.value = formatPhone(d);
  }

  function onZipInput(e) {
    const d = e.target.value.replace(/\D/g, '').slice(0, 9);
    const v = d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d;
    if (!(e.inputType || '').startsWith('delete') || /[^\d-]/.test(e.target.value)) e.target.value = v;
  }

  function onMembershipInput(e) {
    const v = e.target.value.replace(/\D/g, '').slice(0, 10);
    if (v !== e.target.value) e.target.value = v;
  }

  // ------------------------------------------------------------- narrative
  function updateCounter() {
    $('#narrative-count').textContent = fmt(F.narrative.counter, { count: wordCount($('#narrative').value), max: LIM.narrativeMaxWords });
  }

  /** Hard stop at the word limit: an edit that would exceed it is undone, with a clear message. */
  function onNarrativeInput(e) {
    const input = e.target;
    const note = $('#narrative-limit');
    if (wordCount(input.value) > LIM.narrativeMaxWords) {
      const pasted = (e.inputType || '').startsWith('insertFromPaste') || (e.inputType || '').startsWith('insertFromDrop');
      const caret = Math.max(0, (input.selectionStart ?? input.value.length) - (input.value.length - state.narrativeOk.length));
      input.value = state.narrativeOk;
      input.setSelectionRange(caret, caret);
      note.textContent = fmt(pasted ? MSG.narrativePaste : MSG.narrativeLimit, { max: LIM.narrativeMaxWords });
      note.hidden = false;
    } else {
      state.narrativeOk = input.value;
      if (wordCount(input.value) < LIM.narrativeMaxWords) note.hidden = true;
    }
    updateCounter();
  }

  // ---------------------------------------------------------------- data → UI
  function option(value, text, selected = false) {
    const o = el('option', { value, text });
    if (selected) o.selected = true;
    return o;
  }

  const selectedLocal = () => state.data.locals.find((l) => l.name === $('#local').value) || null;

  function populate(data) {
    const stateSel = $('#state');
    stateSel.append(option('', STR.common.select));
    data.states.forEach((s) => stateSel.append(option(s, s, s === F.state.default)));
    const localSel = $('#local');
    localSel.append(option('', STR.common.select, true));
    data.locals.forEach((l) => localSel.append(option(l.name, l.name)));
    const issueSel = $('#issueType');
    issueSel.append(option('', STR.common.select, true));
    STR.issueTypes.forEach((t) => issueSel.append(option(t, t)));
    $('#narrative-help').textContent = fmt(F.narrative.help, { max: LIM.narrativeMaxWords });
    $('#incidentDate').max = Dates.today();
    $('#dateOfBirth').max = Dates.today();
    resetWorksite();
    updateCounter();
    renderFiles();
  }

  /** Show or hide an "other" text field; hiding clears it. */
  function reveal(name, show) {
    $(`#${name}-wrap`).hidden = !show;
    const input = $(`#${name}`);
    input.required = show;
    if (!show) {
      input.value = '';
      showError(name, '');
      state.touched.delete(name);
    }
  }

  function resetWorksite() {
    const sel = $('#worksite');
    sel.textContent = '';
    const local = selectedLocal();
    if (!local) {
      sel.append(option('', F.worksite.chooseLocalFirst, true));
      sel.disabled = true;
    } else {
      sel.append(option('', STR.common.select, true));
      local.worksites.forEach((w) => sel.append(option(w, w)));
      sel.disabled = false;
    }
    reveal('worksiteOther', false);
    showError('worksite', '');
    state.touched.delete('worksite');
  }

  function onLocalChange() {
    reveal('localOther', val('local') === notListed());
    resetWorksite();   // changing the local always resets the worksite
  }

  function onWorksiteChange() {
    reveal('worksiteOther', $('#worksite').value === notListed());
  }

  function onIssueTypeChange() {
    reveal('issueTypeOther', val('issueType') === OTHER_ISSUE);
  }

  // ----------------------------------------------------------------- uploads
  const extOf = (name) => (name.includes('.') ? name.split('.').pop().toLowerCase() : '');
  const totalBytes = () => state.files.reduce((n, f) => n + f.size, 0);
  const sizeText = (bytes) => (bytes < MB
    ? fmt(U.sizeKb, { n: Math.max(1, Math.ceil(bytes / 1024)) })
    : fmt(U.sizeMb, { n: (bytes / MB).toFixed(1) }));

  /** Final check of the attached set (each file was already checked when added). */
  function filesProblem() {
    if (state.files.length > LIM.maxFiles) return fmt(U.limitCount, { max: LIM.maxFiles });
    if (totalBytes() > LIM.maxTotalMb * MB) return fmt(U.limitTotal, { max: LIM.maxTotalMb });
    return '';
  }

  function addFiles(list) {
    const messages = [];
    const rejected = [];
    for (const f of Array.from(list)) {
      const name = f.name;
      let problem = '';
      if (state.files.length >= LIM.maxFiles) problem = fmt(U.tooMany, { name, max: LIM.maxFiles });
      else if (!LIM.extensions.includes(extOf(name))) problem = fmt(U.badType, { name });
      else if (f.size === 0) problem = fmt(U.empty, { name });
      else if (f.size > LIM.maxFileMb * MB) problem = fmt(U.tooBig, { name, max: LIM.maxFileMb });
      else if (totalBytes() + f.size > LIM.maxTotalMb * MB) problem = fmt(U.totalTooBig, { name, max: LIM.maxTotalMb });
      else if (state.files.some((g) => g.name === f.name && g.size === f.size && g.lastModified === f.lastModified)) problem = fmt(U.duplicate, { name });
      if (problem) {
        rejected.push(problem);
      } else {
        state.files.push(f);
        messages.push(fmt(U.added, { name }));
      }
    }
    const err = $('#files-err');
    err.textContent = '';
    if (rejected.length) {
      err.append(el('span', { class: 'err__prefix', text: STR.common.errorPrefix }), ` ${rejected.join(' ')}`);
      err.hidden = false;
    } else {
      err.hidden = true;
    }
    $('#files').closest('.field').classList.toggle('is-invalid', rejected.length > 0);
    $('#files-status').textContent = messages.join(' ');
    $('#files').value = '';   // the input is only a picker; state.files is the list
    renderFiles();
  }

  function removeFile(index) {
    const [f] = state.files.splice(index, 1);
    $('#files-err').hidden = true;
    $('#files').closest('.field').classList.remove('is-invalid');
    $('#files-status').textContent = fmt(U.removed, { name: f.name });
    renderFiles();
    const buttons = $$('#files-list button');
    (buttons[Math.min(index, buttons.length - 1)] || $('#files')).focus();
    markDirty();
  }

  function renderFiles() {
    const list = $('#files-list');
    list.textContent = '';
    state.files.forEach((f, i) => {
      const btn = el('button', { type: 'button', class: 'link-button', 'aria-label': fmt(U.removeLabel, { name: f.name }), text: U.remove });
      btn.addEventListener('click', () => removeFile(i));
      list.append(el('li', { class: 'files__item' }, [
        el('span', { class: 'files__name', text: f.name }),
        el('span', { class: 'files__size', text: sizeText(f.size) }),
        btn,
      ]));
    });
    list.hidden = state.files.length === 0;
    $('#files-summary').textContent = fmt(U.summary, {
      count: state.files.length, max: LIM.maxFiles, total: (totalBytes() / MB).toFixed(1), maxTotal: LIM.maxTotalMb,
    });
  }

  // ----------------------------------------------------------------- views
  function showView(view, focusId) {
    state.view = view;
    $('#view-form').hidden = view !== 'form';
    $('#view-review').hidden = view !== 'review';
    if (view === 'review') renderReview();
    window.scrollTo(0, 0);
    const target = (focusId && document.getElementById(focusId)) || (view === 'review' ? $('#review-title') : $('#main'));
    target.focus({ preventScroll: Boolean(!focusId) });
    if (focusId) target.scrollIntoView({ block: 'center' });
  }

  function tryReview() {
    const errors = allErrors();
    errors.forEach((e) => {
      state.touched.add(e.name);
      showError(e.name, e.message);
    });
    renderSummary(errors);
    if (errors.length) {
      $('#form-summary').focus();
      return;
    }
    $('#submit-error').hidden = true;
    showView('review');
    window.history.pushState({ view: 'review' }, '');
  }

  // ---------------------------------------------------------------- review
  // A text answer is "long" (shown below its label at full width) when it is one of
  // the free-text fields, contains a line break, or is longer than
  // limits.reviewInlineMaxChars. Same rule as the PDF (PLAN §11).
  const isLongText = (text) => /[\r\n]/.test(text) || text.length > Number(STR.limits.reviewInlineMaxChars);

  function row(list, label, value, alwaysLong) {
    const dd = el('dd');
    let long = Boolean(alwaysLong);
    if (value instanceof Node || Array.isArray(value)) dd.append(...[].concat(value));
    else {
      const text = value === '' || value == null ? STR.review.notProvided : String(value);
      dd.textContent = text;
      long = long || isLongText(text);
    }
    list.append(el('div', { class: long ? 'review__row review__row--long' : 'review__row' }, [el('dt', { text: label }), dd]));
  }

  const withOther = (value, other) => (other ? `${value}: ${other}` : value);

  function renderReview() {
    const m = $('#review-member');
    m.textContent = '';
    row(m, `${F.firstName.label} / ${F.lastName.label}`, `${val('firstName')} ${val('lastName')}`);
    row(m, F.membershipNumber.label, val('membershipNumber'));
    row(m, F.address.label, [val('street'), el('br'), `${val('city')}, ${val('state')} ${val('zip')}`]);
    row(m, F.phone.label, formatPhone(phoneDigits()));
    row(m, F.email.label, val('email'));
    row(m, F.dateOfBirth.label, val('dateOfBirth') ? Dates.toUs(val('dateOfBirth')) : F.dateOfBirth.notProvided);

    const e = $('#review-employment');
    e.textContent = '';
    row(e, F.local.label, withOther(val('local'), val('local') === notListed() ? val('localOther') : ''));
    row(e, F.worksite.label, withOther($('#worksite').value, $('#worksite').value === notListed() ? val('worksiteOther') : ''));
    row(e, F.jobTitle.label, val('jobTitle'));
    row(e, F.timeInPosition.label, val('timeInPosition'));

    const i = $('#review-issue');
    i.textContent = '';
    const issueOther = val('issueType') === OTHER_ISSUE;
    row(i, F.issueType.label, withOther(val('issueType'), issueOther ? val('issueTypeOther') : ''), issueOther);
    row(i, F.incidentDate.label, Dates.toUs(val('incidentDate')));
    row(i, F.narrative.label, val('narrative'), true);
    row(i, F.requestedOutcome.label, val('requestedOutcome'), true);

    const d = $('#review-documents');
    d.textContent = '';
    if (!state.files.length) d.append(el('li', { text: STR.review.noFiles }));
    state.files.forEach((f) => d.append(el('li', {}, [el('span', { class: 'files__name', text: f.name }), ' ', el('span', { class: 'files__size', text: sizeText(f.size) })])));
  }

  // --------------------------------------------------------------- submit
  function buildPayload() {
    const ws = $('#worksite').value;
    return {
      formVersion: CFG.formVersion,
      dataVersion: state.data.version,
      firstName: val('firstName'),
      lastName: val('lastName'),
      membershipNumber: val('membershipNumber'),
      street: val('street'),
      city: val('city'),
      state: val('state'),
      zip: val('zip'),
      phone: phoneDigits(),
      email: val('email'),
      dateOfBirth: val('dateOfBirth'),
      local: val('local'),
      localOther: val('local') === notListed() ? val('localOther') : '',
      worksite: ws,
      worksiteOther: ws === notListed() ? val('worksiteOther') : '',
      jobTitle: val('jobTitle'),
      timeInPosition: val('timeInPosition'),
      issueType: val('issueType'),
      issueTypeOther: val('issueType') === OTHER_ISSUE ? val('issueTypeOther') : '',
      incidentDate: val('incidentDate'),
      narrative: val('narrative'),
      requestedOutcome: val('requestedOutcome'),
      website: $('#website').value,
    };
  }

  function setBusy(busy) {
    state.busy = busy;
    const btn = $('#submit-btn');
    btn.disabled = busy;
    btn.textContent = busy ? STR.buttons.submitting : STR.buttons.submit;
    $('#back-btn').disabled = busy;
    $$('[data-edit]').forEach((b) => { b.disabled = busy; });
  }

  function setProgress(percent) {
    const box = $('#upload-progress');
    if (percent === null) {
      box.hidden = true;
      return;
    }
    box.hidden = false;
    $('#upload-bar').value = percent;
    $('#upload-text').textContent = fmt(STR.progress.uploading, { percent });
  }

  /** Message box on the review page; `content` is text or a list of nodes/strings. */
  function showSubmitError(content) {
    const box = $('#submit-error');
    box.textContent = '';
    box.append(...[].concat(content));
    box.hidden = false;
    box.focus();
  }

  function sessionExpiredMessage() {
    // "Your sign-in has expired. [Sign in again] (opens a new tab), then come back here and click Submit."
    return [
      `${MSG.sessionExpired} `,
      el('a', { href: '/legal/', target: '_blank', rel: 'noopener', text: MSG.signInAgain }),
      ` ${MSG.sessionExpiredAfter}`,
    ];
  }

  /** Map a non-success response (docs/PAYLOAD.md) to a message; answers are always kept. */
  function submitErrorMessage(status, json) {
    if (status === 401) return sessionExpiredMessage();
    if (status === 404) return MSG.notEnabled;
    if (status === 413) return MSG.submitTooLarge;
    if (status === 429) return MSG.submitBusy;
    if (status === 503) return MSG.submitUnavailable;
    const code = json && typeof json.error === 'string' ? json.error : '';
    if (code === 'invalid_input') {
      const fields = Array.isArray(json.fields) ? json.fields.filter((f) => typeof f === 'string') : [];
      if (fields.includes('formVersion') || fields.includes('dataVersion')) return MSG.submitStale;
      const known = fields.filter((f) => RULES[f]);
      known.forEach((f) => {
        state.touched.add(f);
        showError(f, f === 'files' ? MSG.fileRejected : MSG.serverRejected);
      });
      return known.length ? fmt(MSG.submitInvalid, { fields: known.map(labelFor).join('; ') }) : MSG.submitFailed;
    }
    return MSG.submitFailed;
  }

  /** GET ping.php with redirect:'manual'. Resolves to 'ok' | 'expired' | 'disabled' | 'error' | 'network'. */
  async function ping() {
    try {
      const res = await fetch(CFG.pingUrl, {
        method: 'GET', redirect: 'manual', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
      });
      if (res.type === 'opaqueredirect' || res.status === 401) return { result: 'expired' };
      if (res.status === 404) return { result: 'disabled' };
      if (res.status === 200) {
        let json = null;
        try { json = await res.json(); } catch (_) { json = null; }
        return { result: 'ok', identity: json };
      }
      return { result: 'error', status: res.status };
    } catch (_) {
      return { result: 'network' };
    }
  }

  function showIdentity(identity) {
    if (!identity || typeof identity.email !== 'string' || identity.email === '') return;
    const p = $('#signed-in');
    p.textContent = fmt(STR.page.signedInAs, { email: identity.email });   // Access tokens carry no display name
    p.hidden = false;
  }

  function post() {
    return new Promise((resolve) => {
      const fd = new FormData();
      fd.append('payload', JSON.stringify(buildPayload()));
      state.files.forEach((f) => fd.append('files[]', f, f.name));
      const xhr = new XMLHttpRequest();
      xhr.open('POST', CFG.submitUrl);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) setProgress(Math.min(100, Math.round((e.loaded / e.total) * 100)));
      });
      xhr.addEventListener('load', () => {
        let json = null;
        try { json = JSON.parse(xhr.responseText); } catch (_) { json = null; }
        resolve({ status: xhr.status, json });
      });
      xhr.addEventListener('error', () => resolve({ status: 0, json: null }));
      xhr.addEventListener('abort', () => resolve({ status: 0, json: null }));
      xhr.send(fd);
    });
  }

  async function submit() {
    if (state.busy) return;
    $('#submit-error').hidden = true;
    const errors = allErrors();
    if (errors.length) {
      showView('form');
      tryReview();
      return;
    }
    setBusy(true);
    const p = await ping();
    if (p.result !== 'ok') {
      setBusy(false);
      if (p.result === 'expired') showSubmitError(sessionExpiredMessage());
      else if (p.result === 'disabled') showSubmitError(MSG.notEnabled);
      else if (p.result === 'network') showSubmitError(MSG.submitNetwork);
      else showSubmitError(submitErrorMessage(p.status, null));
      return;
    }
    showIdentity(p.identity);
    setProgress(0);
    const { status, json } = await post();
    setProgress(null);
    if (status === 200 && json && json.ok === true && typeof json.id === 'string') {
      succeed(json.id);
      return;
    }
    setBusy(false);
    showSubmitError(status === 0 ? MSG.submitNetwork : submitErrorMessage(status, json));
  }

  function wipe() {
    form.reset();
    state.files = [];
    state.touched.clear();
    state.narrativeOk = '';
    Object.keys(RULES).forEach((n) => showError(n, ''));
    renderSummary([]);
    ['localOther', 'issueTypeOther'].forEach((n) => reveal(n, false));
    $('#state').value = F.state.default;
    resetWorksite();
    $('#narrative-limit').hidden = true;
    $('#files-err').hidden = true;
    $('#files-status').textContent = '';
    updateCounter();
    renderFiles();
    $$('.review__list, .review__files').forEach((l) => { l.textContent = ''; });
    $('#submit-error').hidden = true;
    setProgress(null);
    state.dirty = false;
  }

  function succeed(id) {
    setBusy(false);
    wipe();
    form.hidden = true;
    $('#confirmation-ref').textContent = fmt(STR.confirmation.reference, { id });
    $('#confirmation').hidden = false;
    window.history.replaceState(null, '', window.location.pathname);
    window.scrollTo(0, 0);
    $('#confirmation-title').focus();
  }

  function markDirty() {
    state.dirty = true;
  }

  // ----------------------------------------------------------------- init
  function wire() {
    form.addEventListener('submit', (e) => e.preventDefault());
    form.addEventListener('input', (e) => {
      if (e.target.id === 'website' || e.target.id === 'files') return;
      markDirty();
      onFieldInput(e.target.id);
    });
    form.addEventListener('change', (e) => {
      const id = e.target.id;
      if (id === 'website') return;
      markDirty();
      if (id === 'files') {
        addFiles(e.target.files);
        return;
      }
      if (id === 'local') onLocalChange();
      if (id === 'worksite') onWorksiteChange();
      if (id === 'issueType') onIssueTypeChange();
      onFieldInput(id);
    });
    form.addEventListener('focusout', (e) => {
      if (e.target.id === 'files') return;
      if (RULES[e.target.id]) onFieldBlur(e.target.id);
    });
    $('#phone').addEventListener('input', onPhoneInput);
    $('#phone').addEventListener('blur', (e) => { e.target.value = formatPhone(e.target.value.replace(/\D/g, '').replace(/^1(?=\d{10}$)/, '')); });
    $('#zip').addEventListener('input', onZipInput);
    $('#membershipNumber').addEventListener('input', onMembershipInput);
    $('#narrative').addEventListener('input', onNarrativeInput);
    $('#review-btn').addEventListener('click', tryReview);
    $('#back-btn').addEventListener('click', () => window.history.back());
    $('#submit-btn').addEventListener('click', submit);
    $$('[data-edit]').forEach((b) => b.addEventListener('click', () => {
      state.pendingFocus = SECTION_FIRST[b.dataset.edit];   // set before back(): popstate is async
      window.history.back();   // leaves the review entry; popstate shows the form
    }));
    window.addEventListener('popstate', (e) => {
      if (state.busy) return;
      const view = e.state && e.state.view === 'review' ? 'review' : 'form';
      if (view === 'review' && allErrors().length) {
        showView('form');
        return;
      }
      showView(view, view === 'form' ? state.pendingFocus : undefined);
      state.pendingFocus = undefined;
    });
    $('#new-request').addEventListener('click', () => {
      $('#confirmation').hidden = true;
      form.hidden = false;
      showView('form', SECTION_FIRST.member);
    });
    window.addEventListener('beforeunload', (e) => {
      if (state.dirty && !state.busy) {
        e.preventDefault();
        e.returnValue = '';
      }
    });
  }

  async function init() {
    try {
      const res = await fetch(CFG.dataUrl, { credentials: 'same-origin' });
      if (!res.ok) throw new Error(`form-data ${res.status}`);
      state.data = await res.json();
      populate(state.data);
      wire();
      $('#loading').hidden = true;
      form.hidden = false;
      showView('form');
      window.history.replaceState({ view: 'form' }, '');
      // "Signed in as …": shown once ping.php answers 200 with the identity (Prompt 4).
      ping().then((p) => { if (p.result === 'ok') showIdentity(p.identity); });
    } catch (err) {
      $('#loading').hidden = true;
      $('#load-error').hidden = false;
      // eslint-disable-next-line no-console
      console.error(err);
    }
  }

  init();
})();
