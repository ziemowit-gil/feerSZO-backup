<?php
/**
 * timesheets/qr_generate.php — generowanie arkuszy QR dla umów wolontariackich.
 */
require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/qr.php';

require_login();
if (!can_edit()) {
    flash_set('error', 'Brak uprawnień do tej strony.');
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// Pobieramy aktywne umowy wolontariackie
$contracts = db_all(
    "SELECT id, numer_umowy, imie_nazwisko, status, data_od, data_do
       FROM umowy_wolontariat
      WHERE status IN ('podpisana', 'w realizacji')
      ORDER BY imie_nazwisko ASC"
);

$PAGE_TITLE = 'Kody QR — Check-in Wolontariuszy';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($PAGE_TITLE) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .qr-card {
            text-align: center;
            padding: 1.25rem 1rem;
            border: 1px solid #dee2e6;
            border-radius: .75rem;
            background: #fff;
            break-inside: avoid;
        }
        .qr-card img {
            max-width: 180px;
            height: auto;
        }
        .qr-name {
            font-size: .95rem;
            font-weight: 600;
            margin-top: .5rem;
            word-break: break-word;
        }
        .qr-number {
            font-size: .8rem;
            color: #6c757d;
        }
        .qr-status {
            font-size: .75rem;
        }
        .qr-url {
            font-size: .65rem;
            color: #aaa;
            word-break: break-all;
            margin-top: .25rem;
        }

        @media print {
            body { background: white !important; }
            .no-print { display: none !important; }
            .container { max-width: 100% !important; padding: 0 !important; }
            .row { margin: 0 !important; }
            .qr-card {
                border: 1px solid #ccc;
                border-radius: .5rem;
                padding: 1rem .75rem;
                page-break-inside: avoid;
            }
            h1, .lead { margin-bottom: .5rem; }
            .col-sm-6.col-md-4.col-lg-3 {
                width: 25% !important;
                float: left !important;
            }
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">

    <div class="d-flex align-items-center mb-3 no-print">
        <a href="<?= h(APP_URL) ?>/index.php" class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h1 class="h4 mb-0"><i class="bi bi-qr-code me-2"></i><?= h($PAGE_TITLE) ?></h1>
            <p class="text-muted small mb-0">Aktywne umowy wolontariackie (podpisana / w realizacji)</p>
        </div>
        <button class="btn btn-primary no-print" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Drukuj arkusz QR
        </button>
    </div>

    <?= flash_html() ?>

    <?php if (empty($contracts)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Brak aktywnych umów wolontariackich (status: podpisana lub w realizacji).
        </div>
    <?php else: ?>
        <div class="mb-3 text-muted small no-print">
            Znaleziono <strong><?= count($contracts) ?></strong> aktywnych umów.
        </div>

        <div class="row g-3">
            <?php foreach ($contracts as $c):
                $checkin_url = qr_checkin_url((int)$c['id']);
                $qr_img_url  = qr_url($checkin_url, 200);
            ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="qr-card">
                    <img src="<?= h($qr_img_url) ?>"
                         alt="QR <?= h($c['imie_nazwisko']) ?>"
                         width="180" height="180"
                         loading="lazy">
                    <div class="qr-name"><?= h($c['imie_nazwisko']) ?></div>
                    <div class="qr-number"><?= h($c['numer_umowy'] ?? '—') ?></div>
                    <div class="qr-status mt-1">
                        <span class="badge bg-<?= $c['status'] === 'podpisana' ? 'success' : 'primary' ?>">
                            <?= h($c['status']) ?>
                        </span>
                        <?php if ($c['data_od'] || $c['data_do']): ?>
                        <span class="text-muted ms-1"><?= h($c['data_od'] ?? '?') ?>–<?= h($c['data_do'] ?? '?') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="qr-url"><?= h($checkin_url) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-4 text-muted small no-print">
            <i class="bi bi-info-circle me-1"></i>
            Każdy kod QR jest unikalnie powiązany z umową i wymaga zalogowania w portalu.
            Wydrukuj ten arkusz i umieść kody w miejscu pracy wolontariusza.
        </div>
    <?php endif; ?>

</div>
</body>
</html>
