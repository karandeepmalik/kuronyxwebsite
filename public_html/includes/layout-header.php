<?php
/**
 * Shared Kuronyx chrome for GS-441524 / admin pages.
 * Set before including: $pageTitle, $pageDescription, $canonical, $robotsNoindex (bool),
 * $extraHead (raw string, e.g. OG tags), $wideWrap (bool), $backHref, $backLabel.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

$pageTitle       = $pageTitle ?? 'Kuronyx Sciences';
$pageDescription = $pageDescription ?? 'Kuronyx Sciences — Indian veterinary compounding pharmacy.';
$canonical       = $canonical ?? '';
$robotsNoindex   = $robotsNoindex ?? false;
$backHref        = $backHref ?? '/';
$backLabel       = $backLabel ?? 'Back to site';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></title>
<meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES) ?>">
<?php if ($canonical): ?><link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES) ?>"><?php endif; ?>
<?php if ($robotsNoindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,200;8..60,300;8..60,400&family=JetBrains+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<?= $extraHead ?? '' ?>
<style>
  :root{
    --navy:#22384A; --mid:#38454E; --teal:#58959D;
    --paper:#F5F5F5; --paper-2:#F5F5F5cc; --paper-3:#F5F5F580; --paper-4:#F5F5F540;
    --rule:#F5F5F520; --rule-2:#F5F5F510;
    --serif:"Source Serif 4",Georgia,serif;
    --mono:"JetBrains Mono",ui-monospace,"SFMono-Regular",monospace;
    --gutter:clamp(1.25rem,4vw,3rem);
    --link:#8CC3CB;
  }
  *,*::before,*::after{ margin:0; padding:0; box-sizing:border-box; }
  html,body{ background:var(--navy); color:var(--paper); }
  body{ min-height:100vh; min-height:100dvh; display:flex; flex-direction:column; font-family:var(--serif); font-weight:300; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; line-height:1.55; }
  ::selection{ background:var(--teal); color:#0c161e; }
  body::before{
    content:""; position:fixed; inset:0; pointer-events:none; z-index:1;
    background-image:linear-gradient(to right, var(--rule-2) 1px, transparent 1px);
    background-size:8.333% 100%; opacity:.55;
  }
  header.chrome{
    position:sticky; top:0;
    background:linear-gradient(to bottom, rgba(34,56,74,0.96), rgba(34,56,74,0.7));
    -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);
    padding:1.1rem var(--gutter);
    display:grid; grid-template-columns:1fr auto;
    align-items:center; z-index:10;
    border-bottom:1px solid var(--rule);
  }
  .wordmark{ font-family:var(--mono); font-weight:400; font-size:0.875rem; letter-spacing:0.02em; color:var(--paper); text-decoration:none; text-transform:lowercase; display:inline-flex; align-items:center; gap:.5rem; }
  .wordmark img{ height:16px; width:auto; display:block; }
  .back{ font-family:var(--mono); font-weight:400; font-size:0.625rem; letter-spacing:0.18em; text-transform:uppercase; color:var(--paper-2); text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem; padding:0.5rem 0.85rem; border:1px solid var(--paper-4); background:rgba(245,245,245,0.04); transition:color .2s ease, border-color .2s ease, background .2s ease; }
  .back:hover{ color:var(--paper); border-color:var(--teal); background:rgba(88,149,157,0.18); }
  .back .arr{ color:var(--teal); }
  .site-nav{ display:flex; align-items:center; gap:1.1rem; flex-wrap:wrap; justify-content:flex-end; font-family:var(--mono); font-weight:400; font-size:0.625rem; letter-spacing:0.12em; text-transform:uppercase; }
  .site-nav a{ color:var(--paper-3); text-decoration:none; border-bottom:1px solid transparent; padding-bottom:2px; white-space:nowrap; transition:color .2s ease, border-color .2s ease; }
  .site-nav a:hover, .site-nav a.active{ color:var(--paper); border-bottom-color:var(--teal); }
  @media (max-width:720px){ .site-nav{ gap:0.75rem; font-size:0.5625rem; } }
  main{ flex:1 0 auto; width:100%; position:relative; z-index:2; padding:clamp(2.5rem,6vh,5rem) var(--gutter) clamp(3rem,8vh,6rem); }
  .wrap{ max-width:44rem; margin:0 auto; }
  .wrap.wide{ max-width:68rem; }
  .doc-eyebrow{ font-family:var(--mono); font-weight:400; font-size:0.625rem; letter-spacing:0.22em; text-transform:uppercase; color:var(--paper-3); margin-bottom:1.25rem; display:flex; align-items:center; gap:.6rem; }
  .doc-eyebrow::before{ content:""; display:inline-block; width:14px; height:1px; background:var(--teal); }
  h1.doc-title{ font-family:var(--serif); font-weight:200; font-size:clamp(2rem,4vw + 0.5rem,3.25rem); line-height:1.08; letter-spacing:-0.022em; margin-bottom:0.6rem; }
  .doc-meta{ font-family:var(--mono); font-weight:400; font-size:0.625rem; letter-spacing:0.22em; text-transform:uppercase; color:var(--paper-4); padding-bottom:1.6rem; border-bottom:1px solid var(--rule); margin-bottom:2.25rem; }
  .doc-meta .sep{ color:var(--paper-4); margin:0 0.5rem; }
  .lead{ font-family:var(--serif); font-weight:300; font-size:1rem; color:var(--paper-2); margin-bottom:1.05em; text-wrap:pretty; }
  section.doc{ margin-top:2.5rem; }
  section.doc h2{ font-family:var(--mono); font-weight:500; font-size:0.6875rem; letter-spacing:0.22em; text-transform:uppercase; color:var(--paper); margin-bottom:1rem; display:grid; grid-template-columns:2.75rem 1fr; column-gap:1rem; align-items:baseline; padding-bottom:0.55rem; border-bottom:1px solid var(--rule); }
  section.doc h2 .n{ color:var(--teal); font-weight:400; letter-spacing:0.22em; }
  section.doc p{ font-size:0.875rem; line-height:1.65; color:var(--paper-2); margin-bottom:0.85em; text-wrap:pretty; }
  section.doc ul{ list-style:none; margin:0.4em 0 1.1em 0; padding:0; }
  section.doc ul li{ position:relative; padding-left:1.4rem; margin-bottom:0.55em; font-size:0.875rem; line-height:1.6; color:var(--paper-2); text-wrap:pretty; }
  section.doc ul li::before{ content:"—"; position:absolute; left:0; top:0; color:var(--teal); font-family:var(--mono); }
  section.doc strong{ color:var(--paper); font-weight:400; font-style:italic; }
  section.doc a{ color:var(--paper); text-decoration:none; border-bottom:1px solid var(--paper-3); transition:border-color .2s ease; }
  section.doc a:hover{ border-bottom-color:var(--teal); }
  .notice{ border:1px solid var(--rule); border-left:2px solid var(--teal); padding:1rem 1.25rem; margin:1.5rem 0; background:var(--rule-2); }
  .notice p{ margin-bottom:0; }
  .notice .notice-label{ font-family:var(--mono); font-size:0.625rem; letter-spacing:0.18em; text-transform:uppercase; color:var(--teal); display:block; margin-bottom:0.5rem; }
  .choice-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-top:1.5rem; }
  @media (max-width:640px){ .choice-grid{ grid-template-columns:1fr; } }
  .choice-card{ display:block; border:1px solid var(--rule); padding:1.75rem; text-decoration:none; transition:border-color .25s ease, background .25s ease; }
  .choice-card:hover{ border-color:var(--teal); background:var(--rule-2); }
  .choice-card .cc-label{ font-family:var(--mono); font-size:0.625rem; letter-spacing:0.2em; text-transform:uppercase; color:var(--paper-3); display:block; margin-bottom:0.75rem; }
  .choice-card h3{ font-family:var(--serif); font-weight:300; font-size:1.35rem; color:var(--paper); margin-bottom:0.5rem; }
  .choice-card p{ font-size:0.8125rem; color:var(--paper-3); line-height:1.6; margin:0; }
  .btn-primary{ display:inline-flex; align-items:center; gap:0.6rem; font-family:var(--mono); font-weight:500; font-size:0.75rem; letter-spacing:0.1em; text-transform:uppercase; color:var(--navy); background:var(--teal); padding:0.9rem 1.5rem; text-decoration:none; border:none; cursor:pointer; transition:opacity .2s ease; }
  .btn-primary:hover{ opacity:0.88; }
  .btn-primary:disabled{ opacity:0.5; cursor:not-allowed; }
  .btn-secondary{ display:inline-flex; align-items:center; gap:0.6rem; font-family:var(--mono); font-weight:400; font-size:0.75rem; letter-spacing:0.1em; text-transform:uppercase; color:var(--paper); background:rgba(245,245,245,0.04); padding:0.85rem 1.4rem; text-decoration:none; border:1px solid var(--paper-4); cursor:pointer; transition:border-color .2s ease, background .2s ease; }
  .btn-secondary:hover{ border-color:var(--teal); background:rgba(88,149,157,0.18); }
  .field-row{ display:grid; grid-template-columns:1fr; gap:1.1rem; margin-bottom:1.1rem; }
  .field-row.two{ grid-template-columns:1fr 1fr; }
  .field-row.three{ grid-template-columns:1fr 1fr 1fr; }
  @media (max-width:640px){ .field-row.two, .field-row.three{ grid-template-columns:1fr; } }
  .field{ display:flex; flex-direction:column; gap:0.4rem; }
  .field .lbl{ font-family:var(--mono); font-size:0.625rem; letter-spacing:0.16em; text-transform:uppercase; color:var(--paper-3); }
  .field input, .field select, .field textarea{
    font-family:var(--serif); font-size:0.9375rem; color:var(--paper);
    background:transparent; border:none; border-bottom:1px solid var(--rule);
    padding:0.5rem 0.1rem; outline:none; transition:border-color .2s ease; width:100%;
  }
  .field input[type=file]{ font-family:var(--mono); font-size:0.75rem; padding-top:0.75rem; }
  .field input:focus, .field select:focus, .field textarea:focus{ border-color:var(--teal); }
  .field select option{ background:var(--navy); color:var(--paper); }
  .field.has-error input, .field.has-error select, .field.has-error textarea{ border-color:#ff8787; }
  .field-hint{ font-size:0.75rem; color:var(--paper-4); }
  .checkbox-field{ display:flex; align-items:flex-start; gap:0.75rem; margin:1.5rem 0; }
  .checkbox-field input[type=checkbox]{ margin-top:0.25rem; width:16px; height:16px; accent-color:var(--teal); flex-shrink:0; }
  .checkbox-field label{ font-size:0.8125rem; color:var(--paper-2); line-height:1.6; }
  .checkbox-field.has-error label{ color:#ff8787; }
  fieldset{ border:none; padding:0; margin:0 0 1.75rem 0; }
  legend{ font-family:var(--mono); font-weight:500; font-size:0.6875rem; letter-spacing:0.22em; text-transform:uppercase; color:var(--paper); margin-bottom:1.1rem; padding-bottom:0.55rem; border-bottom:1px solid var(--rule); width:100%; }
  .radio-row{ display:flex; gap:1.5rem; flex-wrap:wrap; }
  .radio-field{ display:flex; align-items:center; gap:0.5rem; }
  .radio-field input{ accent-color:var(--teal); }
  .radio-field label{ font-size:0.875rem; color:var(--paper-2); }
  .form-status{ font-family:var(--mono); font-size:0.75rem; letter-spacing:0.06em; color:var(--paper-3); margin-top:1rem; }
  .form-status.is-err{ color:#ff8787; }
  .form-status.is-ok{ color:var(--teal); }
  .alert{ border:1px solid #ff8787; background:rgba(255,135,135,0.08); color:#ffb3b3; padding:0.9rem 1.1rem; margin-bottom:1.5rem; font-size:0.8125rem; }
  .alert-ok{ border-color:var(--teal); background:rgba(88,149,157,0.1); color:var(--paper); }
  table.data-table{ width:100%; border-collapse:collapse; font-size:0.8125rem; }
  table.data-table th{ font-family:var(--mono); font-weight:500; font-size:0.625rem; letter-spacing:0.14em; text-transform:uppercase; color:var(--paper-3); text-align:left; padding:0.75rem 0.6rem; border-bottom:1px solid var(--rule); }
  table.data-table td{ padding:0.85rem 0.6rem; border-bottom:1px solid var(--rule-2); color:var(--paper-2); vertical-align:top; }
  table.data-table tr:hover td{ background:var(--rule-2); }
  table.data-table a{ color:var(--link); border-bottom:1px solid var(--link); text-decoration:none; }
  table.data-table a:hover{ color:var(--paper); border-bottom-color:var(--paper); }
  .table-scroll{ overflow-x:auto; }
  .status-pill{ display:inline-block; font-family:var(--mono); font-size:0.625rem; letter-spacing:0.08em; text-transform:uppercase; padding:0.25rem 0.6rem; border:1px solid var(--rule); color:var(--paper-2); white-space:nowrap; }
  .status-pill.status-approved,.status-pill.status-finished{ border-color:var(--teal); color:var(--teal); }
  .status-pill.status-closed,.status-pill.status-cancelled,.status-pill.status-rejected{ border-color:#ff8787; color:#ff8787; }
  .admin-nav{ display:flex; flex-wrap:wrap; align-items:center; gap:0.5rem; font-family:var(--mono); font-weight:400; font-size:0.6875rem; letter-spacing:0.1em; text-transform:uppercase; margin-top:1.25rem; margin-bottom:1.75rem; padding-bottom:1.25rem; border-bottom:1px solid var(--rule); }
  .admin-nav a{ color:var(--paper); text-decoration:none; padding:0.6rem 1rem; border:1px solid var(--paper-4); background:rgba(245,245,245,0.04); white-space:nowrap; transition:color .2s ease, border-color .2s ease, background .2s ease; }
  .admin-nav a:hover{ border-color:var(--teal); background:rgba(88,149,157,0.18); }
  .admin-nav a.active{ color:var(--navy); background:var(--teal); border-color:var(--teal); font-weight:500; }
  .admin-nav .signout{ margin-left:auto; color:var(--paper-2); }
  .admin-nav .signout:hover{ color:#ff8787; border-color:#ff8787; background:rgba(255,135,135,0.1); }
  .admin-nav a:focus-visible, .back:focus-visible, .btn-secondary:focus-visible, table.data-table a:focus-visible{ outline:2px solid var(--teal); outline-offset:2px; }
  @media (max-width:640px){ .admin-nav{ font-size:0.625rem; } .admin-nav a{ padding:0.5rem 0.7rem; } .admin-nav .signout{ margin-left:0; } }
  .filter-bar{ display:flex; gap:1rem; flex-wrap:wrap; align-items:end; margin-bottom:1.75rem; padding-bottom:1.5rem; border-bottom:1px solid var(--rule); }
  .filter-bar .field{ min-width:10rem; }
  .card-panel{ border:1px solid var(--rule); padding:1.5rem; margin-bottom:1.5rem; }
  .card-panel h2{ font-family:var(--mono); font-weight:500; font-size:0.6875rem; letter-spacing:0.2em; text-transform:uppercase; color:var(--paper); margin-bottom:1rem; }
  .kv-grid{ display:grid; grid-template-columns:1fr 1fr; gap:0.6rem 1.5rem; font-size:0.8125rem; }
  @media (max-width:640px){ .kv-grid{ grid-template-columns:1fr; } }
  .kv-grid .k{ font-family:var(--mono); font-size:0.625rem; letter-spacing:0.12em; text-transform:uppercase; color:var(--paper-4); }
  .kv-grid .v{ color:var(--paper-2); margin-bottom:0.5rem; }
  .note-item{ padding:0.85rem 0; border-bottom:1px solid var(--rule-2); font-size:0.8125rem; }
  .note-item .note-meta{ font-family:var(--mono); font-size:0.625rem; letter-spacing:0.08em; color:var(--paper-4); text-transform:uppercase; margin-bottom:0.35rem; }
  .note-item .note-body{ color:var(--paper-2); white-space:pre-wrap; }
  footer.foot{ flex-shrink:0; position:relative; z-index:2; border-top:1px solid var(--rule); padding:2rem var(--gutter); display:grid; grid-template-columns:1fr auto 1fr; gap:1rem; align-items:center; font-family:var(--mono); font-weight:400; font-size:0.625rem; letter-spacing:0.18em; text-transform:uppercase; color:var(--paper-3); }
  footer.foot .legal{ justify-self:start; }
  footer.foot .domain{ justify-self:center; }
  footer.foot .place{ justify-self:end; }
  footer.foot a{ color:var(--paper-2); text-decoration:none; border-bottom:1px solid var(--rule); transition:color .25s ease,border-color .25s ease; }
  footer.foot a:hover{ color:var(--paper); border-bottom-color:var(--teal); }
  @media (max-width:720px){ footer.foot{ grid-template-columns:1fr; justify-items:start; gap:0.5rem; } footer.foot > *{ justify-self:start !important; } }
</style>
</head>
<body>
<header class="chrome">
  <a class="wordmark" href="/"><img src="/assets/logoNoBG.png" alt="Kuronyx"></a>
  <nav class="site-nav" aria-label="Primary">
    <?php
    $activeNav = $activeNav ?? '';
    $navItems = [
        'about'            => ['/about', 'About'],
        'for-veterinarians' => ['/for-veterinarians', 'For Veterinarians'],
        'for-cat-owners'    => ['/for-cat-owners', 'For Cat Owners'],
        'dispatches'        => ['/dispatches', 'Dispatches'],
        'contact-us'        => ['/contact-us', 'Contact Us'],
    ];
    foreach ($navItems as $key => [$href, $label]):
    ?>
      <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" <?= $activeNav === $key ? 'class="active"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main>
  <div class="wrap<?= !empty($wideWrap) ? ' wide' : '' ?>">
    <?php if (!empty($backHref) && $backHref !== '/'): ?>
      <a class="back" href="<?= htmlspecialchars($backHref, ENT_QUOTES) ?>" style="display:inline-flex; margin-bottom:1.5rem;"><span class="arr">←</span> <?= htmlspecialchars($backLabel ?? 'Back', ENT_QUOTES) ?></a>
    <?php endif; ?>
