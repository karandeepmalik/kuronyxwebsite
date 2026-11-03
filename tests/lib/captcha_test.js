// Runs public_html/js/captcha.js against a pretend page, a stub fetch and a stub Turnstile, and prints one JSON array of
// { name, ok, detail } for tests/cases/85d_captcha_unavailable.php to assert on. No browser, no network.
// Usage: node captcha_test.js
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public_html', 'js', 'captcha.js'), 'utf8');
const PROBLEM = "Verification couldn't load. Please email hello@kuronyx.in directly.";
const LOADING = 'Verification is still loading. Please try again in a moment.';

function el(tag) {
  return {
    tag, attrs: {}, children: [], style: {}, hidden: false, textContent: '', parentNode: null,
    setAttribute(k, v) { this.attrs[k] = String(v); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(c, ref) { c.parentNode = this; const i = ref ? this.children.indexOf(ref) : -1; if (i < 0) this.children.push(c); else this.children.splice(i, 0, c); return c; },
    querySelector(sel) { return sel === 'button[type="submit"]' ? this.btn || null : null; },
  };
}
function makeForm() { const f = el('form'); const btn = el('button'); f.btn = btn; f.appendChild(btn); return f; }

// A page with N forms, a fetch answering `config`, and controls for what the Turnstile script does.
function page({ config, forms = 2, fetchFails = false, turnstile = true }) {
  const formEls = Array.from({ length: forms }, makeForm);
  const timers = [];
  const rendered = [];
  const scripts = [];
  const head = { appendChild(s) { scripts.push(s); return s; } };
  const doc = { createElement: el, head, querySelectorAll: (sel) => (sel === 'form[data-captcha]' ? formEls : []) };
  const win = {};
  if (turnstile) {
    win.turnstile = {
      render(slot, opts) { rendered.push(opts); return rendered.length; },
      getResponse: (id) => 'token-' + id,
      reset() {},
    };
  }
  const sandbox = {
    window: win, document: doc,
    fetch: () => (fetchFails ? Promise.reject(new Error('offline')) : Promise.resolve({ json: () => Promise.resolve(config) })),
    setTimeout: (fn, ms) => { timers.push({ fn, ms, cleared: false }); return timers.length; },
    clearTimeout: (id) => { if (timers[id - 1]) timers[id - 1].cleared = true; },
  };
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox);
  return { win, formEls, timers, rendered, scripts, captcha: () => win.KuronyxCaptcha, flush: () => new Promise((r) => setTimeout(r, 5)) };
}
const noteOf = (form) => form.children.find((c) => c.attrs && c.attrs.role === 'alert');

const results = [];
async function check(name, fn) {
  let ok = false, detail = '';
  try { const r = await fn(); ok = r === true; detail = ok ? '' : 'returned ' + JSON.stringify(r); } catch (e) { detail = 'THREW: ' + (e && e.stack || e); }
  results.push({ name, ok, detail });
}

(async () => {
  await check('not configured (no site key): nothing happens, no script, forms are never blocked', async () => {
    const p = page({ config: { siteKey: null } }); await p.flush();
    return p.scripts.length === 0 && p.captcha().enabled === false && p.captcha().blocked === '' && p.formEls.every((f) => !noteOf(f));
  });

  await check('configured but the script has not arrived yet: not enabled, and blocked says it is still loading', async () => {
    const p = page({ config: { siteKey: 'k' } }); await p.flush();
    return p.scripts.length === 1 && p.captcha().enabled === false && p.captcha().blocked === LOADING;
  });

  await check('enabled is NOT set before Cloudflare\'s script loads (the original bug set it straight away)', async () => {
    const p = page({ config: { siteKey: 'k' } }); await p.flush();
    return p.captcha().enabled === false;
  });

  await check('script loads: enabled, not blocked, a widget drawn in every form, tokens come through', async () => {
    const p = page({ config: { siteKey: 'k' } }); await p.flush();
    p.scripts[0].onload();
    return p.captcha().enabled === true && p.captcha().blocked === '' && p.rendered.length === 2 && p.rendered[0].sitekey === 'k'
      && p.captcha().token(p.formEls[0]) === 'token-1' && p.timers[0].cleared === true;
  });

  await check('script error (blocked by an extension or a network): the way forward is shown under EVERY form and blocked says so', async () => {
    const p = page({ config: { siteKey: 'k' } }); await p.flush();
    p.scripts[0].onerror();
    return p.captcha().enabled === false && p.captcha().blocked === PROBLEM
      && p.formEls.every((f) => noteOf(f) && noteOf(f).textContent === PROBLEM && noteOf(f).hidden === false && f.children.indexOf(noteOf(f)) < f.children.indexOf(f.btn));
  });

  await check('the message names hello@kuronyx.in and no other address', async () => {
    return PROBLEM.indexOf('hello@kuronyx.in') !== -1 && PROBLEM.indexOf('ops@') === -1 && source.indexOf('ops@') === -1;
  });

  await check('a script that never answers: after the 10 second wait the same message appears', async () => {
    const p = page({ config: { siteKey: 'k' } }); await p.flush();
    const wait = p.timers.find((t) => t.ms === 10000);
    if (!wait) return 'no 10 second timer';
    wait.fn();
    return p.captcha().blocked === PROBLEM && p.formEls.every((f) => noteOf(f) && noteOf(f).textContent === PROBLEM);
  });

  await check('the wait does nothing once the script has loaded', async () => {
    const p = page({ config: { siteKey: 'k' } }); await p.flush();
    p.scripts[0].onload();
    p.timers.find((t) => t.ms === 10000).fn();
    return p.captcha().enabled === true && p.captcha().blocked === '';
  });

  await check('a script that "loads" but is not the real Turnstile (a blocker\'s stand-in) counts as a failure', async () => {
    const p = page({ config: { siteKey: 'k' }, turnstile: false }); await p.flush();
    p.scripts[0].onload();
    return p.captcha().enabled === false && p.captcha().blocked === PROBLEM;
  });

  await check('a very slow connection: the script arrives AFTER the wait ran out and everything recovers', async () => {
    const p = page({ config: { siteKey: 'k' } }); await p.flush();
    p.timers.find((t) => t.ms === 10000).fn();
    const hadProblem = p.captcha().blocked === PROBLEM;
    p.scripts[0].onload();
    return hadProblem && p.captcha().enabled === true && p.captcha().blocked === '' && p.formEls.every((f) => noteOf(f).hidden === true);
  });

  await check('a widget that reports an error shows the way forward, and the message goes when the widget later succeeds', async () => {
    const p = page({ config: { siteKey: 'k' } }); await p.flush();
    p.scripts[0].onload();
    p.rendered[0]['error-callback']();
    const shown = p.captcha().blocked === PROBLEM && p.captcha().enabled === false;
    p.rendered[0].callback();
    return shown && p.captcha().blocked === '' && p.captcha().enabled === true && p.formEls.every((f) => noteOf(f).hidden === true);
  });

  await check('the config request failing does not throw and blocks nothing', async () => {
    const p = page({ config: null, fetchFails: true }); await p.flush();
    return p.captcha().enabled === false && p.captcha().blocked === '' && p.scripts.length === 0;
  });

  console.log(JSON.stringify(results));
})();
