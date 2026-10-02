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
      const res = await fetch("/php/send-welcome.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const resData = await res.json();

      if (resData.success) {
        form.classList.add('is-ok');
        btn.querySelector('.label').textContent = 'Subscribed';
        status.innerHTML = '✓ Subscribed — check your inbox. <a href="#" id="subscribeReset" style="color: var(--teal); text-decoration: underline; margin-left: 8px; cursor: pointer;">Subscribe another</a>';
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
        status.textContent = "⚠ " + (resData.message || "Something went wrong.");
        status.style.color = '#ff8787';
        console.error('Brevo API Error:', resData);
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
