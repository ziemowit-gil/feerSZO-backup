<?php
/**
 * karty30/ti/kursant/unenroll_parent.php — Strona zatwierdzenia wypisania przez opiekuna.
 * Dostęp przez jednorazowy token z e-maila. Nie wymaga logowania.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

karty30_migrate();

$token = trim((string)($_GET['token'] ?? ''));
$org   = defined('ORG_NAME') ? ORG_NAME : 'FEER';

$req = $token !== '' ? db_one(
    "SELECT r.*, c.name AS course_name, cl.name AS client_name,
            acc.guardian_name
     FROM k30_ti_unenroll_requests r
     JOIN k30_ti_courses c  ON c.id=r.course_id
     JOIN k30_clients cl    ON cl.id=r.client_id
     LEFT JOIN k30_ti_student_accounts acc ON acc.client_id=r.client_id
     WHERE r.parent_token=?",
    [$token]
) : null;

$msg = null;
$ok  = false;

if ($req && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    $result = k30_ti_unenroll_parent_approve($token);
    if ($result === null) {
        $msg = ['type' => 'danger', 'text' => 'Nie znaleziono wniosku dla tego linku.'];
    } else {
        $ok  = $result['ok'];
        $msg = ['type' => $result['ok'] ? 'success' : 'warning', 'text' => $result['msg']];
    }
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Potwierdzenie wypisania z kursu — <?= h($org) ?></title>
<link rel="stylesheet" href="<?= rtrim(APP_URL ?? '', '/') ?>/assets/bootstrap/bootstrap.min.css">
<style>
body { background: #f8f9fa; }
.card { max-width: 540px; margin: 3rem auto; }
</style>
</head>
<body>
<div class="card shadow-sm">
  <div class="card-body p-4">
    <h1 class="h4 fw-bold mb-3">
      <span class="text-danger">&#9888;</span> Wniosek o wypisanie z kursu
    </h1>

    <?php if ($msg): ?>
    <div class="alert alert-<?= h($msg['type']) ?>">
      <?= h($msg['text']) ?>
      <?php if ($ok): ?>
      <p class="mb-0 mt-2 small">Administrator systemu otrzymał powiadomienie i podejmie ostateczną decyzję.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$req): ?>
    <div class="alert alert-danger">Nieprawidłowy lub wygasły link potwierdzenia.</div>

    <?php elseif ($req['status'] === 'approved'): ?>
    <div class="alert alert-success">Ten wniosek został już zatwierdzony i kursant jest wypisany z kursu.</div>

    <?php elseif ($req['status'] === 'rejected'): ?>
    <div class="alert alert-warning">Ten wniosek został odrzucony przez administratora.</div>

    <?php elseif ($req['status'] === 'pending_admin'): ?>
    <div class="alert alert-info">Dziękujemy — Twoje potwierdzenie zostało już przyjęte. Wniosek oczekuje teraz na zatwierdzenie administratora.</div>

    <?php elseif (!$ok): ?>
    <p>Kursant <strong><?= h($req['client_name']) ?></strong> złożył(a) wniosek o wypisanie z kursu:</p>
    <p class="fs-5 fw-semibold"><?= h($req['course_name']) ?></p>
    <?php if ($req['reason'] !== ''): ?>
    <p class="text-secondary small">Podany powód: <?= nl2br(h($req['reason'])) ?></p>
    <?php endif; ?>
    <p>Jako opiekun prawny musisz potwierdzić tę decyzję. Po Twoim zatwierdzeniu wniosek trafi do administratora systemu.</p>
    <form method="post">
      <input type="hidden" name="approve" value="1">
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-danger fw-semibold">
          &#10003; Zatwierdzam wypisanie
        </button>
        <a href="javascript:window.close()" class="btn btn-secondary">Anuluj</a>
      </div>
    </form>
    <?php endif; ?>
  </div>
  <div class="card-footer text-secondary small text-center">
    <?= h($org) ?>
  </div>
</div>
</body>
</html>
