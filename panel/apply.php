<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/letters.php';
require_once dirname(__DIR__) . '/includes/applications.php';

require_login();
panel_require_enabled('wnioski', 'Wnioski');
$PAGE_TITLE = 'Wyślij pismo / Złóż wniosek';
$user = current_user();

$_db_user = db_one("SELECT microsoft_id FROM users WHERE id = ?", [$user['id']]);
$user['microsoft_id'] = $_db_user['microsoft_id'] ?? '';

// ── Pobierz umowy użytkownika ─────────────────────────────────────────────────
function apply_user_contracts(array $user): array {
    $email = $user['email'] ?? '';
    $ms_id = $user['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];
    $results = [];
    $tables = [
        ['zlecenie',    ['m365_user_id', 'm365_login'],                          'data_zakonczenia'],
        ['wolontariat', ['m365_user_id', 'm365_login', 'email', 'rodzic_email'], 'data_zakonczenia'],
        ['dzielo',      ['m365_user_id', 'm365_login'],                          'termin_oddania'],
        ['praca',       ['email_login'],                                          'data_zakonczenia'],
    ];
    foreach ($tables as [$type, $fields, $end_col]) {
        $conds = []; $params = [];
        foreach ($fields as $f) {
            if ($f === 'm365_user_id' && !$ms_id) continue;
            if (in_array($f, ['email', 'm365_login', 'rodzic_email']) && !$email) continue;
            $conds[]  = "{$f} = ?";
            $params[] = ($f === 'm365_user_id') ? $ms_id : $email;
        }
        if (!$conds) continue;
        $rows = db_all(
            "SELECT id, '{$type}' AS contract_type, numer_umowy, status, {$end_col} AS data_zakonczenia
             FROM umowy_{$type} WHERE (" . implode(' OR ', $conds) . ")",
            $params
        );
        $results = array_merge($results, $rows);
    }
    $seen = [];
    return array_values(array_filter($results, function ($r) use (&$seen) {
        $k = $r['contract_type'] . ':' . $r['id'];
        if (isset($seen[$k])) return false;
        return $seen[$k] = true;
    }));
}

$contracts   = apply_user_contracts($user);
$types       = app_types_with_fields(active_only: true);

// Rozwiązanie umowy ma własny, dedykowany moduł (panel/terminations.php).
// Gdy jest włączony, nie dublujemy go w generycznym kreatorze wniosków —
// usuwamy typ z listy (i z puli prawidłowych type_id, więc POST też go odrzuci).
$_term_module = module_enabled('terminations_enabled');
if ($_term_module) {
    $types = array_values(array_filter($types, fn($t) => ($t['name'] ?? '') !== 'wniosek_rozwiazanie'));
}
// Bez umowy (np. personel biura): tylko typy niewymagające umowy — m.in. wzory ZSW-1, ZSW-2, DOK.
$_all_types_cnt = count($types);
if (!$contracts) {
    $types = array_values(array_filter($types, fn($t) => empty($t['requires_contract'])));
}
$types_by_id = array_column($types, null, 'id');

// ── Obsługa POST ──────────────────────────────────────────────────────────────
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $type_id      = (int)($_POST['type_id'] ?? 0);
    $tytul        = trim($_POST['tytul'] ?? '');
    $contract_key = $_POST['contract'] ?? '';

    $app_type = $types_by_id[$type_id] ?? null;
    if (!$app_type) $errors[] = 'Wybierz typ pisma / wniosku.';
    // Wzory urzędowe (ZSW-1…) — tytuł z nazwy dokumentu, gdy nie wpisano
    if ($tytul === '' && $app_type) $tytul = appDocsDefaultTitle((string)$app_type['name'], ['dokument' => trim((string)($_POST['field_dokument'] ?? ''))]);
    if ($tytul === '') $errors[] = 'Tytuł jest wymagany.';

    $contract_type = ''; $contract_id = 0;
    if ($contract_key !== '') {
        [$ct, $cid] = explode(':', $contract_key . ':0');
        $found = array_filter($contracts, fn($c) => $c['contract_type'] === $ct && (int)$c['id'] === (int)$cid);
        if ($found) { $contract_type = $ct; $contract_id = (int)$cid; }
        else        $errors[] = 'Nieprawidłowa umowa.';
    }

    if ($app_type && ($app_type['requires_contract'] ?? 0) && !$contract_id) {
        $errors[] = 'Ten typ wniosku wymaga wskazania umowy.';
    }

    // Zbierz pola dynamiczne
    $fields_data = [];
    if ($app_type) {
        $collected = app_collect_fields($app_type['fields']);
        $errors    = array_merge($errors, $collected['errors']);
        $fields_data = appDocsApplyLocked($app_type['name'] ?? null, $collected['data'], $user);
    }

    // Załącznik
    $plik = null;
    if (empty($errors) && !empty($_FILES['plik']['tmp_name']) && ($app_type['allow_attachment'] ?? 1)) {
        $plik = handle_letter_upload('plik');
        if ($plik === null) $errors[] = 'Błąd przesyłania pliku. Dozwolone: PDF, DOCX, JPG, PNG (maks. 30 MB).';
    }

    if (empty($errors)) {
        $_new_app_id = db_insert('user_applications', [
            'user_id'       => $user['id'],
            'type_id'       => $type_id,
            'contract_type' => $contract_type,
            'contract_id'   => $contract_id,
            'tytul'         => $tytul,
            'fields_json'   => json_encode($fields_data, JSON_UNESCAPED_UNICODE),
            'plik'          => $plik,
            'status'        => 'nowy',
        ]);
        // Wzory urzędowe (ZSW-1, ZSW-2, DOK): numer, suma kontrolna, rejestracja w EZD (RPW) z PDF
        $_adoc_tpl = appDocsTemplateFor($app_type['name'] ?? null);
        if ($_adoc_tpl) appDocsAfterSubmit((int)$_new_app_id);
        flash_set('success', $_adoc_tpl
            ? 'Wniosek ' . $_adoc_tpl['code'] . ' został złożony elektronicznie. Potwierdzenie złożenia znajdziesz w „Moich wnioskach”.'
            : 'Pismo / wniosek został wysłany. Administrator wróci do Ciebie wkrótce.');
        require_once dirname(__DIR__) . '/includes/notifications.php';
        $admins = db_all("SELECT id FROM users WHERE role IN ('admin','editor') AND is_active=1");
        foreach ($admins as $adm) {
            notif_create((int)$adm['id'], 'system', 'Nowy wniosek od ' . $user['name'], $tytul, APP_URL . '/admin/applications.php');
        }
        header('Location: ' . APP_URL . '/panel/apply.php');
        exit;
    }
}

// ── Historia ──────────────────────────────────────────────────────────────────
$history = db_all(
    "SELECT a.*, t.label AS type_label, t.icon AS type_icon, t.name AS type_name
     FROM user_applications a
     LEFT JOIN application_types t ON t.id = a.type_id
     WHERE a.user_id = ?
     ORDER BY a.created_at DESC LIMIT 30",
    [$user['id']]
);

$status_map = [
    'nowy'        => ['label' => 'Nowy',        'class' => 'primary'],
    'w_trakcie'   => ['label' => 'W trakcie',   'class' => 'warning'],
    'rozpatrzony' => ['label' => 'Rozpatrzony', 'class' => 'success'],
    'odrzucony'   => ['label' => 'Odrzucony',   'class' => 'danger'],
];

$selected_type_id = (int)($_POST['type_id'] ?? 0);

$_is_volunteer_only = panel_volunteer_shell($user); // powłoka panelu: wolontariusz albo ktokolwiek z umową

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
    require_once __DIR__ . '/includes/pv_ui.php'; pv_ui_styles(); // style panelu (.tz-*, .pv-*) także pod powłoką SZO
}
?>

<?php
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0f6fa8');
$_ap_pal = array_values(panelMetroPalette());
?>
<style>
/* Wnioski — METRO: kafle typów (otwierają kreator w oknie) po lewej, moje wnioski po prawej */
.pvm .ap-cols{display:grid;gap:1.5rem;grid-template-columns:1fr;align-items:start}
@media(min-width:1100px){.pvm .ap-cols{grid-template-columns:minmax(0,7fr) minmax(0,4fr)}}
.pvm .ap-types{display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
.pvm .ap-type{display:flex;flex-direction:column;justify-content:space-between;gap:.5rem;min-height:118px;margin:0;padding:.85rem 1rem;background:var(--pvm-bg);color:var(--pvm-ink);border-left:8px solid var(--c);cursor:pointer}
.pvm .ap-type:hover{background:var(--pvm-light-h)}
.pvm .ap-type .ic{font-size:1.6rem;line-height:1;color:var(--c)}
.pvm .ap-type b{display:block;font-size:.92rem;font-weight:600;line-height:1.25}
.pvm .ap-type small{display:block;font-size:.76rem;color:var(--pvm-muted);line-height:1.35}
.pvm .ap-side{position:sticky;top:5rem}
.pvm .ap-h{display:flex;gap:.75rem;background:var(--pvm-light);border-left:6px solid var(--c);padding:.8rem 1rem;margin-bottom:6px}
.pvm .ap-h__ttl{font-weight:600;font-size:.92rem;color:var(--pvm-ink)}
.pvm .ap-h__meta{font-size:.76rem;color:var(--pvm-muted)}
.pvm .ap-h__ans{margin-top:.4rem;background:var(--pvm-bg);padding:.5rem .65rem;font-size:.85rem}
:root[data-theme="hc"] .pvm .ap-type{border:2px solid #000;border-left-width:8px}
</style>

<div class="pv-wrap pvm">

<div class="mt-head">
  <div>
    <div class="mt-kicker">Panel współpracownika · Sprawy</div>
    <h1 class="mt-title">Wnioski</h1>
    <p class="mt-lead">Złóż pismo lub wniosek związany z umową — odpowiedź zobaczysz w „Moich wnioskach”.</p>
  </div>
  <a href="<?= APP_URL ?>/panel/index.php" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i>Pulpit</a>
</div>

<?= flash_html() ?>

<style>
/* Kreator w okienku: kafle rodzajów na stronie → okno z krokami 1–3 (szata Metro, zero zaokrągleń) */
.ap-wiz .modal-content{border:0;border-radius:0}
.ap-wiz .modal-header{background:var(--c,#0f6fa8);color:#fff;border:0;border-radius:0;align-items:flex-start}
.ap-wiz .modal-header .btn-close{filter:invert(1) grayscale(1) brightness(2);opacity:.9}
.ap-wiz .ap-wiz__kicker{font-size:.68rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;opacity:.85}
.ap-wiz .modal-title{font-weight:300;font-size:1.35rem;line-height:1.2}
.ap-wiz .ap-steps{display:flex;gap:4px;margin:0 0 1.1rem;padding:0;list-style:none}
.ap-wiz .ap-steps li{flex:1;display:flex;align-items:center;gap:.5rem;padding:.45rem .65rem;background:#f2f2f2;color:#5f5f5f;font-size:.78rem;font-weight:600}
.ap-wiz .ap-steps li b{display:inline-flex;align-items:center;justify-content:center;width:1.5rem;height:1.5rem;background:#fff;color:#5f5f5f;font-size:.78rem}
.ap-wiz .ap-steps li[aria-current="step"]{background:var(--c,#0f6fa8);color:#fff}
.ap-wiz .ap-steps li[aria-current="step"] b{color:var(--c,#0f6fa8)}
.ap-wiz .ap-steps li.is-done b{background:var(--m-green,#007a00);color:#fff}
.ap-wiz .form-control,.ap-wiz .form-select{border:2px solid #8a8a8a;border-radius:0}
.ap-wiz .form-label{font-size:.82rem;font-weight:600}
.ap-wiz .ap-fields{display:grid;gap:0 1rem;grid-template-columns:1fr}
@media(min-width:576px){.ap-wiz .ap-fields{grid-template-columns:1fr 1fr}}
.ap-wiz .ap-fields>.ap-full{grid-column:1/-1}
.ap-wiz .is-invalid{border-color:var(--m-red,#a20025)!important}
.ap-wiz .ap-sum{margin:0;display:grid;grid-template-columns:minmax(8rem,auto) 1fr;gap:.35rem 1rem;font-size:.86rem}
.ap-wiz .ap-sum dt{font-weight:600;color:#5f5f5f}
.ap-wiz .ap-sum dd{margin:0;white-space:pre-line;word-break:break-word}
.ap-wiz .modal-footer{border-top:1px solid #e1e1e1;justify-content:space-between}
.ap-wiz .mt-btn{display:inline-flex;align-items:center;gap:.4rem;background:var(--c,#0f6fa8);color:#fff;border:2px solid var(--c,#0f6fa8);border-radius:0;padding:.45rem 1rem;font-weight:600;min-height:40px}
.ap-wiz .mt-btn--ghost{background:transparent;color:#111827;border-color:#111827}
.ap-wiz .mt-btn--ghost:hover{background:#111827;color:#fff}
/* Moje wnioski: rozwijane szczegóły zamiast surowych pól */
.pvm .ap-h details{margin-top:.35rem}
.pvm .ap-h summary{cursor:pointer;font-size:.78rem;font-weight:600;color:var(--pvm-ink)}
.pvm .ap-h dl{margin:.35rem 0 0;display:grid;grid-template-columns:minmax(7rem,auto) 1fr;gap:.2rem .8rem;font-size:.8rem;background:var(--pvm-bg);padding:.5rem .65rem}
.pvm .ap-h dt{font-weight:600;color:var(--pvm-muted)}
.pvm .ap-h dd{margin:0;word-break:break-word}
.pvm .ap-type{width:100%;text-align:left;border-top:0;border-right:0;border-bottom:0;font:inherit}
</style>

<?php if (!$types && ($contracts || !$_all_types_cnt)): ?>
<div class="mt-empty mb-4"><i class="bi bi-tools" style="font-size:2rem;display:block;margin-bottom:.4rem" aria-hidden="true"></i><p class="mb-0">Administrator jeszcze nie skonfigurował typów wniosków. Wróć później.</p></div>
<?php elseif (!$types): ?>
<div class="mt-empty mb-4"><i class="bi bi-exclamation-circle" style="font-size:2rem;display:block;margin-bottom:.4rem" aria-hidden="true"></i><p class="mb-0">Nie masz żadnych umów powiązanych z kontem. Skontaktuj się z administratorem.</p></div>
<?php else: ?>

<div class="ap-cols">
<section aria-labelledby="ap-new-h">
  <h2 class="mt-h2" id="ap-new-h">Nowe <b>pismo / wniosek</b></h2>
  <p class="text-muted small mb-2">Wybierz rodzaj — otworzy się okno, które poprowadzi Cię krok po kroku.</p>
  <div class="ap-types">
    <?php foreach ($types as $_ti => $t): ?>
    <button type="button" class="ap-type" data-ap-type="<?= (int)$t['id'] ?>" style="--c:<?= h($_ap_pal[$_ti % 8]) ?>">
      <i class="bi <?= h($t['icon']) ?> ic" aria-hidden="true"></i>
      <span><b><?= h($t['label']) ?></b>
        <?php if ($t['description']): ?><small><?= h($t['description']) ?></small><?php endif; ?></span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php if ($_term_module): ?>
  <div class="tz-note mt-3">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span class="flex-grow-1">Chcesz rozwiązać umowę? Skorzystaj z dedykowanego modułu.</span>
    <a href="<?= APP_URL ?>/panel/terminations.php" class="tz-btn tz-btn--ghost ms-2">
      <i class="bi bi-file-earmark-x me-1" aria-hidden="true"></i>Zakończ współpracę
    </a>
  </div>
  <?php endif; ?>
</section>

<aside class="ap-side" aria-labelledby="ap-hist-h">
  <h2 class="mt-h2" id="ap-hist-h">Moje <b>pisma i wnioski</b><?php if ($history): ?> <span class="mt-no" style="font-size:1rem">(<?= count($history) ?>)</span><?php endif; ?></h2>
  <?php if (!$history): ?>
  <div class="mt-empty"><i class="bi bi-inboxes" style="font-size:2rem;display:block;margin-bottom:.4rem" aria-hidden="true"></i><p class="mb-0">Nie złożono jeszcze żadnego wniosku.</p></div>
  <?php endif; ?>
  <?php foreach ($history as $app):
      $st = $status_map[$app['status']] ?? ['label' => $app['status'], 'class' => 'secondary'];
      $fields_data = json_decode($app['fields_json'] ?? '{}', true) ?: [];
      $st_icon_map = ['primary' => 'bi-clock', 'warning' => 'bi-hourglass-split', 'success' => 'bi-check-circle-fill', 'danger' => 'bi-x-circle-fill'];
      $st_icon = $st_icon_map[$st['class']] ?? 'bi-circle';
      $_apc = ['primary'=>'var(--m-blue)','warning'=>'var(--m-orange)','success'=>'var(--m-green)','danger'=>'var(--m-red)'][$st['class']] ?? 'var(--m-grey)';
      // Etykiety pól z definicji typu (zamiast surowych kluczy „miejscowosc”, „zalaczniki”…)
      $_lbls = [];
      foreach (($types_by_id[(int)$app['type_id']]['fields'] ?? app_type_fields((int)$app['type_id'])) as $_f) $_lbls[$_f['name']] = $_f['label'];
  ?>
  <article class="ap-h" style="--c:<?= $_apc ?>">
    <i class="bi <?= h($app['type_icon'] ?: $st_icon) ?>" style="font-size:1.15rem;color:<?= $_apc ?>" aria-hidden="true"></i>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="ap-h__ttl"><?= h($app['tytul']) ?></span>
        <span class="mt-badge" style="background:<?= $_apc ?>"><?= h($st['label']) ?></span>
      </div>
      <div class="ap-h__meta"><?= h($app['type_label'] ?? '') ?> · <?= h(date('d.m.Y', strtotime($app['created_at']))) ?></div>
      <?php if ($app['odpowiedz']): ?>
      <div class="ap-h__ans">
        <i class="bi bi-reply me-1" aria-hidden="true"></i>
        <strong>Odpowiedź:</strong> <?= h($app['odpowiedz']) ?>
        <?php if ($app['odpowiedz_at']): ?><span class="text-muted ms-1"><?= h(substr($app['odpowiedz_at'], 0, 10)) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php $_fd = array_filter($fields_data, fn($v) => $v !== '' && $v !== null); ?>
      <?php if ($_fd): ?>
      <details>
        <summary>Szczegóły wniosku</summary>
        <dl>
          <?php foreach ($_fd as $k => $v): ?>
          <dt><?= h($_lbls[$k] ?? ucfirst(str_replace('_', ' ', (string)$k))) ?></dt><dd><?= h(is_array($v) ? implode(', ', $v) : (string)$v) ?></dd>
          <?php endforeach; ?>
        </dl>
      </details>
      <?php endif; ?>
      <?php $_adoc = appDocsTemplateFor($app['type_name'] ?? null); ?>
      <?php if ($app['plik'] || $_adoc): ?>
      <div class="mt-1 d-flex flex-wrap gap-1">
        <?php if ($_adoc): ?>
        <a href="<?= h(appDocsUrl((int)$app['id'])) ?>" class="mt-btn mt-btn--ghost mt-btn--sm" style="min-height:32px;padding:.2rem .6rem;font-size:.8rem" target="_blank">
          <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Wniosek <?= h($_adoc['code']) ?>
        </a>
        <a href="<?= h(appDocsUrl((int)$app['id'], true)) ?>" class="mt-btn mt-btn--ghost mt-btn--sm" style="min-height:32px;padding:.2rem .6rem;font-size:.8rem" target="_blank">
          <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
        </a>
        <a href="<?= h(appDocsReceiptUrl((int)$app['id'])) ?>" class="mt-btn mt-btn--ghost mt-btn--sm" style="min-height:32px;padding:.2rem .6rem;font-size:.8rem" target="_blank">
          <i class="bi bi-patch-check me-1" aria-hidden="true"></i>Potwierdzenie złożenia
        </a>
        <?php endif; ?>
        <?php if ($app['plik']): ?>
        <a href="<?= h(letter_file_url($app['plik'])) ?>" class="mt-btn mt-btn--ghost mt-btn--sm" style="min-height:32px;padding:.2rem .6rem;font-size:.8rem" download target="_blank">
          <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Załącznik
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</aside>
</div><!-- /.ap-cols -->

<!-- ═══ Okno kreatora: 1. Umowa i tytuł → 2. Szczegóły → 3. Załącznik i podsumowanie ═══ -->
<div class="modal fade ap-wiz" id="apWizard" tabindex="-1" aria-labelledby="apWizTitle" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <form class="modal-content" method="post" enctype="multipart/form-data" novalidate id="apply-form">
      <div class="modal-header">
        <div>
          <div class="ap-wiz__kicker">Nowe pismo / wniosek</div>
          <h2 class="modal-title" id="apWizTitle">Wniosek</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij bez wysyłania"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <?php foreach ($types as $_ti => $t): ?>
        <input type="radio" class="d-none type-radio" name="type_id" id="type_<?= (int)$t['id'] ?>" value="<?= (int)$t['id'] ?>"
               data-label="<?= h($t['label']) ?>" data-color="<?= h($_ap_pal[$_ti % 8]) ?>"
               data-requires-contract="<?= (int)$t['requires_contract'] ?>" data-allow-attachment="<?= (int)$t['allow_attachment'] ?>"
               <?= $selected_type_id === (int)$t['id'] ? 'checked' : '' ?>>
        <?php endforeach; ?>

        <ol class="ap-steps" aria-label="Kroki wniosku">
          <li data-step-ind="1"><b>1</b>Umowa i tytuł</li>
          <li data-step-ind="2"><b>2</b>Szczegóły</li>
          <li data-step-ind="3"><b>3</b>Załącznik i wysyłka</li>
        </ol>

        <div aria-live="assertive" id="ap-wiz-err">
        <?php if ($errors): ?>
        <div class="pv-alert pv-alert-err" role="alert">
          <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>
        </div>

        <!-- Krok 1 -->
        <div class="ap-step" data-step="1">
          <div class="mb-3" id="contract-row">
            <label for="contract" class="form-label">Powiązana umowa <span id="contract-req-star" class="text-danger" style="display:none">*</span></label>
            <select name="contract" id="contract" class="form-select">
              <option value="">— bez wskazania konkretnej umowy —</option>
              <?php
              $ct_labels = ['zlecenie' => 'Zlecenie', 'wolontariat' => 'Wolontariat', 'dzielo' => 'Dzieło', 'praca' => 'Praca'];
              foreach ($contracts as $c):
                  $key = $c['contract_type'] . ':' . $c['id'];
                  $sel = ($_POST['contract'] ?? '') === $key;
              ?>
              <option value="<?= h($key) ?>"<?= $sel ? ' selected' : '' ?>>
                <?= h($ct_labels[$c['contract_type']] ?? $c['contract_type']) ?>
                <?= h($c['numer_umowy'] ?: '#' . $c['id']) ?>
                <?php if ($c['data_zakonczenia']): ?>(do <?= h($c['data_zakonczenia']) ?>)<?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-1">
            <label for="tytul" class="form-label">Tytuł <span class="text-danger" id="tytul-req">*</span></label>
            <input type="text" id="tytul" name="tytul" class="form-control" value="<?= h($_POST['tytul'] ?? '') ?>"
                   placeholder="Krótki tytuł pisma lub wniosku" maxlength="255" aria-required="true">
            <div class="form-text" id="tytul-auto" style="display:none">Dla wzorów urzędowych tytuł może zostać pusty — nadamy go z nazwy dokumentu.</div>
          </div>
        </div>

        <!-- Krok 2 -->
        <div class="ap-step" data-step="2" hidden>
        <?php foreach ($types as $t) { ?>
          <div class="type-fields" id="fields-<?= (int)$t['id'] ?>" data-official="<?= appDocsTemplateFor($t['name'] ?? null) ? '1' : '0' ?>"
               style="<?= $selected_type_id !== (int)$t['id'] ? 'display:none' : '' ?>">
            <div class="ap-fields">
            <?php foreach ($t['fields'] as $f) {
                $_lock = appDocsFieldLocked($t['name'] ?? null, $f['name']);
                // Wzory ZSW/DOK: data sporządzenia = data złożenia, stała (bez pola w formularzu)
                if ($f['name'] === 'miejscowosc' && appDocsTemplateFor($t['name'] ?? null)): ?>
            <div class="mb-3">
              <label class="form-label" for="fld_data_sp_<?= $t['id'] ?>">Data sporządzenia wniosku</label>
              <input type="text" id="fld_data_sp_<?= $t['id'] ?>" class="form-control" value="<?= h(date('d.m.Y')) ?>" readonly aria-readonly="true"
                     style="background:#f2f2f2;color:#5f5f5f;cursor:not-allowed" aria-describedby="fld_data_sp_<?= $t['id'] ?>_lock">
              <div class="form-text" id="fld_data_sp_<?= $t['id'] ?>_lock"><i class="bi bi-lock me-1" aria-hidden="true"></i>Ustawiana automatycznie — data złożenia wniosku.</div>
            </div>
            <?php endif;
                $val   = $_lock ? appDocsPrefill($t['name'] ?? null, $f['name'], $user)
                                : ($_POST['field_' . $f['name']] ?? appDocsPrefill($t['name'] ?? null, $f['name'], $user));
                $ph    = h($f['placeholder']);
                $fid   = 'fld_' . h($f['name']) . '_' . $t['id'];
                $fname = 'field_' . h($f['name']);
            ?>
            <div class="mb-3<?= in_array($f['field_type'], ['date', 'number'], true) ? '' : ' ap-full' ?>">
              <label class="form-label" for="<?= $fid ?>">
                <?= h($f['label']) ?><?php if ($f['required']): ?> <span class="text-danger">*</span><?php endif; ?>
              </label>
              <?php if ($f['field_type'] === 'textarea'): ?>
              <textarea name="<?= $fname ?>" id="<?= $fid ?>" class="form-control" rows="<?= $_lock ? 2 : 4 ?>"
                        placeholder="<?= $ph ?>" data-sum="<?= h($f['label']) ?>"
                        <?= $_lock ? 'readonly aria-readonly="true" style="background:#f2f2f2;color:#5f5f5f;cursor:not-allowed;resize:none" aria-describedby="' . $fid . '_lock"' : '' ?>
                        <?= $f['required'] ? 'aria-required="true"' : '' ?>><?= h($val) ?></textarea>
              <?php if ($_lock): ?><div class="form-text" id="<?= $fid ?>_lock"><i class="bi bi-lock me-1" aria-hidden="true"></i>Adresat stały — wniosek trafia do organizacji.</div><?php endif; ?>
              <?php elseif ($f['field_type'] === 'select'): ?>
              <select name="<?= $fname ?>" id="<?= $fid ?>" class="form-select" data-sum="<?= h($f['label']) ?>"
                      <?= $f['required'] ? 'aria-required="true"' : '' ?>>
                <option value="">— wybierz —</option>
                <?php foreach (app_field_options($f) as $opt): ?>
                <option value="<?= h($opt) ?>"<?= $val === $opt ? ' selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
              <?php elseif ($f['field_type'] === 'checkbox'): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="<?= $fname ?>" data-sum="<?= h($f['label']) ?>"
                       id="chk_<?= h($f['name']) ?>_<?= $t['id'] ?>" value="tak"
                       <?= $val === 'tak' ? 'checked' : '' ?>
                       <?= $f['required'] ? 'aria-required="true"' : '' ?>>
                <label class="form-check-label" for="chk_<?= h($f['name']) ?>_<?= $t['id'] ?>">Tak</label>
              </div>
              <?php else: ?>
              <input type="<?= $f['field_type'] === 'date' ? 'date' : ($f['field_type'] === 'number' ? 'number' : 'text') ?>"
                     name="<?= $fname ?>" id="<?= $fid ?>" class="form-control" data-sum="<?= h($f['label']) ?>"
                     value="<?= h($val) ?>" placeholder="<?= $ph ?>"
                     <?= $_lock ? 'readonly aria-readonly="true" style="background:#f2f2f2;color:#5f5f5f;cursor:not-allowed" aria-describedby="' . $fid . '_lock"' : '' ?>
                     <?= $f['required'] ? 'aria-required="true"' : '' ?>>
              <?php if ($_lock): ?><div class="form-text" id="<?= $fid ?>_lock"><i class="bi bi-lock me-1" aria-hidden="true"></i>Wnioskodawcą jest zalogowana osoba — dane z konta.</div><?php endif; ?>
              <?php endif; ?>
            </div>
            <?php } ?>
            <?php if (!$t['fields']): ?>
            <div class="mb-3 ap-full">
              <label class="form-label" for="fld_fallback_<?= $t['id'] ?>">Treść / opis <span class="text-danger">*</span></label>
              <textarea name="field__tresc_fallback" id="fld_fallback_<?= $t['id'] ?>" class="form-control" rows="5" data-sum="Treść"
                        placeholder="Opisz szczegółowo swoją sprawę..." aria-required="true"><?= h($_POST['field__tresc_fallback'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>
            </div>
          </div>
        <?php } ?>
        </div>

        <!-- Krok 3 -->
        <div class="ap-step" data-step="3" hidden>
          <div class="mb-3" id="attachment-row" style="display:none">
            <label for="plik" class="form-label">Załącznik <span class="text-muted fw-normal">(opcjonalnie)</span></label>
            <input type="file" id="plik" name="plik" class="form-control" accept=".pdf,.docx,.jpg,.jpeg,.png">
            <div class="form-text">Dozwolone formaty: PDF, DOCX, JPG, PNG. Maks. 30 MB.</div>
          </div>
          <h3 class="h6 fw-semibold mb-2">Sprawdź przed wysłaniem</h3>
          <dl class="ap-sum" id="ap-sum"></dl>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="mt-btn mt-btn--ghost" id="ap-back"><i class="bi bi-arrow-left" aria-hidden="true"></i>Wstecz</button>
        <div class="d-flex gap-2">
          <button type="button" class="mt-btn mt-btn--ghost" data-bs-dismiss="modal">Anuluj</button>
          <button type="button" class="mt-btn" id="ap-next">Dalej<i class="bi bi-arrow-right" aria-hidden="true"></i></button>
          <button type="submit" class="mt-btn" id="ap-send" hidden><i class="bi bi-send" aria-hidden="true"></i>Wyślij</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  const modalEl = document.getElementById('apWizard');
  if (!modalEl || !window.bootstrap) return;
  const modal   = bootstrap.Modal.getOrCreateInstance(modalEl);
  const form    = document.getElementById('apply-form');
  const steps   = Array.from(form.querySelectorAll('.ap-step'));
  const inds    = Array.from(form.querySelectorAll('[data-step-ind]'));
  const btnBack = document.getElementById('ap-back'), btnNext = document.getElementById('ap-next'), btnSend = document.getElementById('ap-send');
  const errBox  = document.getElementById('ap-wiz-err');
  let step = 1;

  function radio() { return form.querySelector('.type-radio:checked'); }
  function curFields() { const r = radio(); return r ? document.getElementById('fields-' + r.value) : null; }

  function selectType(id) {
    const r = document.getElementById('type_' + id);
    if (!r) return;
    r.checked = true;
    form.querySelectorAll('.type-fields').forEach(d => d.style.display = d.id === 'fields-' + id ? '' : 'none');
    document.getElementById('attachment-row').style.display = r.dataset.allowAttachment === '1' ? '' : 'none';
    document.getElementById('contract-req-star').style.display = r.dataset.requiresContract === '1' ? '' : 'none';
    const official = curFields() && curFields().dataset.official === '1';
    document.getElementById('tytul-req').style.display = official ? 'none' : '';
    document.getElementById('tytul-auto').style.display = official ? '' : 'none';
    document.getElementById('apWizTitle').textContent = r.dataset.label;
    modalEl.style.setProperty('--c', r.dataset.color);
  }

  function go(n) {
    step = n;
    steps.forEach(s => s.hidden = Number(s.dataset.step) !== n);
    inds.forEach(li => {
      const k = Number(li.dataset.stepInd);
      if (k === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
      li.classList.toggle('is-done', k < n);
    });
    btnBack.style.visibility = n > 1 ? 'visible' : 'hidden';
    btnNext.hidden = n === 3; btnSend.hidden = n !== 3;
    if (n === 3) summary();
    const first = steps[n - 1].querySelector('input:not([type=hidden]):not([readonly]):not(.d-none),select,textarea:not([readonly])');
    if (first) setTimeout(() => first.focus(), 50);
  }

  // Wymagane pola bieżącego kroku (formularz ma novalidate — serwer i tak sprawdza wszystko)
  function check(n) {
    const scope = n === 2 ? curFields() : steps[n - 1];
    const bad = [];
    scope.querySelectorAll('[aria-required="true"]').forEach(el => {
      if (el.readOnly) return;
      const empty = el.type === 'checkbox' ? !el.checked : !String(el.value).trim();
      el.classList.toggle('is-invalid', empty);
      if (empty) bad.push(el);
    });
    if (n === 1 && radio() && radio().dataset.requiresContract === '1' && !document.getElementById('contract').value) bad.push(document.getElementById('contract'));
    if (n === 1 && !(curFields() && curFields().dataset.official === '1') && !document.getElementById('tytul').value.trim()) bad.push(document.getElementById('tytul'));
    errBox.innerHTML = bad.length ? '<div class="pv-alert pv-alert-err" role="alert">Uzupełnij wymagane pola zaznaczone na czerwono.</div>' : '';
    bad.forEach(el => el.classList.add('is-invalid'));
    if (bad.length) bad[0].focus();
    return !bad.length;
  }

  function summary() {
    const dl = document.getElementById('ap-sum'), rows = [];
    const add = (k, v) => { if (v) rows.push('<dt>' + esc(k) + '</dt><dd>' + esc(v) + '</dd>'); };
    const c = document.getElementById('contract');
    add('Rodzaj', radio() ? radio().dataset.label : '');
    add('Umowa', c.value ? c.options[c.selectedIndex].text.trim() : 'bez wskazania umowy');
    add('Tytuł', document.getElementById('tytul').value.trim() || '(nadany automatycznie)');
    if (curFields()) curFields().querySelectorAll('[data-sum]').forEach(el => {
      add(el.dataset.sum, el.type === 'checkbox' ? (el.checked ? 'Tak' : '') : (el.tagName === 'SELECT' ? (el.value ? el.options[el.selectedIndex].text : '') : el.value.trim()));
    });
    dl.innerHTML = rows.join('');
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[ch]); }

  document.querySelectorAll('[data-ap-type]').forEach(b => b.addEventListener('click', () => {
    selectType(b.dataset.apType); errBox.innerHTML = ''; go(1); modal.show();
  }));
  btnNext.addEventListener('click', () => { if (check(step)) go(step + 1); });
  btnBack.addEventListener('click', () => { errBox.innerHTML = ''; go(Math.max(1, step - 1)); });
  form.addEventListener('input', e => e.target.classList && e.target.classList.remove('is-invalid'));
  form.addEventListener('submit', e => { if (!check(1) ) { e.preventDefault(); go(1); return; } if (!check(2)) { e.preventDefault(); go(2); } else btnSend.disabled = true; });

  // Po błędzie walidacji na serwerze: otwórz okno z poprzednio wybranym rodzajem
  const pre = radio();
  if (pre) { selectType(pre.value); go(1); <?= $errors ? 'modal.show();' : '' ?> } else go(1);
})();
</script>

<?php endif; ?>

</div><!-- /.pv-wrap -->

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
