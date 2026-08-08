<?php
/**
 * karty30/ti/kursant/chooser.php
 * Publiczna strona wyboru interfejsu panelu kursanta.
 * Dostępna przez ti.feer.org.pl/ (redirect z index.php).
 */
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
           . '://' . ($_SERVER['HTTP_HOST'] ?? 'ti.feer.org.pl');
?><!DOCTYPE html>
<html lang="pl" dir="ltr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel Kursanta TI — wybierz interfejs</title>
  <meta name="robots" content="noindex,nofollow">
  <style>
    *,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:    #0f172a;
      --bg2:   #1e293b;
      --bg3:   #263347;
      --accent: #e05a1e;
      --accent2: #f97316;
      --text:  #f1f5f9;
      --muted: #94a3b8;
      --border: rgba(255,255,255,.08);
      --radius: 1rem;
    }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 2rem 1rem;
    }

    .logo-wrap {
      display: flex;
      align-items: center;
      gap: .75rem;
      margin-bottom: 2.5rem;
    }

    .logo-badge {
      width: 3rem; height: 3rem;
      border-radius: .75rem;
      background: linear-gradient(135deg, var(--accent), var(--accent2));
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
      font-size: 1.1rem;
      color: #fff;
      flex-shrink: 0;
    }

    .logo-text { line-height: 1.15; }
    .logo-text .org  { font-size: .75rem; color: var(--muted); text-transform: uppercase; letter-spacing: .1em; }
    .logo-text .name { font-size: 1.15rem; font-weight: 700; }

    h1 {
      font-size: clamp(1.35rem, 4vw, 1.75rem);
      font-weight: 700;
      text-align: center;
      margin-bottom: .5rem;
    }

    .subtitle {
      color: var(--muted);
      font-size: .9rem;
      text-align: center;
      margin-bottom: 2.5rem;
    }

    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
      gap: 1.25rem;
      width: 100%;
      max-width: 660px;
    }

    .card {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      padding: 1.75rem;
      background: var(--bg2);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      text-decoration: none;
      color: var(--text);
      transition: border-color .15s, transform .15s, box-shadow .15s;
      position: relative;
      overflow: hidden;
    }

    .card::before {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(ellipse at 80% 10%, rgba(224,90,30,.07), transparent 60%);
      pointer-events: none;
    }

    .card:hover, .card:focus-visible {
      border-color: var(--accent);
      transform: translateY(-2px);
      box-shadow: 0 8px 32px rgba(0,0,0,.35);
      outline: none;
    }

    .card:focus-visible { outline: 2px solid var(--accent2); outline-offset: 2px; }

    .card.new { border-color: rgba(224,90,30,.35); }

    .card-icon {
      width: 2.75rem; height: 2.75rem;
      border-radius: .625rem;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
    }

    .card.classic .card-icon { background: rgba(148,163,184,.12); }
    .card.new .card-icon     { background: rgba(224,90,30,.15); }

    .card-body { flex: 1; }
    .card-title { font-size: 1.05rem; font-weight: 700; margin-bottom: .35rem; }
    .card-desc  { font-size: .85rem; color: var(--muted); line-height: 1.5; }

    .card-badge {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      font-size: .72rem;
      font-weight: 600;
      padding: .2rem .55rem;
      border-radius: 100px;
      text-transform: uppercase;
      letter-spacing: .06em;
    }

    .card.new .card-badge    { background: rgba(224,90,30,.18); color: #fb923c; }
    .card.classic .card-badge{ background: rgba(148,163,184,.12); color: var(--muted); }

    .card-arrow {
      align-self: flex-end;
      font-size: 1.3rem;
      color: var(--muted);
      transition: color .15s, transform .15s;
    }

    .card:hover .card-arrow { color: var(--accent2); transform: translateX(3px); }

    .divider-section {
      width: 100%;
      max-width: 660px;
      margin-top: 2rem;
      padding-top: 1.5rem;
      border-top: 1px solid var(--border);
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      justify-content: center;
    }

    .link-btn {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      font-size: .8rem;
      color: var(--muted);
      text-decoration: none;
      padding: .4rem .8rem;
      border: 1px solid var(--border);
      border-radius: 100px;
      transition: color .15s, border-color .15s;
    }

    .link-btn:hover { color: var(--text); border-color: rgba(255,255,255,.2); }
    .link-btn:focus-visible { outline: 2px solid var(--accent2); outline-offset: 2px; }

    .footer {
      margin-top: 2.5rem;
      font-size: .75rem;
      color: rgba(148,163,184,.5);
      text-align: center;
    }

    @media (max-width: 480px) {
      .cards { grid-template-columns: 1fr; }
    }

    /* Skip link */
    .skip-link {
      position: absolute;
      top: -4rem;
      left: 1rem;
      background: var(--accent);
      color: #fff;
      padding: .5rem 1rem;
      border-radius: .4rem;
      font-size: .85rem;
      font-weight: 600;
      text-decoration: none;
      z-index: 9999;
      transition: top .1s;
    }
    .skip-link:focus { top: 1rem; }
  </style>
</head>
<body>
  <a class="skip-link" href="#main">Przejdź do treści</a>

  <div class="logo-wrap" aria-hidden="true">
    <div class="logo-badge">TI</div>
    <div class="logo-text">
      <div class="org">FEER</div>
      <div class="name">Centrum TI</div>
    </div>
  </div>

  <main id="main">
    <h1>Panel Kursanta</h1>
    <p class="subtitle">Wybierz wersję interfejsu, aby się zalogować</p>

    <nav class="cards" aria-label="Wybór interfejsu panelu kursanta">
      <a href="<?= htmlspecialchars($base_url) ?>/newUI/"
         class="card new"
         aria-label="Nowy panel Angular — zaloguj się do nowego interfejsu">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div class="card-icon">✨</div>
          <span class="card-badge">Nowy</span>
        </div>
        <div class="card-body">
          <div class="card-title">Nowy panel</div>
          <div class="card-desc">Przeprojektowany interfejs Angular 19. Szybszy, czytelniejszy, w pełni dostępny (WCAG AA).</div>
        </div>
        <div class="card-arrow" aria-hidden="true">→</div>
      </a>

      <a href="<?= htmlspecialchars($base_url) ?>/karty30/ti/kursant/login.php"
         class="card classic"
         aria-label="Klasyczny panel PHP — zaloguj się do poprzedniej wersji">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div class="card-icon">📋</div>
          <span class="card-badge">Klasyczny</span>
        </div>
        <div class="card-body">
          <div class="card-title">Panel klasyczny</div>
          <div class="card-desc">Poprzednia wersja panelu kursanta. Dostępna do czasu pełnego przejścia na nowy interfejs.</div>
        </div>
        <div class="card-arrow" aria-hidden="true">→</div>
      </a>
    </nav>

    <div class="divider-section" role="navigation" aria-label="Inne wejścia do systemu">
      <a href="<?= htmlspecialchars($base_url) ?>/karty30/ti/dydaktyk/login.php"
         class="link-btn"
         aria-label="Panel prowadzącego TI">
        👨‍🏫 Panel prowadzącego
      </a>
      <a href="<?= htmlspecialchars($base_url) ?>/karty30/ti/kursant/login.php?tab=parent"
         class="link-btn"
         aria-label="Logowanie dla rodziców / opiekunów">
        👪 Rodzic / Opiekun
      </a>
      <a href="<?= htmlspecialchars($base_url) ?>/karty30/ti/kursant/login.php?tab=pfron"
         class="link-btn"
         aria-label="Portal PFRON — logowanie za pomocą numeru umowy i telefonu">
        🏛 Portal PFRON
      </a>
    </div>
  </main>

  <footer class="footer">
    &copy; <?= date('Y') ?> FEER — Centrum TI
  </footer>
</body>
</html>
