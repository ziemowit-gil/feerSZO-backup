<?php
/**
 * weryfikuj/index.php
 * Publiczna weryfikacja autentyczności zaświadczenia (dostępna pod /weryfikuj).
 * Osoba z zewnątrz skanuje kod QR z dokumentu (lub wpisuje kod ręcznie)
 * i sprawdza, czy zaświadczenie zostało faktycznie wydane przez organizację.
 *
 * NIE wymaga logowania. Nie ujawnia danych wrażliwych ponad to, co i tak
 * widnieje na trzymanym w ręku dokumencie. Kod jest losowy i niezgadywalny.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

header('X-Robots-Tag: noindex, nofollow');

$code   = trim($_GET['kod'] ?? $_POST['kod'] ?? '');
$req    = $code !== '' ? get_certificate_by_verify_code($code) : null;
$result = null; // 'valid' | 'pending' | 'rejected' | 'notfound'

$person = ''; $okres = ''; $typ_label = 'Zaświadczenie';
if ($code !== '') {
    if (!$req) {
        $result = 'notfound';
    } elseif (in_array($req['status'], ['wydane', 'gotowe'], true)) {
        $result = 'valid';
    } elseif ($req['status'] === 'esign_oczekuje') {
        $result = 'pending';
    } else {
        $result = 'rejected';
    }

    if ($req && $result === 'valid') {
        $t     = table_for_type($req['contract_type']);
        $row   = $t ? db_one("SELECT * FROM {$t} WHERE id=?", [$req['contract_id']]) : null;
        $person = $row ? get_contract_person_name($req['contract_type'], $row) : '';
        if ($row && (!empty($row['data_rozpoczecia']) || !empty($row['data_zakonczenia']))) {
            $okres = trim((isset($row['data_rozpoczecia']) ? date_pl($row['data_rozpoczecia']) : '')
                   . ' – ' . (isset($row['data_zakonczenia']) ? date_pl($row['data_zakonczenia']) : ''), ' –');
        }
        $typ_label = (defined('CONTRACT_TYPES') && isset(CONTRACT_TYPES[$req['contract_type']]))
            ? CONTRACT_TYPES[$req['contract_type']] : 'Zaświadczenie o wolontariacie';
    }
}

$org = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
$org_city = org_setting('org_miejscowosc') ?: '';
$self = rtrim(APP_URL, '/') . '/weryfikuj/';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Weryfikacja zaświadczenia — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background:#f1f5f9; }
  .verify-wrap { max-width:560px; margin:0 auto; padding:2.5rem 1rem 3rem; }
  .verify-card { border:0; border-radius:1rem; box-shadow:0 8px 40px rgba(15,23,42,.10); overflow:hidden; }
  .verify-head { background:#1e3a5f; color:#fff; padding:1.5rem; text-align:center; }
  .result-banner { padding:1.5rem; text-align:center; }
  .result-icon { font-size:3rem; line-height:1; }
  .kv { display:flex; justify-content:space-between; gap:1rem; padding:.55rem 0; border-bottom:1px solid #eef2f6; font-size:.92rem; }
  .kv:last-child { border-bottom:0; }
  .kv .k { color:#64748b; }
  .kv .v { font-weight:600; text-align:right; }
  code.codebox { font-size:1.05rem; letter-spacing:.08em; }
</style>
</head>
<body>
<div class="verify-wrap">
  <div class="card verify-card">
    <div class="verify-head">
      <i class="bi bi-patch-check-fill" style="font-size:2rem"></i>
      <div class="fw-bold mt-2" style="font-size:1.05rem">Weryfikacja autentyczności zaświadczenia</div>
      <div class="small opacity-75"><?= h($org) ?></div>
    </div>

    <?php if ($result === 'valid'): ?>
    <div class="result-banner bg-success-subtle text-success-emphasis">
      <div class="result-icon"><i class="bi bi-check-circle-fill"></i></div>
      <div class="fw-bold mt-2">Zaświadczenie autentyczne</div>
      <div class="small">Dokument o tym kodzie został wydany przez organizację.</div>
    </div>
    <div class="card-body">
      <div class="kv"><span class="k">Wydane przez</span><span class="v"><?= h($org) ?></span></div>
      <?php if (!empty($req['cert_number'])): ?>
      <div class="kv"><span class="k">Numer dokumentu</span><span class="v"><?= h($req['cert_number']) ?></span></div>
      <?php endif; ?>
      <div class="kv"><span class="k">Rodzaj</span><span class="v"><?= h($typ_label) ?></span></div>
      <?php if ($person): ?>
      <div class="kv"><span class="k">Dotyczy</span><span class="v"><?= h($person) ?></span></div>
      <?php endif; ?>
      <?php if ($okres): ?>
      <div class="kv"><span class="k">Okres</span><span class="v"><?= h($okres) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($req['issued_at'])): ?>
      <div class="kv"><span class="k">Data wydania</span><span class="v"><?= h(date_pl($req['issued_at'])) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($req['issued_by_name'])): ?>
      <div class="kv"><span class="k">Wystawił(a)</span><span class="v"><?= h($req['issued_by_name']) ?></span></div>
      <?php endif; ?>
      <div class="kv"><span class="k">Kod weryfikacyjny</span><span class="v"><code class="codebox"><?= h(strtoupper($code)) ?></code></span></div>
    </div>

    <?php elseif ($result === 'pending'): ?>
    <div class="result-banner bg-warning-subtle text-warning-emphasis">
      <div class="result-icon"><i class="bi bi-hourglass-split"></i></div>
      <div class="fw-bold mt-2">Dokument w trakcie wydawania</div>
      <div class="small">Zaświadczenie oczekuje na podpis elektroniczny i nie zostało jeszcze ostatecznie wydane.</div>
    </div>

    <?php elseif ($result === 'rejected'): ?>
    <div class="result-banner bg-danger-subtle text-danger-emphasis">
      <div class="result-icon"><i class="bi bi-x-octagon-fill"></i></div>
      <div class="fw-bold mt-2">Dokument nieważny</div>
      <div class="small">Wniosek o to zaświadczenie nie zakończył się wydaniem dokumentu.</div>
    </div>

    <?php elseif ($result === 'notfound'): ?>
    <div class="result-banner bg-danger-subtle text-danger-emphasis">
      <div class="result-icon"><i class="bi bi-question-octagon-fill"></i></div>
      <div class="fw-bold mt-2">Nie znaleziono zaświadczenia</div>
      <div class="small">Brak dokumentu o kodzie <code><?= h(strtoupper($code)) ?></code>. Sprawdź, czy kod został wpisany poprawnie.</div>
    </div>
    <?php endif; ?>

    <div class="card-body border-top">
      <form method="get" action="<?= h($self) ?>" class="row g-2 align-items-end">
        <div class="col">
          <label class="form-label small fw-semibold mb-1">Kod weryfikacyjny z dokumentu</label>
          <input type="text" name="kod" value="<?= h(strtoupper($code)) ?>"
                 class="form-control" placeholder="np. A1B2C3D4E5" autocomplete="off"
                 maxlength="32" autofocus>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-search me-1"></i>Sprawdź
          </button>
        </div>
      </form>
      <div class="form-text mt-2">
        <i class="bi bi-info-circle me-1"></i>
        Kod znajdziesz na zaświadczeniu (przy kodzie QR). Zeskanowanie QR otwiera tę stronę automatycznie.
      </div>
    </div>
  </div>

  <p class="text-center text-muted small mt-3 mb-0">
    <?= h($org) ?><?= $org_city ? ' · ' . h($org_city) : '' ?>
  </p>
</div>
</body>
</html>
