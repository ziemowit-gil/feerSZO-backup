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
        $fields_data = $collected['data'];
    }

    // Załącznik
    $plik = null;
    if (empty($errors) && !empty($_FILES['plik']['tmp_name']) && ($app_type['allow_attachment'] ?? 1)) {
        $plik = handle_letter_upload('plik');
        if ($plik === null) $errors[] = 'Błąd przesyłania pliku. Dozwolone: PDF, DOCX, JPG, PNG (maks. 30 MB).';
    }

    if (empty($errors)) {
        db_insert('user_applications', [
            'user_id'       => $user['id'],
            'type_id'       => $type_id,
            'contract_type' => $contract_type,
            'contract_id'   => $contract_id,
            'tytul'         => $tytul,
            'fields_json'   => json_encode($fields_data, JSON_UNESCAPED_UNICODE),
            'plik'          => $plik,
            'status'        => 'nowy',
        ]);
        flash_set('success', 'Pismo / wniosek został wysłany. Administrator wróci do Ciebie wkrótce.');
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
    "SELECT a.*, t.label AS type_label, t.icon AS type_icon
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

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<div class="pv-wrap">

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i>Wnioski</h1>
    <p class="pv-page-sub">Składaj wnioski związane z umową wolontariacką</p>
  </div>
</div>

<?= flash_html() ?>

<?php if ($_is_volunteer_only): ?>

<?php if (!$types): ?>
<div class="tz-card mb-4">
  <div class="tz-card__hd"><i class="bi bi-tools me-2" aria-hidden="true"></i>Brak typów wniosków</div>
  <div class="tz-card__bd">
    <div class="tz-empty">
      <i class="bi bi-tools" aria-hidden="true"></i>
      <p class="mt-3 mb-0">Administrator jeszcze nie skonfigurował typów wniosków. Wróć później.</p>
    </div>
  </div>
</div>
<?php elseif (!$contracts): ?>
<div class="tz-card mb-4">
  <div class="tz-card__hd"><i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i>Brak umów</div>
  <div class="tz-card__bd">
    <div class="tz-empty">
      <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
      <p class="mt-3 mb-0">Nie masz żadnych umów powiązanych z kontem. Skontaktuj się z administratorem.</p>
    </div>
  </div>
</div>
<?php else: ?>

<div class="tz-card mb-4">
  <div class="tz-card__hd"><i class="bi bi-pencil-square me-2" aria-hidden="true"></i>Nowe pismo / wniosek</div>
  <div class="tz-card__bd">

    <?php if ($errors): ?>
    <div class="pv-alert pv-alert-err" role="alert">
      <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate id="apply-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-4">
        <label class="form-label fw-semibold">Typ pisma / wniosku <span class="text-danger">*</span></label>
        <div class="row g-2">
          <?php foreach ($types as $t):
              $sel = $selected_type_id === $t['id']; ?>
          <div class="col-sm-6">
            <input type="radio" class="btn-check type-radio" name="type_id"
                   id="type_<?= $t['id'] ?>" value="<?= $t['id'] ?>"
                   data-requires-contract="<?= $t['requires_contract'] ?>"
                   data-allow-attachment="<?= $t['allow_attachment'] ?>"
                   <?= $sel ? 'checked' : '' ?> required>
            <label class="tz-card d-flex align-items-center gap-2 w-100 text-start p-3"
                   for="type_<?= $t['id'] ?>">
              <i class="bi <?= h($t['icon']) ?> flex-shrink-0" aria-hidden="true"></i>
              <div>
                <div class="fw-semibold"><?= h($t['label']) ?></div>
                <?php if ($t['description']): ?>
                <div class="text-muted"><?= h($t['description']) ?></div>
                <?php endif; ?>
              </div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($_term_module): ?>
        <div class="tz-note mt-2">
          <i class="bi bi-info-circle" aria-hidden="true"></i>
          <span class="flex-grow-1">Chcesz rozwiązać umowę? Skorzystaj z dedykowanego modułu.</span>
          <a href="<?= APP_URL ?>/panel/terminations.php" class="tz-btn tz-btn--ghost ms-2">
            <i class="bi bi-file-earmark-x me-1" aria-hidden="true"></i>Zakończ współpracę
          </a>
        </div>
        <?php endif; ?>
      </div>

      <div class="mb-3" id="contract-row">
        <label for="contract" class="form-label fw-semibold">
          Powiązana umowa <span id="contract-req-star" class="text-danger" style="display:none">*</span>
        </label>
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

      <div class="mb-3">
        <label for="tytul" class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
        <input type="text" id="tytul" name="tytul" class="form-control"
               value="<?= h($_POST['tytul'] ?? '') ?>"
               placeholder="Krótki tytuł pisma lub wniosku" maxlength="255"
               required aria-required="true">
      </div>

      <?php foreach ($types as $t) { ?>
      <div class="type-fields" id="fields-<?= $t['id'] ?>"
           style="<?= $selected_type_id !== $t['id'] ? 'display:none' : '' ?>">
        <?php foreach ($t['fields'] as $f) {
            $val   = $_POST['field_' . $f['name']] ?? '';
            $ph    = h($f['placeholder']);
            $fid   = 'fld_' . h($f['name']) . '_' . $t['id'];
            $fname = 'field_' . h($f['name']);
        ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="<?= $fid ?>">
            <?= h($f['label']) ?>
            <?php if ($f['required']): ?><span class="text-danger">*</span><?php endif; ?>
          </label>
          <?php if ($f['field_type'] === 'textarea'): ?>
          <textarea name="<?= $fname ?>" id="<?= $fid ?>" class="form-control" rows="4"
                    placeholder="<?= $ph ?>"
                    <?= $f['required'] ? 'aria-required="true"' : '' ?>><?= h($val) ?></textarea>
          <?php elseif ($f['field_type'] === 'select'): ?>
          <select name="<?= $fname ?>" id="<?= $fid ?>" class="form-select"
                  <?= $f['required'] ? 'aria-required="true"' : '' ?>>
            <option value="">— wybierz —</option>
            <?php foreach (app_field_options($f) as $opt): ?>
            <option value="<?= h($opt) ?>"<?= $val === $opt ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <?php elseif ($f['field_type'] === 'checkbox'): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="<?= $fname ?>"
                   id="chk_<?= h($f['name']) ?>_<?= $t['id'] ?>" value="tak"
                   <?= $val === 'tak' ? 'checked' : '' ?>
                   <?= $f['required'] ? 'aria-required="true"' : '' ?>>
            <label class="form-check-label" for="chk_<?= h($f['name']) ?>_<?= $t['id'] ?>">Tak</label>
          </div>
          <?php else: ?>
          <input type="<?= $f['field_type'] === 'date' ? 'date' : ($f['field_type'] === 'number' ? 'number' : 'text') ?>"
                 name="<?= $fname ?>" id="<?= $fid ?>" class="form-control"
                 value="<?= h($val) ?>" placeholder="<?= $ph ?>"
                 <?= $f['required'] ? 'aria-required="true"' : '' ?>>
          <?php endif; ?>
        </div>
        <?php } ?>
        <?php if (!$t['fields']): ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="fld_fallback_<?= $t['id'] ?>">
            Treść / opis <span class="text-danger">*</span>
          </label>
          <textarea name="field__tresc_fallback" id="fld_fallback_<?= $t['id'] ?>"
                    class="form-control" rows="5"
                    placeholder="Opisz szczegółowo swoją sprawę..."
                    aria-required="true"><?= h($_POST['field__tresc_fallback'] ?? '') ?></textarea>
        </div>
        <?php endif; ?>
      </div>
      <?php } ?>

      <div class="mb-4" id="attachment-row" style="display:none">
        <label for="plik" class="form-label fw-semibold">
          Załącznik <span class="text-muted fw-normal">(opcjonalnie)</span>
        </label>
        <input type="file" id="plik" name="plik" class="form-control"
               accept=".pdf,.docx,.jpg,.jpeg,.png">
        <div class="form-text">Dozwolone formaty: PDF, DOCX, JPG, PNG. Maks. 30 MB.</div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="tz-btn">
          <i class="bi bi-send me-2" aria-hidden="true"></i>Wyślij
        </button>
        <a href="<?= APP_URL ?>/panel/index.php" class="tz-btn tz-btn--ghost">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php if ($history): ?>
<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>Moje wnioski
    <span class="tz-badge ms-auto"><?= count($history) ?></span>
  </div>
  <?php foreach ($history as $app):
      $st = $status_map[$app['status']] ?? ['label' => $app['status'], 'class' => 'secondary'];
      $fields_data = json_decode($app['fields_json'] ?? '{}', true) ?: [];
      $st_icon_map = ['primary' => 'bi-clock', 'warning' => 'bi-hourglass-split', 'success' => 'bi-check-circle-fill', 'danger' => 'bi-x-circle-fill'];
      $st_icon = $st_icon_map[$st['class']] ?? 'bi-circle';
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon bg-<?= $st['class'] ?> bg-opacity-15 text-<?= $st['class'] ?>">
      <i class="bi <?= $st_icon ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold"><?= h($app['tytul']) ?></span>
        <span class="pv-status-pill pv-sp-<?= $st['class'] ?>"><?= $st['label'] ?></span>
      </div>
      <div class="text-muted"><?= h($app['type_label'] ?? '') ?></div>
      <?php if ($app['odpowiedz']): ?>
      <div class="mt-1 p-2 rounded bg-body-secondary">
        <i class="bi bi-reply me-1" aria-hidden="true"></i>
        <strong>Odpowiedź:</strong> <?= h($app['odpowiedz']) ?>
        <?php if ($app['odpowiedz_at']): ?>
        <span class="text-muted ms-1"><?= h(substr($app['odpowiedz_at'], 0, 10)) ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($app['plik']): ?>
      <div class="mt-1">
        <a href="<?= h(letter_file_url($app['plik'])) ?>" class="tz-btn tz-btn--ghost py-0 px-2"
           download target="_blank">
          <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Załącznik
        </a>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-muted text-nowrap"><?= h(substr($app['created_at'], 0, 10)) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: /* !$_is_volunteer_only — admin/editor layout */ ?>

<?php if (!$types): ?>
<div class="tz-card mb-4">
  <div class="tz-card__bd">
    <div class="tz-empty">
      <i class="bi bi-tools" aria-hidden="true"></i>
      <p class="mb-0">Administrator jeszcze nie skonfigurował typów wniosków. Wróć później.</p>
    </div>
  </div>
</div>
<?php elseif (!$contracts): ?>
<div class="tz-card mb-4">
  <div class="tz-card__bd">
    <div class="tz-empty">
      <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
      <p class="mb-0">Nie masz żadnych umów powiązanych z kontem. Skontaktuj się z administratorem.</p>
    </div>
  </div>
</div>
<?php else: ?>

<!-- ── Formularz ─────────────────────────────────────────────────────────── -->
<div class="tz-card mb-4">
  <div class="tz-card__hd"><i class="bi bi-pencil-square me-2" aria-hidden="true"></i>Nowe pismo / wniosek</div>
  <div class="tz-card__bd">

    <?php if ($errors): ?>
    <div class="pv-alert pv-alert-err" role="alert">
      <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate id="apply-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <!-- Krok 1: Wybór typu -->
      <div class="mb-4">
        <label class="form-label fw-semibold">Typ pisma / wniosku <span class="text-danger">*</span></label>
        <div class="row g-2">
          <?php foreach ($types as $t):
              $sel = $selected_type_id === $t['id']; ?>
          <div class="col-sm-6 col-lg-4">
            <input type="radio" class="btn-check type-radio" name="type_id"
                   id="type_<?= $t['id'] ?>" value="<?= $t['id'] ?>"
                   data-requires-contract="<?= $t['requires_contract'] ?>"
                   data-allow-attachment="<?= $t['allow_attachment'] ?>"
                   <?= $sel ? 'checked' : '' ?> required>
            <label class="tz-btn tz-btn--ghost w-100 text-start d-flex align-items-center gap-2 py-2 px-3"
                   for="type_<?= $t['id'] ?>">
              <i class="bi <?= h($t['icon']) ?> fs-5 flex-shrink-0" aria-hidden="true"></i>
              <div>
                <div class="fw-semibold"><?= h($t['label']) ?></div>
                <?php if ($t['description']): ?>
                <div class="text-muted"><?= h($t['description']) ?></div>
                <?php endif; ?>
              </div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Krok 2: Powiązana umowa -->
      <div class="mb-3" id="contract-row">
        <label for="contract" class="form-label fw-semibold">
          Powiązana umowa <span id="contract-req-star" class="text-danger" style="display:none">*</span>
        </label>
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

      <!-- Krok 3: Tytuł -->
      <div class="mb-3">
        <label for="tytul" class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
        <input type="text" id="tytul" name="tytul" class="form-control"
               value="<?= h($_POST['tytul'] ?? '') ?>"
               placeholder="Krótki tytuł pisma lub wniosku" maxlength="255"
               required aria-required="true">
      </div>

      <!-- Krok 4: Dynamiczne pola per typ -->
      <?php foreach ($types as $t) { ?>
      <div class="type-fields" id="fields-<?= $t['id'] ?>"
           style="<?= $selected_type_id !== $t['id'] ? 'display:none' : '' ?>">
        <?php foreach ($t['fields'] as $f) {
            $val   = $_POST['field_' . $f['name']] ?? '';
            $ph    = h($f['placeholder']);
            $fid   = 'fld_' . h($f['name']) . '_' . $t['id'];
            $fname = 'field_' . h($f['name']);
        ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="<?= $fid ?>">
            <?= h($f['label']) ?>
            <?php if ($f['required']): ?><span class="text-danger">*</span><?php endif; ?>
          </label>
          <?php if ($f['field_type'] === 'textarea'): ?>
          <textarea name="<?= $fname ?>" id="<?= $fid ?>" class="form-control" rows="4"
                    placeholder="<?= $ph ?>"
                    <?= $f['required'] ? 'aria-required="true"' : '' ?>><?= h($val) ?></textarea>
          <?php elseif ($f['field_type'] === 'select'): ?>
          <select name="<?= $fname ?>" id="<?= $fid ?>" class="form-select"
                  <?= $f['required'] ? 'aria-required="true"' : '' ?>>
            <option value="">— wybierz —</option>
            <?php foreach (app_field_options($f) as $opt): ?>
            <option value="<?= h($opt) ?>"<?= $val === $opt ? ' selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <?php elseif ($f['field_type'] === 'checkbox'): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="<?= $fname ?>"
                   id="chk_<?= h($f['name']) ?>_<?= $t['id'] ?>" value="tak"
                   <?= $val === 'tak' ? 'checked' : '' ?>
                   <?= $f['required'] ? 'aria-required="true"' : '' ?>>
            <label class="form-check-label" for="chk_<?= h($f['name']) ?>_<?= $t['id'] ?>">Tak</label>
          </div>
          <?php else: ?>
          <input type="<?= $f['field_type'] === 'date' ? 'date' : ($f['field_type'] === 'number' ? 'number' : 'text') ?>"
                 name="<?= $fname ?>" id="<?= $fid ?>" class="form-control"
                 value="<?= h($val) ?>" placeholder="<?= $ph ?>"
                 <?= $f['required'] ? 'aria-required="true"' : '' ?>>
          <?php endif; ?>
        </div>
        <?php } ?>
        <?php if (!$t['fields']): ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="fld_fallback_<?= $t['id'] ?>">
            Treść / opis <span class="text-danger">*</span>
          </label>
          <textarea name="field__tresc_fallback" id="fld_fallback_<?= $t['id'] ?>"
                    class="form-control" rows="5"
                    placeholder="Opisz szczegółowo swoją sprawę..."
                    aria-required="true"><?= h($_POST['field__tresc_fallback'] ?? '') ?></textarea>
        </div>
        <?php endif; ?>
      </div>
      <?php } ?>

      <!-- Załącznik -->
      <div class="mb-4" id="attachment-row" style="display:none">
        <label for="plik" class="form-label fw-semibold">
          Załącznik <span class="text-muted fw-normal">(opcjonalnie)</span>
        </label>
        <input type="file" id="plik" name="plik" class="form-control"
               accept=".pdf,.docx,.jpg,.jpeg,.png">
        <div class="form-text">Dozwolone formaty: PDF, DOCX, JPG, PNG. Maks. 30 MB.</div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="tz-btn">
          <i class="bi bi-send me-2" aria-hidden="true"></i>Wyślij
        </button>
        <a href="<?= APP_URL ?>/panel/index.php" class="tz-btn tz-btn--ghost">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<!-- ── Historia ──────────────────────────────────────────────────────────── -->
<?php if ($history): ?>
<div class="tz-card">
  <div class="tz-card__hd d-flex align-items-center gap-2">
    <i class="bi bi-clock-history" aria-hidden="true"></i> Moje pisma i wnioski
    <span class="tz-badge ms-auto"><?= count($history) ?></span>
  </div>
  <div class="tz-card__bd p-0">
    <?php foreach ($history as $app):
        $st = $status_map[$app['status']] ?? ['label' => $app['status'], 'class' => 'secondary'];
        $fields_data = json_decode($app['fields_json'] ?? '{}', true) ?: [];
    ?>
    <div class="d-flex align-items-start gap-3 px-4 py-3 border-top">
      <i class="bi <?= h($app['type_icon'] ?? 'bi-file-text') ?> text-primary mt-1 flex-shrink-0 fs-5" aria-hidden="true"></i>
      <div class="flex-grow-1">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
          <span class="fw-semibold"><?= h($app['tytul']) ?></span>
          <span class="pv-status-pill pv-sp-<?= $st['class'] ?>"><?= $st['label'] ?></span>
          <span class="text-muted small ms-auto text-nowrap"><?= h(substr($app['created_at'], 0, 10)) ?></span>
        </div>
        <div class="text-muted small mb-2"><?= h($app['type_label'] ?? $app['type_id']) ?></div>
        <?php if ($fields_data): ?>
        <div class="small text-muted d-flex flex-wrap gap-3 mb-2">
          <?php foreach ($fields_data as $k => $v) { if ($v): ?>
          <span><strong><?= h(str_replace('_', ' ', $k)) ?>:</strong> <?= h($v) ?></span>
          <?php endif; } ?>
        </div>
        <?php endif; ?>
        <?php if ($app['odpowiedz']): ?>
        <div class="pv-alert pv-alert-ok py-2 px-3 mb-0 mt-1 small">
          <i class="bi bi-reply me-1" aria-hidden="true"></i>
          <strong>Odpowiedź:</strong> <?= h($app['odpowiedz']) ?>
          <?php if ($app['odpowiedz_at']): ?>
          <span class="text-muted ms-2"><?= h(substr($app['odpowiedz_at'], 0, 10)) ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($app['plik']): ?>
        <div class="mt-1">
          <a href="<?= h(letter_file_url($app['plik'])) ?>" class="tz-btn tz-btn--ghost py-0 px-2"
             download target="_blank">
            <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Załącznik
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; /* $_is_volunteer_only */ ?>

<script>
(function () {
    const radios   = document.querySelectorAll('.type-radio');
    const allFields= document.querySelectorAll('.type-fields');
    const attRow   = document.getElementById('attachment-row');
    const contrRow = document.getElementById('contract-row');
    const reqStar  = document.getElementById('contract-req-star');

    function switchType(radio) {
        const tid = radio.value;
        allFields.forEach(d => d.style.display = (d.id === 'fields-' + tid) ? '' : 'none');
        attRow.style.display   = radio.dataset.allowAttachment  === '1' ? '' : 'none';
        const reqC = radio.dataset.requiresContract === '1';
        reqStar.style.display  = reqC ? '' : 'none';
    }

    radios.forEach(r => r.addEventListener('change', () => switchType(r)));

    // Init on page load
    const checked = document.querySelector('.type-radio:checked');
    if (checked) switchType(checked);
})();
</script>

</div><!-- /.pv-wrap -->

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
