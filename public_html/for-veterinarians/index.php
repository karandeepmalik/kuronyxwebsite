<?php
$pageTitle       = 'For Veterinarians — GS-441524 Prescription Requests | Kuronyx Sciences';
$pageDescription = 'Veterinarians and clinics: submit GS-441524 requests for FIP patients through a verified Kuronyx account. Sign in or apply for access.';
$canonical       = 'https://kuronyx.in/for-veterinarians';
$activeNav       = 'for-veterinarians';
$extraHead = <<<HTML
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "MedicalWebPage",
  "name": "GS-441524 — For Veterinarians",
  "url": "https://kuronyx.in/for-veterinarians",
  "about": { "@type": "MedicalCondition", "name": "Feline Infectious Peritonitis (FIP)" },
  "audience": { "@type": "MedicalAudience", "audienceType": "Veterinarians" },
  "publisher": { "@type": "Pharmacy", "name": "Kuronyx Sciences" }
}
</script>
HTML;
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">For veterinarians · GS-441524</p>
    <h1 class="doc-title">GS-441524, compounded to your prescription.</h1>
    <p class="doc-meta">Kuronyx Sciences<span class="sep">·</span>Prescription-only, verified accounts</p>

    <p class="lead">
      Kuronyx Speciality Pharmacy is a prescription-only dispensary — we do not sell to the public. GS-441524 is an
      antiviral compound used in veterinary medicine, most often in connection with the treatment of feline
      infectious peritonitis (FIP), under your diagnosis and supervision. We compound to your prescription, on a
      patient-by-patient basis, and courier-dispatch nationwide.
    </p>

    <div class="notice">
      <span class="notice-label">Prescription required</span>
      <p>
        GS-441524 requests are reviewed on a patient-specific basis. Kuronyx does not diagnose animals or replace
        the treating veterinarian at any point — we compound to prescription, we do not prescribe. Strength,
        concentration and dosing remain your clinical decisions.
      </p>
    </div>

    <section class="doc">
      <h2><span class="n">01</span><span>Available formulations</span></h2>
      <p>
        We currently compound <strong>injection</strong> and <strong>oral tablet/pill</strong> forms. The requested
        category is a starting point for review — final formulation, strength and quantity remain subject to
        prescription review, and we may contact you directly to confirm details before proceeding. We do not
        publish fixed prices, as this depends on the specific prescription and quantity.
      </p>
    </section>

    <section class="doc" id="access">
      <h2><span class="n">02</span><span>Veterinary account access</span></h2>
      <p>
        GS-441524 requests submitted by a clinic are tied to a verified veterinary account, so Kuronyx can confirm
        the treating veterinarian before a request is reviewed. If your clinic doesn't have an account yet, apply
        below — applications are reviewed manually before access is granted.
      </p>
      <div class="choice-grid">
        <a class="choice-card" href="/for-veterinarians/login.php">
          <span class="cc-label">Existing veterinary account</span>
          <h3>Sign In</h3>
          <p>Sign in to submit and track GS-441524 requests directly from your verified account.</p>
        </a>
        <a class="choice-card" href="/for-veterinarians/apply">
          <span class="cc-label">New veterinarian / clinic</span>
          <h3>Apply for a Veterinary Account</h3>
          <p>Submit your professional and clinic details for review. This is an application, not instant approval.</p>
        </a>
      </div>
    </section>

    <p style="margin-top:2rem; font-size:0.8125rem; color:var(--paper-3);">
      General question instead? <a href="/contact-us" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Contact us</a> or write to
      <a href="mailto:hello@kuronyx.in" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">hello@kuronyx.in</a>.
    </p>

    <p style="margin-top:1.5rem; font-size:0.75rem; color:var(--paper-4);">
      Sources: <a href="https://www.fda.gov/animal-veterinary/cvm-updates/fda-announces-position-use-compounded-gs-441524-treat-fip" target="_blank" rel="noopener noreferrer" style="color:var(--paper-4); border-bottom-color:var(--rule);">FDA — position on compounded GS-441524</a>,
      <a href="https://www.fda.gov/animal-veterinary/animal-drug-compounding/qa-gfi-256-compounding-animal-drugs-bulk-drug-substances" target="_blank" rel="noopener noreferrer" style="color:var(--paper-4); border-bottom-color:var(--rule);">FDA GFI #256</a>.
    </p>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
