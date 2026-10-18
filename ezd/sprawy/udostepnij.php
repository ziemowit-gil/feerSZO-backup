<?php
/**
 * ezd/sprawy/udostepnij.php — PUBLICZNA strona podglądu dokumentów koszulki
 * udostępnionych na zewnątrz (dostęp przez token w linku + kod SMS).
 *
 * Bez logowania i celowo POZA bramką VPN modułu EZD (require_module_enabled)
 * — odbiorca zewnętrzny nie ma dostępu do sieci wewnętrznej/VPN organizacji.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
require_once dirname(dirname(__DIR__)) . '/includes/notifications.php';
require_once dirname(dirname(__DIR__)) . '/includes/ext_deliver.php';

auth_start();

/** Czy plik da się wyświetlić bezpośrednio w przeglądarce (tryb "Tylko podgląd"). */
function _ezd_ext_share_is_previewable(array $f): bool {
    $mime = (string)($f['mime_type'] ?? '');
    $ext  = strtolower(pathinfo((string)$f['original_name'], PATHINFO_EXTENSION));
    return $mime === 'application/pdf' || str_starts_with($mime, 'image/') || in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'], true);
}

$token  = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$share  = $token !== '' ? ezd_ext_share_get_by_token($token) : null;
$status = $share ? ezd_ext_share_status($share) : null;
$sess_key = 'ezd_ext_verified_' . $token;
$msg = null; $msg_t = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $share) {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'send_otp' && $status === 'active') {
        $cooldown = !empty($share['otp_sent_at']) && (time() - strtotime($share['otp_sent_at'])) < 30;
        if ($cooldown) {
            $msg = 'Kod został już wysłany — poczekaj chwilę przed ponowną próbą.'; $msg_t = 'warning';
        } else {
            $r = ezd_ext_share_send_otp($share);
            $share = ezd_ext_share_get((int)$share['id']);
            $msg   = $r['ok'] ? ('Kod SMS został wysłany na numer kończący się na ' . substr($share['recipient_phone'], -3) . '.') : $r['error'];
            $msg_t = $r['ok'] ? 'success' : 'error';
        }
    } elseif ($act === 'verify_otp' && $status === 'active') {
        $code = trim($_POST['code'] ?? '');
        if ($code !== '' && ezd_ext_share_verify_otp($share, $code)) {
            // Kod trzymany w sesji (nie w bazie) — służy też jako hasło do ZIP-a przy kilku plikach.
            $_SESSION[$sess_key] = $code;
            $share = ezd_ext_share_get((int)$share['id']);
        } else {
            $share = ezd_ext_share_get((int)$share['id']);
            $msg   = ((int)$share['otp_attempts'] >= 5) ? 'Zbyt wiele nieudanych prób — poproś o nowy kod.' : 'Nieprawidłowy lub wygasły kod.';
            $msg_t = 'error';
        }
    }
}

$otp_code = $share ? (string)($_SESSION[$sess_key] ?? '') : '';
$verified = (bool)($share && $status === 'active' && $otp_code !== '');
$sprawa   = $share ? ezd_sprawa_get((int)$share['sprawa_id']) : null;
$files    = $verified ? ezd_ext_share_files($share) : [];
// Tryb "download" (domyślny) pozwala pobrać plik/ZIP; "view" — wyłącznie podgląd w przeglądarce.
$can_download = $share && ($share['access_mode'] ?? 'download') !== 'view';

if ($verified && $sprawa && empty($_GET['dl']) && empty($_GET['zip']) && empty($_GET['view'])) {
    ezd_ext_share_touch_view((int)$share['id']);
}

// Podgląd pojedynczego pliku wprost w przeglądarce (dostępny w obu trybach, tylko PDF/obraz)
if (isset($_GET['view']) && $verified) {
    $zal_id = (int)$_GET['view'];
    $match  = null;
    foreach ($files as $f) { if ((int)$f['id'] === $zal_id) { $match = $f; break; } }
    if ($match && _ezd_ext_share_is_previewable($match)) {
        $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $match['sprawa_id'] . '/' . $match['filename'];
        if (is_file($path)) {
            ezd_ext_share_touch_view((int)$share['id']);
            ezd_ext_share_log((int)$share['id'], 'view', $match['original_name']);

            $servePath = $path;
            $tmpWm = null;
            $ext = strtolower(pathinfo($match['original_name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf' || $match['mime_type'] === 'application/pdf') {
                // Znak wodny (kto i kiedy oglądał) — utrudnia dalsze rozpowszechnianie zrzutu ekranu.
                $tmpWm = tempnam(sys_get_temp_dir(), 'ezdwm_') . '.pdf';
                $mark  = ORG_NAME . ' · podgląd: ' . $share['recipient_email'] . ' · ' . date('d.m.Y H:i');
                if (ext_watermark_pdf($path, $tmpWm, $mark, 'both')) {
                    $servePath = $tmpWm;
                } else {
                    @unlink($tmpWm); $tmpWm = null;
                }
            }

            header('Content-Type: ' . ($match['mime_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . addslashes($match['original_name']) . '"');
            header('Content-Length: ' . filesize($servePath));
            header('X-Content-Type-Options: nosniff');
            readfile($servePath);
            if ($tmpWm) @unlink($tmpWm);
            exit;
        }
    }
    http_response_code(404); exit('Podgląd niedostępny dla tego pliku.');
}

// Pobieranie pojedynczego pliku — tylko w trybie "Pobieranie" (tylko po weryfikacji, z listy objętej udostępnieniem)
if (isset($_GET['dl']) && $verified) {
    if (!$can_download) { http_response_code(403); exit('Ten link umożliwia wyłącznie podgląd w przeglądarce.'); }
    $zal_id = (int)$_GET['dl'];
    $match  = null;
    foreach ($files as $f) { if ((int)$f['id'] === $zal_id) { $match = $f; break; } }
    if ($match) {
        $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $match['sprawa_id'] . '/' . $match['filename'];
        if (is_file($path)) {
            ezd_ext_share_touch_view((int)$share['id']);
            ezd_ext_share_log((int)$share['id'], 'download', $match['original_name']);
            header('Content-Type: ' . ($match['mime_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . addslashes($match['original_name']) . '"');
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
    }
    http_response_code(404); exit('Plik nie znaleziony.');
}

// Pobieranie wszystkich plików naraz jako ZIP zaszyfrowany kodem SMS — tylko w trybie "Pobieranie"
if (isset($_GET['zip']) && $verified) {
    if (!$can_download) { http_response_code(403); exit('Ten link umożliwia wyłącznie podgląd w przeglądarce.'); }
    if (count($files) <= 1) { http_response_code(400); exit('ZIP dostępny tylko przy kilku plikach.'); }
    $zipPath = ezd_ext_share_build_zip($files, $otp_code);
    if ($zipPath) {
        ezd_ext_share_touch_view((int)$share['id']);
        ezd_ext_share_log((int)$share['id'], 'zip_download', count($files) . ' plik(ów)');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . addslashes($sprawa['znak_sprawy'] ?? 'dokumenty') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        header('X-Content-Type-Options: nosniff');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }
    http_response_code(500); exit('Nie udało się przygotować archiwum ZIP.');
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Udostępnione dokumenty<?= $sprawa ? ' — ' . h($sprawa['znak_sprawy']) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* Szata Metro UI jak System Tożsamości: ciemny app-bar, zero zaokrągleń i cieni, ramki 2px, żółty fokus */
* { box-sizing:border-box }
body { margin:0; background:#fff; color:#111827; font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,sans-serif; -webkit-font-smoothing:antialiased }
*:focus-visible { outline:3px solid #fbbf24; outline-offset:2px }
.top { background:#1d1d1d; color:#fff; padding:.55rem 1rem; }
.top .wrap { max-width:640px; margin:0 auto; display:flex; align-items:center; gap:.6rem; font-weight:700 }
.top .bi { background:#0f6fa8; width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center }
.wrap { max-width:640px; margin:0 auto; padding:0 1rem }
.panel { background:#f2f2f2; border-radius:0; padding:22px 24px; margin:18px auto; box-shadow:none }
.panel h1 { font-weight:300 !important; font-size:1.6rem !important; letter-spacing:-.02em; line-height:1.15 }
.alert { border-radius:0; padding:.75rem 1rem; font-size:.9rem; margin:14px auto; background:#f2f2f2; border:0; border-left:6px solid #0f6fa8; color:#111827 }
.alert-success { border-left-color:#007a00 }
.alert-warning { border-left-color:#c25100 }
.alert-error   { border-left-color:#a20025 }
.alert-info    { border-left-color:#0f6fa8 }
.btn { border:2px solid transparent; border-radius:0; padding:.6rem 1.1rem; font-weight:600; cursor:pointer; font-size:.92rem; min-height:44px }
.btn-primary { background:#0f6fa8; border-color:#0f6fa8; color:#fff }
.btn-primary:hover { background:#1d1d1d; border-color:#1d1d1d }
.btn-outline { background:transparent; border-color:#1d1d1d; color:#1d1d1d }
.btn-outline:hover { background:#1d1d1d; color:#fff }
.lbl { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.14em; color:#5f5f5f }
.inp { width:100%; padding:.5rem .65rem; border:2px solid #8a8a8a; border-radius:0; font-size:1rem; letter-spacing:.05em; background:#fff }
.inp:focus { border-color:#0f6fa8; outline:none }
.file-row { display:flex; align-items:center; gap:.6rem; padding:.6rem 0; border-bottom:1px solid #e1e1e1 }
.file-row:last-child { border-bottom:none }
.foot { text-align:center; font-size:.76rem; color:#5f5f5f; padding:14px 0 26px }
</style>
</head>
<body>

<div class="top"><div class="wrap">
  <i class="bi bi-folder2-open" aria-hidden="true"></i>
  <span>Udostępnione dokumenty</span>
</div></div>

<div class="wrap">

<?php if (!$share): ?>
  <div class="panel" style="text-align:center">
    <i class="bi bi-exclamation-circle" style="font-size:2rem;color:#B45309" aria-hidden="true"></i>
    <h1 style="font-size:1.1rem">Nieprawidłowy link</h1>
    <p style="font-size:.9rem;color:#6B7280">Link jest nieprawidłowy. Prosimy o kontakt z osobą, która go przekazała.</p>
  </div>

<?php elseif ($status !== 'active'): ?>
  <div class="panel" style="text-align:center">
    <i class="bi bi-hourglass-bottom" style="font-size:2rem;color:#B45309" aria-hidden="true"></i>
    <h1 style="font-size:1.1rem"><?= $status === 'revoked' ? 'Link odwołany' : 'Link wygasł' ?></h1>
    <p style="font-size:.9rem;color:#6B7280">
      <?= $status === 'revoked'
          ? 'Dostęp do tych dokumentów został cofnięty przez udostępniającego.'
          : 'Termin ważności tego linku minął (' . h(date('d.m.Y H:i', strtotime($share['expires_at']))) . ').' ?>
      Prosimy o kontakt z osobą, która przekazała ten link.
    </p>
  </div>

<?php elseif (!$verified): $otp_manual = ($share['otp_mode'] ?? 'sms') === 'manual'; ?>
  <div class="panel">
    <h1 style="font-size:1.05rem;margin:0 0 .3rem">Potwierdź dostęp kodem</h1>
    <?php if ($otp_manual): ?>
    <p style="font-size:.88rem;color:#6B7280;margin:0 0 1rem">
      Ze względów bezpieczeństwa dostęp do udostępnionych dokumentów wymaga kodu, który przekazała
      Państwu osoba udostępniająca te dokumenty (telefonicznie lub osobiście).
    </p>
    <?php else: ?>
    <p style="font-size:.88rem;color:#6B7280;margin:0 0 1rem">
      Ze względów bezpieczeństwa dostęp do udostępnionych dokumentów wymaga jednorazowego kodu
      wysyłanego SMS-em na numer telefonu kończący się na <strong>••• <?= h(substr($share['recipient_phone'], -3)) ?></strong>.
    </p>
    <?php endif; ?>

    <?php if ($msg): ?><div class="alert alert-<?= h($msg_t) ?>" role="status"><?= h($msg) ?></div><?php endif; ?>

    <?php if (!$otp_manual): ?>
    <form method="post" style="margin-bottom:.8rem">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="t" value="<?= h($token) ?>">
      <input type="hidden" name="act" value="send_otp">
      <button type="submit" class="btn btn-outline"><i class="bi bi-phone me-1" aria-hidden="true"></i> Wyślij kod SMS</button>
    </form>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="t" value="<?= h($token) ?>">
      <input type="hidden" name="act" value="verify_otp">
      <label class="lbl" for="code"><?= $otp_manual ? 'Kod dostępu' : 'Kod z SMS' ?></label>
      <div style="display:flex;gap:.5rem;margin-top:.3rem">
        <input class="inp" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" required autofocus>
        <button type="submit" class="btn btn-primary">Potwierdź</button>
      </div>
    </form>
    <?php if (!$otp_manual): ?>
    <p style="font-size:.76rem;color:#9CA3AF;margin:.8rem 0 0">Kod jest ważny 10 minut od wysłania.</p>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php if ($msg): ?><div class="alert alert-<?= h($msg_t) ?>" role="status"><?= h($msg) ?></div><?php endif; ?>
  <div class="panel">
    <div class="lbl">Sprawa</div>
    <h1 style="font-size:1.05rem;margin:.15rem 0 .8rem"><?= h($sprawa['znak_sprawy'] . ' — ' . $sprawa['title']) ?></h1>

    <?php if (!$can_download): ?>
    <div class="alert alert-info" role="status" style="margin-bottom:1rem">
      <i class="bi bi-eye me-1" aria-hidden="true"></i>Ten link umożliwia wyłącznie podgląd w przeglądarce — bez pobierania plików.
    </div>
    <?php endif; ?>

    <?php if (!$files): ?>
    <div style="font-size:.86rem;color:#6B7280">Brak plików do wyświetlenia.</div>

    <?php elseif (!$can_download): ?>
      <?php foreach ($files as $f): $prev = _ezd_ext_share_is_previewable($f); ?>
      <div class="file-row">
        <i class="bi bi-file-earmark-text fs-5 text-muted" aria-hidden="true"></i>
        <div style="flex:1 1 auto;overflow:hidden">
          <div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($f['original_name']) ?></div>
          <div style="font-size:.74rem;color:#9CA3AF"><?= h(ezd_filesize((int)$f['file_size'])) ?></div>
        </div>
        <?php if ($prev): ?>
        <a class="btn btn-outline" href="?t=<?= h($token) ?>&view=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-eye me-1" aria-hidden="true"></i>Podgląd</a>
        <?php else: ?>
        <span class="text-muted" style="font-size:.76rem;white-space:nowrap">Podgląd niedostępny</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

    <?php elseif (count($files) === 1): $f = $files[0]; ?>
    <div class="file-row">
      <i class="bi bi-file-earmark-text fs-5 text-muted" aria-hidden="true"></i>
      <div style="flex:1 1 auto;overflow:hidden">
        <div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($f['original_name']) ?></div>
        <div style="font-size:.74rem;color:#9CA3AF"><?= h(ezd_filesize((int)$f['file_size'])) ?></div>
      </div>
      <a class="btn btn-outline" href="?t=<?= h($token) ?>&dl=<?= (int)$f['id'] ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz</a>
    </div>
    <?php else: ?>
      <?php foreach ($files as $f): ?>
      <div class="file-row">
        <i class="bi bi-file-earmark-text fs-5 text-muted" aria-hidden="true"></i>
        <div style="flex:1 1 auto;overflow:hidden">
          <div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($f['original_name']) ?></div>
          <div style="font-size:.74rem;color:#9CA3AF"><?= h(ezd_filesize((int)$f['file_size'])) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="alert alert-info" role="status" style="margin:1rem 0 0">
        Ze względu na liczbę plików (<?= count($files) ?>) pobierają się razem, jako jedno archiwum ZIP
        zabezpieczone hasłem. <strong>Hasłem do archiwum jest ten sam kod dostępu</strong>, którym przed chwilą potwierdzono dostęp.
      </div>
      <a class="btn btn-primary" style="margin-top:.6rem;display:inline-block" href="?t=<?= h($token) ?>&zip=1">
        <i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i>Pobierz wszystkie jako ZIP (<?= count($files) ?>)
      </a>
    <?php endif; ?>

    <p style="font-size:.78rem;color:#9CA3AF;margin:1rem 0 0">
      Dostęp ważny do <?= h(date('d.m.Y H:i', strtotime($share['expires_at']))) ?>.
    </p>
  </div>
<?php endif; ?>

<div class="foot">
  <?= h(ORG_NAME) ?>
  <div style="margin-top:.3rem">Dokumenty udostępnione przez system SZO.</div>
</div>

</div>
</body>
</html>
