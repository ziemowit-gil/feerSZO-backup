<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';

require_login();

// Wywołanie z modala Alpine.js (contracts/includes/edit_request_modal.php) ustawia
// ten nagłówek i oczekuje JSON zamiast przekierowania — reszta logiki jest identyczna
// jak dla zwykłego (nie-JS) wejścia na tę stronę.
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

function changes_request_fail(bool $isAjax, string $level, string $msg, string $back): void {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($level === 'danger' ? 404 : 409);
        echo json_encode(['ok' => false, 'errors' => [$msg]]);
        exit;
    }
    flash_set($level, $msg);
    header('Location: ' . $back);
    exit;
}

$type = preg_replace('/[^a-z]/', '', $_GET['type'] ?? $_POST['type'] ?? '');
$id   = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$back = APP_URL . "/contracts/{$type}/view.php?id={$id}";

if (!$type || !$id) { header('Location: ' . APP_URL); exit; }

$table = table_for_type($type);
$row   = db_one("SELECT * FROM {$table} WHERE id=?", [$id]);
if (!$row) changes_request_fail($isAjax, 'danger', 'Nie znaleziono umowy.', $back);

// Blokuj jeśli jest oczekujący wniosek
$pending = db_one("SELECT id FROM contract_edit_requests WHERE contract_type=? AND contract_id=? AND status='oczekuje'", [$type, $id]);
if ($pending) {
    changes_request_fail($isAjax, 'warning', 'Ta umowa ma już oczekujący wniosek o edycję.', $back);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $opis = trim($_POST['opis_zmian'] ?? '');
    if (!$opis) $errors[] = 'Opis wymaganych zmian jest obowiązkowy.';

    if (!$errors) {
        $user   = current_user();
        $result = submit_edit_request($type, $id, $user['id'], $row['numer_umowy'], $opis);
        $msg    = 'Wniosek o edycję złożony.';
        if ($result['emails_sent'] > 0) $msg .= " ";
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => $msg]);
            exit;
        }
        flash_set('success', $msg);
        header('Location: ' . $back);
        exit;
    } elseif ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
}

$PAGE_TITLE = 'Wniosek o edycję — ' . $row['numer_umowy'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= $back ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h5 class="mb-0">Wniosek o edycję: <strong><?= h($row['numer_umowy']) ?></strong></h5>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:640px">
<div class="card-header fw-semibold"><i class="bi bi-pencil-square"></i> Wniosek o edycję umowy</div>
<div class="card-body">
<p class="text-muted small mb-3">Opisz jakie zmiany chcesz wprowadzić do umowy. Administrator otrzyma powiadomienie i zatwierdzi lub odrzuci wniosek.</p>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="type" value="<?= h($type) ?>">
  <input type="hidden" name="id"   value="<?= $id ?>">

  <div class="mb-3">
    <label class="form-label fw-semibold">Co chcesz zmienić? <span class="text-danger">*</span></label>
    <textarea name="opis_zmian" class="form-control" rows="5" required
      placeholder="Np. Zmiana daty zakończenia z 31.12.2025 na 28.02.2026, powód: przedłużenie projektu..."><?= h($_POST['opis_zmian'] ?? '') ?></textarea>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Złóż wniosek</button>
    <a href="<?= $back ?>" class="btn btn-outline-secondary">Anuluj</a>
  </div>
</form>
</div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
