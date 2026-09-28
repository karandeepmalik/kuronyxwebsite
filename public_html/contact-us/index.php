<?php
$pageTitle       = 'Contact Us — Kuronyx Sciences';
$pageDescription = 'Write to Kuronyx Sciences with a patient query or a prescription enquiry — for veterinarians and clinics.';
$canonical       = 'https://kuronyx.in/contact-us';
$activeNav       = 'contact-us';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Contact</p>
    <h1 class="doc-title">Write to us.</h1>
    <p class="doc-meta">Kuronyx Sciences<span class="sep">·</span>Reply within one working day</p>

    <p class="lead">For veterinarians with a patient query or a prescription enquiry.</p>

    <form class="contact-form" id="enquiryForm"
          name="enquiry"
          method="POST"
          data-netlify="true"
          netlify-honeypot="bot-field"
          novalidate>
      <input type="hidden" name="form-name" value="enquiry" />
      <p class="field-hint" hidden><label>Don't fill this out: <input name="bot-field" /></label></p>

      <div class="field-row two">
        <label class="field">
          <span class="lbl">Name</span>
          <input type="text" name="name" id="enq-name" required autocomplete="name" />
        </label>
        <label class="field">
          <span class="lbl">Clinic / Hospital</span>
          <input type="text" name="clinic" id="enq-clinic" required autocomplete="organization" />
        </label>
      </div>

      <div class="field-row two">
        <label class="field">
          <span class="lbl">City</span>
          <input type="text" name="city" id="enq-city" required autocomplete="address-level2" />
        </label>
        <label class="field">
          <span class="lbl">Email</span>
          <input type="email" name="email" id="enq-email" required autocomplete="email" placeholder="your.name@clinic.in" spellcheck="false" />
        </label>
      </div>

      <label class="field">
        <span class="lbl">Phone number</span>
        <input type="tel" name="phone" id="enq-phone" required autocomplete="tel" inputmode="tel" />
      </label>

      <label class="field">
        <span class="lbl">Message</span>
        <textarea name="message" id="enq-message" rows="4" required></textarea>
      </label>

      <button type="submit" class="btn-primary" id="enquiryBtn">Write to us</button>
      <p class="form-status" id="enquiryStatus">Awaiting input</p>
    </form>

    <p style="margin-top:2.5rem; font-size:0.8125rem; color:var(--paper-3);">
      Or write directly to <a href="mailto:hello@kuronyx.in" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">hello@kuronyx.in</a>.
    </p>

<script>
(function () {
  const enqForm   = document.getElementById('enquiryForm');
  const enqBtn    = document.getElementById('enquiryBtn');
  const enqStatus = document.getElementById('enquiryStatus');
  if (!enqForm) return;

  const allFields = enqForm.querySelectorAll('input, textarea');
  allFields.forEach(f => {
    if (f.name === 'bot-field' || f.name === 'form-name') return;
    f.addEventListener('input', () => {
      enqForm.classList.remove('is-err', 'is-ok');
      f.closest('.field')?.classList.remove('has-error');
      enqStatus.textContent = 'Ready to send';
      enqStatus.classList.remove('is-err', 'is-ok');
    });
  });

  enqForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    enqForm.querySelectorAll('.field').forEach(f => f.classList.remove('has-error'));
    enqStatus.classList.remove('is-err', 'is-ok');

    let firstInvalid = null;
    ['name', 'clinic', 'city', 'email', 'phone', 'message'].forEach(n => {
      const f = enqForm.querySelector('[name="' + n + '"]');
      if (!f) return;
      const v = (f.value || '').trim();
      let bad = !v;
      if (!bad && n === 'email') bad = !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
      if (bad) {
        f.closest('.field')?.classList.add('has-error');
        if (!firstInvalid) firstInvalid = f;
      }
    });
    if (firstInvalid) {
      enqStatus.textContent = 'Please complete required fields';
      enqStatus.classList.add('is-err');
      firstInvalid.focus();
      return;
    }

    enqBtn.disabled = true;
    enqStatus.textContent = 'Transmitting…';
    try {
      const formData = new FormData(enqForm);
      const payload = {};
      formData.forEach((v, k) => { if (k !== 'bot-field' && k !== 'form-name') payload[k] = v; });

      try {
        const body = new URLSearchParams();
        formData.forEach((v, k) => body.append(k, v));
        fetch('/', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
      } catch (e) {}

      const res = await fetch('/php/send-enquiry.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const resData = await res.json();
      if (!res.ok || !resData.success) throw new Error(resData.message || 'Transmission failed');

      enqStatus.textContent = 'Received · we will reply within one working day';
      enqStatus.classList.add('is-ok');
      enqBtn.textContent = 'Sent';
      allFields.forEach(f => f.setAttribute('readonly', 'readonly'));
    } catch (err) {
      enqStatus.textContent = err.message || 'Connection error. Try again.';
      enqStatus.classList.add('is-err');
      enqBtn.disabled = false;
    }
  });
})();
</script>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
