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
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ms_edu_bulk/logic/msEduBulk.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ms_edu_bulk/logic/msEduBulkMail.php';

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

    if (in_array($op, ['mail_preview', 'mail_test', 'mail_send'], true) && !empty($_SESSION['new_ms_bulk'])) {
        $mail = ['subject' => trim((string)($_POST['mb_subject'] ?? '')) ?: MS_EDU_BULK_MAIL_SUBJECT,
                 'body' => (string)($_POST['mb_body'] ?? '') ?: MS_EDU_BULK_MAIL_BODY,
                 'szkolenie' => trim((string)($_POST['mb_szkolenie'] ?? ''))];
        $_SESSION['new_ms_mail'] = $mail;
        $rowsB = $_SESSION['new_ms_bulk'];
        if ($op === 'mail_test') {
            $to = trim((string)($me['email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) flash_set('danger', 'Brak Twojego adresu e-mail w sesji panelu.');
            else {
                $f = $rowsB[0]; $f['email'] = $to;
                $t = msEduBulkMailSend([$f], '[TEST] ' . $mail['subject'], $mail['body'], $mail['szkolenie']);
                flash_set($t['sent'] ? 'success' : 'danger', $t['sent'] ? "Wysłano test na $to." : implode(' ', $t['failed']));
            }
        } elseif ($op === 'mail_send') {
            $only = array_map('intval', (array)($_POST['mb_sel'] ?? []));
            if (!$only) flash_set('danger', 'Zaznacz odbiorców.');
            else {
                $sx = msEduBulkMailSend($rowsB, $mail['subject'], $mail['body'], $mail['szkolenie'], $only);
                $_SESSION['new_ms_bulk'] = $sx['rows'];
                flash_set($sx['failed'] ? 'warning' : 'success', 'Zakolejkowano wiadomości: ' . $sx['sent'] . ($sx['failed'] ? '. Błędy: ' . implode(' | ', $sx['failed']) : '.'));
            }
        }
        header('Location: microsoft365.php'); exit;
    }

    if ($op === 'ms_bulk') {
        $ids  = array_values(array_unique(array_map('intval', (array)($_POST['account_ids'] ?? []))));
        $mode = in_array($_POST['tryb'] ?? '', ['szkolenie', 'imie_nazwisko'], true) ? $_POST['tryb'] : 'szkolenie';
        $slug = trim((string)($_POST['szkolenie'] ?? ''));
        $sku  = (string)($_POST['sku'] ?? '');
        if (!ti_ms_enabled()) { flash_set('danger', 'Moduł kont Microsoft nie jest skonfigurowany.'); header('Location: microsoft365.php'); exit; }
        if (!$ids) { flash_set('danger', 'Zaznacz kursantów.'); header('Location: microsoft365.php'); exit; }
        if ($mode === 'szkolenie' && msEduBulkAscii($slug) === '') { flash_set('danger', 'Podaj nazwę szkolenia.'); header('Location: microsoft365.php'); exit; }
        $graph = m365_training();
        $rows = []; $map = []; $noMail = [];
        foreach (array_slice($ids, 0, 200) as $aid) {
            $r = ti_student_row($aid);
            if (!$r || !empty($r['ms_user_id'])) continue;
            $name = trim((string)($r['client_name'] ?: $r['login']));
            $altMail = trim((string)($r['client_email'] ?? ''));
            if (!filter_var($altMail, FILTER_VALIDATE_EMAIL)) { $noMail[] = $name; continue; }
            $parts = preg_split('/\s+/u', $name, 2);
            $rows[] = ['imie' => $parts[0], 'nazwisko' => $parts[1] ?? $parts[0], 'rola' => 'uczen', 'klasa' => $slug, 'aid' => $aid, 'email' => $altMail];
        }
        if ($noMail) flash_set('warning', 'Pominięto (brak e-maila alternatywnego w karcie beneficjenta): ' . implode(', ', $noMail));
        if (!$rows) { if (!$noMail) flash_set('danger', 'Brak kursantów do utworzenia.'); header('Location: microsoft365.php'); exit; }
        $domain = ti_m365_setting('domain') ?: (m365_setting('m365_domain') ?: 'feer.org.pl');
        $start = 1 + (int)(db_one("SELECT COUNT(*) c FROM k30_ti_student_accounts WHERE ms_upn LIKE ?", [msEduBulkAscii($slug) . '%@%'])['c'] ?? 0);
        $planned = msEduBulkPlan($graph, $rows, $domain, date('Y'), $mode, $slug, $start);
        $res = msEduBulkCreate($graph, $planned, ['uczen' => $sku, 'nauczyciel' => $sku], date('Y'), false);
        foreach ($res['ok'] as $r) {
            db_update('k30_ti_student_accounts', [
                'ms_user_id' => $r['ms_user_id'], 'ms_upn' => $r['upn'],
                'ms_created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ], (int)$r['aid']);
        }
        if ($res['ok']) { $_SESSION['new_ms_bulk'] = $res['ok']; $_SESSION['new_ms_mail'] = ['subject' => MS_EDU_BULK_MAIL_SUBJECT, 'body' => MS_EDU_BULK_MAIL_BODY, 'szkolenie' => $slug]; }
        flash_set($res['errors'] ? 'warning' : 'success', 'Utworzono kont: ' . count($res['ok']) . ($res['errors'] ? '. Błędy: ' . implode(' | ', $res['errors']) : '.'));
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

$new_ms_bulk = $_SESSION['new_ms_bulk'] ?? null;
if (isset($_GET['csv']) && $new_ms_bulk) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="konta_m365_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBFosoba;login;haslo\n";
    foreach ($new_ms_bulk as $r) echo $r['imie'] . ' ' . $r['nazwisko'] . ';' . $r['upn'] . ';' . $r['password'] . "\n";
    unset($_SESSION['new_ms_bulk']); exit;
}
if (isset($_GET['pdf']) && $new_ms_bulk) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="konta_m365_' . date('Ymd_His') . '.pdf"');
    echo msEduBulkPdf($new_ms_bulk); exit;
}
if (isset($_GET['clear'])) { unset($_SESSION['new_ms_bulk'], $_SESSION['new_ms_mail']); header('Location: microsoft365.php'); exit; }
$ms_skus = $ms_online_enabled ? m365_training()->get_subscribed_skus() : [];
$ms_default_sku = ti_m365_setting('default_sku');
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

<?php if ($new_ms_bulk): ?>
<div class="alert alert-warning mb-4 shadow-sm">
  <div class="d-flex gap-2 align-items-center mb-2 flex-wrap">
    <div class="fw-bold">⚠ Wygenerowane konta (<?= count($new_ms_bulk) ?>) — hasła widoczne tylko tutaj</div>
    <a href="?pdf=1" target="_blank" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj PDF</a>
    <a href="?csv=1" class="btn btn-sm btn-success"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz CSV</a>
    <a href="?clear=1" class="btn btn-sm btn-outline-secondary">Zamknij</a>
  </div>
  <table class="table table-sm table-bordered mb-0 font-monospace" style="background:#fff;font-size:.85rem">
    <?php foreach ($new_ms_bulk as $r): ?>
    <tr><td><?= h($r['imie'] . ' ' . $r['nazwisko']) ?></td><td><?= h($r['upn']) ?></td><td class="text-danger"><?= h($r['password']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($new_ms_bulk):
  $_m = $_SESSION['new_ms_mail'] ?? ['subject' => MS_EDU_BULK_MAIL_SUBJECT, 'body' => MS_EDU_BULK_MAIL_BODY, 'szkolenie' => ''];
  $mbRows = $new_ms_bulk; $mbTokenName = '_token'; $mbTokenValue = dyd_token(); $mbAction = 'microsoft365.php';
  $mbSubject = $_m['subject']; $mbBody = $_m['body']; $mbSzkolenie = $_m['szkolenie'] ?? '';
  include dirname(__DIR__, 3) . '/modules/ms_edu_bulk/views/mailComposer.php';
endif; ?>

<?php if ($ms_online_enabled): ?>
<form method="post" id="msBulkForm" class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-lightning-charge me-2 text-primary" aria-hidden="true"></i>Generuj konta hurtowo</div>
  <div class="card-body">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="ms_bulk">
    <div class="row g-3 align-items-end">
      <div class="col-md-4"><label class="form-label small fw-semibold" for="bkTryb">Schemat loginu</label>
        <select id="bkTryb" name="tryb" class="form-select form-select-sm">
          <option value="szkolenie">nazwa szkolenia + numer (excel01, excel02…)</option>
          <option value="imie_nazwisko">imie.nazwisko</option>
        </select></div>
      <div class="col-md-4"><label class="form-label small fw-semibold" for="bkSzk">Nazwa szkolenia</label>
        <input id="bkSzk" name="szkolenie" class="form-control form-control-sm" placeholder="np. excel" maxlength="40"></div>
      <div class="col-md-4"><label class="form-label small fw-semibold" for="bkSku">Licencja</label>
        <select id="bkSku" name="sku" class="form-select form-select-sm"><option value="">— bez licencji —</option>
          <?php foreach ($ms_skus as $sk): ?>
          <option value="<?= h($sk['skuId']) ?>"<?= $sk['skuId'] === $ms_default_sku ? ' selected' : '' ?>><?= h($sk['skuPartNumber']) ?></option>
          <?php endforeach; ?></select></div>
    </div>
    <p class="small text-muted mt-2 mb-0">Zaznacz kursantów w tabeli poniżej (tylko bez konta) i kliknij „Utwórz konta dla zaznaczonych”. Konta zostaną powiązane z kursantami.</p>
    <button type="submit" class="btn btn-primary btn-sm mt-2" onclick="return confirm('Utworzyć REALNE konta Microsoft dla zaznaczonych kursantów?')"><i class="bi bi-microsoft me-1" aria-hidden="true"></i>Utwórz konta dla zaznaczonych</button>
  </div>
</form>
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
        <tr><th><span class="visually-hidden">Zaznacz</span></th><th>Beneficjent</th><th>Login (panel)</th><th>Konto Microsoft</th><th class="text-end">Akcja</th></tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $a): $has_ms = !empty($a['ms_user_id']); ?>
        <tr class="<?= $a['is_active'] ? '' : 'opacity-50' ?>">
          <td><?php if (!$has_ms && $ms_online_enabled): ?><input type="checkbox" form="msBulkForm" name="account_ids[]" value="<?= (int)$a['id'] ?>" aria-label="Zaznacz <?= h($a['client_name']) ?>"><?php endif; ?></td>
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
