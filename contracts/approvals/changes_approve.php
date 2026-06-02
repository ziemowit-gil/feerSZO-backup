<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';

// ── Tryb aplikacyjny ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['token'])) {
    require_role('admin');
    csrf_check();

    $rid      = intval($_POST['request_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $note     = trim($_POST['decision_note'] ?? '');

    if (!in_array($decision, ['zaakceptowany','odrzucony'], true)) {
        flash_set('danger', 'Nieznana decyzja.');
        header('Location: ' . APP_URL . '/contracts/approvals/index.php'); exit;
    }

    $r = db_one("SELECT * FROM contract_edit_requests WHERE id=?", [$rid]);
    if (!$r) { flash_set('danger', 'Nie znaleziono wniosku.'); header('Location: ' . APP_URL . '/contracts/approvals/index.php'); exit; }

    if ($decision === 'odrzucony' && $note === '') {
        flash_set('danger', 'Przy odrzuceniu komentarz jest wymagany.');
        header('Location: ' . APP_URL . '/contracts/approvals/index.php'); exit;
    }

    $user = current_user();
    decide_edit_request($rid, $decision, $note, $user['id'], false);

    flash_set($decision === 'zaakceptowany' ? 'success' : 'warning',
        'Decyzja zapisana: ' . ($decision === 'zaakceptowany' ? 'Zatwierdzone' : 'Odrzucono') . '.');
    header('Location: ' . APP_URL . '/contracts/' . $r['contract_type'] . '/view.php?id=' . $r['contract_id']);
    exit;
}

// ── Tryb e-mail ───────────────────────────────────────────────────────────────
$token    = trim($_GET['token'] ?? '');
$decision = trim($_GET['action'] ?? '');

if (!$token) { http_response_code(400); die('<p>Brak tokenu.</p>'); }

$req = db_one("SELECT * FROM contract_edit_requests WHERE token=?", [$token]);

$error = '';
if (!$req)                                       $error = 'Nieprawidłowy link.';
elseif ($req['status'] !== 'oczekuje')           $error = 'Ten wniosek został już rozpatrzony: ' . (EDIT_REQUEST_STATUSES[$req['status']]['label'] ?? $req['status']);
elseif (strtotime($req['token_expires']) < time()) $error = 'Link wygasł.';

$contract_row = null;
if (!$error) {
    $table = table_for_type($req['contract_type']);
    $contract_row = db_one("SELECT * FROM {$table} WHERE id=?", [$req['contract_id']]);
    if (!$contract_row) $error = 'Nie znaleziono umowy.';
}

$done = false; $final = '';

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $final = in_array($_POST['decision'] ?? '', ['zaakceptowany','odrzucony']) ? $_POST['decision'] : '';
    $note  = trim($_POST['decision_note'] ?? '');
    if ($final) {
        if ($final === 'odrzucony' && $note === '') {
            $error = 'Przy odrzuceniu komentarz jest wymagany.';
        } else {
            decide_edit_request($req['id'], $final, $note, null, true);
            $done = true;
        }
    }
}
?>
<!DOCTYPE html><html lang="pl"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Wniosek o edycję — <?= h(ORG_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>body{background:#f0f4f8}.card{max-width:560px;margin:80px auto}</style>
</head><body>
<div class="card shadow-sm">
<div class="card-header fw-bold"><i class="bi bi-pencil-square"></i> Wniosek o edycję — <?= h(ORG_NAME) ?></div>
<div class="card-body p-4">

<?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-primary">Zaloguj się</a>

<?php elseif ($done): ?>
  <div class="alert alert-<?= $final === 'zaakceptowany' ? 'success' : 'warning' ?> text-center">
    <h5><?= $final === 'zaakceptowany' ? '✓ Edycja zatwierdzona' : '✗ Wniosek odrzucony' ?></h5>
    <p class="mb-0">Decyzja zapisana. Wnioskujący otrzyma powiadomienie.</p>
  </div>

<?php else: ?>
  <table class="table table-sm table-bordered mb-3">
    <tr><th>Typ</th><td><?= h(CONTRACT_TYPES[$req['contract_type']] ?? $req['contract_type']) ?></td></tr>
    <tr><th>Numer</th><td><strong><?= h($contract_row['numer_umowy']) ?></strong></td></tr>
    <tr><th>Opis zmian</th><td><?= nl2br(h($req['opis_zmian'])) ?></td></tr>
    <tr><th>Złożono</th><td><?= date_pl($req['requested_at']) ?></td></tr>
    <tr><th>Link ważny do</th><td><?= date_pl($req['token_expires']) ?></td></tr>
  </table>

  <form method="post" id="tokenForm">
    <input type="hidden" name="decision" id="tokenDecision" value="">
    <div class="mb-3">
      <label class="form-label">Komentarz <span id="noteHint" class="text-muted">(opcjonalny)</span></label>
      <textarea name="decision_note" id="tokenNote" class="form-control" rows="3"
        placeholder="Opcjonalny komentarz..."></textarea>
      <div class="invalid-feedback">Przy odrzuceniu komentarz jest wymagany.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button type="button" class="btn btn-success" onclick="tokenSubmit('zaakceptowany')">
        <i class="bi bi-check-lg"></i> Akceptuję
      </button>
      <button type="button" class="btn btn-danger" onclick="tokenSubmit('odrzucony')">
        <i class="bi bi-x-lg"></i> Odrzuć
      </button>
    </div>
  </form>
  <script>
  function tokenSubmit(d) {
    var note = document.getElementById('tokenNote');
    if (d === 'odrzucony' && !note.value.trim()) {
      note.classList.add('is-invalid');
      document.getElementById('noteHint').textContent = '(wymagany)';
      document.getElementById('noteHint').className = 'text-danger';
      note.focus();
      return;
    }
    note.classList.remove('is-invalid');
    document.getElementById('tokenDecision').value = d;
    document.getElementById('tokenForm').submit();
  }
  </script>
<?php endif; ?>

</div></div>
</body></html>
