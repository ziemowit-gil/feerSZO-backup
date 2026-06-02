<?php
/**
 * admin/ika_karta.php — Drukowanie karty IKA (Indywidualny Kod Autoryzacyjny).
 *
 * GET: user_id (opcjonalnie) — wydruk karty dla wybranego użytkownika.
 * Bez parametru: admin wybiera użytkownika z listy.
 * Strona jest czystym HTML bez layoutu aplikacji (druk bezpośredni).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

// ── Lista użytkowników ────────────────────────────────────────────────────────
$users = db_all(
    "SELECT id, name, first_name, last_name, email, role, cpc_code
     FROM users
     WHERE role IN ('admin','editor') AND is_active = 1
     ORDER BY role='admin' DESC, last_name ASC, first_name ASC"
);

$selected_id = (int)($_GET['user_id'] ?? 0);
$selected = null;

if ($selected_id) {
    foreach ($users as $u) {
        if ((int)$u['id'] === $selected_id) {
            $selected = $u;
            break;
        }
    }
}

function _display(array $u): string {
    $fn = trim($u['first_name'] ?? '');
    $ln = trim($u['last_name']  ?? '');
    if ($fn && $ln) return $fn . ' ' . $ln;
    return $u['name'] ?? '';
}

?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Karta IKA — <?= h($org_name) ?></title>
<style>
/* ── Reset ───────────────────────────────────────────────────── */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f8fafc;color:#1e293b;font-size:14px}

/* ── Panel wyboru (tylko ekran) ─────────────────────────────── */
.picker{max-width:520px;margin:30px auto;background:#fff;border-radius:10px;
        box-shadow:0 4px 18px rgba(0,0,0,.08);padding:24px}
.picker h2{font-size:1.1rem;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.picker select{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;
               font-size:.95rem;margin-bottom:14px}
.picker button{background:#2563eb;color:#fff;border:none;border-radius:6px;
               padding:9px 22px;font-size:.92rem;cursor:pointer}
.picker button:hover{background:#1d4ed8}
.picker .back{margin-top:10px;text-align:center}
.picker .back a{color:#64748b;font-size:.85rem;text-decoration:none}

/* ── Karta IKA ───────────────────────────────────────────────── */
.card-wrap{display:flex;flex-direction:column;align-items:center;
           justify-content:center;min-height:100vh;gap:20px;padding:20px}
.ika-card{
    width:85.6mm;          /* ISO/IEC 7810 ID-1 szerokość */
    height:54mm;           /* ISO/IEC 7810 ID-1 wysokość  */
    border-radius:5mm;
    background:linear-gradient(135deg,#1e3a8a 0%,#1d4ed8 60%,#3b82f6 100%);
    color:#fff;
    padding:7mm 7mm 5mm;
    position:relative;
    overflow:hidden;
    box-shadow:0 8px 32px rgba(30,58,138,.3),0 1px 0 rgba(255,255,255,.12) inset;
    page-break-inside:avoid;
}
/* Ozdobna siatka/wzór tła */
.ika-card::before{
    content:'';
    position:absolute;inset:0;
    background:repeating-linear-gradient(
        60deg,
        rgba(255,255,255,.04) 0,rgba(255,255,255,.04) 1px,
        transparent 1px,transparent 12px
    );
    pointer-events:none;
}
.ika-card::after{
    content:'';
    position:absolute;right:-15mm;bottom:-15mm;
    width:45mm;height:45mm;
    border-radius:50%;
    background:rgba(255,255,255,.06);
    pointer-events:none;
}

/* Nagłówek karty */
.card-top{display:flex;justify-content:space-between;align-items:flex-start;
          margin-bottom:3.5mm}
.card-org{font-size:6.5pt;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
          opacity:.85;max-width:50mm;line-height:1.3}
.card-chip{width:9mm;height:7mm;border:1.5px solid rgba(255,255,255,.5);
           border-radius:1.5mm;background:rgba(255,255,255,.15);flex-shrink:0;
           display:flex;align-items:center;justify-content:center;font-size:5pt;
           letter-spacing:.5px;opacity:.7;text-transform:uppercase}

/* Typ dokumentu */
.card-type{font-size:5pt;letter-spacing:2px;text-transform:uppercase;
           opacity:.65;margin-bottom:1.5mm}
.card-label{font-size:7pt;font-weight:700;letter-spacing:.5px;margin-bottom:2mm;
            line-height:1.2}

/* Kod */
.card-code{
    font-size:20pt;font-weight:900;letter-spacing:.35em;
    font-family:'Courier New',Courier,monospace;
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.3);
    border-radius:2.5mm;
    padding:2mm 5mm;
    display:inline-block;
    text-shadow:0 1px 4px rgba(0,0,0,.25);
    margin-bottom:3mm;
}

/* Stopka karty */
.card-footer-row{display:flex;justify-content:space-between;align-items:flex-end;
                 margin-top:auto}
.card-name{font-size:7pt;font-weight:700;letter-spacing:.3px;max-width:55mm;
           white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-sub{font-size:5.5pt;opacity:.65;margin-top:.8mm}
.card-role-badge{font-size:5pt;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
                 border-radius:1.5mm;padding:1mm 2.5mm;text-transform:uppercase;letter-spacing:.8px;
                 flex-shrink:0}

/* ── Panel akcji (tylko ekran) ──────────────────────────────── */
.action-bar{background:#1e293b;color:#fff;padding:8px 18px;
            display:flex;align-items:center;gap:10px;position:fixed;top:0;left:0;right:0;z-index:99;
            font-size:.84rem}
.action-bar button,.action-bar a{padding:5px 14px;border-radius:5px;font-size:.82rem;cursor:pointer;border:none}
.btn-print{background:#2563eb;color:#fff}
.btn-back{background:#475569;color:#fff;text-decoration:none;display:inline-block}

/* ── Wiele kart na jednej stronie (druk) ────────────────────── */
.cards-grid{display:flex;flex-wrap:wrap;gap:8mm;justify-content:center}

/* ── Druk ────────────────────────────────────────────────────── */
@media print{
    body{background:#fff}
    .action-bar,.picker,.no-print{display:none!important}
    .card-wrap{min-height:unset;padding:10mm}
    .ika-card{box-shadow:none;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    @page{size:A4;margin:15mm}
}
@media screen{
    .action-bar + .card-wrap{margin-top:44px}
}
</style>
</head>
<body>

<?php if (!$selected): ?>
<!-- ══ Panel wyboru użytkownika ══════════════════════════════════════ -->
<div class="picker">
  <h2>
    <span style="font-size:1.4rem">&#128274;</span>
    Drukuj kartę IKA
  </h2>
  <p style="color:#64748b;font-size:.88rem;margin-bottom:14px">
    Wybierz użytkownika, aby wydrukować jego indywidualną kartę autoryzacyjną IKA.
    Karta zawiera kod 6-cyfrowy niezbędny do autoryzacji operacji krytycznych.
  </p>
  <form method="get">
    <select name="user_id">
      <option value="">— wybierz użytkownika —</option>
      <?php foreach ($users as $u): ?>
      <option value="<?= $u['id'] ?>"
              <?= empty($u['cpc_code']) ? 'style="color:#94a3b8"' : '' ?>>
        <?= h(_display($u)) ?>
        (<?= $u['role'] === 'admin' ? 'administrator' : 'edytor' ?>)
        <?= empty($u['cpc_code']) ? '— brak kodu' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
    <button type="submit"><i>&#128438;</i> Pokaż kartę</button>
  </form>
  <div class="back">
    <a href="<?= APP_URL ?>/admin/manage_cpc.php">&#8592; Wróć do zarządzania kodami IKA</a>
  </div>
</div>

<?php else: ?>
<!-- ══ Pasek akcji (ekran) ══════════════════════════════════════════ -->
<div class="action-bar no-print">
  <button class="btn-print" onclick="window.print()">&#9113; Drukuj kartę</button>
  <a class="btn-back" href="<?= APP_URL ?>/admin/manage_cpc.php">&#8592; Zarządzanie IKA</a>
  <span style="color:#94a3b8">
    Karta IKA &mdash; <?= h(_display($selected)) ?>
  </span>
</div>

<!-- ══ Podgląd karty ════════════════════════════════════════════════ -->
<div class="card-wrap">
  <?php
  $code       = $selected['cpc_code'] ?? '';
  $name       = _display($selected);
  $role_label = $selected['role'] === 'admin' ? 'Administrator' : 'Edytor';
  $has_code   = $code !== '';
  ?>

  <?php if (!$has_code): ?>
  <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:16px 20px;
              max-width:400px;text-align:center;color:#92400e">
    <div style="font-size:2rem;margin-bottom:8px">&#9888;</div>
    <strong>Brak kodu IKA</strong><br>
    <span style="font-size:.88rem">
      Użytkownik <?= h($name) ?> nie ma jeszcze przypisanego kodu IKA.<br>
      <a href="<?= APP_URL ?>/admin/manage_cpc.php">Przypisz kod IKA</a> przed drukowaniem karty.
    </span>
  </div>
  <?php else: ?>

  <!-- Karta -->
  <div class="ika-card">
    <div class="card-top">
      <div class="card-org"><?= h($org_name) ?></div>
      <div class="card-chip">IKA</div>
    </div>

    <div class="card-type">Indywidualny Kod Autoryzacyjny</div>
    <div class="card-label">Kod dostępu do systemu</div>

    <div class="card-code"><?= h($code) ?></div>

    <div class="card-footer-row">
      <div>
        <div class="card-name"><?= h($name) ?></div>
        <div class="card-sub"><?= h($selected['email'] ?? '') ?></div>
      </div>
      <div class="card-role-badge"><?= h($role_label) ?></div>
    </div>
  </div>

  <!-- Karta — kopia 2 (druk: 2 karty na stronie A4) -->
  <div class="ika-card">
    <div class="card-top">
      <div class="card-org"><?= h($org_name) ?></div>
      <div class="card-chip">IKA</div>
    </div>

    <div class="card-type">Indywidualny Kod Autoryzacyjny</div>
    <div class="card-label">Kod dostępu do systemu — KOPIA</div>

    <div class="card-code"><?= h($code) ?></div>

    <div class="card-footer-row">
      <div>
        <div class="card-name"><?= h($name) ?></div>
        <div class="card-sub"><?= h($selected['email'] ?? '') ?></div>
      </div>
      <div class="card-role-badge"><?= h($role_label) ?></div>
    </div>
  </div>

  <div class="no-print" style="text-align:center;color:#64748b;font-size:.82rem;margin-top:8px">
    Na stronie A4 mieszczą się dwie karty — wydrukuj i wytnij wzdłuż krawędzi.
  </div>

  <?php endif; ?>
</div>

<?php endif; ?>

</body>
</html>
