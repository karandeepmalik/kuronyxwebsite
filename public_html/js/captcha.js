// Optional Cloudflare Turnstile for the newsletter and enquiry forms. Asks
// /php/captcha-config.php whether it is switched on (no keys configured => nothing happens
// and every form works exactly as before). Forms opt in with data-captcha; submit handlers
// call KuronyxCaptcha.token(form) and send it as "cf-turnstile-response".
(function () {
  const widgets = new WeakMap();
  const state = { enabled: false };

  function render(form, siteKey) {
    const slot = document.createElement('div');
    slot.style.margin = '1rem 0';
    const btn = form.querySelector('button[type="submit"]');
    if (btn && btn.parentNode) btn.parentNode.insertBefore(slot, btn); else form.appendChild(slot);
    widgets.set(form, window.turnstile.render(slot, { sitekey: siteKey, theme: 'dark' }));
  }

  fetch('/php/captcha-config.php')
    .then(r => r.json())
    .then(cfg => {
      if (!cfg.siteKey) return;
      state.enabled = true;
      const s = document.createElement('script');
      s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      s.async = true;
      s.onload = () => document.querySelectorAll('form[data-captcha]').forEach(f => render(f, cfg.siteKey));
      document.head.appendChild(s);
    })
    .catch(() => {});

  window.KuronyxCaptcha = {
    get enabled() { return state.enabled; },
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
