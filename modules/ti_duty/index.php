<?php
/**
 * modules/ti_duty/index.php — DK: dyżury kierownika / Zarządu — zarządzanie w SZO.
 *
 * Grafik: dyżury stałe (co tydzień) i w konkretnych dniach (zastępują stałe; „brak dyżuru”).
 * Publicznie: strona „Dyżury” (modules/ti_duty/public/index.php, krótko /dyzury) i zakładka DK w planie TI.
 * Logika: modules/ti_duty/logic/dutyManager.php; widok formularza: modules/ti_duty/ui/dutyAdmin.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/dutyManager.php';
require_once __DIR__ . '/ui/dutyAdmin.php';
require_once dirname(__DIR__, 2) . '/modules/ti_plan_public/logic/planPublicPortal.php';

require_login();
if (!can_edit()) { flash_set('error', 'Brak uprawnień do grafiku dyżurów.'); header('Location: ' . APP_URL . '/index.php'); exit; }
tiDutyMigrate();

$SELF = APP_URL . '/modules/ti_duty/index.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = tiDutyAdminHandlePost($_POST, (int)(current_user()['id'] ?? 0));
    if ($res) {
        flash_set($res[0] === 'danger' ? 'error' : $res[0], $res[1]);
        header('Location: ' . $SELF . ($res[2] > 0 ? '?edit=' . $res[2] . '#dk-form' : ($res[2] < 0 ? '#dk-form' : ''))); exit;
    }
}

// Adresy publiczne: krótkie na subdomenach ti.* / szo.* / kurs*.*, inaczej techniczne
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$short = preg_match('/^(ti|szo|kurs)[^.]*\./i', $host) === 1;
$publicUrl = $short ? $scheme . '://' . $host . '/dyzury' : APP_URL . '/karty30/ti/dydaktyk/dyzury.php';
$planDkUrl = $short ? $scheme . '://' . $host . '/plan/dk' : APP_URL . '/karty30/ti/dydaktyk/planPubliczny.php?k=dk';

$PAGE_TITLE = 'Dyżury kierownika / Zarządu';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:1200px">
  <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
    <h1 class="h4 fw-bold mb-0"><i class="bi bi-person-badge me-2" aria-hidden="true"></i>DK — dyżury kierownika / Zarządu</h1>
    <span class="text-body-secondary small">kiedy i gdzie można zastać kierownika lub członka Zarządu</span>
  </div>
  <p class="small text-body-secondary mb-3">Jeden grafik dla strony „Dyżury” i zakładki DK w planie zajęć TI. Publicznie dyżurni są pokazywani inicjałami z funkcją
    (RODO); pełne imię i nazwisko widać tylko w widoku pełnym planu (po haśle kierownika).</p>
  <?= flash_html() ?>
  <?php tiDutyAdminRender($SELF, csrf_field(), $publicUrl, $planDkUrl); ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
