<?php
/**
 * auth/choose_context.php — Wybór kontekstu pracy po zalogowaniu (tylko admin).
 *
 * Administrator decyduje, jak wejść do systemu:
 *   • Administrator — pełny system (domyślnie).
 *   • Mój panel — własny panel współpracownika (jeśli konto admina
 *     ma własne umowy).
 *   • Wcielenie w użytkownika — podgląd cudzego konta z prawdziwymi danymi.
 *   • Podgląd roli — interfejs danej roli bez danych konkretnej osoby.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

// Dostęp tylko dla prawdziwego administratora.
if (!ctx_can_switch()) { header('Location: ' . APP_URL . '/portal.php'); exit; }

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $choice = $_POST['choice'] ?? '';

    if ($choice === 'admin') {
        ctx_exit(); // wyczyść ewentualny kontekst, oznacz „zdecydowano"
        header('Location: ' . APP_URL . '/portal.php'); exit;
    }

    if ($choice === 'mypanel') {
        unset($_SESSION['ctx']);
        $_SESSION['ctx_decided'] = 1;
        header('Location: ' . APP_URL . '/panel/index.php'); exit;
    }

    if ($choice === 'role') {
        if (ctx_enter_role($_POST['role'] ?? '')) {
            header('Location: ' . APP_URL . '/portal.php'); exit;
        }
        $err = 'Nie udało się ustawić podglądu roli.';
    }

    if ($choice === 'user') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $err = 'Podaj powód wcielenia — jest wymagany i zapisywany w dzienniku.';
        } elseif (ctx_enter_user((int)($_POST['uid'] ?? 0), $reason)) {
            header('Location: ' . APP_URL . '/panel/index.php'); exit;
        } else {
            $err = 'Nie udało się wcielić w wybranego użytkownika.';
        }
    }
}

// Lista aktywnych użytkowników do wcielenia (bez siebie i konta serwisowego).
$users = [];
try {
    $real_id = (int)ctx_real_user()['id'];
    $users = db_all(
        "SELECT id, name, email, role FROM users
          WHERE is_active=1 AND id<>? AND email<>'serwis@local'
          ORDER BY name COLLATE NOCASE", [$real_id]
    );
} catch (\Throwable $e) { $users = []; }

$org   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$rname = ctx_real_user()['name'] ?? '';
$tok   = csrf_token();
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1d1d1d">
<title>Wybór kontekstu · <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  /* Warstwa wizualna (2026-10-06): Metro UI (metroui.org.ua) — spójnie z Systemem Tożsamości i stroną startową:
     ciemny app-bar #1d1d1d, Segoe UI, kafle w jednolitych kolorach, zero zaokrągleń i cieni. */
  :root{ --m-cyan:#1ba1e2; --m-cyan-d:#0e7bb3; --m-green:#008a00; --m-orange:#fa6800; --m-crimson:#a20025; --m-teal:#00aba9; --m-magenta:#d80073; --m-dark:#1d1d1d; --m-light:#f2f2f2; --m-line:#e1e1e1; }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif; background:#fff; color:#111; min-height:100vh; -webkit-font-smoothing:antialiased; }
  .bar{ height:56px; background:var(--m-dark); color:#fff; display:flex; align-items:center; position:sticky; top:0; z-index:40; }
  .bar .in{ width:100%; max-width:960px; margin:0 auto; padding:0 1rem; display:flex; align-items:center; gap:.65rem; }
  .mark{ width:34px; height:34px; background:var(--m-cyan); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
  .bar b{ display:block; font-size:.95rem; font-weight:600; line-height:1.05; }
  .bar small{ display:block; font-size:.64rem; color:rgba(255,255,255,.65); }
  .bar .out{ margin-left:auto; color:rgba(255,255,255,.85); text-decoration:none; font-size:.82rem; font-weight:500; padding:.45rem .7rem; display:inline-flex; align-items:center; gap:.4rem; }
  .bar .out:hover{ background:var(--m-crimson); color:#fff; }
  .head{ max-width:960px; margin:0 auto; padding:2rem 1rem 1.25rem; }
  .eyebrow{ font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.18em; color:#777; margin-bottom:.35rem; }
  .head h1{ font-size:clamp(1.8rem,4vw,2.5rem); font-weight:300; letter-spacing:-.02em; margin:0; line-height:1.05; color:#111; }
  .head p{ color:#555; margin:.6rem 0 0; font-size:.95rem; font-weight:300; }
  .head p strong{ color:#111; font-weight:600; }
  .wrap{ max-width:960px; margin:0 auto; padding:0 1rem 3rem; }
  .grid{ display:grid; grid-template-columns:1fr; gap:8px; }
  @media(min-width:680px){ .grid{ grid-template-columns:1fr 1fr; } .card--main{ grid-column:span 2; } }
  .card{ background:var(--m-light); padding:0; display:flex; flex-direction:column; position:relative; border:0; }
  .card-hd{ display:flex; align-items:center; gap:.9rem; padding:1rem 1.1rem; color:#fff; background:var(--t,var(--m-cyan)); }
  .card--main .card-hd{ padding:1.3rem 1.25rem; }
  .ic{ width:46px; height:46px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; color:#fff; flex-shrink:0; background:rgba(255,255,255,.2); }
  .card h2{ font-size:1.25rem; margin:0; font-weight:300; letter-spacing:-.015em; }
  .card--main h2{ font-size:1.6rem; }
  .card-bd{ padding:1rem 1.1rem 1.1rem; display:flex; flex-direction:column; gap:.75rem; flex:1; }
  .card .desc{ color:#444; font-size:.88rem; margin:0; line-height:1.45; flex:1; font-weight:300; }
  .tag{ display:inline-flex; align-items:center; gap:.3rem; background:rgba(0,0,0,.28); color:#fff; font-size:.64rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; padding:.2rem .55rem; position:absolute; top:.85rem; right:.9rem; }
  button.btn{ border:2px solid transparent; border-radius:0; padding:.6rem 1rem; font-weight:600; font-size:.88rem; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; justify-content:center; gap:.4rem; transition:background .12s,color .12s; }
  button.btn:focus-visible{ outline:3px solid #000; outline-offset:2px; }
  .btn-primary{ background:var(--m-cyan); border-color:var(--m-cyan); color:#fff; width:100%; font-size:1rem; padding:.8rem; }
  .btn-primary:hover{ background:var(--m-cyan-d); border-color:var(--m-cyan-d); }
  .btn-soft{ background:transparent; color:#111; border-color:#111; }
  .btn-soft:hover{ background:#111; color:#fff; }
  .row-inline{ display:flex; gap:.5rem; flex-wrap:wrap; }
  select,input[type=search]{ width:100%; padding:.55rem .65rem; border:2px solid #8a8a8a; border-radius:0; font-size:.9rem; margin-bottom:.55rem; background:#fff; font-family:inherit; }
  select:focus,input:focus,textarea:focus{ outline:none; border-color:var(--m-cyan); box-shadow:none; }
  .err{ background:var(--m-crimson); color:#fff; padding:.65rem .9rem; margin-bottom:1rem; font-size:.88rem; display:flex; gap:.5rem; align-items:flex-start; }
  .muted{ color:#777; font-size:.8rem; text-align:center; margin-top:1.25rem; }
  textarea{ width:100%; padding:.55rem .65rem; border:2px solid #8a8a8a; border-radius:0; font-size:.9rem; font-family:inherit; resize:vertical; margin-bottom:.55rem; }
  .lbl{ display:block; font-size:.78rem; font-weight:600; color:#333; margin:.2rem 0 .3rem; text-transform:uppercase; letter-spacing:.06em; }
  dialog.dlg{ border:0; border-radius:0; padding:1.25rem 1.35rem; width:min(92vw,540px); box-shadow:none; border-top:6px solid var(--m-orange); }
  dialog.dlg::backdrop{ background:rgba(0,0,0,.6); }
  .dlg-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:.9rem; }
  .dlg-head strong{ font-size:1.3rem; font-weight:300; display:inline-flex; align-items:center; gap:.5rem; }
  .dlg-x{ border:2px solid #111; background:transparent; width:32px; height:32px; font-size:1rem; cursor:pointer; color:#111; line-height:1; }
  .dlg-x:hover{ background:#111; color:#fff; }
  .dlg-foot{ display:flex; gap:.5rem; justify-content:flex-end; margin-top:.4rem; }
  .audit{ display:inline-flex; align-items:center; gap:.35rem; font-size:.68rem; color:#fff; background:var(--m-orange); padding:.15rem .5rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
</style>
</head>
<body>
<header class="bar">
  <div class="in">
    <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
    <span><b>System Tożsamości</b><small><?= h($org) ?></small></span>
    <a href="<?= APP_URL ?>/auth/logout.php" class="out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Wyloguj</a>
  </div>
</header>
<div class="head">
  <div class="eyebrow">Wybór kontekstu pracy</div>
  <h1>Jak chcesz dziś korzystać z systemu?</h1>
  <p>Zalogowano jako <strong><?= h($rname) ?></strong> (administrator). Kontekst możesz w każdej chwili zmienić — wróć do administratora przyciskiem w górnym pasku.</p>
</div>

<main class="wrap">
  <?php if ($err): ?><div class="err" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span><?= h($err) ?></span></div><?php endif; ?>

  <div class="grid">
  <!-- Administrator -->
  <div class="card card--main" style="--t:var(--m-cyan)">
    <span class="tag"><i class="bi bi-star-fill" aria-hidden="true"></i>Domyślnie</span>
    <div class="card-hd">
      <span class="ic" aria-hidden="true"><i class="bi bi-shield-fill-check"></i></span>
      <h2>Administrator</h2>
    </div>
    <div class="card-bd">
    <p class="desc">Pełny dostęp do wszystkich modułów i panelu administracyjnego.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="admin">
      <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Wejdź jako administrator</button>
    </form>
    </div>
  </div>

  <!-- Mój panel -->
  <div class="card" style="--t:var(--m-magenta)">
    <div class="card-hd">
      <span class="ic" aria-hidden="true"><i class="bi bi-person-heart"></i></span>
      <h2>Mój panel</h2>
    </div>
    <div class="card-bd">
    <p class="desc">Twoje własne umowy i zadania — jeśli Twoje konto jest też wolontariuszem lub zleceniobiorcą.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="mypanel">
      <button class="btn btn-soft" type="submit">Otwórz mój panel <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </form>
    </div>
  </div>

  <!-- Wcielenie w użytkownika -->
  <div class="card" style="--t:var(--m-orange)">
    <div class="card-hd">
      <span class="ic" aria-hidden="true"><i class="bi bi-incognito"></i></span>
      <h2>Wciel się w użytkownika</h2>
    </div>
    <div class="card-bd">
    <p class="desc">Zobacz system oczami konkretnej osoby — jej panel i dane. <span class="audit"><i class="bi bi-journal-check" aria-hidden="true"></i>wymagany powód · audytowane</span></p>
    <?php if ($users): ?>
    <button class="btn btn-soft" type="button" onclick="document.getElementById('imp-dlg').showModal()">
      Wybierz użytkownika… <i class="bi bi-search" aria-hidden="true"></i>
    </button>
    <?php else: ?>
    <p class="muted" style="margin:0;text-align:left">Brak innych aktywnych użytkowników do wcielenia.</p>
    <?php endif; ?>
    </div>
  </div>

  <?php if ($users): ?>
  <dialog id="imp-dlg" class="dlg">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="user">
      <div class="dlg-head">
        <strong><i class="bi bi-incognito" aria-hidden="true" style="color:#b45309"></i>Wciel się w użytkownika</strong>
        <button type="button" class="dlg-x" onclick="document.getElementById('imp-dlg').close()" aria-label="Zamknij">✕</button>
      </div>
      <?php if ($err && ($_POST['choice'] ?? '') === 'user'): ?>
      <div class="err"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span><?= h($err) ?></span></div>
      <?php endif; ?>
      <label class="lbl" for="userFilter">Użytkownik</label>
      <input type="search" id="userFilter" placeholder="Szukaj po nazwisku lub e-mailu…" autocomplete="off">
      <select name="uid" id="userSelect" size="7" required aria-label="Lista użytkowników">
        <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>"
          data-s="<?= h(mb_strtolower(($u['name'] ?? '').' '.($u['email'] ?? ''),'UTF-8')) ?>">
          <?= h($u['name']) ?> — <?= h($u['email']) ?> [<?= h($u['role']) ?>]
        </option>
        <?php endforeach; ?>
      </select>
      <label class="lbl" for="impReason">Powód wcielenia <span style="color:#dc2626">*</span></label>
      <textarea name="reason" id="impReason" rows="2" required maxlength="500"
                placeholder="np. diagnoza zgłoszenia #1234, weryfikacja widoku umów…"><?= h($_POST['reason'] ?? '') ?></textarea>
      <div class="dlg-foot">
        <button type="button" class="btn btn-soft" onclick="document.getElementById('imp-dlg').close()">Anuluj</button>
        <button type="submit" class="btn btn-primary" style="width:auto;padding:.6rem 1.1rem;font-size:.9rem">Wciel się</button>
      </div>
    </form>
  </dialog>
  <?php endif; ?>

  <!-- Podgląd roli -->
  <div class="card" style="--t:var(--m-teal)">
    <div class="card-hd">
      <span class="ic" aria-hidden="true"><i class="bi bi-eyeglasses"></i></span>
      <h2>Podgląd roli (bez danych)</h2>
    </div>
    <div class="card-bd">
    <p class="desc">Zobacz interfejs i uprawnienia danej roli na własnym koncie — bez danych konkretnej osoby.</p>
    <form method="post" class="row-inline">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="role">
      <button class="btn btn-soft" type="submit" name="role" value="viewer">Wolontariusz (widz)</button>
      <button class="btn btn-soft" type="submit" name="role" value="editor">Edytor</button>
      <button class="btn btn-soft" type="submit" name="role" value="crm_user">Użytkownik CRM</button>
      <button class="btn btn-soft" type="submit" name="role" value="ezd_user">Użytkownik EZD</button>
    </form>
    </div>
  </div>
  </div><!-- /grid -->

  <p class="muted"><i class="bi bi-info-circle" aria-hidden="true"></i> Wybór zapamiętujemy na czas tej sesji. Przy następnym logowaniu zapytamy ponownie.</p>
</main>

<script>
  (function(){
    var f = document.getElementById('userFilter'),
        s = document.getElementById('userSelect');
    if (!f || !s) return;
    f.addEventListener('input', function(){
      var q = this.value.trim().toLowerCase();
      Array.prototype.forEach.call(s.options, function(o){
        o.hidden = q && (o.getAttribute('data-s') || '').indexOf(q) === -1;
      });
    });
  })();
  <?php if ($err && ($_POST['choice'] ?? '') === 'user'): ?>
  (function(){ var d = document.getElementById('imp-dlg'); if (d && !d.open) d.showModal(); })();
  <?php endif; ?>
</script>
</body>
</html>
