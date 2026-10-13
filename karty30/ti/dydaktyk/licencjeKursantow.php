<?php
/**
 * karty30/ti/dydaktyk/licencjeKursantow.php — Licencje kursantów TI (kierownik).
 *
 * Lista przypisań licencji z filtrami (szukaj, status, forma rozliczenia FAK/FAZ/DSC/GST,
 * oprogramowanie), kafelki ze statusami (Aktywna / Wygasająca wkrótce / Wygasła /
 * Unieważniona), formularz przypisania i edycji (forma rozliczenia obowiązkowa),
 * unieważnianie, ponowne powiadomienie SMS / e-mail, katalog oprogramowania oraz
 * konfiguracja integracji JetBrains. Zastępuje dawne ekrany licencje.php (kierownik)
 * i licencje_admin.php (admin SZO) — oba przekierowują tutaj.
 *
 * Logika i reguły: modules/ti_licencje/logic/ (TiLicencjeManager). Ekran: Bootstrap 5.3,
 * WCAG 2.1 — etykiety pól, aria-*, odznaki z ikoną i tekstem (kolor nie jest jedynym
 * nośnikiem informacji), kontrast tekstu odznak ≥ 7:1, wynik filtrowania w regionie status.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_licencje/logic/tiLicencjeManager.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
ti_licencje_migrate();

/** Adres tej strony z filtrami (puste i domyślne wartości pomijamy). */
function lic_url(array $f, array $extra = [], string $hash = ''): string {
    $q = array_merge($f, $extra);
    if (($q['sort'] ?? '') === 'client') unset($q['sort']);
    if (($q['dir'] ?? '') === 'asc')     unset($q['dir']);
    if ((int)($q['page'] ?? 1) <= 1)     unset($q['page']);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== 0 && $v !== null && $v !== false);
    return 'licencjeKursantow.php' . ($q ? '?' . http_build_query($q) : '') . $hash;
}

// ── Operacje (POST → PRG; błąd walidacji = ta sama strona z wpisanymi danymi) ─────────
$errors = []; $old = null; $edit_id = (int)($_GET['edit'] ?? 0); $soft_id = (int)($_GET['soft'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    parse_str((string)($_POST['_f'] ?? ''), $bf);                       // filtry i strona sprzed wysłania formularza
    $back = lic_url(TiLicencjeManager::filters($bf), ['page' => max(1, (int)($bf['page'] ?? 1))]);
    $done = function (string $type, string $msg, string $hash = '') use ($back): void {
        flash_set($type, $msg);
        header('Location: ' . $back . $hash);
        exit;
    };

    switch ($op) {
        case 'assign':
            $ch = [];
            if (!empty($_POST['notify_email'])) $ch[] = 'email';
            if (!empty($_POST['notify_sms']))   $ch[] = 'sms';
            $res = TiLicencjeManager::assign($_POST, $uid, $ch);
            if ($res['ok']) {
                $n = $res['notify'];
                $done($n && !$n['ok'] ? 'warning' : 'success',
                      'Licencja przypisana kursantowi.' . ($n ? ' Powiadomienia — ' . $n['msg'] : ' Bez powiadomienia.'));
            }
            $errors = $res['errors']; $old = $_POST; $edit_id = 0;
            break;

        case 'update':
            $edit_id = (int)($_POST['assignment_id'] ?? 0);
            $res = TiLicencjeManager::update($edit_id, $_POST, $uid);
            if ($res['ok']) $done('success', 'Zmiany zapisane.');
            $errors = $res['errors']; $old = $_POST;
            break;

        case 'revoke':
            $res = TiLicencjeManager::revoke((int)($_POST['assignment_id'] ?? 0), (string)($_POST['reason'] ?? ''), $uid);
            $done($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Licencja unieważniona.' : $res['error']);
            break;

        case 'notify':
            $res = TiLicencjeManager::notifyAssigned((int)($_POST['assignment_id'] ?? 0), $uid, true);
            $done($res['ok'] ? 'success' : 'warning', 'Powiadomienia — ' . $res['msg']);
            break;

        case 'save_license':
            $res = TiLicencjeManager::saveSoftware($_POST, (int)($_POST['license_id'] ?? 0), $uid);
            if ($res['ok']) $done('success', 'Oprogramowanie zapisane w katalogu.', '#katalog');
            $errors = $res['errors']; $soft_id = (int)($_POST['license_id'] ?? 0); $old = $_POST;
            break;

        case 'toggle_license':
            TiLicencjeManager::toggleSoftware((int)($_POST['license_id'] ?? 0));
            $done('success', 'Zmieniono dostępność oprogramowania w katalogu.', '#katalog');
            break;

        case 'delete_license':
            $res = TiLicencjeManager::deleteSoftware((int)($_POST['license_id'] ?? 0));
            $done($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Oprogramowanie usunięte z katalogu.' : $res['error'], '#katalog');
            break;

        case 'jetbrains_save':
            // Tylko konfiguracja (token/URL); automatyczne przydzielanie licencji do wdrożenia po ustaleniu produktu.
            org_setting_set('jetbrains_enabled', isset($_POST['jetbrains_enabled']) ? '1' : '0');
            org_setting_set('jetbrains_api_url', trim((string)($_POST['jetbrains_api_url'] ?? '')));
            if (trim((string)($_POST['jetbrains_api_token'] ?? '')) !== '') org_setting_set('jetbrains_api_token', trim((string)$_POST['jetbrains_api_token']));
            $done('success', 'Ustawienia integracji JetBrains zapisane.', '#jetbrains');
            break;
    }
}

// ── Dane do widoku ───────────────────────────────────────────────────────────────────
$f        = TiLicencjeManager::filters($_GET);
$page     = max(1, (int)($_GET['page'] ?? 1));
$list     = TiLicencjeManager::search($f, $page);
$stats    = TiLicencjeManager::stats();
$catalog  = TiLicencjeManager::catalog(false);
$active_catalog = array_values(array_filter($catalog, fn($l) => !empty($l['is_active'])));
$clients  = TiLicencjeManager::clients();
$today    = TiLicencjeManager::today();
$sms_ready = function_exists('sms_channel_ready') && sms_channel_ready();

$edit      = $edit_id ? TiLicencjeManager::get($edit_id) : null;
$soft_edit = null;
foreach ($catalog as $c) if ((int)$c['id'] === $soft_id) $soft_edit = $c;

// Wartości formularza przypisania/edycji: z błędnie wysłanego POST, z edytowanego wiersza albo domyślne.
$fv = ['license_id' => 0, 'client_id' => 0, 'login' => '', 'access_key' => '', 'notes' => '', 'issued_at' => $today,
       'expires_at' => '', 'billing_form' => '', 'billing_ref' => '', 'billing_note' => ''];
if ($edit) {
    foreach ($fv as $k => $_) $fv[$k] = (string)($k === 'issued_at' ? ($edit['issued'] ?? $today) : ($edit[$k] ?? ''));
}
if ($old && ($_POST['_op'] ?? '') !== 'save_license') {
    foreach ($fv as $k => $_) if (array_key_exists($k, $old)) $fv[$k] = (string)$old[$k];
}
$soft_fv = $soft_edit ?: ['id' => 0, 'name' => '', 'vendor' => '', 'category' => '', 'vendor_url' => '', 'seats_total' => 0, 'notes' => '', 'is_active' => 1];
if ($old && ($_POST['_op'] ?? '') === 'save_license') $soft_fv = array_merge($soft_fv, $old, ['is_active' => !empty($old['is_active']) ? 1 : 0]);

$form_open = $edit || (($_POST['_op'] ?? '') === 'assign' && $errors) || isset($_GET['new']);
$flt_hidden = http_build_query(array_merge($f, ['page' => $list['page']]));   // wraca na ten sam widok po zapisie

$ST_UI   = ['active' => ['bi-check-circle-fill', 'lic-st-active'], 'expiring' => ['bi-hourglass-split', 'lic-st-expiring'],
            'expired' => ['bi-x-octagon-fill', 'lic-st-expired'], 'revoked' => ['bi-slash-circle-fill', 'lic-st-revoked']];
$FORM_UI = ['FAK' => ['bi-receipt', 'lic-form-fak'], 'FAZ' => ['bi-journals', 'lic-form-faz'],
            'DSC' => ['bi-percent', 'lic-form-dsc'],  'GST' => ['bi-heart-fill', 'lic-form-gst']];
$days_txt = function (int $n): string { return $n === 1 ? '1 dzień' : $n . ' dni'; };
$sort_link = function (string $key) use ($f, $list): array {
    $cur = $f['sort'] === $key;
    $dir = $cur && $f['dir'] === 'asc' ? 'desc' : 'asc';
    return [lic_url($f, ['sort' => $key, 'dir' => $dir, 'page' => 1]), $cur ? ($f['dir'] === 'asc' ? 'ascending' : 'descending') : 'none', $cur ? ($f['dir'] === 'asc' ? 'bi-arrow-up-short' : 'bi-arrow-down-short') : ''];
};

$KP_TITLE  = 'Licencje kursantów — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  /* Odznaki: jasne tło + ciemny tekst (≥ 7:1) + obramowanie w kolorze tekstu + ikona i etykieta tekstowa */
  .badge.lic-b { font-weight: 700; font-size: .78rem; padding: .3em .55em; border: 1px solid currentColor; white-space: nowrap; }
  .badge.lic-st-active   { background: #d1e7dd; color: #0a3622; }
  .badge.lic-st-expiring { background: #fff3cd; color: #664d03; }
  .badge.lic-st-expired  { background: #f8d7da; color: #58151c; }
  .badge.lic-st-revoked  { background: #e2e3e5; color: #2b2f32; }
  .badge.lic-form-fak    { background: #cfe2ff; color: #052c65; }
  .badge.lic-form-faz    { background: #cff4fc; color: #032830; }
  .badge.lic-form-dsc    { background: #e6dcf5; color: #2d1b4e; }
  .badge.lic-form-gst    { background: #212529; color: #ffffff; }
  .badge.lic-form-none   { background: #ffffff; color: #7a2e0e; border-style: dashed; }
  /* Kafelki statusów (linki-filtry) */
  .lic-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .6rem; margin: 0 0 .75rem; padding: 0; list-style: none; }
  a.lic-kpi, body.ti-skin a.lic-kpi { display: block; padding: .55rem .75rem; border: 1px solid #adb5bd; border-left-width: 6px; border-radius: 4px;
        background: #fff; color: #1b2430; text-decoration: none; min-height: 44px; }
  a.lic-kpi:hover, body.ti-skin a.lic-kpi:hover { background: #f1f4f8; text-decoration: underline; }
  a.lic-kpi.is-on, body.ti-skin a.lic-kpi.is-on { background: #eef3fb; border-color: #10335c; box-shadow: inset 0 0 0 1px #10335c; font-weight: 700; }
  .lic-kpi .n { display: block; font-size: 1.6rem; font-weight: 800; line-height: 1.1; }
  .lic-kpi .l { font-size: .8rem; }
  .lic-kpi-active   { border-left-color: #0a3622 !important; } .lic-kpi-expiring { border-left-color: #664d03 !important; }
  .lic-kpi-expired  { border-left-color: #58151c !important; } .lic-kpi-revoked  { border-left-color: #2b2f32 !important; }
  .lic-chips { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; margin: 0 0 1rem; padding: 0; list-style: none; }
  a.lic-chip, body.ti-skin a.lic-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .6rem; border: 1px solid #6c757d; border-radius: 999px;
        background: #fff; color: #1b2430; text-decoration: none; font-size: .82rem; min-height: 32px; }
  a.lic-chip:hover, body.ti-skin a.lic-chip:hover { background: #f1f4f8; text-decoration: underline; }
  a.lic-chip.is-on, body.ti-skin a.lic-chip.is-on { background: #10335c; border-color: #10335c; color: #fff; font-weight: 700; }
  .lic-row-revoked > * { color: #495057; background: #f8f9fa; }
  .lic-table th a, body.ti-skin .lic-table th a { color: inherit; text-decoration: none; white-space: nowrap; }
  .lic-table th a:hover, body.ti-skin .lic-table th a:hover { text-decoration: underline; }
  summary.lic-sum { cursor: pointer; list-style: none; display: flex; align-items: center; gap: .5rem; }
  summary.lic-sum::-webkit-details-marker { display: none; }
  summary.lic-sum::after { content: "\F282"; font-family: bootstrap-icons; margin-left: auto; transition: transform .15s; }
  details[open] > summary.lic-sum::after { transform: rotate(180deg); }
  .lic-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
</style>

<?php if (function_exists('dyd_mode') && dyd_mode() === 'konsultacje') { $RZ_CUR = 'licencjeKursantow.php'; $RZ_LABEL = 'Licencje'; include __DIR__ . '/_konsultacje_bar.php'; }   // w trybie Konsultacje — menu Konsultacji
   else { $KIER_CUR = 'licencjeKursantow.php'; $KIER_LABEL = 'Licencje kursantów'; include __DIR__ . '/_kierownik_bar.php'; } ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1280px">

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-key text-primary me-2" aria-hidden="true"></i>Licencje kursantów TI</h1>
  <span class="text-body-secondary small">oprogramowanie inne niż Microsoft 365 — z formą rozliczenia i powiadomieniami</span>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a class="btn btn-success btn-sm" href="<?= h(lic_url($f, ['page' => $list['page'], 'new' => 1], '#lic-form')) ?>"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Przypisz licencję</a>
    <a class="btn btn-outline-secondary btn-sm" href="#katalog"><i class="bi bi-card-list me-1" aria-hidden="true"></i>Katalog oprogramowania</a>
  </div>
</div>

<?= flash_html() ?>

<?php if ($errors): ?>
<div class="alert alert-danger" role="alert" id="lic-errors" tabindex="-1">
  <strong>Nie zapisano — popraw dane:</strong>
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<script>document.getElementById('lic-errors').focus();</script>
<?php endif; ?>

<!-- Kafelki: liczba licencji według statusu (każdy kafelek filtruje listę) -->
<nav aria-label="Filtr według statusu licencji">
  <ul class="lic-kpis">
    <?php foreach ([['', 'Bieżące', $stats['current'], 'bi-collection', ''],
                    ['active', 'Aktywne', $stats['active'], $ST_UI['active'][0], 'lic-kpi-active'],
                    ['expiring', 'Wygasające wkrótce (' . TiLicencjeManager::WARN_DAYS . ' dni)', $stats['expiring'], $ST_UI['expiring'][0], 'lic-kpi-expiring'],
                    ['expired', 'Wygasłe', $stats['expired'], $ST_UI['expired'][0], 'lic-kpi-expired'],
                    ['revoked', 'Unieważnione', $stats['revoked'], $ST_UI['revoked'][0], 'lic-kpi-revoked']] as [$k, $lbl, $n, $ico, $cls]): ?>
    <li><a class="lic-kpi <?= $cls ?><?= $f['status'] === $k ? ' is-on' : '' ?>" href="<?= h(lic_url($f, ['status' => $k, 'page' => 1])) ?>"<?= $f['status'] === $k ? ' aria-current="true"' : '' ?>>
      <span class="n"><i class="bi <?= $ico ?> me-1" aria-hidden="true"></i><?= (int)$n ?></span>
      <span class="l"><?= h($lbl) ?></span>
    </a></li>
    <?php endforeach; ?>
  </ul>
</nav>

<!-- Chipy: forma rozliczenia (liczby bez unieważnionych) -->
<nav aria-label="Filtr według formy rozliczenia">
  <ul class="lic-chips">
    <li class="small fw-semibold" id="lic-forms-lbl">Forma rozliczenia:</li>
    <li><a class="lic-chip<?= $f['form'] === '' ? ' is-on' : '' ?>" href="<?= h(lic_url($f, ['form' => '', 'page' => 1])) ?>"<?= $f['form'] === '' ? ' aria-current="true"' : '' ?>>Wszystkie</a></li>
    <?php foreach (TiLicencjeManager::BILLING_FORMS as $code => $bf_meta): ?>
    <li><a class="lic-chip<?= $f['form'] === $code ? ' is-on' : '' ?>" href="<?= h(lic_url($f, ['form' => $code, 'page' => 1])) ?>"<?= $f['form'] === $code ? ' aria-current="true"' : '' ?>>
      <i class="bi <?= $FORM_UI[$code][0] ?>" aria-hidden="true"></i><strong><?= $code ?></strong> <?= h($bf_meta['label']) ?> <span class="badge text-bg-secondary"><?= (int)$stats['forms'][$code] ?></span></a></li>
    <?php endforeach; ?>
    <li><a class="lic-chip<?= $f['form'] === 'none' ? ' is-on' : '' ?>" href="<?= h(lic_url($f, ['form' => 'none', 'page' => 1])) ?>"<?= $f['form'] === 'none' ? ' aria-current="true"' : '' ?>>
      <i class="bi bi-question-circle" aria-hidden="true"></i>Brak formy (do uzupełnienia) <span class="badge text-bg-secondary"><?= (int)$stats['forms']['none'] ?></span></a></li>
  </ul>
</nav>

<!-- Formularz: przypisanie nowej licencji / edycja istniejącej -->
<details class="card border-0 shadow-sm mb-3" id="lic-form"<?= $form_open ? ' open' : '' ?>>
  <summary class="card-header fw-semibold lic-sum">
    <i class="bi bi-<?= $edit ? 'pencil-square' : 'person-plus' ?> text-success" aria-hidden="true"></i>
    <?= $edit ? 'Edytuj licencję: ' . h($edit['license_name']) . ' — ' . h($edit['client_name']) : 'Przypisz licencję kursantowi' ?>
  </summary>
  <div class="card-body">
  <?php if ($edit && $edit['status'] === 'revoked'): ?>
    <p class="alert alert-secondary mb-0">Ta licencja jest unieważniona (<?= h(date_pl($edit['revoked_at'])) ?><?= $edit['revoke_reason'] !== '' ? ', powód: ' . h($edit['revoke_reason']) : '' ?>) — nie można jej edytować.
      <a href="<?= h(lic_url($f, ['page' => $list['page']])) ?>">Zamknij</a></p>
  <?php elseif (!$edit && !$active_catalog): ?>
    <p class="text-body-secondary mb-0">Najpierw dodaj aktywne oprogramowanie do <a href="#katalog">katalogu</a>.</p>
  <?php elseif (!$edit && !$clients): ?>
    <p class="text-body-secondary mb-0">Brak kursantów TI (zapisanych na kurs lub z kontem panelu).</p>
  <?php else: ?>
    <form method="post" action="<?= h(lic_url($f, ['page' => $list['page']], '#lic-form')) ?>" novalidate>
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="<?= $edit ? 'update' : 'assign' ?>">
      <input type="hidden" name="_f" value="<?= h($flt_hidden) ?>">
      <?php if ($edit): ?><input type="hidden" name="assignment_id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <p class="small text-body-secondary mb-2">Pola oznaczone <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">gwiazdką</span> są wymagane.</p>

      <div class="row g-3">
        <div class="col-md-4">
          <?php if ($edit): ?>
            <div class="form-label fw-semibold mb-1" id="lic-sw-lbl">Oprogramowanie</div>
            <div class="form-control-plaintext py-1" aria-labelledby="lic-sw-lbl"><?= h($edit['license_name']) ?><?= $edit['vendor'] !== '' ? ' <span class="text-body-secondary">(' . h($edit['vendor']) . ')</span>' : '' ?></div>
          <?php else: ?>
            <label class="form-label fw-semibold" for="lic-license">Oprogramowanie <span class="text-danger" aria-hidden="true">*</span></label>
            <select class="form-select" id="lic-license" name="license_id" required>
              <option value="">— wybierz —</option>
              <?php foreach ($active_catalog as $l): ?>
              <option value="<?= (int)$l['id'] ?>"<?= (int)$fv['license_id'] === (int)$l['id'] ? ' selected' : '' ?>><?= h($l['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <?php if ($edit): ?>
            <div class="form-label fw-semibold mb-1" id="lic-cl-lbl">Kursant</div>
            <div class="form-control-plaintext py-1" aria-labelledby="lic-cl-lbl"><?= h($edit['client_name']) ?></div>
          <?php else: ?>
            <label class="form-label fw-semibold" for="lic-client">Kursant <span class="text-danger" aria-hidden="true">*</span></label>
            <select class="form-select" id="lic-client" name="client_id" required>
              <option value="">— wybierz —</option>
              <?php foreach ($clients as $c): ?>
              <option value="<?= (int)$c['id'] ?>"<?= (int)$fv['client_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label fw-semibold" for="lic-issued">Data wydania</label>
          <input type="date" class="form-control" id="lic-issued" name="issued_at" value="<?= h($fv['issued_at']) ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label fw-semibold" for="lic-exp">Ważna do</label>
          <input type="date" class="form-control" id="lic-exp" name="expires_at" value="<?= h($fv['expires_at']) ?>" aria-describedby="lic-exp-help">
          <div class="form-text" id="lic-exp-help">Puste = bezterminowo.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold" for="lic-login">Login / konto</label>
          <input type="text" class="form-control lic-mono" id="lic-login" name="login" value="<?= h($fv['login']) ?>" maxlength="190" autocomplete="off">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold" for="lic-key">Klucz licencyjny / hasło</label>
          <input type="text" class="form-control lic-mono" id="lic-key" name="access_key" value="<?= h($fv['access_key']) ?>" maxlength="1000" autocomplete="off">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold" for="lic-notes">Uwagi widoczne dla kursanta</label>
          <input type="text" class="form-control" id="lic-notes" name="notes" value="<?= h($fv['notes']) ?>" maxlength="500" placeholder="np. zakres, ograniczenia">
        </div>

        <div class="col-lg-8">
          <fieldset class="border rounded p-2 h-100">
            <legend class="float-none w-auto px-1 fs-6 fw-semibold mb-1">Forma rozliczenia <span class="text-danger" aria-hidden="true">*</span></legend>
            <div class="row g-2">
              <?php foreach (TiLicencjeManager::BILLING_FORMS as $code => $bf_meta): ?>
              <div class="col-sm-6">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="billing_form" id="lic-bf-<?= $code ?>" value="<?= $code ?>" required
                         data-ref-label="<?= h($bf_meta['ref_label']) ?>" data-ref-required="<?= $bf_meta['ref_required'] ? '1' : '0' ?>"<?= $fv['billing_form'] === $code ? ' checked' : '' ?>>
                  <label class="form-check-label" for="lic-bf-<?= $code ?>"><span class="badge lic-b <?= $FORM_UI[$code][1] ?>"><i class="bi <?= $FORM_UI[$code][0] ?> me-1" aria-hidden="true"></i><?= $code ?></span> <?= h($bf_meta['label']) ?></label>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php if ($edit && $edit['billing_form'] === ''): ?><div class="form-text text-danger-emphasis">Ta licencja powstała przed wprowadzeniem formy rozliczenia — wybierz ją, aby zapisać zmiany.</div><?php endif; ?>
          </fieldset>
        </div>
        <div class="col-lg-4">
          <label class="form-label fw-semibold" for="lic-ref"><span id="lic-ref-label">Numer faktury / kod rabatowy / referencja</span><span id="lic-ref-req" class="text-danger" aria-hidden="true" hidden> *</span></label>
          <input type="text" class="form-control lic-mono" id="lic-ref" name="billing_ref" value="<?= h($fv['billing_ref']) ?>" maxlength="190" autocomplete="off">
          <div class="form-text">Etykieta zależy od wybranej formy. Kod rabatowy jest wymagany.</div>
        </div>

        <div class="col-lg-8">
          <label class="form-label fw-semibold" for="lic-bnote">Notatka rozliczeniowa <span class="fw-normal text-body-secondary">(wewnętrzna — kursant jej nie widzi)</span></label>
          <input type="text" class="form-control" id="lic-bnote" name="billing_note" value="<?= h($fv['billing_note']) ?>" maxlength="500">
        </div>
        <?php if (!$edit): ?>
        <div class="col-lg-4">
          <fieldset aria-describedby="lic-notify-help">
            <legend class="fs-6 fw-semibold mb-1">Powiadom o przypisaniu</legend>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="notify_email" id="lic-n-mail" value="1"<?= (!$old || !empty($old['notify_email'])) ? ' checked' : '' ?>>
              <label class="form-check-label" for="lic-n-mail">E-mail</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="notify_sms" id="lic-n-sms" value="1"<?= $sms_ready ? '' : ' disabled' ?><?= ($sms_ready && (!$old || !empty($old['notify_sms']))) ? ' checked' : '' ?>>
              <label class="form-check-label" for="lic-n-sms">SMS<?= $sms_ready ? '' : ' (kanał SMS nie jest skonfigurowany)' ?></label>
            </div>
            <div class="form-text" id="lic-notify-help">Do kursanta, a gdy jest małoletni — także do opiekuna, zgodnie z ich ustawieniami powiadomień. Bez loginu i klucza.</div>
          </fieldset>
        </div>
        <?php endif; ?>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-<?= $edit ? 'primary' : 'success' ?>"><i class="bi bi-<?= $edit ? 'check-lg' : 'person-plus' ?> me-1" aria-hidden="true"></i><?= $edit ? 'Zapisz zmiany' : 'Przypisz' ?></button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= h(lic_url($f, ['page' => $list['page']])) ?>">Anuluj</a><?php endif; ?>
      </div>
    </form>

    <?php if ($edit): $nlog = TiLicencjeManager::notifications((int)$edit['id']); ?>
    <hr>
    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
      <h2 class="h6 mb-0">Powiadomienia o przypisaniu</h2>
      <span class="small text-body-secondary">przypisano <?= h(date_pl(substr((string)$edit['assigned_at'], 0, 10))) ?><?= !empty($edit['assigned_by_name']) ? ' — ' . h($edit['assigned_by_name']) : '' ?></span>
      <form method="post" class="ms-auto" onsubmit="return confirm('Wysłać ponownie powiadomienie (e-mail i SMS) o tej licencji?')">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="notify">
        <input type="hidden" name="_f" value="<?= h($flt_hidden) ?>">
        <input type="hidden" name="assignment_id" value="<?= (int)$edit['id'] ?>">
        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-bell me-1" aria-hidden="true"></i>Wyślij powiadomienie ponownie</button>
      </form>
    </div>
    <?php if (!$nlog): ?>
      <p class="small text-body-secondary mb-0">Nie wysłano jeszcze żadnego powiadomienia o tej licencji.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
          <caption class="visually-hidden">Historia prób powiadomienia o przypisaniu licencji</caption>
          <thead class="table-light"><tr><th scope="col">Kiedy</th><th scope="col">Kanał</th><th scope="col">Odbiorca</th><th scope="col">Wynik</th></tr></thead>
          <tbody>
          <?php foreach ($nlog as $nl): ?>
            <tr>
              <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$nl['created_at']))) ?></td>
              <td><?= $nl['channel'] === 'sms' ? 'SMS' : 'E-mail' ?></td>
              <td><?= $nl['recipient_kind'] === 'opiekun' ? 'opiekun' : 'kursant' ?><?= $nl['recipient'] !== '' ? ' <span class="lic-mono">' . h($nl['recipient']) . '</span>' : '' ?></td>
              <td><?= ['sent' => 'wysłano', 'failed' => 'błąd', 'skipped' => 'pominięto'][$nl['status']] ?? h($nl['status']) ?><?= $nl['detail'] !== '' ? ' — ' . h($nl['detail']) : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; endif; ?>
  <?php endif; ?>
  </div>
</details>

<!-- Filtry -->
<form method="get" role="search" aria-label="Filtrowanie licencji" class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label" for="f-q">Szukaj</label>
        <input type="search" class="form-control" id="f-q" name="q" value="<?= h($f['q']) ?>" placeholder="kursant, oprogramowanie, login, nr faktury / kod">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="f-status">Status</label>
        <select class="form-select" id="f-status" name="status">
          <option value=""<?= $f['status'] === '' ? ' selected' : '' ?>>Bieżące (bez unieważnionych)</option>
          <option value="all"<?= $f['status'] === 'all' ? ' selected' : '' ?>>Wszystkie</option>
          <?php foreach (TiLicencjeManager::STATUSES as $k => $lbl): ?>
          <option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="f-form">Forma rozliczenia</label>
        <select class="form-select" id="f-form" name="form">
          <option value="">Wszystkie</option>
          <?php foreach (TiLicencjeManager::BILLING_FORMS as $code => $bf_meta): ?>
          <option value="<?= $code ?>"<?= $f['form'] === $code ? ' selected' : '' ?>><?= $code ?> — <?= h($bf_meta['label']) ?></option>
          <?php endforeach; ?>
          <option value="none"<?= $f['form'] === 'none' ? ' selected' : '' ?>>Brak formy (do uzupełnienia)</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label" for="f-lic">Oprogramowanie</label>
        <select class="form-select" id="f-lic" name="license_id">
          <option value="0">Wszystkie</option>
          <?php foreach ($catalog as $l): ?>
          <option value="<?= (int)$l['id'] ?>"<?= $f['license_id'] === (int)$l['id'] ? ' selected' : '' ?>><?= h($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtruj</button>
        <a class="btn btn-outline-secondary" href="licencjeKursantow.php">Wyczyść</a>
      </div>
    </div>
    <?php if ($f['sort'] !== 'client'): ?><input type="hidden" name="sort" value="<?= h($f['sort']) ?>"><?php endif; ?>
    <?php if ($f['dir'] !== 'asc'): ?><input type="hidden" name="dir" value="<?= h($f['dir']) ?>"><?php endif; ?>
    <?php if ($f['client_id']): ?><input type="hidden" name="client_id" value="<?= (int)$f['client_id'] ?>"><?php endif; ?>
  </div>
</form>

<!-- Lista -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header d-flex align-items-center gap-2 flex-wrap">
    <span class="fw-semibold"><i class="bi bi-people me-2 text-primary" aria-hidden="true"></i>Przypisane licencje</span>
    <span role="status" id="lic-count" class="badge text-bg-secondary">Wyników: <?= (int)$list['total'] ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 lic-table" style="font-size:.85rem">
      <caption class="visually-hidden">Licencje kursantów TI — <?= (int)$list['total'] ?> wyników, strona <?= (int)$list['page'] ?> z <?= (int)$list['pages'] ?></caption>
      <thead class="table-light"><tr>
        <?php foreach (['client' => 'Kursant', 'software' => 'Oprogramowanie'] as $sk => $sl): [$su, $sa, $si] = $sort_link($sk); ?>
        <th scope="col" aria-sort="<?= $sa ?>"><a href="<?= h($su) ?>"><?= $sl ?><?= $si ? '<i class="bi ' . $si . '" aria-hidden="true"></i>' : '' ?></a></th>
        <?php endforeach; ?>
        <?php [$su, $sa, $si] = $sort_link('expires'); ?>
        <th scope="col" aria-sort="<?= $sa ?>">Wydano → <a href="<?= h($su) ?>">ważna do<?= $si ? '<i class="bi ' . $si . '" aria-hidden="true"></i>' : '' ?></a></th>
        <th scope="col">Status</th>
        <?php [$su, $sa, $si] = $sort_link('form'); ?>
        <th scope="col" aria-sort="<?= $sa ?>"><a href="<?= h($su) ?>">Rozliczenie<?= $si ? '<i class="bi ' . $si . '" aria-hidden="true"></i>' : '' ?></a></th>
        <th scope="col" class="text-end">Akcje</th>
      </tr></thead>
      <tbody>
      <?php if (!$list['rows']): ?>
        <tr><td colspan="6" class="text-center text-body-secondary py-4">Brak licencji spełniających kryteria. <a href="licencjeKursantow.php">Wyczyść filtry</a>.</td></tr>
      <?php endif; ?>
      <?php foreach ($list['rows'] as $r):
        $st   = TiLicencjeManager::statusOf($r, $today);
        $left = TiLicencjeManager::daysLeft($r, $today);
        $form = (string)$r['billing_form'];
        $who  = $r['license_name'] . ' — ' . $r['client_name']; ?>
      <tr class="<?= $st === 'revoked' ? 'lic-row-revoked' : '' ?>">
        <th scope="row" class="fw-semibold">
          <?= h($r['client_name']) ?>
          <div class="small fw-normal text-body-secondary">
            <?php if (!empty($r['notified_at'])): ?><i class="bi bi-envelope-check" aria-hidden="true"></i> Powiadomiono <?= h(date('d.m.Y H:i', strtotime((string)$r['notified_at']))) ?>
            <?php else: ?><i class="bi bi-envelope-slash" aria-hidden="true"></i> Nie powiadomiono<?php endif; ?>
          </div>
        </th>
        <td>
          <?= h($r['license_name']) ?>
          <div class="small text-body-secondary">
            <?= $r['vendor'] !== '' ? h($r['vendor']) . ' · ' : '' ?>
            <?php if ($r['login'] !== '' || $r['access_key'] !== ''): ?>
              <?= $r['login'] !== '' ? 'login <span class="lic-mono">' . h($r['login']) . '</span>' : '' ?><?= ($r['login'] !== '' && $r['access_key'] !== '') ? ', ' : '' ?><?= $r['access_key'] !== '' ? 'klucz <span class="lic-mono">' . h(TiLicencjeManager::maskKey($r['access_key'])) . '</span>' : '' ?>
            <?php else: ?>brak danych dostępowych<?php endif; ?>
          </div>
        </td>
        <td class="text-nowrap"><?= h(date_pl($r['issued'])) ?> → <?= !empty($r['expires_at']) ? h(date_pl($r['expires_at'])) : 'bezterminowo' ?></td>
        <td>
          <span class="badge lic-b <?= $ST_UI[$st][1] ?>"><i class="bi <?= $ST_UI[$st][0] ?> me-1" aria-hidden="true"></i><?= h(TiLicencjeManager::STATUSES[$st]) ?></span>
          <div class="small text-body-secondary">
            <?php if ($st === 'expiring'): ?><?= $left === 0 ? 'dziś ostatni dzień' : 'za ' . $days_txt((int)$left) ?>
            <?php elseif ($st === 'expired'): ?><?= $days_txt(abs((int)$left)) ?> temu
            <?php elseif ($st === 'revoked'): ?><?= !empty($r['revoked_at']) ? h(date_pl(substr((string)$r['revoked_at'], 0, 10))) : '' ?><?= $r['revoke_reason'] !== '' ? ' — ' . h($r['revoke_reason']) : '' ?>
            <?php endif; ?>
          </div>
        </td>
        <td>
          <?php if ($form === ''): ?>
            <span class="badge lic-b lic-form-none"><i class="bi bi-question-circle me-1" aria-hidden="true"></i>Brak formy</span>
            <?php if ($st !== 'revoked'): ?><div class="small"><a href="<?= h(lic_url($f, ['edit' => $r['id'], 'page' => $list['page']], '#lic-form')) ?>">uzupełnij</a></div><?php endif; ?>
          <?php else: ?>
            <span class="badge lic-b <?= $FORM_UI[$form][1] ?>"><i class="bi <?= $FORM_UI[$form][0] ?> me-1" aria-hidden="true"></i><?= h($form) ?></span>
            <span class="small"><?= h(TiLicencjeManager::BILLING_FORMS[$form]['label']) ?></span>
            <div class="small text-body-secondary"><?= $r['billing_ref'] !== '' ? '<span class="lic-mono">' . h($r['billing_ref']) . '</span>' : '<span class="fst-italic">brak numeru</span>' ?></div>
          <?php endif; ?>
        </td>
        <td class="text-end text-nowrap">
          <?php if ($st !== 'revoked'): ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(lic_url($f, ['edit' => $r['id'], 'page' => $list['page']], '#lic-form')) ?>" title="Edytuj" aria-label="Edytuj licencję: <?= h($who) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
          <form method="post" class="d-inline" onsubmit="return confirm('Wysłać ponownie powiadomienie (e-mail i SMS) o tej licencji?')">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="notify">
            <input type="hidden" name="_f" value="<?= h($flt_hidden) ?>">
            <input type="hidden" name="assignment_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary" title="Wyślij powiadomienie ponownie" aria-label="Wyślij powiadomienie ponownie: <?= h($who) ?>"><i class="bi bi-bell" aria-hidden="true"></i></button>
          </form>
          <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#licRevokeModal"
                  data-id="<?= (int)$r['id'] ?>" data-who="<?= h($who) ?>" title="Unieważnij" aria-label="Unieważnij licencję: <?= h($who) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
          <?php else: ?><span class="small text-body-secondary">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($list['pages'] > 1): ?>
  <div class="card-footer d-flex justify-content-center">
    <?= pagination_html(paginate($list['total'], $list['per_page'], $list['page'], lic_url($f, ['page' => 1]))) ?>
  </div>
  <?php endif; ?>
</div>

<!-- Katalog oprogramowania -->
<details class="card border-0 shadow-sm mb-3" id="katalog"<?= ($soft_edit || (($_POST['_op'] ?? '') === 'save_license' && $errors) || !$catalog) ? ' open' : '' ?>>
  <summary class="card-header fw-semibold lic-sum"><i class="bi bi-card-list text-primary" aria-hidden="true"></i>Katalog oprogramowania <span class="badge text-bg-secondary"><?= count($catalog) ?></span></summary>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-lg-7">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
            <caption class="visually-hidden">Katalog oprogramowania</caption>
            <thead class="table-light"><tr><th scope="col">Nazwa</th><th scope="col">Przypisań</th><th scope="col" class="text-end">Akcje</th></tr></thead>
            <tbody>
              <?php if (!$catalog): ?><tr><td colspan="3" class="text-center text-body-secondary py-3">Brak pozycji. Dodaj pierwsze oprogramowanie.</td></tr><?php endif; ?>
              <?php foreach ($catalog as $l): ?>
              <tr class="<?= $l['is_active'] ? '' : 'opacity-75' ?>">
                <th scope="row" class="fw-normal">
                  <span class="fw-semibold"><?= h($l['name']) ?></span> <?php if (!$l['is_active']): ?><span class="badge lic-b lic-st-revoked">nieaktywne</span><?php endif; ?>
                  <div class="small text-body-secondary"><?= $l['vendor'] !== '' ? h($l['vendor']) : '' ?><?= $l['category'] !== '' ? ' · ' . h($l['category']) : '' ?><?php if ((int)$l['seats_total'] > 0): ?> · <?= (int)$l['assigned_count'] ?>/<?= (int)$l['seats_total'] ?> miejsc<?php endif; ?></div>
                </th>
                <td><span class="badge text-bg-secondary"><?= (int)$l['assigned_count'] ?></span></td>
                <td class="text-end text-nowrap">
                  <a href="<?= h(lic_url($f, ['soft' => $l['id'], 'page' => $list['page']], '#katalog')) ?>" class="btn btn-sm btn-outline-secondary" title="Edytuj" aria-label="Edytuj: <?= h($l['name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="toggle_license">
                    <input type="hidden" name="_f" value="<?= h($flt_hidden) ?>"><input type="hidden" name="license_id" value="<?= (int)$l['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $l['is_active'] ? 'Wyłącz' : 'Włącz' ?>" aria-label="<?= $l['is_active'] ? 'Wyłącz' : 'Włącz' ?>: <?= h($l['name']) ?>"><i class="bi bi-<?= $l['is_active'] ? 'eye-slash' : 'eye' ?>" aria-hidden="true"></i></button>
                  </form>
                  <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę pozycję z katalogu? Usunąć można tylko oprogramowanie bez przypisań.')">
                    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="delete_license">
                    <input type="hidden" name="_f" value="<?= h($flt_hidden) ?>"><input type="hidden" name="license_id" value="<?= (int)$l['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń" aria-label="Usuń: <?= h($l['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="col-lg-5">
        <h2 class="h6"><?= $soft_edit ? 'Edytuj: ' . h($soft_edit['name']) : 'Dodaj oprogramowanie' ?></h2>
        <form method="post" action="<?= h(lic_url($f, ['page' => $list['page']], '#katalog')) ?>">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save_license">
          <input type="hidden" name="_f" value="<?= h($flt_hidden) ?>"><input type="hidden" name="license_id" value="<?= (int)$soft_fv['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="sw-name">Nazwa <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="sw-name" name="name" value="<?= h($soft_fv['name']) ?>" required maxlength="190" placeholder="np. Adobe Creative Cloud, Canva Pro, ESET">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label" for="sw-vendor">Producent</label><input type="text" class="form-control" id="sw-vendor" name="vendor" value="<?= h($soft_fv['vendor']) ?>" maxlength="190"></div>
            <div class="col-6"><label class="form-label" for="sw-cat">Kategoria</label><input type="text" class="form-control" id="sw-cat" name="category" value="<?= h($soft_fv['category']) ?>" maxlength="190" placeholder="grafika, antywirus…"></div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-8"><label class="form-label" for="sw-url">Link (logowanie / pobranie)</label><input type="url" class="form-control" id="sw-url" name="vendor_url" value="<?= h($soft_fv['vendor_url']) ?>" maxlength="500" placeholder="https://…"></div>
            <div class="col-4"><label class="form-label" for="sw-seats">Liczba miejsc</label><input type="number" class="form-control" id="sw-seats" name="seats_total" min="0" value="<?= (int)$soft_fv['seats_total'] ?>" aria-describedby="sw-seats-help"><div class="form-text" id="sw-seats-help">0 = bez limitu</div></div>
          </div>
          <div class="mb-2"><label class="form-label" for="sw-notes">Notatki</label><textarea class="form-control" id="sw-notes" name="notes" rows="2" maxlength="500"><?= h($soft_fv['notes']) ?></textarea></div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="sw-act"<?= !empty($soft_fv['is_active']) ? ' checked' : '' ?>>
            <label class="form-check-label" for="sw-act">Aktywne (dostępne do przypisania)</label>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $soft_edit ? 'Zapisz zmiany' : 'Dodaj' ?></button>
            <?php if ($soft_edit): ?><a class="btn btn-outline-secondary" href="<?= h(lic_url($f, ['page' => $list['page']], '#katalog')) ?>">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</details>

<!-- Integracja JetBrains (konfiguracja) -->
<?php $jb_enabled = org_setting('jetbrains_enabled') === '1'; $jb_has_token = org_setting('jetbrains_api_token') !== ''; ?>
<details class="card border-0 shadow-sm mb-3" id="jetbrains">
  <summary class="card-header fw-semibold lic-sum"><i class="bi bi-plug text-primary" aria-hidden="true"></i>Integracja JetBrains (API)
    <span class="badge lic-b <?= $jb_enabled ? 'lic-st-active' : 'lic-st-revoked' ?>"><?= $jb_enabled ? 'Włączona' : 'Wyłączona' ?></span></summary>
  <div class="card-body">
    <p class="text-body-secondary small">Miejsce na dane dostępowe do API JetBrains (np. Floating License Server). Same pola konfiguracyjne — automatyczne przydzielanie i odbieranie licencji trzeba dopiąć osobno, gdy będzie znany konkretny produkt JetBrains.</p>
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="jetbrains_save"><input type="hidden" name="_f" value="<?= h($flt_hidden) ?>">
      <div class="col-auto">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" name="jetbrains_enabled" id="jbEnabled"<?= $jb_enabled ? ' checked' : '' ?>>
          <label class="form-check-label" for="jbEnabled">Włączona</label>
        </div>
      </div>
      <div class="col-12 col-md-4"><label class="form-label" for="jbUrl">URL serwera / API</label><input type="text" class="form-control" id="jbUrl" name="jetbrains_api_url" value="<?= h(org_setting('jetbrains_api_url')) ?>" placeholder="np. https://license-server.wewnetrzny.pl"></div>
      <div class="col-12 col-md-4"><label class="form-label" for="jbToken">Token API <span class="fw-normal text-body-secondary">(puste = bez zmiany)</span></label><input type="password" class="form-control" id="jbToken" name="jetbrains_api_token" autocomplete="new-password" placeholder="<?= $jb_has_token ? '••••••••' : '' ?>"></div>
      <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz</button></div>
    </form>
  </div>
</details>

</div>
</main>

<!-- Unieważnienie licencji (jedno okno dla całej listy; poza tabelą) -->
<div class="modal fade" id="licRevokeModal" tabindex="-1" aria-labelledby="licRevokeTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="revoke">
      <input type="hidden" name="_f" value="<?= h($flt_hidden) ?>">
      <input type="hidden" name="assignment_id" id="licRevokeId" value="">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="licRevokeTitle">Unieważnij licencję</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p>Unieważnisz: <strong id="licRevokeWho"></strong>. Kursant przestanie ją widzieć w panelu; przypisanie zostaje w historii rozliczeń.</p>
        <label class="form-label" for="licRevokeReason">Powód <span class="fw-normal text-body-secondary">(opcjonalnie)</span></label>
        <textarea class="form-control" id="licRevokeReason" name="reason" rows="2" maxlength="300"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Unieważnij licencję</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  // Forma rozliczenia: podpis i wymagalność pola „numer / kod” zależą od wybranej formy.
  var ref = document.getElementById('lic-ref'), lbl = document.getElementById('lic-ref-label'), req = document.getElementById('lic-ref-req');
  function syncRef() {
    var r = document.querySelector('input[name="billing_form"]:checked');
    if (!r || !ref) return;
    lbl.textContent = r.getAttribute('data-ref-label');
    var must = r.getAttribute('data-ref-required') === '1';
    ref.required = must; req.hidden = !must;
  }
  document.querySelectorAll('input[name="billing_form"]').forEach(function (r) { r.addEventListener('change', syncRef); });
  syncRef();
  // Okno unieważnienia: wstaw id i opis wybranej licencji.
  var m = document.getElementById('licRevokeModal');
  if (m) m.addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget; if (!b) return;
    document.getElementById('licRevokeId').value = b.getAttribute('data-id');
    document.getElementById('licRevokeWho').textContent = b.getAttribute('data-who');
  });
})();
</script>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
