<?php
/**
 * auth/choose_context.php — Wybór kontekstu pracy po zalogowaniu (tylko admin).
 *
 * Administrator decyduje, jak wejść do systemu:
 *   • Administrator — pełny system (domyślnie).
 *   • Mój panel — własny panel wolontariusza / współpracownika (jeśli konto admina
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
<meta name="theme-color" content="#0b1a3a">
<title>Wybór kontekstu · <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  /* Warstwa wizualna (2026-10-05): język Systemu Tożsamości / strony startowej — granatowy band, karty nachodzące na band. */
  :root{ --brand:#1E6DFF; --brand-dark:#1656d6; --ink:#0b1a3a; --line:#e6e9f0; --muted:#56637f; }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif; background:#f3f5f9; color:#0f172a; min-height:100vh; -webkit-font-smoothing:antialiased; }
  .band{ background:radial-gradient(1200px 420px at 85% -20%,rgba(30,109,255,.45),transparent 60%),radial-gradient(800px 300px at 0% 120%,rgba(124,92,255,.35),transparent 60%),linear-gradient(135deg,#0b1a3a 0%,#11275e 55%,#143070 100%); color:#fff; position:relative; overflow:hidden; }
  .band::before{ content:''; position:absolute; inset:0; background-image:linear-gradient(rgba(255,255,255,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.05) 1px,transparent 1px); background-size:36px 36px; -webkit-mask-image:linear-gradient(180deg,rgba(0,0,0,.7),transparent 85%); mask-image:linear-gradient(180deg,rgba(0,0,0,.7),transparent 85%); pointer-events:none; }
  .band .in{ max-width:860px; margin:0 auto; padding:1.5rem 1rem 5rem; position:relative; }
  .top{ display:flex; align-items:center; gap:.6rem; margin-bottom:1.75rem; }
  .mark{ width:36px; height:36px; border-radius:11px; background:linear-gradient(135deg,#1E6DFF,#7c5cff); display:flex; align-items:center; justify-content:center; font-size:1.05rem; box-shadow:0 6px 16px -4px rgba(30,109,255,.7); flex-shrink:0; }
  .top b{ display:block; font-size:.95rem; font-weight:700; line-height:1.05; }
  .top small{ display:block; font-size:.64rem; color:rgba(255,255,255,.62); }
  .top .out{ margin-left:auto; color:rgba(255,255,255,.82); text-decoration:none; font-size:.83rem; font-weight:500; padding:.45rem .7rem; border-radius:9px; display:inline-flex; align-items:center; gap:.4rem; }
  .top .out:hover{ background:rgba(255,255,255,.1); color:#fff; }
  .eyebrow{ font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.14em; color:rgba(255,255,255,.6); }
  .band h1{ font-size:clamp(1.5rem,3vw,2rem); font-weight:800; letter-spacing:-.025em; margin:.2rem 0 0; line-height:1.15; }
  .band p{ color:rgba(255,255,255,.72); margin:.45rem 0 0; font-size:.92rem; }
  .band p strong{ color:#fff; }
  .wrap{ max-width:860px; margin:-3.5rem auto 0; padding:0 1rem 3rem; position:relative; z-index:1; }
  .grid{ display:grid; grid-template-columns:1fr; gap:.9rem; }
  @media(min-width:680px){ .grid{ grid-template-columns:1fr 1fr; } .card--main{ grid-column:span 2; } }
  .card{ background:#fff; border:1px solid var(--line); border-radius:20px; padding:1.2rem 1.25rem; box-shadow:0 1px 2px rgba(16,24,40,.04),0 14px 36px -24px rgba(16,24,40,.35); display:flex; flex-direction:column; gap:.6rem; position:relative; overflow:hidden; }
  .card--main{ border-color:transparent; box-shadow:0 0 0 2px var(--brand),0 14px 36px -24px rgba(16,24,40,.35); }
  .card--main::before{ content:''; position:absolute; left:0; right:0; top:0; height:5px; background:linear-gradient(90deg,#1E6DFF,#7c5cff); }
  .card-hd{ display:flex; align-items:center; gap:.8rem; }
  .ic{ width:46px; height:46px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#fff; flex-shrink:0; box-shadow:0 10px 20px -10px rgba(2,6,23,.5); }
  .card h2{ font-size:1.02rem; margin:0; font-weight:800; letter-spacing:-.015em; }
  .card .desc{ color:var(--muted); font-size:.84rem; margin:0; line-height:1.45; flex:1; }
  .tag{ display:inline-flex; align-items:center; gap:.3rem; background:var(--ink); color:#fff; font-size:.64rem; font-weight:700; padding:.22rem .55rem; border-radius:999px; position:absolute; top:1rem; right:1rem; }
  .tag i{ color:#fbbf24; }
  button.btn{ border:0; border-radius:11px; padding:.6rem 1rem; font-weight:700; font-size:.88rem; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; justify-content:center; gap:.4rem; transition:background .12s,transform .12s; }
  button.btn:focus-visible{ outline:3px solid #fbbf24; outline-offset:2px; }
  .btn-primary{ background:var(--brand); color:#fff; width:100%; font-size:.98rem; padding:.78rem; box-shadow:0 8px 18px -10px rgba(30,109,255,.9); }
  .btn-primary:hover{ background:var(--brand-dark); transform:translateY(-1px); }
  .btn-soft{ background:#eef2f8; color:#0f172a; }
  .btn-soft:hover{ background:#e2e8f0; }
  .row-inline{ display:flex; gap:.5rem; flex-wrap:wrap; }
  select,input[type=search]{ width:100%; padding:.55rem .65rem; border:1px solid #cbd5e1; border-radius:10px; font-size:.9rem; margin-bottom:.55rem; background:#fff; font-family:inherit; }
  select:focus,input:focus,textarea:focus{ outline:none; border-color:var(--brand); box-shadow:0 0 0 .2rem rgba(30,109,255,.15); }
  .err{ background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:12px; padding:.6rem .85rem; margin-bottom:1rem; font-size:.88rem; display:flex; gap:.5rem; align-items:flex-start; }
  .muted{ color:#7d8aa6; font-size:.8rem; text-align:center; margin-top:1.25rem; }
  textarea{ width:100%; padding:.55rem .65rem; border:1px solid #cbd5e1; border-radius:10px; font-size:.9rem; font-family:inherit; resize:vertical; margin-bottom:.55rem; }
  .lbl{ display:block; font-size:.8rem; font-weight:700; color:#334155; margin:.2rem 0 .3rem; }
  dialog.dlg{ border:0; border-radius:18px; padding:1.2rem 1.3rem; width:min(92vw,540px); box-shadow:0 24px 60px rgba(2,6,23,.35); }
  dialog.dlg::backdrop{ background:rgba(11,26,58,.55); -webkit-backdrop-filter:blur(3px); backdrop-filter:blur(3px); }
  .dlg-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:.8rem; }
  .dlg-head strong{ font-size:1.05rem; display:inline-flex; align-items:center; gap:.5rem; }
  .dlg-x{ border:0; background:#f1f5f9; width:32px; height:32px; border-radius:50%; font-size:1rem; cursor:pointer; color:#56637f; line-height:1; }
  .dlg-x:hover{ background:#e2e8f0; color:#0f172a; }
  .dlg-foot{ display:flex; gap:.5rem; justify-content:flex-end; margin-top:.4rem; }
  .audit{ display:inline-flex; align-items:center; gap:.35rem; font-size:.7rem; color:#b45309; background:#fffbeb; border:1px solid #fde68a; border-radius:999px; padding:.15rem .55rem; font-weight:600; }
</style>
</head>
<body>
<section class="band">
  <div class="in">
    <div class="top">
      <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
      <span><b>System Tożsamości</b><small><?= h($org) ?></small></span>
      <a href="<?= APP_URL ?>/auth/logout.php" class="out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Wyloguj</a>
    </div>
    <div class="eyebrow">Wybór kontekstu pracy</div>
    <h1>Jak chcesz dziś korzystać z systemu?</h1>
    <p>Zalogowano jako <strong><?= h($rname) ?></strong> (administrator). Kontekst możesz w każdej chwili zmienić — wróć do administratora przyciskiem w górnym pasku.</p>
  </div>
</section>

<main class="wrap">
  <?php if ($err): ?><div class="err" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span><?= h($err) ?></span></div><?php endif; ?>

  <div class="grid">
  <!-- Administrator -->
  <div class="card card--main">
    <span class="tag"><i class="bi bi-star-fill" aria-hidden="true"></i>Domyślnie</span>
    <div class="card-hd">
      <span class="ic" style="background:linear-gradient(135deg,#1E6DFF,#7c5cff)" aria-hidden="true"><i class="bi bi-shield-fill-check"></i></span>
      <h2>Administrator</h2>
    </div>
    <p class="desc">Pełny dostęp do wszystkich modułów i panelu administracyjnego.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="admin">
      <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Wejdź jako administrator</button>
    </form>
  </div>

  <!-- Mój panel -->
  <div class="card">
    <div class="card-hd">
      <span class="ic" style="background:linear-gradient(135deg,#9D174D,#EC4899)" aria-hidden="true"><i class="bi bi-person-heart"></i></span>
      <h2>Mój panel</h2>
    </div>
    <p class="desc">Twoje własne umowy i zadania — jeśli Twoje konto jest też wolontariuszem lub zleceniobiorcą.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="mypanel">
      <button class="btn btn-soft" type="submit">Otwórz mój panel <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </form>
  </div>

  <!-- Wcielenie w użytkownika -->
  <div class="card">
    <div class="card-hd">
      <span class="ic" style="background:linear-gradient(135deg,#b45309,#f59e0b)" aria-hidden="true"><i class="bi bi-incognito"></i></span>
      <h2>Wciel się w użytkownika</h2>
    </div>
    <p class="desc">Zobacz system oczami konkretnej osoby — jej panel i dane. <span class="audit"><i class="bi bi-journal-check" aria-hidden="true"></i>wymagany powód · audytowane</span></p>
    <?php if ($users): ?>
    <button class="btn btn-soft" type="button" onclick="document.getElementById('imp-dlg').showModal()">
      Wybierz użytkownika… <i class="bi bi-search" aria-hidden="true"></i>
    </button>
    <?php else: ?>
    <p class="muted" style="margin:0;text-align:left">Brak innych aktywnych użytkowników do wcielenia.</p>
    <?php endif; ?>
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
  <div class="card">
    <div class="card-hd">
      <span class="ic" style="background:linear-gradient(135deg,#0f766e,#14b8a6)" aria-hidden="true"><i class="bi bi-eyeglasses"></i></span>
      <h2>Podgląd roli (bez danych)</h2>
    </div>
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
