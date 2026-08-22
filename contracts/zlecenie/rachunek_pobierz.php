<?php
/**
 * contracts/zlecenie/rachunek_pobierz.php
 * Udostępnianie rachunku z rejestru „Rachunki” umowy zlecenie.
 *
 * Tryby:
 *   ?token=XXX            — publiczna strona dla zleceniobiorcy (bez logowania);
 *   ?token=XXX&plik=1     — pobranie pliku (odnotowywane: data + licznik + status „pobrany”);
 *   POST ?token=XXX       — wgranie skanu PODPISANEGO rachunku przez zleceniobiorcę;
 *   ?id=N                 — pobranie pliku przez zalogowanego pracownika (bez zmiany statusu);
 *   ?id=N&podpisany=1     — pobranie skanu podpisanego rachunku przez pracownika.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/zlecenie_rachunki.php';

/** Wysyła plik rachunku (oryginalny lub podpisany) do przeglądarki i kończy skrypt. */
function _rach_stream(array $rach, bool $inline = false, string $col = 'plik'): void {
    $abs = rachunek_file_abs($rach[$col] ?? null);
    if (!$abs) { http_response_code(404); die('Plik rachunku nie jest dostępny.'); }

    $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $mime = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ][$ext] ?? 'application/octet-stream';

    $name_col = $col === 'plik_podpisany' ? 'plik_podpisany_nazwa' : 'plik_nazwa';
    $name = $rach[$name_col] ?: ('rachunek_' . (int)$rach['id'] . '.' . $ext);
    $disp = ($inline && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) ? 'inline' : 'attachment';

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($name) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

// ── Tryb wewnętrzny: zalogowany pracownik pobiera plik ────────────────────────
if (isset($_GET['id'])) {
    require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
    require_login();

    $rach = get_rachunek((int)$_GET['id']);
    if (!$rach) { http_response_code(404); die('Nie znaleziono rachunku.'); }

    $contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rach['contract_id']]);
    if (!$contract || !viewer_owns_contract('zlecenie', $contract)) {
        http_response_code(403); die('Brak dostępu do tego rachunku.');
    }
    _rach_stream($rach, !empty($_GET['inline']),
        !empty($_GET['podpisany']) ? 'plik_podpisany' : 'plik');
}

// ── Tryb publiczny: dostęp po tokenie ─────────────────────────────────────────
$token = trim($_GET['token'] ?? '');
if ($token === '') { http_response_code(400); die('Brak tokenu dostępu.'); }

$rach = get_rachunek_by_token($token);
if (!$rach) { http_response_code(404); die('Nie znaleziono rachunku lub link wygasł.'); }

$contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rach['contract_id']]);
if (!$contract) { http_response_code(404); die('Nie znaleziono umowy.'); }

if (!empty($_GET['plik'])) {
    if (empty($rach['plik'])) { http_response_code(404); die('Do tego rachunku nie dołączono pliku.'); }
    rachunek_mark_downloaded((int)$rach['id']);
    _rach_stream($rach);
}

// ── Wgranie skanu PODPISANEGO rachunku przez zleceniobiorcę (opcja 1) ─────────
$upload_ok  = !empty($_GET['ok']);
$upload_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_rach_signed'])) {
    try {
        if (($_FILES['rach_podpisany']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Nie wybrano pliku.');
        }
        $cols = rachunek_store_signed_file($_FILES['rach_podpisany']);
        rachunek_mark_signed((int)$rach['id'], $cols, $_SERVER['REMOTE_ADDR'] ?? '');
        rachunek_notify_signed((int)$rach['id']);
        try {
            require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
            log_contract_action('zlecenie', (int)$rach['contract_id'], 0, 'rachunek_signed',
                'Zleceniobiorca wgrał podpisany rachunek #' . (int)$rach['id'] . ' (' . $cols['plik_podpisany_nazwa'] . ')');
        } catch (\Throwable $e) {}
        header('Location: ' . APP_URL . '/contracts/zlecenie/rachunek_pobierz.php?token=' . urlencode($token) . '&ok=1');
        exit;
    } catch (\Throwable $e) {
        $upload_err = $e->getMessage();
    }
    $rach = get_rachunek((int)$rach['id']) ?: $rach;
}

// ── Dane organizacji ──────────────────────────────────────────────────────────
$org_name     = org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_krs      = org_setting('org_krs')         ?: '';
$org_nip      = org_setting('org_nip')         ?: '';
$org_adres    = org_setting('org_adres')       ?: '';
$org_miasto   = org_setting('org_miejscowosc') ?: '';
$org_logo_key = org_setting('org_logo')        ?: '';
$org_logo_url = $org_logo_key ? APP_URL . '/uploads/' . $org_logo_key : '';
$org_mail     = org_setting('org_email')       ?: (defined('ORG_EMAIL') ? ORG_EMAIL : '');

$numer      = (string)($contract['numer_umowy'] ?? '');
$imie_nazw  = (string)($contract['imie_nazwisko'] ?? '');
$adres_osob = trim(address_format($contract));
if ($adres_osob === '') $adres_osob = trim((string)($contract['adres'] ?? ''));
$siedziba   = trim($org_adres . ($org_miasto ? ', ' . $org_miasto : ''));

$has_file   = !empty($rach['plik']) && rachunek_file_abs($rach['plik']);
$file_url   = APP_URL . '/contracts/zlecenie/rachunek_pobierz.php?token=' . urlencode($token) . '&plik=1';

$skan_mail  = rachunek_skan_email();
$dl         = rachunek_deadlines($rach);
$is_signed  = rachunek_is_signed($rach);
$self_url   = APP_URL . '/contracts/zlecenie/rachunek_pobierz.php?token=' . urlencode($token);
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rachunek<?= $numer ? ' · ' . h($numer) : '' ?> — <?= h($org_name) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;
     color:#1e293b;font-size:15px;line-height:1.65;padding:28px 16px}
.wrap{max-width:640px;margin:0 auto}
.card{background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.12)}
.head{background:#1e293b;color:#e2e8f0;padding:16px 28px;display:flex;justify-content:space-between;
      align-items:center;gap:16px;flex-wrap:wrap}
.head .org{font-size:13px;font-weight:600;letter-spacing:.03em}
.head img{max-height:38px;max-width:120px;display:block}
.body{padding:28px}
h1{font-size:20px;font-weight:700;margin-bottom:6px}
.lead{color:#475569;font-size:14px;margin-bottom:22px}
table.data{width:100%;border-collapse:collapse;margin-bottom:22px}
table.data th{text-align:left;font-weight:600;color:#64748b;font-size:13px;
              padding:7px 12px 7px 0;width:44%;vertical-align:top}
table.data td{padding:7px 0;font-size:14px;vertical-align:top}
table.data tr+tr th,table.data tr+tr td{border-top:1px solid #f1f5f9}
.btn{display:inline-block;background:#2563eb;color:#fff;padding:12px 28px;border-radius:8px;
     text-decoration:none;font-weight:600;font-size:15px}
.btn:hover{background:#1d4ed8}
.steps{background:#f8fafc;border-left:3px solid #2563eb;border-radius:4px;padding:14px 18px;margin-top:24px;font-size:14px}
.steps ol{margin:8px 0 0 18px}
.steps li{margin-bottom:5px}
.warn{background:#fff7ed;border-left:3px solid #f59e0b;border-radius:4px;padding:12px 16px;font-size:14px}
.ok{background:#f0fdf4;border-left:3px solid #16a34a;border-radius:4px;padding:12px 16px;font-size:14px;margin-top:20px}
.opt{border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;margin-top:14px;background:#fff}
.opt-h{font-weight:700;font-size:15px;display:flex;align-items:center;gap:10px;margin-bottom:4px}
.opt-h .num{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;
            border-radius:50%;background:#2563eb;color:#fff;font-size:13px;flex:0 0 24px}
.foot{color:#94a3b8;font-size:12px;text-align:center;padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0}
a{color:#1d4ed8}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="head">
      <span class="org"><?= h($org_name) ?></span>
      <?php if ($org_logo_url): ?><img src="<?= h($org_logo_url) ?>" alt=""><?php endif; ?>
    </div>
    <div class="body">
      <h1>Rachunek do umowy zlecenie</h1>
      <p class="lead">
        Dzień dobry<?= $imie_nazw ? ', ' . h($imie_nazw) : '' ?>. Poniżej znajdziesz rachunek
        udostępniony przez <?= h($org_name) ?>.
      </p>

      <table class="data">
        <?php if ($numer): ?>
        <tr><th>Umowa</th><td><?= h($numer) ?></td></tr>
        <?php endif; ?>
        <?php if ($imie_nazw): ?>
        <tr><th>Zleceniobiorca</th><td><?= h($imie_nazw) ?></td></tr>
        <?php endif; ?>
        <?php if ($adres_osob): ?>
        <tr><th>Adres</th><td><?= h($adres_osob) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($rach['numer'])): ?>
        <tr><th>Numer rachunku</th><td><?= h($rach['numer']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($rach['data_wystawienia'])): ?>
        <tr><th>Data wystawienia</th><td><?= date_pl($rach['data_wystawienia']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($rach['okres'])): ?>
        <tr><th>Za jaki okres</th><td><?= h($rach['okres']) ?></td></tr>
        <?php endif; ?>
        <?php if ($rach['kwota_brutto'] !== null && $rach['kwota_brutto'] !== ''): ?>
        <tr><th>Kwota brutto</th><td><strong><?= money((float)$rach['kwota_brutto']) ?></strong></td></tr>
        <?php endif; ?>
        <?php if (!empty($rach['uwagi'])): ?>
        <tr><th>Uwagi</th><td><?= nl2br(h($rach['uwagi'])) ?></td></tr>
        <?php endif; ?>
      </table>

      <?php if ($has_file): ?>
      <div style="text-align:center">
        <a class="btn" href="<?= h($file_url) ?>">&#11015; Pobierz dokument</a>
        <?php if (!empty($rach['plik_nazwa'])): ?>
        <div style="color:#94a3b8;font-size:12px;margin-top:8px">
          <?= h($rach['plik_nazwa']) ?><?= $rach['plik_size'] ? ' · ' . h(rachunek_file_size_h((int)$rach['plik_size'])) : '' ?>
        </div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="warn">
        Do tego rachunku nie dołączono jeszcze pliku. Prosimy o kontakt z <?= h($org_name) ?>
        <?= $org_mail ? '(<a href="mailto:' . h($org_mail) . '">' . h($org_mail) . '</a>)' : '' ?>.
      </div>
      <?php endif; ?>

      <?php if ($upload_ok): ?>
      <div class="ok">
        <strong>&#10003; Dziękujemy — podpisany rachunek został przyjęty.</strong>
        <div style="margin-top:6px">
          Osoby prowadzące umowę zostały o tym powiadomione. Pamiętaj o dostarczeniu
          <strong>oryginału</strong> rachunku<?= $dl['oryginal'] ? ' do <strong>' . date_pl($dl['oryginal']) . '</strong>' : '' ?><?php
            ?><?= $siedziba ? ' na adres: <strong>' . h($siedziba) . '</strong>' : '' ?>.
        </div>
      </div>
      <?php elseif ($upload_err): ?>
      <div class="warn" style="margin-top:20px">
        <strong>Nie udało się wgrać pliku:</strong> <?= h($upload_err) ?>
      </div>
      <?php endif; ?>

      <div class="steps">
        <strong>Co dalej? Rachunek trzeba wydrukować, podpisać odręcznie i dostarczyć.</strong>
        <p style="margin:8px 0 0;font-size:13.5px;color:#475569">
          Wybierz jeden z dwóch sposobów — <strong>opcja 1</strong> jest szybsza i nie wymaga wysyłki skanu e-mailem.
        </p>
      </div>

      <div class="opt">
        <div class="opt-h"><span class="num">1</span> Wgraj podpisany rachunek tutaj</div>
        <p style="font-size:14px;color:#475569;margin-bottom:12px">
          Podpisz wydrukowany rachunek, zeskanuj go lub zrób wyraźne zdjęcie i wgraj plik poniżej —
          trafi bezpośrednio do systemu.
          <?php if ($dl['oryginal']): ?>
          Oryginał dostarczasz następnie w ciągu <?= ZLEC_RACHUNEK_ORYGINAL_DAYS ?> dni,
          czyli do <strong><?= date_pl($dl['oryginal']) ?></strong>.
          <?php endif; ?>
        </p>

        <?php if ($is_signed && !empty($rach['signed_at'])): ?>
        <div class="ok" style="margin:0">
          Podpisany rachunek już do nas dotarł (<?= date_pl($rach['signed_at']) ?>).
          Możesz wgrać poprawioną wersję, jeśli poprzednia była nieczytelna.
        </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" action="<?= h($self_url) ?>" style="margin-top:12px">
          <input type="hidden" name="_rach_signed" value="1">
          <label for="rachPodpisany" style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px">
            Skan lub zdjęcie podpisanego rachunku
          </label>
          <input id="rachPodpisany" type="file" name="rach_podpisany" required
                 accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                 style="display:block;width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;background:#fff">
          <div style="color:#94a3b8;font-size:12px;margin:6px 0 12px">PDF, JPG, PNG, DOC lub DOCX — do 30 MB.</div>
          <button type="submit" class="btn">&#8593; Wyślij podpisany rachunek</button>
        </form>
      </div>

      <div class="opt">
        <div class="opt-h"><span class="num">2</span> Wyślij skan e-mailem, a potem oryginał pocztą</div>
        <ol style="margin:10px 0 0 18px;font-size:14px;color:#334155">
          <li style="margin-bottom:6px">
            W ciągu <strong><?= ZLEC_RACHUNEK_SKAN_DAYS ?> dni</strong><?= $dl['skan'] ? ' (do <strong>' . date_pl($dl['skan']) . '</strong>)' : '' ?>
            prześlij <strong>skan podpisanego rachunku</strong> na adres
            <a href="mailto:<?= h($skan_mail) ?>"><?= h($skan_mail) ?></a>.
          </li>
          <li>
            W ciągu <strong><?= ZLEC_RACHUNEK_ORYGINAL_DAYS ?> dni</strong><?= $dl['oryginal'] ? ' (do <strong>' . date_pl($dl['oryginal']) . '</strong>)' : '' ?>
            dostarcz <strong>oryginał</strong> — pocztą lub osobiście<?= $siedziba ? ' na adres: <strong>' . h($siedziba) . '</strong>' : '' ?>.
          </li>
        </ol>
      </div>

      <?php if (!empty($contract['rachunek_bankowy'])): ?>
      <p style="color:#64748b;font-size:13px;margin-top:18px">
        Wynagrodzenie zostanie przekazane na rachunek bankowy wskazany w umowie.
      </p>
      <?php endif; ?>

      <p style="color:#64748b;font-size:13px;margin-top:14px">
        W razie pytań lub problemów technicznych pozostajemy do dyspozycji<?= $org_mail ? ' — <a href="mailto:' . h($org_mail) . '">' . h($org_mail) . '</a>' : '' ?>.
      </p>
    </div>
    <div class="foot">
      <?= h($org_name) ?><?= $org_krs ? ' · KRS ' . h($org_krs) : '' ?><?= $org_nip ? ' · NIP ' . h($org_nip) : '' ?>
    </div>
  </div>
</div>
</body>
</html>
