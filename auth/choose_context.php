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
        if (ctx_enter_user((int)($_POST['uid'] ?? 0))) {
            header('Location: ' . APP_URL . '/panel/index.php'); exit;
        }
        $err = 'Nie udało się wcielić w wybranego użytkownika.';
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
<title>Wybór kontekstu · <?= h($org) ?></title>
<style>
  :root{ --brand:#7c2d12; }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
        background:#f1f5f9; color:#1e293b; min-height:100vh; display:flex;
        align-items:flex-start; justify-content:center; padding:2.5rem 1rem; }
  .wrap{ width:100%; max-width:640px; }
  .head{ text-align:center; margin-bottom:1.5rem; }
  .head h1{ font-size:1.4rem; margin:.2rem 0; }
  .head p{ color:#64748b; margin:0; font-size:.92rem; }
  .card{ background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.1rem 1.2rem;
         margin-bottom:.85rem; box-shadow:0 1px 3px rgba(0,0,0,.05); }
  .card h2{ font-size:1rem; margin:0 0 .15rem; display:flex; align-items:center; gap:.5rem; }
  .card .desc{ color:#64748b; font-size:.85rem; margin:0 0 .8rem; }
  button.btn{ border:0; border-radius:9px; padding:.55rem 1rem; font-weight:600; font-size:.9rem;
              cursor:pointer; }
  .btn-primary{ background:var(--brand); color:#fff; width:100%; font-size:1rem; padding:.7rem; }
  .btn-soft{ background:#f1f5f9; color:#1e293b; }
  .btn-soft:hover{ background:#e2e8f0; }
  .row-inline{ display:flex; gap:.5rem; flex-wrap:wrap; }
  select,input[type=search]{ width:100%; padding:.5rem .6rem; border:1px solid #cbd5e1;
            border-radius:9px; font-size:.9rem; margin-bottom:.55rem; background:#fff; }
  .err{ background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:9px;
        padding:.55rem .8rem; margin-bottom:1rem; font-size:.88rem; }
  .badge{ font-size:.72rem; background:#e2e8f0; color:#475569; padding:.05rem .4rem; border-radius:6px; }
  .muted{ color:#94a3b8; font-size:.8rem; text-align:center; margin-top:.5rem; }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>Wybierz kontekst pracy</h1>
    <p>Zalogowano jako <strong><?= h($rname) ?></strong> (administrator). Jak chcesz dziś korzystać z systemu?</p>
  </div>

  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <!-- Administrator -->
  <div class="card">
    <h2>🛡️ Administrator</h2>
    <p class="desc">Pełny dostęp do wszystkich modułów i panelu administracyjnego.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="admin">
      <button class="btn btn-primary" type="submit">Wejdź jako administrator</button>
    </form>
  </div>

  <!-- Mój panel -->
  <div class="card">
    <h2>🙋 Mój panel wolontariusza / współpracownika</h2>
    <p class="desc">Twoje własne umowy i zadania — jeśli Twoje konto jest też wolontariuszem lub zleceniobiorcą.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="mypanel">
      <button class="btn btn-soft" type="submit">Otwórz mój panel</button>
    </form>
  </div>

  <!-- Wcielenie w użytkownika -->
  <div class="card">
    <h2>🕵️ Wciel się w użytkownika</h2>
    <p class="desc">Zobacz system oczami konkretnej osoby — jej panel i dane. Wejście i wyjście są audytowane.</p>
    <?php if ($users): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($tok) ?>">
      <input type="hidden" name="choice" value="user">
      <input type="search" id="userFilter" placeholder="Szukaj po nazwisku lub e-mailu…" autocomplete="off">
      <select name="uid" id="userSelect" size="6" required>
        <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>"
          data-s="<?= h(mb_strtolower(($u['name'] ?? '').' '.($u['email'] ?? ''),'UTF-8')) ?>">
          <?= h($u['name']) ?> — <?= h($u['email']) ?> [<?= h($u['role']) ?>]
        </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-soft" type="submit">Wciel się</button>
    </form>
    <?php else: ?>
    <p class="muted">Brak innych aktywnych użytkowników do wcielenia.</p>
    <?php endif; ?>
  </div>

  <!-- Podgląd roli -->
  <div class="card">
    <h2>👓 Podgląd roli (bez danych)</h2>
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

  <p class="muted">Kontekst możesz w każdej chwili zmienić — wróć do administratora przyciskiem w górnym pasku.</p>
</div>

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
</script>
</body>
</html>
