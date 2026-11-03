// Runs public_html/js/admin-email-compose.js against a tiny pretend page (no browser) and prints one JSON array of
// { name, ok, detail } for tests/cases/70d_email_compose.php to assert on.
// Usage: node email_compose_test.js
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public_html', 'js', 'admin-email-compose.js'), 'utf8');

// Just enough DOM: elements with attributes, children, handlers and a few helpers.
function el(tag) {
  const e = {
    tag, attrs: {}, children: [], handlers: {}, className: '', hidden: false, style: {}, textContent: '', value: '', parentNode: null,
    setAttribute(k, v) { this.attrs[k] = String(v); },
    getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(c, ref) { c.parentNode = this; const i = ref ? this.children.indexOf(ref) : -1; if (i < 0) this.children.push(c); else this.children.splice(i, 0, c); return c; },
    get nextSibling() { const i = this.parentNode.children.indexOf(this); return this.parentNode.children[i + 1] || null; },
    closest(sel) { return sel === '.field-row' ? this.rowEl || null : null; },
    addEventListener(type, fn) { (this.handlers[type] = this.handlers[type] || []).push(fn); },
    fire(type) { (this.handlers[type] || []).forEach((fn) => fn()); },
    click() { if (this.onclick) this.onclick(); },
  };
  return e;
}

function build({ templates, selected, subject, body }) {
  const form = el('form');
  const row = el('div');
  form.appendChild(row);
  const select = el('select');
  select.attrs['data-templates'] = JSON.stringify(templates);
  select.value = selected;
  select.rowEl = row;
  select.parentNode = row;
  const subjectInput = el('input'); subjectInput.value = subject;
  const bodyInput = el('textarea'); bodyInput.value = body;
  form.appendChild(subjectInput);
  form.appendChild(bodyInput);
  const byId = { 'email-template': select, 'email-subject': subjectInput, 'email-body': bodyInput };
  const doc = { getElementById: (id) => byId[id] || null, createElement: el };
  const sandbox = { window: {}, globalThis: {}, document: undefined };
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox);
  const api = sandbox.window.KuronyxEmailCompose || sandbox.globalThis.KuronyxEmailCompose;
  const inst = api.init(doc);
  const bar = inst.bar;
  const buttons = () => bar.children.filter((c) => c.tag === 'button' && !c.hidden);
  const buttonLabeled = (label) => bar.children.find((c) => c.tag === 'button' && c.textContent === label && !c.hidden);
  const pick = (key) => { select.value = key; select.fire('change'); };
  const type = (box, text) => { box.value = text; box.fire('input'); };
  return { select, subjectInput, bodyInput, bar, inst, pick, type, buttons, buttonLabeled, form };
}

const T = {
  blank: { label: '- Blank / custom -', subject: 'Update on your request (GS-7)', body: '' },
  ready: { label: 'Ready for Dispatch', subject: 'Your order is ready - GS-7', body: 'Your order is ready.\n\nRef GS-7' },
  info: { label: 'Awaiting Information', subject: 'Information needed - GS-7', body: 'We need more information.\n\nRef GS-7' },
};

const results = [];
function check(name, fn) {
  let ok = false, detail = '';
  try { const r = fn(); ok = r === true; detail = ok ? '' : 'returned ' + JSON.stringify(r); } catch (e) { detail = 'THREW: ' + (e && e.stack || e); }
  results.push({ name, ok, detail });
}
const fresh = (over) => build(Object.assign({ templates: T, selected: 'blank', subject: T.blank.subject, body: T.blank.body }, over || {}));

check('untouched boxes: picking a template swaps at once, no bar (as before)', () => {
  const p = fresh();
  p.pick('ready');
  return p.bodyInput.value === T.ready.body && p.subjectInput.value === T.ready.subject && p.select.value === 'ready' && p.bar.hidden === true;
});

check('untouched boxes: switching between templates keeps swapping freely', () => {
  const p = fresh();
  p.pick('ready'); p.pick('info');
  return p.bodyInput.value === T.info.body && p.bar.hidden === true;
});

check('edited body: picking a template does NOT replace it, the dropdown goes back, and the bar asks', () => {
  const p = fresh();
  p.type(p.bodyInput, 'My careful custom message.');
  p.pick('ready');
  return p.bodyInput.value === 'My careful custom message.' && p.select.value === 'blank' && p.bar.hidden === false
    && p.bar.children[0].textContent.indexOf('Replace it with the "Ready for Dispatch" template') !== -1 && !!p.buttonLabeled('Replace') && !!p.buttonLabeled('Keep mine');
});

check('edited subject alone counts as edited', () => {
  const p = fresh();
  p.type(p.subjectInput, 'My own subject');
  p.pick('ready');
  return p.subjectInput.value === 'My own subject' && p.bar.hidden === false;
});

check('picking "Blank" over an edited message asks first too (it used to clear everything silently)', () => {
  const p = fresh({ selected: 'ready', subject: T.ready.subject, body: T.ready.body });
  p.type(p.bodyInput, T.ready.body + '\n\nAnd a personal line.');
  p.pick('blank');
  return p.bodyInput.value.indexOf('And a personal line.') !== -1 && p.select.value === 'ready' && p.bar.hidden === false;
});

check('Keep mine: the bar closes and the text and dropdown stay as they were', () => {
  const p = fresh();
  p.type(p.bodyInput, 'Mine.');
  p.pick('info');
  p.buttonLabeled('Keep mine').click();
  return p.bar.hidden === true && p.bodyInput.value === 'Mine.' && p.select.value === 'blank';
});

check('Replace: the template is applied and the dropdown follows', () => {
  const p = fresh();
  p.type(p.bodyInput, 'Mine.');
  p.pick('info');
  p.buttonLabeled('Replace').click();
  return p.bodyInput.value === T.info.body && p.subjectInput.value === T.info.subject && p.select.value === 'info';
});

check('after Replace an Undo button brings the previous message, subject and template back', () => {
  const p = fresh();
  p.type(p.subjectInput, 'Custom subject');
  p.type(p.bodyInput, 'Custom body.');
  p.pick('info');
  p.buttonLabeled('Replace').click();
  const undo = p.buttonLabeled('Undo');
  if (!undo || p.bar.children[0].textContent !== 'Template applied.') return 'no Undo offered';
  undo.click();
  return p.subjectInput.value === 'Custom subject' && p.bodyInput.value === 'Custom body.' && p.select.value === 'blank' && p.bar.hidden === true;
});

check('after Undo the restored text still counts as edited (the next template pick asks again)', () => {
  const p = fresh();
  p.type(p.bodyInput, 'Custom body.');
  p.pick('info'); p.buttonLabeled('Replace').click(); p.buttonLabeled('Undo').click();
  p.pick('ready');
  return p.bodyInput.value === 'Custom body.' && p.bar.hidden === false;
});

check('typing after a Replace ends the Undo offer, so Undo can never overwrite what was typed since', () => {
  const p = fresh();
  p.type(p.bodyInput, 'Custom body.');
  p.pick('info'); p.buttonLabeled('Replace').click();
  p.type(p.bodyInput, T.info.body + ' plus a new sentence');
  return p.bar.hidden === true; // the whole bar is hidden, so Undo cannot be clicked
});

check('a page that comes back with a kept draft (a failed send) is protected from the first pick', () => {
  const p = fresh({ selected: 'blank', subject: 'Draft subject', body: 'Draft that did not go out.' });
  p.pick('ready');
  return p.bodyInput.value === 'Draft that did not go out.' && p.select.value === 'blank' && p.bar.hidden === false;
});

check('a page that opens on a non-blank template (the approved vet email) swaps freely until it is edited', () => {
  const t = Object.assign({}, T, { approved: { label: 'Approved', subject: 'Approved - VA-3', body: 'Welcome.\nLink: https://example.test/x' } });
  const p = build({ templates: t, selected: 'approved', subject: t.approved.subject, body: t.approved.body });
  p.pick('blank');
  const swapped = p.bodyInput.value === '' && p.bar.hidden === true;
  p.type(p.bodyInput, 'typed');
  p.pick('approved');
  return swapped && p.bodyInput.value === 'typed' && p.bar.hidden === false;
});

check('line endings do not count as edits (a textarea turns \\n into \\r\\n)', () => {
  const p = fresh({ selected: 'ready', subject: T.ready.subject, body: T.ready.body.replace(/\n/g, '\r\n') });
  p.pick('info');
  return p.bodyInput.value === T.info.body && p.bar.hidden === true;
});

check('the bar buttons are type=button (they sit inside the send form and must never submit it) and the bar announces itself', () => {
  const p = fresh();
  const bs = p.bar.children.filter((c) => c.tag === 'button');
  return bs.length === 2 && bs.every((b) => b.attrs.type === 'button') && p.bar.attrs.role === 'status' && p.bar.attrs['aria-live'] === 'polite';
});

check('the bar sits right under the template row', () => {
  const p = fresh();
  const parent = p.bar.parentNode;
  return parent === p.form && parent.children.indexOf(p.bar) === 1;
});

console.log(JSON.stringify(results));
