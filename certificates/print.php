<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();

$req_id = intval($_GET['id'] ?? 0);
$req    = get_certificate_request($req_id);
if (!$req || $req['status'] !== 'wydane') {
    http_response_code(404);
    die('Zaświadczenie nie zostało znalezione lub nie zostało jeszcze wydane.');
}

$type      = $req['contract_type'];
$cid       = $req['contract_id'];
$TABLE     = table_for_type($type);
$row       = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$cid]);
$org       = defined('ORG_NAME') ? ORG_NAME : '';
$issued    = date_pl($req['issued_at']);
$typ_label = CONTRACT_TYPES[$type] ?? $type;
$has_file  = !empty($req['certificate_file']);
$has_text  = !empty($req['certificate_content']);
$file_url  = $has_file ? certificate_file_url($req['certificate_file']) : '';
$is_pdf    = $has_file && str_ends_with(strtolower($req['certificate_file']), '.pdf');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Zaświadczenie — <?= h($row['numer_umowy'] ?? '') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
@page { size: A4; margin: 2cm; }
body { font-family: 'Times New Roman', serif; font-size: 12pt; color: #000; background: #fff; }
.certificate-page { max-width: 800px; margin: 0 auto; padding: 2rem; }
.cert-header { text-align: center; margin-bottom: 2rem; border-bottom: 2px solid #000; padding-bottom: 1rem; }
.cert-org   { font-size: 16pt; font-weight: bold; }
.cert-title { font-size: 22pt; font-weight: bold; text-transform: uppercase; letter-spacing: .15em; margin: 1.5rem 0 .5rem; }
.cert-nr    { font-size: 10pt; color: #555; }
.cert-body  { white-space: pre-wrap; line-height: 1.8; font-size: 12pt; margin: 2rem 0; }
.cert-signature { margin-top: 3rem; display: flex; justify-content: flex-end; }
.cert-sign-block { text-align: center; min-width: 220px; }
.cert-sign-line { border-top: 1px solid #000; margin-bottom: .4rem; padding-top: .4rem; font-size: 10pt; }
.no-print { background: #f0f4f8; border-bottom: 1px solid #dee2e6; padding: .75rem 1.5rem; }
.pdf-embed { width: 100%; height: 85vh; border: none; display: block; }
@media print {
  .no-print  { display: none !important; }
  .certificate-page { padding: 0; }
  .pdf-embed-wrap { display: none !important; }
  body { background: #fff; }
}
</style>
</head>
<body>

<!-- Pasek akcji — tylko ekran -->
<div class="no-print d-flex justify-content-between align-items-center flex-wrap gap-2">
  <span class="fw-semibold text-muted small">
    <i class="bi bi-award"></i>
    Zaświadczenie · <?= h($typ_label) ?> <?= h($row['numer_umowy'] ?? '') ?>
    · wydane <?= h($issued) ?>
  </span>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($has_file): ?>
    <a href="<?= h($file_url) ?>" download class="btn btn-sm btn-primary">
      <i class="bi bi-download"></i> Pobierz plik
    </a>
    <?php endif; ?>
    <?php if ($has_text): ?>
    <button onclick="window.print()" class="btn btn-sm btn-outline-dark">
      <i class="bi bi-printer"></i> Drukuj tekst
    </button>
    <?php endif; ?>
    <a href="<?= h(contract_url($type, $cid)) ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left"></i> Wróć do umowy
    </a>
  </div>
</div>

<?php if ($has_file && $is_pdf): ?>
<!-- PDF wbudowany (skan / ePodpis) -->
<div class="pdf-embed-wrap no-print">
  <iframe src="<?= h($file_url) ?>" class="pdf-embed"
          title="Zaświadczenie PDF"></iframe>
</div>
<?php elseif ($has_file): ?>
<!-- Obraz (JPG/PNG) -->
<div class="no-print text-center p-4">
  <img src="<?= h($file_url) ?>" alt="Zaświadczenie" style="max-width:100%;border:1px solid #dee2e6">
</div>
<?php endif; ?>

<?php if ($has_text): ?>
<!-- Wersja tekstowa / druk -->
<div class="certificate-page <?= $has_file ? 'mt-4 border-top pt-4' : '' ?>">

  <?php if ($has_file): ?>
  <div class="no-print alert alert-secondary small">
    <i class="bi bi-info-circle"></i>
    Poniżej wersja tekstowa zaświadczenia — możesz ją wydrukować klikając „Drukuj tekst".
  </div>
  <?php endif; ?>

  <div class="cert-header">
    <div class="cert-org"><?= h($org) ?></div>
    <div class="cert-title">Zaświadczenie</div>
    <div class="cert-nr">
      Nr: <?= h($typ_label) ?> / <?= h($row['numer_umowy'] ?? '') ?> / <?= date('Y', strtotime($req['issued_at'])) ?>
    </div>
    <div style="font-size:10pt;color:#555">Data wydania: <?= h($issued) ?></div>
  </div>

  <div class="cert-body"><?= h($req['certificate_content']) ?></div>

  <div class="cert-signature">
    <div class="cert-sign-block">
      <br><br>
      <div class="cert-sign-line">Podpis osoby upoważnionej</div>
      <div style="font-size:10pt"><?= h($req['issued_by_name'] ?? '') ?></div>
    </div>
  </div>

  <hr style="margin-top:3rem;border-color:#ccc">
  <div style="font-size:9pt;color:#888;text-align:center">
    Dokument wygenerowany przez system Rejestru Umów <?= h($org) ?> · <?= h($issued) ?>
    <?php if ($req['cel']): ?> · Cel: <?= h($req['cel']) ?><?php endif; ?>
  </div>

</div>
<?php endif; ?>

</body>
</html>
