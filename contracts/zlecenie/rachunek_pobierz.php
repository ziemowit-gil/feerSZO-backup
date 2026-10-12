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
<title>Rachunek<?= !empty($rach['numer']) ? ' ' . h($rach['numer']) : '' ?> — <?= h($org_name) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;color:#0f172a;font-size:15px;line-height:1.6}
a{color:#1d4ed8}
:focus-visible{outline:3px solid #2563eb;outline-offset:2px}
.top{background:#0f172a;color:#e2e8f0}
.top-in{max-width:760px;margin:0 auto;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.top .org{font-weight:600;font-size:14px;letter-spacing:.02em}
.top img{max-height:34px;max-width:120px;background:#fff;border-radius:6px;padding:3px}
.wrap{max-width:760px;margin:0 auto;padding:24px 16px 40px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 1px 2px rgba(15,23,42,.05);padding:22px 24px;margin-bottom:16px}
.eyebrow{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
h1{font-size:24px;line-height:1.25;margin:4px 0 6px}
h2{font-size:17px;margin-bottom:10px}
.muted{color:#64748b}
.small{font-size:13px}
/* Kroki */
.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:18px;list-style:none}
.step{display:flex;flex-direction:column;align-items:center;text-align:center;font-size:12.5px;color:#64748b;position:relative}
.step .dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;border:2px solid #cbd5e1;background:#fff;margin-bottom:5px}
.step.done .dot{background:#16a34a;border-color:#16a34a;color:#fff}
.step.now .dot{border-color:#2563eb;color:#2563eb}
.step.now{color:#0f172a;font-weight:600}
.step:not(:last-child)::after{content:"";position:absolute;top:15px;left:calc(50% + 18px);right:calc(-50% + 18px);height:2px;background:#e2e8f0}
.step.done:not(:last-child)::after{background:#86efac}
/* Kwota */
.sum{display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;justify-content:space-between}
.amount .lbl{font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
.amount .val{font-size:30px;font-weight:800;line-height:1.15}
.facts{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;font-size:14px;margin-top:4px}
.facts dt{color:#64748b}
.facts dd{font-weight:500}
.btn{display:inline-flex;align-items:center;gap:8px;background:#2563eb;color:#fff;border:0;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px;cursor:pointer;font-family:inherit}
.btn:hover{background:#1d4ed8}
.btn-lg{width:100%;justify-content:center;padding:14px 22px;font-size:16px}
.btn-sec{background:#fff;color:#1d4ed8;border:2px solid #bfdbfe}
.btn-sec:hover{background:#eff6ff}
.file-meta{text-align:center;color:#94a3b8;font-size:12.5px;margin-top:6px}
/* Komunikaty */
.note{border-radius:10px;padding:12px 16px;font-size:14px;margin-bottom:16px}
.note-ok{background:#f0fdf4;border:1px solid #bbf7d0}
.note-warn{background:#fffbeb;border:1px solid #fde68a}
.note-err{background:#fef2f2;border:1px solid #fecaca}
/* Sposoby dostarczenia */
.opts{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.opt{border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;background:#fff;display:flex;flex-direction:column}
.opt.rec{border:2px solid #2563eb;background:#f8fbff}
.opt-h{display:flex;align-items:center;gap:8px;font-weight:700;margin-bottom:6px}
.badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:#dbeafe;color:#1e40af}
.chip{display:inline-flex;align-items:center;gap:6px;font-size:13px;background:#f1f5f9;border-radius:8px;padding:4px 10px;margin:4px 6px 0 0}
.drop{display:block;border:2px dashed #93c5fd;border-radius:10px;padding:16px;text-align:center;background:#fff;cursor:pointer;margin:10px 0}
.drop:hover,.drop:focus-within{border-color:#2563eb;background:#eff6ff}
.drop input{display:block;margin:8px auto 0;max-width:100%;font-size:13px}
.opt ol{margin:6px 0 0 18px;font-size:14px}
.opt li{margin-bottom:6px}
/* Komentarze */
.com{background:#f8fafc;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:14px}
.com.org{background:#eef2ff}
.com-meta{color:#64748b;font-size:12.5px;margin-bottom:3px}
textarea{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;font-family:inherit;resize:vertical}
.foot{color:#94a3b8;font-size:12px;text-align:center;margin-top:10px}
@media (max-width:640px){
  .opts{grid-template-columns:1fr}
  h1{font-size:21px}
  .amount .val{font-size:26px}
  .step{font-size:11.5px}
}
</style>
</head>
<body>
<header class="top">
  <div class="top-in">
    <span class="org"><?= h($org_name) ?></span>
    <?php if ($org_logo_url): ?><img src="<?= h($org_logo_url) ?>" alt=""><?php endif; ?>
  </div>
</header>

<main class="wrap">

  <?php if (rachunek_is_test($rach)): ?>
  <div class="note note-warn"><strong>Dokument testowy.</strong> Ten rachunek służy wyłącznie sprawdzeniu obiegu — nie wywołuje skutków księgowych i nie wymaga od Ciebie żadnych działań.</div>
  <?php endif; ?>

  <?php if ($upload_ok): ?>
  <div class="note note-ok" role="status">
    <strong>✓ Dziękujemy — podpisany rachunek został przyjęty.</strong>
    Osoby prowadzące umowę zostały powiadomione. Pamiętaj jeszcze o <strong>oryginale</strong><?= $dl['oryginal'] ? ' — do <strong>' . date_pl($dl['oryginal']) . '</strong>' : '' ?><?= $siedziba ? ', adres: <strong>' . h($siedziba) . '</strong>' : '' ?>.
  </div>
  <?php elseif ($upload_err): ?>
  <div class="note note-err" role="alert"><strong>Nie udało się wgrać pliku:</strong> <?= h($upload_err) ?></div>
  <?php endif; ?>

  <!-- Nagłówek i kroki -->
  <section class="card" aria-labelledby="h1">
    <div class="eyebrow">Rachunek do umowy zlecenie</div>
    <h1 id="h1">Twój rachunek<?= !empty($rach['okres']) ? ' za ' . h($rach['okres']) : '' ?></h1>
    <p class="muted">Dzień dobry<?= $imie_nazw ? ', ' . h($imie_nazw) : '' ?>. Pobierz rachunek, podpisz go odręcznie i dostarcz — poniżej krok po kroku.</p>
    <ol class="steps" aria-label="Postęp">
      <?php foreach ($kroki as $i => [$l, $done]): $cls = $done ? 'done' : ($i === $krok_teraz ? 'now' : ''); ?>
      <li class="step <?= $cls ?>"<?= $i === $krok_teraz ? ' aria-current="step"' : '' ?>>
        <span class="dot" aria-hidden="true"><?= $done ? '✓' : $i + 1 ?></span>
        <?= h($l) ?><span class="visually-hidden" style="position:absolute;left:-9999px"><?= $done ? ' — zrobione' : ($i === $krok_teraz ? ' — teraz' : '') ?></span>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <!-- Kwota i dane -->
  <section class="card" aria-labelledby="hSum">
    <h2 id="hSum" style="position:absolute;left:-9999px">Dane rachunku</h2>
    <div class="sum">
      <div class="amount">
        <?php if ($netto !== null): ?>
          <div class="lbl">Do wypłaty (netto)</div>
          <div class="val"><?= money($netto) ?></div>
          <?php if ($brutto !== null): ?><div class="muted small">brutto <?= money($brutto) ?></div><?php endif; ?>
        <?php elseif ($brutto !== null): ?>
          <div class="lbl">Kwota brutto</div>
          <div class="val"><?= money($brutto) ?></div>
        <?php endif; ?>
      </div>
      <dl class="facts">
        <?php if (!empty($rach['numer'])): ?><dt>Numer</dt><dd><?= h($rach['numer']) ?></dd><?php endif; ?>
        <?php if (!empty($rach['data_wystawienia'])): ?><dt>Wystawiony</dt><dd><?= date_pl($rach['data_wystawienia']) ?></dd><?php endif; ?>
        <?php if (!empty($rach['okres'])): ?><dt>Okres</dt><dd><?= h($rach['okres']) ?></dd><?php endif; ?>
        <dt>Umowa</dt><dd><?= $umowa_ref !== '' ? h($umowa_ref) : 'umowa zlecenie' ?></dd>
        <?php if ($imie_nazw): ?><dt>Zleceniobiorca</dt><dd><?= h($imie_nazw) ?></dd><?php endif; ?>
      </dl>
    </div>
    <?php if (!empty($rach['uwagi'])): ?><p class="small muted" style="margin-top:12px"><strong>Uwagi:</strong> <?= nl2br(h($rach['uwagi'])) ?></p><?php endif; ?>

    <div style="margin-top:18px">
      <?php if ($has_file): ?>
      <a class="btn btn-lg" href="<?= h($file_url) ?>"><span aria-hidden="true">⬇</span> Pobierz rachunek do wydruku</a>
      <?php if (!empty($rach['plik_nazwa'])): ?>
      <div class="file-meta"><?= h($rach['plik_nazwa']) ?><?= $rach['plik_size'] ? ' · ' . h(rachunek_file_size_h((int)$rach['plik_size'])) : '' ?></div>
      <?php endif; ?>
      <?php else: ?>
      <div class="note note-warn" style="margin:0">Do tego rachunku nie dołączono jeszcze pliku. Prosimy o kontakt<?= $org_mail ? ': <a href="mailto:' . h($org_mail) . '">' . h($org_mail) . '</a>' : '' ?>.</div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Sposoby dostarczenia -->
  <section class="card" aria-labelledby="hOpt">
    <h2 id="hOpt">Jak dostarczyć podpisany rachunek</h2>
    <p class="small muted" style="margin-bottom:12px">Wydrukuj, podpisz odręcznie i wybierz jeden ze sposobów. Oryginał papierowy jest potrzebny zawsze.</p>
    <div class="opts">
      <div class="opt rec">
        <div class="opt-h">1. Wgraj skan tutaj <span class="badge">zalecane</span></div>
        <p class="small muted">Zeskanuj albo zrób wyraźne zdjęcie podpisanego rachunku — trafi od razu do systemu.</p>
        <?php if ($is_signed && !empty($rach['signed_at'])): ?>
        <div class="note note-ok small" style="margin:10px 0 0">Podpisany rachunek już dotarł (<?= date_pl($rach['signed_at']) ?>). Możesz wgrać poprawioną wersję, jeśli poprzednia była nieczytelna.</div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="<?= h($self_url) ?>">
          <input type="hidden" name="_rach_signed" value="1">
          <label class="drop" for="rachPodpisany">
            <strong>Wybierz plik ze skanem lub zdjęciem</strong>
            <span class="small muted" style="display:block">PDF, JPG, PNG, DOC lub DOCX — do 30 MB</span>
            <input id="rachPodpisany" type="file" name="rach_podpisany" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
          </label>
          <button type="submit" class="btn" style="width:100%;justify-content:center"><span aria-hidden="true">⬆</span> Wyślij podpisany rachunek</button>
        </form>
        <?php if ($dl['oryginal']): ?>
        <div><span class="chip">📮 Oryginał do <strong><?= date_pl($dl['oryginal']) ?></strong></span></div>
        <?php endif; ?>
      </div>
      <div class="opt">
        <div class="opt-h">2. Skan e-mailem, oryginał pocztą</div>
        <ol>
          <li>Skan podpisanego rachunku na <a href="mailto:<?= h($skan_mail) ?>"><?= h($skan_mail) ?></a> — w ciągu <?= ZLEC_RACHUNEK_SKAN_DAYS ?> dni<?= $dl['skan'] ? ', do <strong>' . date_pl($dl['skan']) . '</strong>' : '' ?>.</li>
          <li>Oryginał pocztą lub osobiście — w ciągu <?= ZLEC_RACHUNEK_ORYGINAL_DAYS ?> dni<?= $dl['oryginal'] ? ', do <strong>' . date_pl($dl['oryginal']) . '</strong>' : '' ?>.</li>
        </ol>
        <?php if ($siedziba): ?><p class="small" style="margin-top:auto;padding-top:10px"><strong>Adres:</strong> <?= h($org_name) ?>, <?= h($siedziba) ?></p><?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Wypłata -->
  <?php if (strlen($nrb_digits) === 26): ?>
  <section class="card small" aria-label="Wypłata">
    <strong>Wypłata.</strong> <?= $netto !== null ? 'Kwotę <strong>' . money($netto) . '</strong> przelejemy' : 'Wynagrodzenie przelejemy' ?> na konto wskazane w umowie (kończące się na <strong><?= h(substr($nrb_digits, -4)) ?></strong>) po przyjęciu podpisanego rachunku.
    <?php if ($org_mail): ?><span class="muted">Zmiana konta lub pytania: <a href="mailto:<?= h($org_mail) ?>"><?= h($org_mail) ?></a>.</span><?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- Komentarze -->
  <section class="card" id="komentarze" aria-labelledby="hKom">
    <h2 id="hKom">Pytania i uwagi do rachunku</h2>
    <?php $coms = rachunek_comments((int)$rach['id']); ?>
    <?php if (!empty($_GET['kom'])): ?><div class="note note-ok" role="status">✓ Komentarz został dodany.</div><?php endif; ?>
    <?php if ($comment_err): ?><div class="note note-err" role="alert"><?= h($comment_err) ?></div><?php endif; ?>
    <?php if ($coms): ?>
      <?php foreach ($coms as $c): ?>
      <div class="com<?= $c['author_type'] !== 'zleceniobiorca' ? ' org' : '' ?>">
        <div class="com-meta"><strong><?= h($c['author_name'] ?: 'Nieznany') ?></strong><?= $c['author_type'] !== 'zleceniobiorca' ? ' · ' . h($org_name) : '' ?> · <?= h($c['created_at']) ?></div>
        <div style="white-space:pre-wrap"><?= h($c['tresc']) ?></div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="small muted" style="margin-bottom:10px">Nie ma jeszcze komentarzy. Jeśli coś się nie zgadza (kwota, okres, dane) — napisz tutaj.</p>
    <?php endif; ?>
    <form method="post" action="<?= h($self_url) ?>#komentarze">
      <input type="hidden" name="_rach_comment" value="1">
      <label for="rachKomentarz" class="small" style="display:block;font-weight:600;margin-bottom:6px">Twój komentarz</label>
      <textarea id="rachKomentarz" name="rach_comment" rows="3" required placeholder="np. proszę o korektę okresu rozliczeniowego"></textarea>
      <button type="submit" class="btn btn-sec" style="margin-top:10px">Wyślij komentarz</button>
    </form>
  </section>

  <p class="foot"><?= h($org_name) ?><?= $org_krs ? ' · KRS ' . h($org_krs) : '' ?><?= $org_nip ? ' · NIP ' . h($org_nip) : '' ?></p>
</main>
</body>
</html>
