(function() {
  const form = document.getElementById("subscribeForm");
  if (!form) return;

  const emailInput = form.querySelector("#subscribeEmail");
  const btn = form.querySelector("#subscribeBtn");
  const status = form.querySelector("#subscribeStatus");

  const emailOK = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((v || '').trim());

  emailInput.addEventListener('input', () => {
    form.classList.remove('is-err', 'is-ok');
    status.textContent = emailInput.value ? 'Ready to send' : 'Awaiting input';
    status.style.color = '';
  });

  form.addEventListener("submit", async function (e) {
    e.preventDefault();

    const email = emailInput.value.trim();
    if (!emailOK(email)) {
      form.classList.remove('is-ok');
      form.classList.add('is-err');
      status.textContent = 'Invalid address';
      status.style.color = '#ff8787';
      emailInput.focus();
      return;
    }

    // The verification check could not load (or is still loading): say so, do not send a request that is bound to be refused.
    if (window.KuronyxCaptcha && KuronyxCaptcha.blocked) {
      form.classList.remove('is-ok');
      form.classList.add('is-err');
      status.textContent = KuronyxCaptcha.blocked;
      status.style.color = '#ff8787';
      return;
    }

    form.classList.remove('is-err', 'is-ok');
    btn.disabled = true;
    status.textContent = "Transmitting…";
    status.style.color = '';

    try {
      // Call the backend PHP script to send the Brevo welcome email
      const payload = { email, 'bot-field': (form.querySelector('[name="bot-field"]') || {}).value || '' };
      if (window.KuronyxCaptcha && KuronyxCaptcha.enabled) {
        payload['cf-turnstile-response'] = KuronyxCaptcha.token(form);
        if (!payload['cf-turnstile-response']) throw new Error('Please complete the verification check');
      }
      // KuronyxPost.send never throws and never hands back a raw parser/network message (see js/post-json.js).
      const result = await KuronyxPost.send("/php/send-welcome.php", payload);
      // A Turnstile token works once, whatever the answer: get a fresh one so a retry (or "Subscribe another") is not refused.
      if (window.KuronyxCaptcha) KuronyxCaptcha.reset(form);

      if (result.ok) {
        form.classList.add('is-ok');
        btn.querySelector('.label').textContent = 'Subscribed';
        status.innerHTML = '✓ Subscribed - check your inbox. <a href="#" id="subscribeReset" style="color: var(--teal); text-decoration: underline; margin-left: 8px; cursor: pointer;">Subscribe another</a>';
        status.style.color = 'var(--teal)';
        emailInput.setAttribute('readonly', 'readonly');

        const resetBtn = status.querySelector('#subscribeReset');
        if (resetBtn) {
          resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            form.classList.remove('is-ok', 'is-err');
            emailInput.removeAttribute('readonly');
            emailInput.value = '';
            btn.disabled = false;
            btn.querySelector('.label').textContent = 'Subscribe';
            status.textContent = 'Awaiting input';
            status.style.color = '';
            emailInput.focus();
          });
        }
      } else {
        form.classList.add('is-err');
        status.textContent = "⚠ " + result.message;
        status.style.color = '#ff8787';
        console.error('Newsletter signup failed:', result.message);
        btn.disabled = false;
      }
    } catch (err) {
      form.classList.add('is-err');
      status.textContent = "⚠ " + (err && err.message && err.message.startsWith('Please') ? err.message : "Connection error. Try again.");
      status.style.color = '#ff8787';
      console.error('Submission connection exception:', err);
      btn.disabled = false;
    }
  });
})();
