<?php
/**
 * timesheets/checkin.php — strona mobilnego check-in/check-out przez QR kod.
 */
define('SKIP_CONSENT_CHECK', true);
require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/timesheets.php';
require_once dirname(__DIR__) . '/includes/qr.php';

require_login();
$user = current_user();

// ── Weryfikacja tokenu ─────────────────────────────────────────────────────────
$token       = trim($_GET['token'] ?? '');
$contract_id = $token ? qr_verify_token($token) : null;
$contract    = null;
$error       = '';

if ($token && !$contract_id) {
    $error = 'Nieprawidłowy lub wygasły kod QR.';
} elseif ($contract_id) {
    $contract = db_one(
        "SELECT id, numer_umowy, imie_nazwisko, status, data_od, data_do
           FROM umowy_wolontariat WHERE id = ?",
        [$contract_id]
    );
    if (!$contract) {
        $error = 'Nie znaleziono umowy powiązanej z tym kodem QR.';
        $contract_id = null;
    }
}

// ── Otwarta sesja check-in ─────────────────────────────────────────────────────
$open_session = null;
if ($contract_id) {
    $open_session = db_one(
        "SELECT * FROM checkin_sessions
          WHERE contract_id = ? AND user_id = ? AND status = 'open'
          ORDER BY id DESC LIMIT 1",
        [$contract_id, $user['id']]
    );
}

// ── Obsługa POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $contract_id) {
    csrf_check();

    $action     = $_POST['action'] ?? '';
    $time_input = trim($_POST['time_input'] ?? date('H:i'));
    $date_input = trim($_POST['date_input'] ?? date('Y-m-d'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_input)) $date_input = date('Y-m-d');
    if (!preg_match('/^\d{2}:\d{2}$/', $time_input))        $time_input = date('H:i');

    if ($action === 'checkin' && !$open_session) {
        // Otwieramy nową sesję
        db_insert('checkin_sessions', [
            'contract_id' => $contract_id,
            'user_id'     => $user['id'],
            'date'        => $date_input,
            'time_start'  => $time_input,
            'status'      => 'open',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Zalogowano o ' . $time_input . '. Miłej pracy!');
        header('Location: ' . APP_URL . '/timesheets/checkin.php?token=' . urlencode($token));
        exit;
    }

    if ($action === 'checkout' && $open_session) {
        $start_dt = strtotime($open_session['date'] . ' ' . $open_session['time_start']);
        $end_dt   = strtotime($date_input . ' ' . $time_input);
        if ($end_dt <= $start_dt) {
            flash_set('error', 'Czas zakończenia musi być późniejszy niż czas rozpoczęcia.');
        } else {
            $minutes     = round(($end_dt - $start_dt) / 60);
            $hours_total = round($minutes / 60, 2);

            // Zamykamy sesję check-in
            db_update('checkin_sessions', [
                'time_end'    => $time_input,
                'date_end'    => $date_input,
                'hours_total' => $hours_total,
                'status'      => 'closed',
            ], $open_session['id']);

            // Zapisujemy wpis do timesheets (rok/miesiac z daty startu)
            $rok    = (int)date('Y', $start_dt);
            $miesiac = (int)date('n', $start_dt);

            // Szukamy czy już jest timesheet na ten miesiąc, jeśli nie — tworzymy
            $ts = db_one(
                "SELECT id, godziny FROM timesheets WHERE contract_id=? AND rok=? AND miesiac=? AND status IN ('szkic','złożone')",
                [$contract_id, $rok, $miesiac]
            );

            $opis_entry = date('d.m', $start_dt) . ' ' . $open_session['time_start'] . '–' . $time_input
                        . ' (' . number_format($hours_total, 2, ',', '') . ' h)';

            if ($ts) {
                $new_godziny = round($ts['godziny'] + $hours_total, 2);
                $existing_opis = db_one("SELECT opis FROM timesheets WHERE id=?", [$ts['id']])['opis'] ?? '';
                $new_opis = trim($existing_opis . "\n" . $opis_entry, "\n");
                db_update('timesheets', ['godziny' => $new_godziny, 'opis' => $new_opis], $ts['id']);
            } else {
                db_insert('timesheets', [
                    'contract_id' => $contract_id,
                    'user_id'     => $user['id'],
                    'rok'         => $rok,
                    'miesiac'     => $miesiac,
                    'godziny'     => $hours_total,
                    'opis'        => $opis_entry,
                    'status'      => 'szkic',
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            }

            flash_set('success', 'Wylogowano o ' . $time_input . '. Przepracowano: ' . number_format($hours_total, 2, ',', '') . ' h.');
            header('Location: ' . APP_URL . '/timesheets/checkin.php?token=' . urlencode($token));
            exit;
        }
    }
}

// ── Odśwież stan po redirect ───────────────────────────────────────────────────
if ($contract_id) {
    $open_session = db_one(
        "SELECT * FROM checkin_sessions
          WHERE contract_id = ? AND user_id = ? AND status = 'open'
          ORDER BY id DESC LIMIT 1",
        [$contract_id, $user['id']]
    );
}

// ── Historia sesji (ostatnie 5) ────────────────────────────────────────────────
$history = [];
if ($contract_id) {
    $history = db_all(
        "SELECT * FROM checkin_sessions
          WHERE contract_id = ? AND user_id = ? AND status = 'closed'
          ORDER BY id DESC LIMIT 5",
        [$contract_id, $user['id']]
    );
}

$PAGE_TITLE = 'Check-in QR';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Check-in <?= $contract ? h($contract['imie_nazwisko']) : 'QR' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f8f9fa; }
        .checkin-card { max-width: 480px; margin: 2rem auto; }
        .btn-checkin { font-size: 1.25rem; padding: .85rem 2rem; border-radius: 1rem; }
        .status-badge { font-size: 1rem; }
        .history-item { font-size: .875rem; }
    </style>
</head>
<body>
<div class="container py-3">
    <div class="checkin-card">

        <div class="d-flex align-items-center mb-3">
            <a href="<?= h(APP_URL) ?>/index.php" class="btn btn-outline-secondary btn-sm me-2">
                <i class="bi bi-house"></i>
            </a>
            <h5 class="mb-0"><i class="bi bi-qr-code-scan me-2"></i>Check-in QR</h5>
        </div>

        <?= flash_html() ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div>

        <?php elseif (!$contract_id): ?>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle me-2"></i>
                Brak kodu tokenu. Zeskanuj kod QR przypisany do Twojej umowy wolontariackiej.
            </div>

        <?php else: ?>
            <!-- Karta umowy -->
            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1">
                            <div class="fw-bold fs-5"><?= h($contract['imie_nazwisko']) ?></div>
                            <div class="text-muted small">Umowa: <?= h($contract['numer_umowy'] ?? '—') ?></div>
                            <?php if ($contract['data_od'] || $contract['data_do']): ?>
                            <div class="text-muted small">
                                <?= h($contract['data_od'] ?? '?') ?> – <?= h($contract['data_do'] ?? '?') ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-<?= $contract['status'] === 'podpisana' ? 'success' : 'primary' ?> ms-2">
                            <?= h($contract['status']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Status sesji -->
            <?php if ($open_session): ?>
            <div class="alert alert-success d-flex align-items-center mb-3">
                <i class="bi bi-play-circle-fill fs-4 me-3"></i>
                <div>
                    <div class="fw-semibold">Sesja otwarta</div>
                    <div class="status-badge">od <?= h($open_session['time_start']) ?> (<?= h($open_session['date']) ?>)</div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary d-flex align-items-center mb-3">
                <i class="bi bi-stop-circle fs-4 me-3"></i>
                <div>
                    <div class="fw-semibold">Brak aktywnej sesji</div>
                    <div class="status-badge text-muted">Użyj przycisku poniżej, aby się zalogować</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Formularz check-in / check-out -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <form method="post" action="">
                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="<?= $open_session ? 'checkout' : 'checkin' ?>">

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Data</label>
                                <input type="date" name="date_input" class="form-control form-control-lg"
                                       value="<?= h(date('Y-m-d')) ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Godzina</label>
                                <input type="time" name="time_input" class="form-control form-control-lg"
                                       value="<?= h(date('H:i')) ?>" required>
                            </div>
                        </div>

                        <?php if ($open_session): ?>
                        <button type="submit" class="btn btn-danger btn-checkin w-100">
                            <i class="bi bi-stop-circle me-2"></i>Zakończ pracę (Check-out)
                        </button>
                        <?php else: ?>
                        <button type="submit" class="btn btn-success btn-checkin w-100">
                            <i class="bi bi-play-circle me-2"></i>Rozpocznij pracę (Check-in)
                        </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Historia ostatnich sesji -->
            <?php if ($history): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold small py-2">
                    <i class="bi bi-clock-history me-1"></i> Ostatnie sesje
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($history as $h_row): ?>
                    <li class="list-group-item history-item py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-calendar3 me-1 text-muted"></i><?= h($h_row['date']) ?>
                                &nbsp;<?= h($h_row['time_start']) ?>–<?= h($h_row['time_end'] ?? '?') ?>
                            </span>
                            <span class="badge bg-light text-dark border">
                                <?= number_format((float)($h_row['hours_total'] ?? 0), 2, ',', '') ?> h
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
