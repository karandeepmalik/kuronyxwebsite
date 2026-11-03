<?php
$pageTitle       = 'About Kuronyx | Veterinary-Only Compounding Pharmacy, India';
$pageDescription = 'Kuronyx Super Speciality Pharmacy is India\'s first veterinary-only compounding pharmacy, focused on complex feline conditions including FIP. Prescription-only, ISO 9001:2015 certified.';
$canonical       = 'https://kuronyx.in/about/';
$activeNav       = 'about';
// Same entity as the Pharmacy block on the homepage (same @id), so search engines connect the
// brand name ("Kuronyx Sciences") to the legal company ("Kuronyx Sciences Pvt. Ltd.") and its
// registration numbers. Keep the numbers in step with index.html's JSON-LD and the list below.
$extraHead = <<<HTML
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "@id": "https://kuronyx.in/#organization",
  "name": "Kuronyx Sciences",
  "legalName": "Kuronyx Sciences Pvt. Ltd.",
  "alternateName": "Kuronyx",
  "url": "https://kuronyx.in/",
  "logo": "https://kuronyx.in/assets/logoNoBG.png",
  "email": "ops@kuronyx.in",
  "telephone": "+917083515424",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Unit No. 2, 4th Floor, A Wing, Time Square Building, Marol Naka, Andheri East",
    "addressLocality": "Mumbai",
    "addressRegion": "Maharashtra",
    "postalCode": "400059",
    "addressCountry": "IN"
  },
  "identifier": [
    { "@type": "PropertyValue", "propertyID": "CIN",          "value": "U21000MH2025PTC456216" },
    { "@type": "PropertyValue", "propertyID": "DPIIT",        "value": "DIPP222076" },
    { "@type": "PropertyValue", "propertyID": "Udyam (MSME)", "value": "UDYAM-MH-19-0379978" },
    { "@type": "PropertyValue", "propertyID": "IEC",          "value": "AAMCK1631J" }
  ],
  "hasCredential": {
    "@type": "EducationalOccupationalCredential",
    "credentialCategory": "certification",
    "name": "ISO 9001:2015",
    "identifier": "3050250922125Q",
    "recognizedBy": { "@type": "Organization", "name": "Quality Research Organization (QRO)" }
  },
  "sameAs": [
    "https://www.linkedin.com/company/kuronyx-sciences/"
  ]
}
</script>
HTML;
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">About</p>
    <h1 class="doc-title">Kuronyx did not begin as a company. It began as a gap.</h1>

    <p class="lead">
      A disease with a known treatment. A molecule that existed everywhere except where it was needed. In India,
      cats died not because medicine had failed them, but because no one had built the thing that would carry it to
      them safely.
    </p>

    <p class="lead">
      Kuronyx Super Speciality Pharmacy is that thing. India's first veterinary-only compounding pharmacy.
      Prescription-only. Built around a single discipline: the right molecule, at the right concentration, for the
      animal in front of the veterinarian, prepared to a standard that does not bend.
    </p>

    <p class="lead">
      We compound against valid veterinary prescriptions. We test every input before it becomes a formulation. We hold
      ISO 9001:2015 certification, DPIIT recognition, and the full weight of the Drugs and Cosmetics Act behind how we
      operate. None of this is decoration. It is the difference between a pharmacy and a promise.
    </p>

    <p class="lead">
      We are not loud about this. We are simply here, doing the work the way it should have been done from the
      beginning.
    </p>

    <section class="doc">
      <h2><span>On record</span></h2>
      <p class="doc-kicker">Registrations and certifications</p>
      <div class="kv-grid">
        <div class="k">Quality</div><div class="v">ISO 9001:2015 certified (2025), by Quality Research Organization (QRO)<span class="reg-id">Certificate 3050250922125Q</span></div>
        <div class="k">Recognition</div><div class="v">DPIIT - Healthcare &amp; Life Sciences<span class="reg-id">DIPP222076</span></div>
        <div class="k">Registration</div><div class="v">MSME registered (Udyam)<span class="reg-id">UDYAM-MH-19-0379978</span></div>
        <div class="k">Trade</div><div class="v">IEC active (DGFT)<span class="reg-id">AAMCK1631J</span></div>
        <div class="k">Incorporation</div><div class="v">Kuronyx Sciences Pvt. Ltd. · Sep 2025 · Mumbai<span class="reg-id">CIN U21000MH2025PTC456216</span></div>
      </div>
    </section>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
