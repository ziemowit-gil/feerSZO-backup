<?php
/**
 * ext/kontoMicrosoftEdu/index.php — publiczna realizacja kodu dostępowego (LCCC):
 * automatyczne założenie konta Microsoft 365 + nadanie licencji. Bez logowania.
 * Administracja kodami: admin/ms_edu_codes.php.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/msedu.php';

msedu_migrate();

$org    = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $result = msedu_redeem((string)($_POST['code'] ?? ''), $ip);
}
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Konto Microsoft 365 — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background:#f6f7fb; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem; }
.ke-card { max-width:440px; width:100%; background:#fff; border:1px solid #e5e7eb; border-radius:14px;
           box-shadow:0 4px 16px rgba(0,0,0,.08); padding:2rem; }
.ke-icon { width:52px; height:52px; border-radius:50%; background:linear-gradient(135deg,#0078d4,#4f46e5);
           display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.4rem; margin-bottom:1rem; }
.ke-code-input { font-family:monospace; font-size:1.4rem; letter-spacing:.2em; text-align:center; text-transform:uppercase; }
.ke-cred { background:#f6f7fb; border:1px solid #e5e7eb; border-radius:8px; padding:.6rem .9rem; font-family:monospace; }
</style>
</head><body>
<div class="ke-card">
  <div class="ke-icon"><i class="bi bi-microsoft" aria-hidden="true"></i></div>
  <h1 class="h5 fw-bold mb-1">Konto Microsoft 365</h1>
  <p class="text-muted small mb-4">Wpisz kod dostępowy, aby automatycznie założyć konto.</p>

  <?php if ($result && $result['ok']): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Konto zostało utworzone.
  </div>
  <div class="mb-2">
    <div class="small text-muted mb-1">Login (UPN)</div>
    <div class="ke-cred"><?= h($result['upn']) ?></div>
  </div>
  <div class="mb-3">
    <div class="small text-muted mb-1">Hasło tymczasowe</div>
    <div class="ke-cred"><?= h($result['password']) ?></div>
  </div>
  <div class="alert alert-warning small mb-0">
    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
    Zapisz te dane teraz — nie pokażemy ich drugi raz. Przy pierwszym logowaniu
    będziesz musiał/musiała ustawić własne hasło.
  </div>
  <?php else: ?>

  <?php if ($result && !$result['ok']): ?>
  <div class="alert alert-danger"><i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i><?= h($result['msg']) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="mb-3">
      <label class="form-label fw-semibold" for="ke_code">Kod dostępowy</label>
      <input type="text" class="form-control ke-code-input" id="ke_code" name="code" maxlength="4"
             pattern="[A-Za-z]\d{3}" placeholder="A123" required autofocus
             oninput="this.value=this.value.toUpperCase()">
      <div class="form-text">1 litera i 3 cyfry, np. A123.</div>
    </div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Załóż konto</button>
  </form>
  <?php endif; ?>
</div>
</body></html>
