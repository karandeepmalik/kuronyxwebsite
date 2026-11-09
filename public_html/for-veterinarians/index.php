<?php
$pageTitle       = 'For Veterinarians | Kuronyx Veterinary Compounding Pharmacy India';
$pageDescription = 'Register with Kuronyx to prescribe compounded veterinary formulations, including for feline infectious peritonitis (FIP). Prescription-only, credential-verified, couriered nationwide across India.';
$canonical       = 'https://kuronyx.in/for-veterinarians/';
$activeNav       = 'for-veterinarians';
$extraHead = <<<HTML
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "MedicalWebPage",
  "name": "For veterinarians | Kuronyx",
  "url": "https://kuronyx.in/for-veterinarians/",
  "about": { "@type": "MedicalCondition", "name": "Feline Infectious Peritonitis (FIP)" },
  "audience": { "@type": "MedicalAudience", "audienceType": "Veterinarians" },
  "publisher": { "@type": "Pharmacy", "name": "Kuronyx Sciences" }
}
</script>
HTML;
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">For veterinarians</p>
    <h1 class="doc-title">Kuronyx works with veterinarians. Not around them.</h1>

    <p class="lead">
      Every formulation we prepare begins with your prescription. You identify what the animal needs. We compound it,
      prescription by prescription, to a standard you can put your name behind. Nothing leaves us without a valid
      veterinary prescription, and nothing is prepared from an input we have not tested first.
    </p>

    <section class="doc">
      <h2><span>How it works</span></h2>
      <p>
        You register with us once. We verify your credentials as a practising veterinarian in India. Once approved,
        you have access to prescribe, to see what we compound, and to send us your prescriptions directly.
      </p>
      <p>
        We prepare against that prescription. We verify. We courier, nationwide, to you or to the caregiver you direct
        us to.
      </p>
      <p>
        That is the whole arrangement. No sales calls. No representatives. No pressure. A pharmacy that does one thing,
        and does it the way the diagnosis deserves.
      </p>
    </section>

    <section class="doc" id="access">
      <h2><span>Register to prescribe</span></h2>
      <p class="page-actions">
        <a class="btn-primary" href="/for-veterinarians/apply/">Apply to register</a>
      </p>
      <p>Already registered? <a class="btn-secondary" href="/for-veterinarians/login.php">Log in</a></p>
    </section>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
