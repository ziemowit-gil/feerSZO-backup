<?php
/**
 * karty30/ti/dydaktyk/microsoft365.php — Konta Microsoft 365 (tenant szkoleniowy)
 * kursantów, jako osobny ekran w panelu kierownika (obok konta.php, gdzie
 * tworzenie/usuwanie konta MS jest tylko jedną z akcji dla pojedynczego kursanta).
 *
 * Tu widać WSZYSTKICH kursantów z jednego miejsca — bez wybierania każdego
 * z osobna w Konta kursantów. Konfiguracja samego tenanta/licencji (dane
 * dostępowe do Graph API) zostaje w karty30/ti/online_admin.php — to ekran
 * administracji SZO (k30_require_access()/can_write), kierownik nie ma tam
 * dostępu, bo operuje na osobnym systemie logowania (dyd_require()).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_online.php';

$me       = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
karty30_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'ms_create') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = ti_ms_provision($aid);
        if ($res['ok']) {
            $_SESSION['new_ms_creds'] = ['upn' => $res['upn'] ?? '', 'password' => $res['password'] ?? ''];
            flash_set('success', 'Konto Microsoft utworzone: ' . ($res['upn'] ?? ''));
        } else {
            flash_set('danger', $res['msg']);
        }
        header('Location: microsoft365.php'); exit;
    }

    if ($op === 'ms_delete') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = ti_ms_delete($aid);
        flash_set($res['ok'] ? 'success' : 'danger', $res['msg']);
        header('Location: microsoft365.php'); exit;
    }
}

$ms_online_enabled = ti_ms_enabled();
$accounts = db_all(
    "SELECT a.*, cl.name AS client_name
     FROM k30_ti_student_accounts a
     JOIN k30_clients cl ON cl.id=a.client_id
     ORDER BY cl.name"
);
$ms_count = count(array_filter($accounts, fn($a) => !empty($a['ms_user_id'])));

$new_ms_creds = $_SESSION['new_ms_creds'] ?? null;
unset($_SESSION['new_ms_creds']);

$KP_TITLE  = 'Microsoft 365 — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'microsoft365.php'; $KIER_LABEL = 'Microsoft 365';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-microsoft text-primary me-2" aria-hidden="true"></i>Microsoft 365 — kursanci</h1>
  <span class="badge bg-secondary"><?= $ms_count ?>/<?= count($accounts) ?> z kontem</span>
</div>

<?= flash_html() ?>

<?php if (!$ms_online_enabled): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>
  Moduł kont Microsoft 365 (tenant szkoleniowy) nie jest skonfigurowany. Skontaktuj się z administratorem SZO —
  konfiguracja (dane dostępowe do Graph API, licencje) jest w administracji, poza panelem kierownika.
</div>
<?php endif; ?>

<!-- Nowo utworzone konto Microsoft -->
<?php if ($new_ms_creds): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4 shadow-sm">
  <i class="bi bi-microsoft fs-4 flex-shrink-0" style="color:#0078d4" aria-hidden="true"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-2">⚠ Dane konta Microsoft 365 — przekaż kursantowi i zamknij!</div>
    <table class="table table-sm table-bordered mb-2" style="max-width:360px;background:#fff;font-size:.88rem">
      <tr><th>Login (UPN)</th><td class="font-monospace fw-bold"><?= h($new_ms_creds['upn']) ?></td></tr>
      <tr><th>Hasło tymczasowe</th><td class="font-monospace fw-bold text-danger"><?= h($new_ms_creds['password']) ?></td></tr>
    </table>
    <div class="small text-muted">Dane wysłano też e-mailem/SMS-em (jeśli skonfigurowane).</div>
  </div>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-people me-2 text-primary" aria-hidden="true"></i>Konta kursantów
  </div>
  <?php if (!$accounts): ?>
  <div class="card-body text-muted">Brak kont kursantów.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
      <caption class="visually-hidden">Konta Microsoft 365 kursantów</caption>
      <thead class="table-light">
        <tr><th>Beneficjent</th><th>Login (panel)</th><th>Konto Microsoft</th><th class="text-end">Akcja</th></tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $a): $has_ms = !empty($a['ms_user_id']); ?>
        <tr class="<?= $a['is_active'] ? '' : 'opacity-50' ?>">
          <td class="fw-semibold"><?= h($a['client_name']) ?></td>
          <td class="font-monospace"><?= h($a['login']) ?></td>
          <td>
            <?php if ($has_ms): ?>
            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
              <i class="bi bi-microsoft" aria-hidden="true"></i> <span class="font-monospace"><?= h($a['ms_upn']) ?></span>
            </span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary-emphasis border">Brak konta</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if (!$ms_online_enabled): ?>
            <span class="text-muted small">moduł wyłączony</span>
            <?php else: ?>
            <form method="post" <?= $has_ms ? "onsubmit=\"return confirm('Usunąć konto Microsoft kursanta „" . h(addslashes($a['client_name'])) . "”?')\"" : '' ?>>
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="<?= $has_ms ? 'ms_delete' : 'ms_create' ?>">
              <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $has_ms ? 'btn-outline-danger' : 'btn-outline-primary' ?>">
                <i class="bi bi-microsoft me-1" aria-hidden="true"></i><?= $has_ms ? 'Usuń' : 'Utwórz' ?>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
