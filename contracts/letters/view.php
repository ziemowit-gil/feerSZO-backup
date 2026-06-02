<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo.php';

require_login();

$letter_id = intval($_GET['id'] ?? 0);
$letter    = get_letter($letter_id);
if (!$letter) { http_response_code(404); die('Nie znaleziono pisma.'); }

$type     = $letter['contract_type'];
$cid      = $letter['contract_id'];
$TABLE    = table_for_type($type);
$row      = db_one("SELECT * FROM {$TABLE} WHERE id=?", [$cid]);

$org         = defined('ORG_NAME') ? ORG_NAME : 'Nasza Firma';
$verify_code = strtoupper(substr(md5($letter['id'] . $letter['created_at']), 0, 12));
$issue_date  = date('d.m.Y', strtotime($letter['created_at']));

// Rejestracja odbioru w systemie
if (empty($letter['data_odbioru']) && $letter['kierunek'] === 'przychodzące') {
    db()->prepare("UPDATE contract_letters SET data_odbioru = NOW() WHERE id = ?")->execute([$letter_id]);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($letter['tytul']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap');

        body {
            background: #f8fafc;
            font-family: 'Inter', sans-serif;
            color: #334155;
            margin: 0;
        }

        /* Górny pasek nawigacji - ukryty w druku */
        .top-bar {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        /* Kontener strony A4 */
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 25mm 20mm;
            margin: 30px auto;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
        }

        /* Prosty nagłówek systemowy */
        .meta-info {
            font-size: 8.5pt;
            color: #94a3b8;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 10px;
            margin-bottom: 40px;
            display: flex;
            justify-content: space-between;
        }

        /* Sekcja adresowa */
        .header-section {
            margin-bottom: 50px;
        }

        .sender-name {
            font-weight: 700;
            color: #0f172a;
            font-size: 11pt;
        }

        .recipient-box {
            margin-top: 30px;
            margin-left: auto;
            max-width: 320px;
        }

        .recipient-label {
            font-size: 8pt;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        /* Treść dokumentu */
        .doc-title {
            font-size: 17pt;
            font-weight: 700;
            color: #0f172a;
            margin: 40px 0 25px 0;
            line-height: 1.2;
        }

        .doc-body {
            font-size: 11.5pt;
            line-height: 1.7;
            text-align: justify;
            color: #1e293b;
        }

        /* Podpis - przekazano przez użytkownika */
        .signature-area {
            margin-top: 60px;
            text-align: right;
        }

        .sig-label {
            font-size: 9pt;
            color: #64748b;
        }

        .sig-user {
            font-weight: 700;
            font-size: 13pt;
            color: #0f172a;
            margin: 4px 0;
        }

        /* Stopka */
        .page-footer {
            position: absolute;
            bottom: 15mm;
            left: 20mm;
            right: 20mm;
            font-size: 8pt;
            color: #cbd5e1;
            border-top: 1px solid #f8fafc;
            padding-top: 10px;
        }

        @media print {
            body { background: white; }
            .top-bar { display: none !important; }
            .page {
                margin: 0;
                box-shadow: none;
                width: 100%;
                padding: 15mm;
            }
        }
    </style>
</head>
<body>

<nav class="top-bar d-print-none">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary">
            ← Wróć
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-primary px-4">
            Drukuj lub zapisz jako PDF
        </button>
    </div>
</nav>

<div class="page">

    <div class="meta-info">
        <div>Dokument przekazany systemowo</div>
        <div>ID: <?= $verify_code ?> | Dnia: <?= $issue_date ?></div>
    </div>

    <div class="header-section">
        <div class="d-flex justify-content-between align-items-start">
            <div class="sender-name">
                <?= nl2br(h($letter['nadawca'] ?: $org)) ?>
            </div>
            <div class="text-end text-muted small">
                <?= h(org_setting('org_miejscowosc')) ?>, <?= date_pl($letter['data_pisma']) ?>
            </div>
        </div>

        <div class="recipient-box">
            <div class="recipient-label">Otrzymuje:</div>
            <div class="fw-bold text-dark fs-5">
                <?= nl2br(h($letter['odbiorca'])) ?>
            </div>
        </div>
    </div>

    <div class="doc-title">
        <?= h($letter['tytul']) ?>
    </div>

    <div class="doc-body">
        <?= $letter['tresc'] // Wyświetla sformatowany tekst z edytora ?>
    </div>

    <div class="signature-area">
        <div class="sig-label">Dokument przekazał(a):</div>
        <div class="sig-user"><?= h($letter['created_by_name']) ?></div>
        <div class="sig-label">Wysłano elektronicznie z systemu <?= h($org) ?></div>
    </div>

    <div class="page-footer d-flex justify-content-between">
        <div>Numer umowy: <?= h($row['numer_umowy']) ?></div>
        <div>Kod weryfikacyjny: <?= $verify_code ?></div>
    </div>
</div>


<?php
// ── Sekcja Postivo.pl (widoczna tylko gdy włączone, ukryta w druku) ───────────
if (can_edit() && postivo_setting('postivo_enabled') === '1'):
    $postivo_job_id  = $letter['postivo_job_id']       ?? '';
    $postivo_status  = $letter['postivo_status']        ?? '';
    $postivo_sent_at = $letter['postivo_sent_at']       ?? '';
    $postivo_adres   = $letter['postivo_adres']         ?? '';
    $postivo_kp      = $letter['postivo_kod_pocztowy']  ?? '';
    $postivo_miasto  = $letter['postivo_miasto']        ?? '';
    $has_pdf         = !empty($letter['plik']);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<div class="d-print-none" style="width:210mm;margin:0 auto 40px auto">
  <div class="card shadow-sm border-0" style="border-radius:.6rem;overflow:hidden">
    <div class="card-header d-flex align-items-center gap-2 fw-semibold" style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
      <i class="bi bi-mailbox text-primary"></i>
      Wysyłka pocztą (Postivo.pl)
    </div>
    <div class="card-body">

    <?php if ($postivo_job_id): ?>
      <!-- ── Status istniejącego zlecenia ────────────────────────────────── -->
      <div class="mb-3">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="text-muted small">Status:</span>
          <?= postivo_status_badge($postivo_status) ?>
        </div>
        <?php if ($postivo_sent_at): ?>
        <div class="small text-muted mb-1">
          <i class="bi bi-clock"></i>
          Nadano: <?= h(date('d.m.Y H:i', strtotime($postivo_sent_at))) ?>
        </div>
        <?php endif; ?>
        <?php if ($postivo_adres || $postivo_miasto): ?>
        <div class="small text-muted mb-1">
          <i class="bi bi-geo-alt"></i>
          <?= h($postivo_adres) ?><?= ($postivo_adres && $postivo_miasto) ? ', ' : '' ?><?= h($postivo_kp) ?> <?= h($postivo_miasto) ?>
        </div>
        <?php endif; ?>
        <div class="small text-muted">
          <i class="bi bi-hash"></i> ID zlecenia Postivo: <code><?= h($postivo_job_id) ?></code>
        </div>
      </div>

      <!-- Przycisk odświeżenia statusu -->
      <form method="post" action="<?= APP_URL ?>/contracts/letters/postivo_action.php">
        <input type="hidden" name="_csrf"          value="<?= csrf_token() ?>">
        <input type="hidden" name="letter_id"      value="<?= $letter_id ?>">
        <input type="hidden" name="postivo_action" value="refresh_status">
        <button type="submit" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-clockwise"></i> Odśwież status
        </button>
      </form>

    <?php elseif ($has_pdf): ?>
      <!-- ── Formularz wysyłki ──────────────────────────────────────────── -->
      <form method="post" action="<?= APP_URL ?>/contracts/letters/postivo_action.php">
        <input type="hidden" name="_csrf"          value="<?= csrf_token() ?>">
        <input type="hidden" name="letter_id"      value="<?= $letter_id ?>">
        <input type="hidden" name="postivo_action" value="send">

        <div class="row g-2 mb-3">
          <div class="col-12">
            <label class="form-label form-label-sm fw-semibold mb-1">
              Imię i nazwisko / nazwa odbiorcy <span class="text-danger">*</span>
            </label>
            <input type="text" name="recipient_name" class="form-control form-control-sm"
                   value="<?= h($letter['odbiorca'] ?? '') ?>" required
                   placeholder="Imię Nazwisko lub Nazwa Firmy">
          </div>

          <div class="col-12">
            <label class="form-label form-label-sm fw-semibold mb-1">
              Adres (linia 1) <span class="text-danger">*</span>
            </label>
            <input type="text" name="address_line1" class="form-control form-control-sm"
                   value="<?= h($row['adres'] ?? '') ?>" required
                   placeholder="ul. Przykładowa 1/2">
          </div>

          <div class="col-12">
            <label class="form-label form-label-sm fw-semibold mb-1">
              Adres (linia 2)
              <span class="text-muted fw-normal">(opcjonalnie)</span>
            </label>
            <input type="text" name="address_line2" class="form-control form-control-sm"
                   placeholder="np. m. 5, piętro 3">
          </div>

          <div class="col-4">
            <label class="form-label form-label-sm fw-semibold mb-1">
              Kod pocztowy <span class="text-danger">*</span>
            </label>
            <input type="text" name="postcode" class="form-control form-control-sm font-monospace"
                   required placeholder="00-001" pattern="\d{2}-\d{3}"
                   title="Format: XX-XXX">
          </div>

          <div class="col-8">
            <label class="form-label form-label-sm fw-semibold mb-1">
              Miasto <span class="text-danger">*</span>
            </label>
            <input type="text" name="city" class="form-control form-control-sm"
                   required placeholder="Warszawa">
          </div>

          <div class="col-6">
            <label class="form-label form-label-sm fw-semibold mb-1">Kraj</label>
            <select name="country" class="form-select form-select-sm">
              <option value="PL" selected>PL — Polska</option>
              <!-- TODO: Dodaj inne kraje jeśli Postivo.pl obsługuje wysyłkę zagraniczną -->
            </select>
          </div>

          <div class="col-6 d-flex align-items-end">
            <div class="form-text text-muted mb-1">
              <!-- TODO: Zastąp szacowaną ceną z API gdy dostępne -->
              <i class="bi bi-tag"></i> Koszt: ~wyliczany po wysłaniu
            </div>
          </div>
        </div>

        <div class="alert alert-warning py-2 small mb-3">
          <i class="bi bi-exclamation-triangle"></i>
          Wysyłka jest <strong>bezpowrotna</strong> — sprawdź adres przed zleceniem.
          Za wysyłkę zostanie pobrana opłata z konta Postivo.pl.
        </div>

        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-send"></i> Wyślij listem poleconym przez Postivo.pl
        </button>
      </form>

    <?php else: ?>
      <!-- ── Brak pliku PDF ────────────────────────────────────────────── -->
      <div class="alert alert-info py-2 small mb-0">
        <i class="bi bi-info-circle"></i>
        Aby wysłać listem, najpierw załącz plik PDF do pisma
        (edytuj pismo i dodaj załącznik w polu <em>Plik / załącznik</em>).
      </div>

    <?php endif; ?>

    </div><!-- /card-body -->
  </div><!-- /card -->
</div>
<?php endif; /* postivo_enabled */ ?>

</body>
</html>
