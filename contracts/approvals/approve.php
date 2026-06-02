<?php
/**
 * Obsługuje akceptację/odrzucenie — z aplikacji (POST) i via link e-mail (GET+POST z tokenem).
 * Nie wymaga logowania gdy używany token z maila.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval_workflow.php';

// ── Tryb aplikacyjny (zalogowany admin, POST) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['token'])) {
    require_role('admin');
    csrf_check();

    $appr_id  = intval($_POST['approval_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $note     = trim($_POST['decision_note'] ?? '');

    if (!in_array($decision, ['zaakceptowana','odrzucona'], true)) {
        flash_set('danger', 'Nieznana decyzja.'); header('Location: ' . APP_URL . '/contracts/approvals/index.php'); exit;
    }
    $appr = db_one("SELECT * FROM contract_approvals WHERE id=?", [$appr_id]);
    if (!$appr) { flash_set('danger', 'Nie znaleziono wniosku.'); header('Location: ' . APP_URL . '/contracts/approvals/index.php'); exit; }

    if ($decision === 'odrzucona' && $note === '') {
        flash_set('danger', 'Przy odrzuceniu komentarz jest wymagany.');
        header('Location: ' . APP_URL . '/contracts/approvals/index.php'); exit;
    }

    $user = current_user();
    decide_approval($appr_id, $decision, $note, $user['id'], false);

    // ── Obsługa specjalnych typów po decyzji ──────────────────────────────────
    if ($appr['contract_type'] === 'canva_request') {
        if ($decision === 'zaakceptowana') {
            db_update('umowy_wolontariat', [
                'canva_access'     => 1,
                'canva_invited_at' => date('Y-m-d H:i:s'),
            ], (int)$appr['contract_id']);
            $vol = db_one("SELECT imie_nazwisko, email, m365_login FROM umowy_wolontariat WHERE id=?", [$appr['contract_id']]);
            if ($vol && !empty($vol['email'])) {
                $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                $name = h($vol['imie_nazwisko'] ?? $vol['email']);
                $m365 = $vol['m365_login'] ? "kontem Microsoft 365 (<strong>" . h($vol['m365_login']) . "</strong>)" : "adresem e-mail";
                $body = "<html><body style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529'>"
                    . "<div style='background:linear-gradient(135deg,#7c3aed,#a855f7);padding:22px 26px;border-radius:10px 10px 0 0'>"
                    . "<h2 style='color:#fff;margin:0;font-size:1.1rem'>🎨 Twój dostęp do Canva Pro jest gotowy — {$org}</h2></div>"
                    . "<div style='border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px'>"
                    . "<p>Cześć, <strong>{$name}</strong>!</p>"
                    . "<p>Twoja prośba o dostęp do <strong>Canva Pro</strong> organizacji <strong>{$org}</strong> została zaakceptowana.</p>"
                    . "<div style='background:#fdf4ff;border-left:4px solid #a855f7;border-radius:4px;padding:14px;margin:16px 0;font-size:.9em'>"
                    . "Sprawdź skrzynkę e-mail i kliknij przycisk <strong>Dolacz do zespolu</strong> w wiadomości od Canva.<br>"
                    . "Loguj sie przez {$m365} — wybierz opcje Continue with Microsoft.</div>"
                    . "<div style='text-align:center;margin:20px 0'>"
                    . "<a href='https://www.canva.com' style='background:#7c3aed;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600'>Otwórz Canva →</a></div>"
                    . "</div></body></html>";
                try {
                    require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
                    mail_queue_add($vol['email'], $vol['imie_nazwisko'] ?? '', "Twój dostęp do Canva Pro jest gotowy — {$org}", $body);
                } catch (\Throwable $e) {}
            }
            flash_set('success', 'Prośba o Canva zaakceptowana. E-mail wysłany do wolontariusza.');
        } else {
            db()->prepare("UPDATE umowy_wolontariat SET canva_access_requested_at=NULL WHERE id=?")->execute([$appr['contract_id']]);
            flash_set('warning', 'Prośba o Canva odrzucona.');
        }
        header('Location: ' . APP_URL . '/contracts/wolontariat/view.php?id=' . (int)$appr['contract_id'] . '#tab-m365-anchor');
        exit;
    }

    flash_set($decision === 'zaakceptowana' ? 'success' : 'warning',
        'Decyzja zapisana: ' . ($decision === 'zaakceptowana' ? 'Zaakceptowano' : 'Odrzucono') . '.');
    header('Location: ' . contract_url($appr['contract_type'], (int)$appr['contract_id']));
    exit;
}

// ── Tryb e-mail (token w URL) ────────────────────────────────────────────────
$token    = trim($_GET['token'] ?? '');
$decision = trim($_GET['action'] ?? '');

if (!$token) {
    http_response_code(400);
    die('<p>Brak tokenu. Użyj linku z e-maila.</p>');
}

// Sprawdź najpierw token workflow wielostopniowego
_awf_init();
$awf_dec = db_one("SELECT * FROM approval_step_decisions WHERE token=?", [$token]);

$error = '';
$done  = false;
$final_decision = '';
$contract_row   = null;
$appr           = null;
$is_awf         = false; // czy tryb wielostopniowy

if ($awf_dec) {
    // ── Tryb wielostopniowy ──────────────────────────────────────────────────
    $is_awf = true;
    $awf_request = db_one("SELECT * FROM approval_requests WHERE id=?", [$awf_dec['request_id']]);

    if ($awf_dec['status'] !== 'oczekuje') {
        $error = 'Ta decyzja została już podjęta (' . ($awf_dec['status'] === 'zaakceptowana' ? 'Zaakceptowano' : 'Odrzucono') . ').';
    } elseif (!$awf_request || $awf_request['status'] !== 'oczekuje') {
        $error = 'Ten wniosek nie jest już aktywny (status: ' . ($awf_request['status'] ?? '—') . ').';
    } elseif (strtotime($awf_dec['token_expires']) < time()) {
        $error = 'Link akceptacji wygasł (' . date_pl($awf_dec['token_expires']) . ').';
    }

    if (!$error && $awf_request) {
        $table = table_for_type($awf_request['contract_type']);
        $contract_row = db_one("SELECT * FROM {$table} WHERE id=?", [$awf_request['contract_id']]);
        if (!$contract_row) $error = 'Nie znaleziono umowy.';
    }

    if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $final_decision = in_array($_POST['decision'] ?? '', ['zaakceptowana','odrzucona'])
            ? $_POST['decision']
            : '';
        $note = trim($_POST['decision_note'] ?? '');
        if ($final_decision) {
            if ($final_decision === 'odrzucona' && $note === '') {
                $error = 'Przy odrzuceniu komentarz jest wymagany.';
            } else {
                awf_decide($awf_dec['id'], $final_decision, $note, $awf_dec['approver_id'], true);
                $done = true;
            }
        }
    }
} else {
    // ── Fallback: stary system ───────────────────────────────────────────────
    $appr = db_one("SELECT * FROM contract_approvals WHERE token=?", [$token]);

    if (!$appr) {
        $error = 'Nieprawidłowy link akceptacji.';
    } elseif ($appr['status'] !== 'oczekuje') {
        $error = 'Ten wniosek został już rozpatrzony: ' . (APPROVAL_STATUSES[$appr['status']]['label'] ?? $appr['status']);
    } elseif (strtotime($appr['token_expires']) < time()) {
        $error = 'Link akceptacji wygasł (' . date_pl($appr['token_expires']) . ').';
    }

    if (!$error) {
        $table = table_for_type($appr['contract_type']);
        $contract_row = db_one("SELECT * FROM {$table} WHERE id=?", [$appr['contract_id']]);
        if (!$contract_row) $error = 'Nie znaleziono umowy.';
    }

    if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $final_decision = in_array($_POST['decision'] ?? '', ['zaakceptowana','odrzucona'])
            ? $_POST['decision']
            : '';
        $note = trim($_POST['decision_note'] ?? '');
        if ($final_decision) {
            if ($final_decision === 'odrzucona' && $note === '') {
                $error = 'Przy odrzuceniu komentarz jest wymagany.';
            } else {
                decide_approval($appr['id'], $final_decision, $note, null, true);
                $done = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Akceptacja umowy — <?= h(ORG_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>body{background:#f0f4f8}.card{max-width:560px;margin:80px auto}</style>
</head>
<body>
<div class="card shadow-sm">
<div class="card-header fw-bold">
  <i class="bi bi-check2-circle"></i> Akceptacja umowy — <?= h(ORG_NAME) ?>
</div>
<div class="card-body p-4">

<?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-primary">Zaloguj się do systemu</a>

<?php elseif ($done): ?>
  <?php
    $back_type = $is_awf ? ($awf_request['contract_type'] ?? '') : ($appr['contract_type'] ?? '');
    $back_id   = $is_awf ? ($awf_request['contract_id']   ?? 0)  : ($appr['contract_id']   ?? 0);
    $back_url  = $back_type && $back_id ? APP_URL . '/contracts/' . $back_type . '/view.php?id=' . $back_id : '';
  ?>
  <div class="alert alert-<?= $final_decision === 'zaakceptowana' ? 'success' : 'warning' ?> text-center">
    <h5><?= $final_decision === 'zaakceptowana' ? '✓ Zaakceptowano' : '✗ Odrzucono' ?></h5>
    <p class="mb-0">Decyzja została zapisana. Zgłaszający otrzyma powiadomienie e-mail.</p>
  </div>
  <?php if ($back_url): ?>
  <div class="text-center mt-3">
    <a href="<?= h($back_url) ?>" class="btn btn-outline-primary">
      <i class="bi bi-arrow-left"></i> Powrót do umowy
    </a>
  </div>
  <?php endif; ?>

<?php else: ?>
  <p>Witaj,</p>
  <p>Proszę o podjęcie decyzji w sprawie poniższej umowy:</p>
  <table class="table table-sm table-bordered mb-3">
    <?php if ($is_awf): ?>
    <tr><th>Etap</th><td><strong><?= h($awf_dec['step_name']) ?></strong></td></tr>
    <tr><th>Typ</th><td><?= h(CONTRACT_TYPES[$awf_request['contract_type']] ?? $awf_request['contract_type']) ?></td></tr>
    <?php else: ?>
    <tr><th>Typ</th><td><?= h(CONTRACT_TYPES[$appr['contract_type']] ?? $appr['contract_type']) ?></td></tr>
    <?php endif; ?>
    <tr><th>Numer</th><td><strong><?= h($contract_row['numer_umowy']) ?></strong></td></tr>
    <?php if (!empty($contract_row['nr_rejestru'])): ?>
    <tr><th>Nr rejestru</th><td class="font-monospace"><?= h($contract_row['nr_rejestru']) ?></td></tr>
    <?php endif; ?>
    <tr><th>Przedmiot</th><td><?= h($contract_row['przedmiot_zlecenia'] ?? $contract_row['przedmiot_uslugi'] ?? $contract_row['opis_dziela'] ?? $contract_row['przedmiot_umowy'] ?? '—') ?></td></tr>
    <tr><th>Data zawarcia</th><td><?= date_pl($contract_row['data_zawarcia']) ?></td></tr>
    <tr><th>Wnioskowano</th><td><?= $is_awf ? date_pl($awf_request['requested_at']) : date_pl($appr['requested_at']) ?></td></tr>
    <tr><th>Link ważny do</th><td><?= $is_awf ? date_pl($awf_dec['token_expires']) : date_pl($appr['token_expires']) ?></td></tr>
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
      <button type="button" class="btn btn-success" onclick="tokenSubmit('zaakceptowana')">
        <i class="bi bi-check-lg"></i> Akceptuję
      </button>
      <button type="button" class="btn btn-danger" onclick="tokenSubmit('odrzucona')">
        <i class="bi bi-x-lg"></i> Odrzuć
      </button>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-secondary ms-auto">Zaloguj do systemu</a>
    </div>
  </form>
  <script>
  function tokenSubmit(d) {
    var note = document.getElementById('tokenNote');
    if (d === 'odrzucona' && !note.value.trim()) {
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

</div>
</div>
</body>
</html>
