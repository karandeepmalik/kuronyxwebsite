// Optional Cloudflare Turnstile for the newsletter and enquiry forms. Asks
// /php/captcha-config.php whether it is switched on (no keys configured => nothing happens
// and every form works exactly as before). Forms opt in with data-captcha; submit handlers
// call KuronyxCaptcha.token(form) and send it as "cf-turnstile-response".
//
// If Cloudflare's script cannot load (an ad blocker or privacy extension, a clinic network, a flaky mobile connection) no
// widget ever appears, and the server refuses the form without a token - so the visitor used to meet "Please complete the
// verification check" for a check they could not even see, for good (a Karandeep review comment). Now `enabled` is only true
// once the script has really loaded and the widget is drawn; a script that errors, never arrives (10 seconds), or a widget
// that reports an error shows a plain way forward under the form (email us directly), and the submit handlers stop on
// `KuronyxCaptcha.blocked` instead of sending a request that is bound to be refused.
(function () {
  const WAIT_MS = 10000;
  const PROBLEM = "Verification couldn't load. Please email hello@kuronyx.in directly.";
  const LOADING = 'Verification is still loading. Please try again in a moment.';
  const widgets = new WeakMap();
  const notes = new WeakMap();
  const state = { configured: false, loaded: false, problem: false };

  function forms() { return document.querySelectorAll('form[data-captcha]'); }

  // The line of text under the form (above the button); created on first use so it works before any widget exists.
  function noteFor(form) {
    let note = notes.get(form);
    if (!note) {
      note = document.createElement('div');
      note.setAttribute('role', 'alert');
      note.style.margin = '1rem 0';
      note.style.color = '#ff8787';
      note.hidden = true;
      const btn = form.querySelector('button[type="submit"]');
      if (btn && btn.parentNode) btn.parentNode.insertBefore(note, btn); else form.appendChild(note);
      notes.set(form, note);
    }
    return note;
  }

  function showProblem() {
    state.problem = true;
    forms().forEach(f => { const n = noteFor(f); n.textContent = PROBLEM; n.hidden = false; });
  }
  function clearProblem() {
    state.problem = false;
    forms().forEach(f => { const n = noteFor(f); n.textContent = ''; n.hidden = true; });
  }

  function render(form, siteKey) {
    const slot = document.createElement('div');
    slot.style.margin = '1rem 0';
    const btn = form.querySelector('button[type="submit"]');
    if (btn && btn.parentNode) btn.parentNode.insertBefore(slot, btn); else form.appendChild(slot);
    widgets.set(form, window.turnstile.render(slot, {
      sitekey: siteKey,
      theme: 'dark',
      // A widget that errors may recover by itself (Turnstile retries): show the way forward meanwhile, take it away on success.
      'error-callback': () => { showProblem(); },
      callback: () => { if (state.problem) clearProblem(); },
    }));
  }

  fetch('/php/captcha-config.php')
    .then(r => r.json())
    .then(cfg => {
      if (!cfg.siteKey) return;
      state.configured = true;
      let timer = null;
      const fail = () => { if (timer) clearTimeout(timer); showProblem(); };
      const s = document.createElement('script');
      s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      s.async = true;
      s.onload = () => {
        if (timer) clearTimeout(timer);
        if (!window.turnstile) { showProblem(); return; } // loaded, but not the real thing (a blocker's stand-in)
        state.loaded = true;
        if (state.problem) clearProblem(); // a slow connection: the script arrived after the wait ran out
        forms().forEach(f => render(f, cfg.siteKey));
      };
      s.onerror = fail;
      document.head.appendChild(s);
      // A blocker can also swallow the request without ever answering: do not wait for ever.
      timer = setTimeout(() => { if (!state.loaded) showProblem(); }, WAIT_MS);
    })
    .catch(() => {});

  window.KuronyxCaptcha = {
    // True only once the widget is really on the page and working.
    get enabled() { return state.loaded && !state.problem; },
    // A message when the form should not be sent yet ('' when it is fine to go on): the check could not load, or it is
    // still loading. Submit handlers show it and stop.
    get blocked() {
      if (state.problem) return PROBLEM;
      if (state.configured && !state.loaded) return LOADING;
      return '';
    },
    token(form) {
      const id = widgets.get(form);
      return id !== undefined && window.turnstile ? (window.turnstile.getResponse(id) || '') : '';
    },
    reset(form) {
      const id = widgets.get(form);
      if (id !== undefined && window.turnstile) window.turnstile.reset(id);
    },
  };
})();
