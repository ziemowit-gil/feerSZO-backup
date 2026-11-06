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

// ── Komentarz zleceniobiorcy do rachunku ──────────────────────────────────────
$comment_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_rach_comment'])) {
    $txt = trim($_POST['rach_comment'] ?? '');
    if ($txt === '') {
        $comment_err = 'Komentarz nie może być pusty.';
    } else {
        rachunek_comment_add((int)$rach['id'], $txt, 'zleceniobiorca', null,
            (string)($contract['imie_nazwisko'] ?? 'Zleceniobiorca'), $_SERVER['REMOTE_ADDR'] ?? '');
        header('Location: ' . APP_URL . '/contracts/zlecenie/rachunek_pobierz.php?token='
             . urlencode($token) . '&kom=1#komentarze');
        exit;
    }
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
// Widok: kroki dla zleceniobiorcy (pobierz → podpisz → wgraj skan → oryginał), kwota do wypłaty (netto).
$umowa_ref  = preg_match('/\d/', $numer) ? $numer : '';
$netto      = ($rach['kwota_netto'] ?? null) !== null && $rach['kwota_netto'] !== '' ? (float)$rach['kwota_netto'] : null;
$brutto     = $rach['kwota_brutto'] !== null && $rach['kwota_brutto'] !== '' ? (float)$rach['kwota_brutto'] : null;
$nrb_digits = preg_replace('/\D/', '', (string)($contract['rachunek_bankowy'] ?? ''));
$kroki = [
    ['Pobierz',      !empty($rach['downloaded_at'])],
    ['Podpisz',      $is_signed],
    ['Wgraj skan',   $is_signed],
    ['Oryginał',     !empty($rach['oryginal_at'])],
];
$krok_teraz = 0;
foreach ($kroki as $i => [$_l, $_done]) { if (!$_done) { $krok_teraz = $i; break; } $krok_teraz = $i + 1; }
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Rachunek<?= !empty($rach['numer']) ? ' ' . h($rach['numer']) : '' ?> — <?= h($org_name) ?></title>
<style>
/* Metro UI: płasko, bez zaokrągleń, kafle, Segoe UI Light, akcent #0072c6 (kontrast 5:1 z bielą) */
*{box-sizing:border-box;margin:0;padding:0;border-radius:0}
body{font-family:'Segoe UI','Segoe UI Web',-apple-system,BlinkMacSystemFont,Roboto,sans-serif;background:#f3f3f3;color:#1d1d1d;font-size:15px;line-height:1.55}
a{color:#005a9e}
:focus-visible{outline:3px solid #1d1d1d;outline-offset:2px}
.top{background:#0072c6;color:#fff}
.top-in{max-width:760px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.top img{max-height:32px;max-width:120px;background:#fff;padding:3px}
.wrap{max-width:760px;margin:0 auto;padding:24px 16px 40px}
h1{font-size:34px;font-weight:300;line-height:1.15;margin-bottom:4px}
h2{font-size:20px;font-weight:300;margin:26px 0 8px}
.muted{color:#595959}
.small{font-size:13.5px}
.msg{padding:12px 16px;margin-bottom:16px;border-left:6px solid;background:#fff}
.msg-ok{border-color:#128a3f}.msg-warn{border-color:#b45f06}.msg-err{border-color:#c4262e}
/* Kroki jako kafle */
.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;margin:18px 0;list-style:none}
.step{background:#d9d9d9;color:#1d1d1d;padding:8px 10px;font-size:13px;min-height:54px}
.step b{display:block;font-size:20px;font-weight:300;line-height:1.1}
.step.done{background:#128a3f;color:#fff}
.step.now{background:#0072c6;color:#fff}
/* Kwota */
.panel{background:#fff;padding:18px 20px;margin-bottom:4px}
.amount{font-size:40px;font-weight:300;line-height:1.1}
.lbl{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#595959}
.facts{display:grid;grid-template-columns:auto 1fr;gap:2px 14px;margin-top:12px;font-size:14px}
.facts dt{color:#595959}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;background:#0072c6;color:#fff;border:2px solid #0072c6;padding:12px 22px;text-decoration:none;font-weight:600;font-size:15px;cursor:pointer;font-family:inherit;width:100%}
.btn:hover{background:#005a9e;border-color:#005a9e}
.btn-sec{background:#fff;color:#005a9e;width:auto;display:inline-flex}
.btn-sec:hover{background:#e8f2fa;color:#005a9e}
.drop{display:block;border:2px dashed #0072c6;padding:14px;background:#fff;margin:10px 0;cursor:pointer}
.drop input{display:block;margin-top:8px;max-width:100%;font-size:13px}
details{background:#fff;padding:12px 20px;margin-top:4px}
summary{cursor:pointer;font-weight:600}
details ol{margin:8px 0 0 20px}
.com{background:#fff;border-left:6px solid #d9d9d9;padding:8px 14px;margin-bottom:4px;font-size:14px}
.com.org{border-color:#0072c6}
.com-meta{color:#595959;font-size:12.5px}
textarea{width:100%;padding:10px 12px;border:2px solid #8a8a8a;font-size:14px;font-family:inherit;resize:vertical;background:#fff}
.foot{color:#595959;font-size:12px;margin-top:24px}
.sr{position:absolute;left:-9999px}
@media (max-width:560px){h1{font-size:27px}.amount{font-size:32px}.steps{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<header class="top"><div class="top-in">
  <strong><?= h($org_name) ?></strong>
  <?php if ($org_logo_url): ?><img src="<?= h($org_logo_url) ?>" alt=""><?php endif; ?>
</div></header>

<main class="wrap">

<?php if (rachunek_is_test($rach)): ?>
  <div class="msg msg-warn"><strong>Dokument testowy.</strong> Służy tylko sprawdzeniu obiegu — nie wymaga od Ciebie żadnych działań.</div>
<?php endif; ?>
<?php if ($upload_ok): ?>
  <div class="msg msg-ok" role="status"><strong>Dziękujemy — podpisany rachunek został przyjęty.</strong>
    Pamiętaj jeszcze o oryginale<?= $dl['oryginal'] ? ' — do <strong>' . date_pl($dl['oryginal']) . '</strong>' : '' ?><?= $siedziba ? ', adres: ' . h($siedziba) : '' ?>.</div>
<?php elseif ($upload_err): ?>
  <div class="msg msg-err" role="alert"><strong>Nie udało się wgrać pliku:</strong> <?= h($upload_err) ?></div>
<?php endif; ?>

  <h1>Rachunek<?= !empty($rach['okres']) ? ' za ' . h($rach['okres']) : '' ?></h1>
  <p class="muted">Dzień dobry<?= $imie_nazw ? ', ' . h($imie_nazw) : '' ?>. Pobierz rachunek, podpisz go odręcznie i dostarcz.</p>

  <ol class="steps" aria-label="Postęp">
  <?php foreach ($kroki as $i => [$l, $done]): $cls = $done ? 'done' : ($i === $krok_teraz ? 'now' : ''); ?>
    <li class="step <?= $cls ?>"<?= $i === $krok_teraz ? ' aria-current="step"' : '' ?>><b><?= $done ? '✓' : $i + 1 ?></b><?= h($l) ?><span class="sr"><?= $done ? ' — zrobione' : ($i === $krok_teraz ? ' — teraz' : '') ?></span></li>
  <?php endforeach; ?>
  </ol>

  <section class="panel" aria-label="Dane rachunku">
    <?php if ($netto !== null): ?>
      <div class="lbl">Do wypłaty (netto)</div><div class="amount"><?= money($netto) ?></div>
      <?php if ($brutto !== null): ?><div class="muted small">brutto <?= money($brutto) ?></div><?php endif; ?>
    <?php elseif ($brutto !== null): ?>
      <div class="lbl">Kwota brutto</div><div class="amount"><?= money($brutto) ?></div>
    <?php endif; ?>
    <dl class="facts">
      <?php if (!empty($rach['numer'])): ?><dt>Numer</dt><dd><?= h($rach['numer']) ?></dd><?php endif; ?>
      <?php if (!empty($rach['data_wystawienia'])): ?><dt>Wystawiony</dt><dd><?= date_pl($rach['data_wystawienia']) ?></dd><?php endif; ?>
      <dt>Umowa</dt><dd><?= $umowa_ref !== '' ? h($umowa_ref) : 'umowa zlecenie' ?></dd>
    </dl>
    <?php if (!empty($rach['uwagi'])): ?><p class="small muted" style="margin-top:10px"><strong>Uwagi:</strong> <?= nl2br(h($rach['uwagi'])) ?></p><?php endif; ?>
    <div style="margin-top:16px">
    <?php if ($has_file): ?>
      <a class="btn" href="<?= h($file_url) ?>"><span aria-hidden="true">⬇</span> Pobierz rachunek do wydruku</a>
    <?php else: ?>
      <div class="msg msg-warn" style="margin:0">Do rachunku nie dołączono jeszcze pliku. Kontakt<?= $org_mail ? ': <a href="mailto:' . h($org_mail) . '">' . h($org_mail) . '</a>' : '' ?>.</div>
    <?php endif; ?>
    </div>
  </section>

  <h2>Dostarcz podpisany rachunek</h2>
  <section class="panel">
    <?php if ($is_signed && !empty($rach['signed_at'])): ?>
      <p class="small" style="margin-bottom:8px"><strong>Podpisany rachunek już dotarł (<?= date_pl($rach['signed_at']) ?>).</strong> Możesz wgrać poprawioną wersję.</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= h($self_url) ?>">
      <input type="hidden" name="_rach_signed" value="1">
      <label class="drop" for="rachPodpisany"><strong>Wgraj skan lub zdjęcie podpisanego rachunku</strong>
        <span class="small muted" style="display:block">PDF, JPG, PNG, DOC lub DOCX — do 30 MB</span>
        <input id="rachPodpisany" type="file" name="rach_podpisany" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></label>
      <button type="submit" class="btn"><span aria-hidden="true">⬆</span> Wyślij podpisany rachunek</button>
    </form>
    <p class="small muted" style="margin-top:10px">Oryginał papierowy jest potrzebny zawsze<?= $dl['oryginal'] ? ' — do <strong>' . date_pl($dl['oryginal']) . '</strong>' : '' ?><?= $siedziba ? ': ' . h($org_name) . ', ' . h($siedziba) : '' ?>.</p>
  </section>
  <details>
    <summary>Wolę wysłać skan e-mailem</summary>
    <ol class="small">
      <li>Skan na <a href="mailto:<?= h($skan_mail) ?>"><?= h($skan_mail) ?></a> — w ciągu <?= ZLEC_RACHUNEK_SKAN_DAYS ?> dni<?= $dl['skan'] ? ', do <strong>' . date_pl($dl['skan']) . '</strong>' : '' ?>.</li>
      <li>Oryginał pocztą lub osobiście — w ciągu <?= ZLEC_RACHUNEK_ORYGINAL_DAYS ?> dni<?= $dl['oryginal'] ? ', do <strong>' . date_pl($dl['oryginal']) . '</strong>' : '' ?>.</li>
    </ol>
  </details>

<?php if (strlen($nrb_digits) === 26): ?>
  <p class="small muted" style="margin-top:14px"><strong>Wypłata:</strong> przelew na konto z umowy (końcówka <?= h(substr($nrb_digits, -4)) ?>) po przyjęciu podpisanego rachunku.</p>
<?php endif; ?>

  <h2 id="komentarze">Pytania i uwagi</h2>
  <?php $coms = rachunek_comments((int)$rach['id']); ?>
  <?php if (!empty($_GET['kom'])): ?><div class="msg msg-ok" role="status">Komentarz został dodany.</div><?php endif; ?>
  <?php if ($comment_err): ?><div class="msg msg-err" role="alert"><?= h($comment_err) ?></div><?php endif; ?>
  <?php foreach ($coms as $c): ?>
    <div class="com<?= $c['author_type'] !== 'zleceniobiorca' ? ' org' : '' ?>">
      <div class="com-meta"><strong><?= h($c['author_name'] ?: 'Nieznany') ?></strong><?= $c['author_type'] !== 'zleceniobiorca' ? ' · ' . h($org_name) : '' ?> · <?= h($c['created_at']) ?></div>
      <div style="white-space:pre-wrap"><?= h($c['tresc']) ?></div>
    </div>
  <?php endforeach; ?>
  <form method="post" action="<?= h($self_url) ?>#komentarze" class="panel" style="margin-top:4px">
    <input type="hidden" name="_rach_comment" value="1">
    <label for="rachKomentarz" class="small" style="display:block;font-weight:600;margin-bottom:6px">Coś się nie zgadza (kwota, okres, dane)? Napisz</label>
    <textarea id="rachKomentarz" name="rach_comment" rows="3" required></textarea>
    <button type="submit" class="btn btn-sec" style="margin-top:10px">Wyślij komentarz</button>
  </form>

  <p class="foot"><?= h($org_name) ?><?= $org_krs ? ' · KRS ' . h($org_krs) : '' ?><?= $org_nip ? ' · NIP ' . h($org_nip) : '' ?></p>
</main>
</body>
</html>
