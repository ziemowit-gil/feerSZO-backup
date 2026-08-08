<?php
/**
 * karty30/ti/kursant/chooser.php
 * Publiczna strona wyboru interfejsu panelu kursanta.
 */
$project_root = dirname(__DIR__, 3);
require_once $project_root . '/config.php';
require_once $project_root . '/includes/db.php';
require_once $project_root . '/includes/functions.php';

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
           . '://' . ($_SERVER['HTTP_HOST'] ?? 'ti.feer.org.pl');

$new_ui_url = rtrim(KURSANT_NEW_UI_URL, '/') . '/';

$pfron_on = false;
if (file_exists($project_root . '/includes/pfron.php')) {
    require_once $project_root . '/includes/pfron.php';
    $pfron_on = k30_pfron_enabled();
}
?><!DOCTYPE html>
<html lang="pl" dir="ltr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel Kursanta TI — wybierz interfejs</title>
  <meta name="robots" content="noindex,nofollow">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:      #ffffff;
      --text:    #111111;
      --muted:   #5f5f5f;
      --rule:    #d4d4d4;
      --accent:  #c2410c;
      --accent-h:#9a3409;
      --hover-bg:#f5f5f5;
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --bg:      #0c0c0c;
        --text:    #ededed;
        --muted:   #888888;
        --rule:    #2a2a2a;
        --accent:  #e05a1e;
        --accent-h:#f97316;
        --hover-bg:#161616;
      }
    }
    :root[data-theme="light"] {
      --bg:#ffffff; --text:#111111; --muted:#5f5f5f;
      --rule:#d4d4d4; --accent:#c2410c; --accent-h:#9a3409; --hover-bg:#f5f5f5;
    }
    :root[data-theme="dark"] {
      --bg:#0c0c0c; --text:#ededed; --muted:#888888;
      --rule:#2a2a2a; --accent:#e05a1e; --accent-h:#f97316; --hover-bg:#161616;
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 3rem 1.5rem;
    }

    .wrap {
      width: 100%;
      max-width: 400px;
    }

    /* ── Header ── */
    h1 {
      font-size: 2rem;
      font-weight: 800;
      line-height: 1.1;
      letter-spacing: -.03em;
      margin-bottom: 2rem;
    }

    hr {
      border: none;
      border-top: 1px solid var(--rule);
      margin-bottom: 1.5rem;
    }

    /* ── Label ── */
    .section-label {
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: .75rem;
    }

    /* ── Choice links ── */
    .choices {
      display: flex;
      flex-direction: column;
      gap: .25rem;
      margin-bottom: 2rem;
    }

    .choice {
      display: flex;
      align-items: center;
      gap: .75rem;
      padding: .85rem .9rem;
      border-radius: 4px;
      text-decoration: none;
      color: var(--text);
      font-weight: 600;
      font-size: 1rem;
      transition: background .1s;
    }

    .choice:hover, .choice:focus-visible {
      background: var(--hover-bg);
      outline: none;
    }
    .choice:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }

    .choice .arrow {
      font-style: normal;
      font-weight: 400;
      color: var(--muted);
      flex-shrink: 0;
      font-size: .9rem;
      margin-left: auto;
    }

    .choice.primary { color: var(--accent); }
    .choice.primary:hover { color: var(--accent-h); }
    .choice.primary .arrow { color: var(--accent); opacity: .7; }

    .choice-tag {
      font-size: .65rem;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      border: 1px solid currentColor;
      padding: .1rem .35rem;
      border-radius: 2px;
      opacity: .55;
    }

    /* ── Secondary links ── */
    .secondary {
      border-top: 1px solid var(--rule);
      padding-top: 1.25rem;
      display: flex;
      flex-wrap: wrap;
      gap: .25rem .1rem;
    }

    .sec-link {
      font-size: .8rem;
      color: var(--muted);
      text-decoration: none;
      padding: .3rem .5rem;
      border-radius: 3px;
      transition: color .1s, background .1s;
    }

    .sec-link:hover { color: var(--text); background: var(--hover-bg); }
    .sec-link:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }

    .sec-sep {
      color: var(--rule);
      line-height: 1;
      padding: .3rem 0;
      user-select: none;
    }

    /* ── Footer ── */
    footer {
      margin-top: 3rem;
      font-size: .7rem;
      color: var(--rule);
      text-align: center;
    }

    /* Skip link */
    .skip-link {
      position: absolute;
      top: -5rem;
      left: 1rem;
      background: var(--accent);
      color: #fff;
      padding: .45rem .9rem;
      border-radius: 3px;
      font-size: .8rem;
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

  <main id="main" class="wrap">

    <h1>Wybierz<br>interfejs</h1>
    <hr>

    <nav class="choices" aria-label="Wybór interfejsu">
      <a href="<?= htmlspecialchars($new_ui_url) ?>"
         class="choice primary"
         aria-label="Nowy panel — Angular">
        Nowy panel
        <span class="choice-tag" aria-hidden="true">Nowy</span>
        <span class="arrow" aria-hidden="true">→</span>
      </a>
      <a href="<?= htmlspecialchars($base_url) ?>/karty30/ti/kursant/login.php"
         class="choice"
         aria-label="Panel klasyczny — PHP">
        Panel klasyczny
        <span class="arrow" aria-hidden="true">→</span>
      </a>
    </nav>

    <nav class="secondary" aria-label="Inne wejścia">
      <a href="<?= htmlspecialchars($base_url) ?>/karty30/ti/dydaktyk/login.php"
         class="sec-link">Prowadzący</a>
      <span class="sec-sep" aria-hidden="true">/</span>
      <a href="<?= htmlspecialchars($base_url) ?>/karty30/ti/kursant/login.php?tab=parent"
         class="sec-link">Rodzic / Opiekun</a>
      <?php if ($pfron_on): ?>
      <span class="sec-sep" aria-hidden="true">/</span>
      <a href="<?= htmlspecialchars($base_url) ?>/karty30/ti/kursant/pfron.php"
         class="sec-link">Portal PFRON</a>
      <?php endif; ?>
    </nav>

  </main>

  <footer>&copy; <?= date('Y') ?> FEER</footer>
</body>
</html>
