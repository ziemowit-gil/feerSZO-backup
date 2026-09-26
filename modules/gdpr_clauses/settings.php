<?php
/**
 * modules/gdpr_clauses/settings.php — ustawienia modułu klauzul RODO (tylko admin).
 * Wartości w tabeli settings (prefiks gdpr_), czytane przez GdprClauseService.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/gdpr_clauses.php';

require_role('admin');
new GdprClauseService(); // migracja
$self = APP_URL . '/modules/gdpr_clauses/settings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    org_setting_set('gdpr_require_approval', !empty($_POST['require_approval']) ? '1' : '0');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['approvers'] ?? [])))));
    org_setting_set('gdpr_approvers', implode(',', $ids));
    foreach (array_keys(GDPR_INTEGRATION_CONTEXTS) as $ctx) {
        $v = (string)($_POST['default'][$ctx] ?? '');
        org_setting_set('gdpr_default_' . $ctx, preg_match(GDPR_SLUG_RE, $v) ? $v : '');
    }
    flash_set('success', 'Ustawienia zapisane.');
    header('Location: ' . $self);
    exit;
}

$requireApproval = org_setting('gdpr_require_approval') === '1';
$approvers = array_filter(array_map('intval', explode(',', org_setting('gdpr_approvers'))));
$candidates = db_all("SELECT id, name, email, role FROM users
                      WHERE role IN ('admin','editor') AND COALESCE(is_active,1) = 1 ORDER BY name COLLATE NOCASE");

$PAGE_TITLE = 'Klauzule RODO — ustawienia';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-gear text-primary"></i> Klauzule RODO — ustawienia</h4>
  <a href="<?= APP_URL ?>/modules/gdpr_clauses/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Klauzule</a>
</div>

<?= flash_html() ?>

<form method="post" class="card shadow-sm" style="max-width:760px">
  <?= csrf_field() ?>
  <div class="card-header bg-white fw-semibold small"><i class="bi bi-check2-square me-1"></i>Obieg akceptacji</div>
  <div class="card-body">
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" role="switch" id="req" name="require_approval" value="1" <?= $requireApproval ? 'checked' : '' ?>>
      <label class="form-check-label" for="req">Zmiany opublikowanych klauzul i publikacja wymagają zatwierdzenia</label>
    </div>
    <p class="small text-muted">
      Gdy włączone, zmiana wprowadzona przez osobę spoza listy zatwierdzających trafia do wersji roboczej —
      publicznie obowiązuje dotychczasowa treść, dopóki ktoś z listy jej nie zatwierdzi. Nieopublikowane szkice
      można edytować swobodnie. Zatwierdzający zapisują zmiany od razu (z adnotacją, kto zatwierdził wersję).
    </p>

    <fieldset>
      <legend class="small fw-semibold mb-1">Zatwierdzający (np. IOD, zarząd)</legend>
      <p class="small text-muted mb-2">Nikt nie zaznaczony = zatwierdzają wszyscy administratorzy.</p>
      <div class="row row-cols-md-2 g-1">
        <?php foreach ($candidates as $u): ?>
          <div class="col">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="approvers[]" value="<?= (int)$u['id'] ?>" id="ap<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $approvers, true) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="ap<?= (int)$u['id'] ?>"><?= h($u['name']) ?> <span class="text-muted">(<?= h($u['role']) ?>)</span></label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </fieldset>
  </div>
  <div class="card-header bg-white fw-semibold small border-top"><i class="bi bi-diagram-3 me-1"></i>Klauzule domyślne w innych modułach</div>
  <div class="card-body">
    <p class="small text-muted">
      Klauzula z rejestru, którą pokazują formularze systemu, gdy nie wybrano innej przy ofercie / wydarzeniu /
      celu zgody. „Brak” = moduł zostaje przy swoim dotychczasowym tekście. Akceptacje klauzul z rejestru trafiają
      do <a href="<?= APP_URL ?>/modules/gdpr_clauses/acceptances.php">rejestru akceptacji</a>.
    </p>
    <?php foreach (GDPR_INTEGRATION_CONTEXTS as $ctx => $label): ?>
      <div class="row g-2 align-items-center mb-2">
        <label class="col-md-6 col-form-label col-form-label-sm" for="def-<?= h($ctx) ?>"><?= h($label) ?></label>
        <div class="col-md-6"><?= str_replace('class="form-select"', 'class="form-select form-select-sm"',
            gdpr_clauses_select('default[' . $ctx . ']', gdpr_clauses_default_slug($ctx), '— brak (tekst wbudowany) —', 'def-' . $ctx)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer bg-white text-end"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button></div>
</form>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
