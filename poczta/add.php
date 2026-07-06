<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';

require_login();
require_module_enabled('poczta_enabled', 'Moduł Poczty');
require_role('admin');

$PAGE_TITLE = 'Dodaj skrzynkę';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $mailbox      = trim(strtolower($_POST['mailbox'] ?? ''));
    $display_name = trim($_POST['display_name'] ?? '');

    if (!$mailbox || !filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
        $error = 'Podaj prawidłowy adres e-mail skrzynki.';
    } else {
        $exists = db_one("SELECT id FROM poczta_mailboxes WHERE mailbox=?", [$mailbox]);
        if ($exists) {
            $error = 'Ta skrzynka jest już na liście.';
        } else {
            try {
                $graph = new M365Graph();
                $found = $graph->find_by_email_or_upn($mailbox);
                if (empty($found['id'])) {
                    $error = "Nie znaleziono konta Microsoft 365 dla adresu {$mailbox}. Sprawdź adres i konfigurację M365.";
                } else {
                    $id = db_insert('poczta_mailboxes', [
                        'mailbox'      => $mailbox,
                        'ms_user_id'   => $found['id'],
                        'display_name' => $display_name ?: ($found['displayName'] ?? ''),
                        'enabled'      => 1,
                        'created_by'   => (int)(current_user()['id'] ?? 0),
                    ]);
                    flash_set('success', 'Skrzynka dodana do listy skanowania.');
                    header('Location: ' . APP_URL . '/poczta/index.php'); exit;
                }
            } catch (\Throwable $e) {
                $error = 'Błąd sprawdzania konta M365: ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/includes/header_poczta.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-2" style="color:var(--pc-blue)"></i>Dodaj skrzynkę</h4>
    <a href="<?= APP_URL ?>/poczta/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Wróć do listy</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0" style="max-width:560px">
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Adres e-mail skrzynki (UPN)</label>
                <input type="email" name="mailbox" class="form-control" required
                       placeholder="fundacja@feer.org.pl" value="<?= h($_POST['mailbox'] ?? '') ?>">
                <div class="form-text">Musi istnieć jako konto Microsoft 365 w tenancie organizacji.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Nazwa wyświetlana (opcjonalnie)</label>
                <input type="text" name="display_name" class="form-control"
                       placeholder="np. Skrzynka ogólna fundacji" value="<?= h($_POST['display_name'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="background:var(--pc-blue);border-color:var(--pc-blue)">
                <i class="bi bi-check-lg me-1"></i>Dodaj i zweryfikuj konto M365
            </button>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
