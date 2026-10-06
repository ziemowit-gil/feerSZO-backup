<?php
/**
 * admin/form_fields.php
 * Konfiguracja wymagalności pól we wszystkich formularzach umów.
 * Klucze settings: form_field_{typ}_{pole} = required | recommended | optional
 *
 * Rejestr typów i pól oraz logika poziomów: modules/form_fields/logic/formFields.php
 * (to samo źródło czytają formularze contracts/{typ}/add.php i edit.php — formFieldsAttach()).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/form_fields/logic/formFields.php';

require_role('admin');
$PAGE_TITLE = 'Wymagane pola w formularzach';

$FORM_TYPES = formFieldsRegistry();
$LEVELS     = formFieldsLevelsMeta();

// ── Migracja starych kluczy wolontariat_field_* → form_field_wolontariat_* ───
foreach (($FORM_TYPES['wolontariat']['steps'] ?? []) as $step => $fields) {
    foreach ($fields as $fkey => $flabel) {
        $old = db_one("SELECT value FROM settings WHERE key_=?", ['wolontariat_field_' . $fkey]);
        if ($old) {
            $new_key = 'form_field_wolontariat_' . $fkey;
            $exists  = db_one("SELECT 1 FROM settings WHERE key_=?", [$new_key]);
            if (!$exists) {
                db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$new_key, $old['value']]);
            }
            db()->prepare("DELETE FROM settings WHERE key_=?")->execute(['wolontariat_field_' . $fkey]);
        }
    }
}

// ── Zapis ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type   = $_POST['_type'] ?? '';
    $action = $_POST['_action'] ?? 'save';
    if (array_key_exists($type, $FORM_TYPES)) {
        $keys = formFieldsKeys($type);
        if ($action === 'reset') {
            // Przywróć domyślne = usuń wpisy (formularz użyje poziomu domyślnego typu)
            foreach ($keys as $fkey) {
                db()->prepare("DELETE FROM settings WHERE key_=?")->execute(['form_field_' . $type . '_' . $fkey]);
            }
            flash_set('success', 'Przywrócono ustawienia domyślne dla: ' . $FORM_TYPES[$type]['label'] . '.');
        } elseif ($action === 'copy' && array_key_exists($_POST['_from'] ?? '', $FORM_TYPES)) {
            // Skopiuj poziomy z innego typu — tylko pola o tych samych kluczach
            $src = formFieldsLevels($_POST['_from']);
            $n = 0;
            foreach ($keys as $fkey) {
                if (isset($src[$fkey])) { formFieldsSave($type, $fkey, $src[$fkey]); $n++; }
            }
            flash_set('success', "Skopiowano poziomy $n wspólnych pól z: " . $FORM_TYPES[$_POST['_from']]['label'] . '.');
        } else {
            foreach ($keys as $fkey) {
                $val = $_POST['field_' . $fkey] ?? 'optional';
                if (!array_key_exists($val, $LEVELS)) $val = 'optional';
                formFieldsSave($type, $fkey, $val);
            }
            flash_set('success', 'Konfiguracja pól zapisana: ' . $FORM_TYPES[$type]['label'] . '.');
        }
        header('Location: ' . APP_URL . '/admin/form_fields.php?type=' . urlencode($type));
        exit;
    }
}

// ── Odczyt ────────────────────────────────────────────────────────────────────
$activeType = $_GET['type'] ?? array_key_first($FORM_TYPES);
if (!array_key_exists($activeType, $FORM_TYPES)) $activeType = array_key_first($FORM_TYPES);

$saved = []; $counts = [];
foreach ($FORM_TYPES as $type => $tdata) {
    $saved[$type]  = formFieldsLevels($type);
    $counts[$type] = ['required' => 0, 'recommended' => 0, 'optional' => 0];
    foreach ($saved[$type] as $lv) $counts[$type][$lv]++;
}
$tdata     = $FORM_TYPES[$activeType];
$suggested = $tdata['suggested'] ?? [];
$cnt       = $counts[$activeType];

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.ff-tabs .nav-link{display:flex;align-items:center;gap:.5rem}
.ff-tabs .nav-link .cnt{font-size:.66rem;font-weight:700;color:#6b7280;background:#f1f5f9;border-radius:999px;padding:.05rem .4rem}
.ff-tabs .nav-link.active .cnt{background:#e0e7ff;color:#3730a3}
.ff-sum{display:flex;gap:.5rem;flex-wrap:wrap}
.ff-sum span{font-size:.78rem;font-weight:600;padding:.25rem .6rem;border-radius:.4rem;border:1px solid}
.ff-quick{font-size:.72rem}
.ff-quick .btn{--bs-btn-padding-y:.1rem;--bs-btn-padding-x:.45rem;--bs-btn-font-size:.7rem}
.ff-row.ff-sug td:first-child::after{content:'';display:none}
.ff-sugmark{font-size:.66rem;color:#6b7280;margin-left:.4rem}
.ff-radio{cursor:pointer;width:1.1rem;height:1.1rem}
tr.ff-hidden{display:none}
.ff-where code{font-size:.72rem}
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Wymagane pola w formularzach</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4">
  <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center"
        style="width:48px;height:48px;flex-shrink:0">
    <i class="bi bi-ui-checks text-primary" style="font-size:1.4rem"></i>
  </span>
  <div>
    <h4 class="mb-0">Wymagane pola w formularzach</h4>
    <div class="text-muted small">Dla każdego pola ustaw poziom — formularz pokaże ostrzeżenie, ale nigdy nie zablokuje zapisu</div>
  </div>
</div>

<?= flash_html() ?>

<!-- Zakładki typów -->
<ul class="nav nav-tabs mb-3 ff-tabs">
  <?php foreach ($FORM_TYPES as $type => $td): ?>
  <li class="nav-item">
    <a class="nav-link <?= $type === $activeType ? 'active' : '' ?>" href="?type=<?= urlencode($type) ?>">
      <i class="bi <?= h($td['icon']) ?>" style="color:<?= h($td['color']) ?>"></i>
      <?= h($td['label']) ?>
      <?php if ($counts[$type]['required'] || $counts[$type]['recommended']): ?>
      <span class="cnt" title="wymagane / zalecane"><?= $counts[$type]['required'] ?>/<?= $counts[$type]['recommended'] ?></span>
      <?php endif; ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- Pasek narzędzi typu -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div class="ff-sum" aria-label="Podsumowanie poziomów">
    <?php foreach ($LEVELS as $lk => $ld): ?>
    <span class="text-<?= $ld['badge'] ?> border-<?= $ld['badge'] ?>-subtle bg-<?= $ld['badge'] ?>-subtle"><i class="bi <?= $ld['icon'] ?> me-1"></i><?= $ld['label'] ?>: <b data-ff-count="<?= $lk ?>"><?= $cnt[$lk] ?></b></span>
    <?php endforeach; ?>
    <span class="text-muted border-light-subtle bg-light">Domyślnie (bez ustawienia): <b><?= $LEVELS[$tdata['default']]['label'] ?></b></span>
  </div>
  <div class="d-flex align-items-center gap-2">
    <div class="input-group input-group-sm" style="width:220px">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="search" class="form-control" id="ffSearch" placeholder="Szukaj pola…" aria-label="Szukaj pola">
    </div>
    <?php if ($suggested): ?>
    <button type="button" class="btn btn-sm btn-outline-primary" id="ffApplySuggested" title="Ustawia proponowane poziomy w formularzu poniżej — zapisz, aby zatwierdzić">
      <i class="bi bi-magic me-1"></i>Zastosuj proponowane
    </button>
    <?php endif; ?>
  </div>
</div>

<form method="post" id="ffForm">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_type" value="<?= h($activeType) ?>">
<input type="hidden" name="_action" value="save">

<?php foreach ($tdata['steps'] as $stepLabel => $fields): ?>
<div class="card mb-3 shadow-sm ff-step">
  <div class="card-header d-flex flex-wrap align-items-center gap-2 py-2" style="background:#f8fafc">
    <i class="bi bi-layers" style="color:<?= h($tdata['color']) ?>"></i>
    <span class="fw-semibold"><?= h($stepLabel) ?></span>
    <span class="text-muted small">· <?= count($fields) ?> pól</span>
    <span class="ms-auto ff-quick text-muted">
      wszystkie w sekcji:
      <?php foreach ($LEVELS as $lk => $ld): ?>
      <button type="button" class="btn btn-outline-<?= $ld['badge'] ?> ms-1" data-ff-set="<?= $lk ?>"><?= $ld['label'] ?></button>
      <?php endforeach; ?>
    </span>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:52%">Pole</th>
          <?php foreach ($LEVELS as $lkey => $ldata): ?>
          <th class="text-center" style="width:16%">
            <span class="badge bg-<?= $ldata['badge'] ?>-subtle text-<?= $ldata['badge'] ?> border border-<?= $ldata['badge'] ?>-subtle">
              <i class="bi <?= $ldata['icon'] ?> me-1"></i><?= $ldata['label'] ?>
            </span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fields as $fkey => $flabel): $cur = $saved[$activeType][$fkey] ?? $tdata['default']; $sug = $suggested[$fkey] ?? 'optional'; ?>
        <tr class="ff-row" data-ff-key="<?= h($fkey) ?>" data-ff-label="<?= h(mb_strtolower($flabel . ' ' . $fkey)) ?>" data-ff-suggested="<?= h($sug) ?>">
          <td class="ps-3">
            <span class="fw-semibold"><?= h($flabel) ?></span>
            <code class="text-muted small ms-2"><?= h($fkey) ?></code>
            <?php if ($sug !== 'optional'): ?><span class="ff-sugmark" title="Poziom proponowany"><i class="bi bi-magic"></i> <?= $LEVELS[$sug]['label'] ?></span><?php endif; ?>
          </td>
          <?php foreach ($LEVELS as $lkey => $ldata): ?>
          <td class="text-center">
            <input type="radio" class="form-check-input ff-radio" name="field_<?= h($fkey) ?>" value="<?= $lkey ?>"
                   aria-label="<?= h($flabel) ?>: <?= $ldata['label'] ?>" <?= $cur === $lkey ? 'checked' : '' ?>>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="alert alert-info d-flex gap-2 mb-0 h-100">
      <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
      <div class="small">
        <strong>Wymagane</strong> — czerwona gwiazdka przy etykiecie; przy zapisie dymek „Brakuje wymaganych pól” z wyborem <em>Uzupełnij</em> / <em>Zapisz mimo to</em>.<br>
        <strong>Zalecane</strong> — żółta kropka przy etykiecie; żółte ostrzeżenie przy przejściu do kolejnej zakładki.<br>
        <strong>Opcjonalne</strong> — bez komunikatów.<br>
        <span class="text-muted">Przyciski „Zapisz roboczo” pomijają sprawdzanie. Pola nieobecne w danym formularzu są ignorowane.</span>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100 ff-where">
      <div class="card-body py-2 small">
        <div class="fw-semibold mb-1"><i class="bi bi-window-stack me-1 text-primary"></i>Gdzie działa ta konfiguracja</div>
        <?php foreach ($tdata['forms'] as $f): ?><div><code><?= h($f) ?></code></div><?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="d-flex flex-wrap align-items-center gap-2">
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Zapisz konfigurację</button>
  <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
  <span class="ms-auto"></span>
  <?php $others = array_diff_key($FORM_TYPES, [$activeType => 1]); ?>
  <div class="input-group input-group-sm" style="width:auto">
    <label class="input-group-text" for="ffCopyFrom">Skopiuj poziomy z</label>
    <select class="form-select" id="ffCopyFrom" form="ffCopyForm" name="_from" style="max-width:220px">
      <?php foreach ($others as $ok => $od): ?><option value="<?= h($ok) ?>"><?= h($od['label']) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline-secondary" form="ffCopyForm" onclick="return confirm('Nadpisać poziomy wspólnych pól wartościami z wybranego typu?')"><i class="bi bi-files me-1"></i>Kopiuj</button>
  </div>
  <button type="submit" class="btn btn-outline-danger btn-sm" form="ffResetForm" onclick="return confirm('Przywrócić ustawienia domyślne (<?= h($LEVELS[$tdata['default']]['label']) ?>) dla wszystkich pól tego typu?')"><i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć domyślne</button>
</div>
</form>

<form method="post" id="ffCopyForm" class="d-none">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_type" value="<?= h($activeType) ?>"><input type="hidden" name="_action" value="copy">
</form>
<form method="post" id="ffResetForm" class="d-none">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_type" value="<?= h($activeType) ?>"><input type="hidden" name="_action" value="reset">
</form>

<script>
(function () {
  var form = document.getElementById('ffForm');
  function recount() {
    var c = { required: 0, recommended: 0, optional: 0 };
    form.querySelectorAll('.ff-radio:checked').forEach(function (r) { c[r.value]++; });
    Object.keys(c).forEach(function (k) { var el = document.querySelector('[data-ff-count="' + k + '"]'); if (el) el.textContent = c[k]; });
  }
  form.addEventListener('change', function (e) { if (e.target.classList.contains('ff-radio')) recount(); });
  // „wszystkie w sekcji: …”
  form.querySelectorAll('[data-ff-set]').forEach(function (b) {
    b.addEventListener('click', function () {
      var lv = b.dataset.ffSet;
      b.closest('.ff-step').querySelectorAll('.ff-row').forEach(function (row) {
        var r = row.querySelector('.ff-radio[value="' + lv + '"]'); if (r) r.checked = true;
      });
      recount();
    });
  });
  // „Zastosuj proponowane”
  var sug = document.getElementById('ffApplySuggested');
  if (sug) sug.addEventListener('click', function () {
    form.querySelectorAll('.ff-row').forEach(function (row) {
      var r = row.querySelector('.ff-radio[value="' + (row.dataset.ffSuggested || 'optional') + '"]'); if (r) r.checked = true;
    });
    recount();
    sug.classList.remove('btn-outline-primary'); sug.classList.add('btn-primary');
    sug.innerHTML = '<i class="bi bi-check-lg me-1"></i>Proponowane ustawione — zapisz';
  });
  // Szukaj pola
  var q = document.getElementById('ffSearch');
  if (q) q.addEventListener('input', function () {
    var v = q.value.trim().toLowerCase();
    form.querySelectorAll('.ff-row').forEach(function (row) { row.classList.toggle('ff-hidden', v !== '' && row.dataset.ffLabel.indexOf(v) < 0); });
    form.querySelectorAll('.ff-step').forEach(function (st) { st.style.display = st.querySelector('.ff-row:not(.ff-hidden)') ? '' : 'none'; });
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
