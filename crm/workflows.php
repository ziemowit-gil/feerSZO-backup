<?php
/**
 * crm/workflows.php — przepływy (szablony akcji).
 *
 * Lista + edytor. Przepływ to nazwana sekwencja kroków, które jednym kliknięciem
 * tworzą komplet wpisów (kontakt → sprawa → zadanie…). Uruchamia się je
 * z pływającego widżetu szybkich akcji (Alt+N → zakładka „Przepływ").
 *
 * Prywatne przepływy widzi i edytuje ich autor; wspólne (dla całego zespołu)
 * zakłada administrator.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_workflows.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}

$PAGE_TITLE = 'Przepływy';
$uid    = (int)(current_user()['id'] ?? 0);
$types  = crm_quick_types();
// Kroki typu „zadanie" tworzą zadania CRM (includes/crm_tasks.php), a te nie mają
// list ani obszarów — do wskazania jest osoba, która ma je zrobić.
require_once dirname(__DIR__) . '/includes/crm_owner_rules.php';
$people = crm_owner_candidates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'save') {
        $steps = [];
        foreach ((array)($_POST['step_type'] ?? []) as $i => $t) {
            $steps[] = [
                'type'    => (string)$t,
                'title'   => (string)($_POST['step_title'][$i] ?? ''),
                'owner_id' => (int)($_POST['step_owner'][$i] ?? 0),
            ];
        }
        $id = crm_workflow_save([
            'name'        => $_POST['name'] ?? '',
            'description' => $_POST['description'] ?? '',
            'icon'        => $_POST['icon'] ?? 'bi-diagram-3',
            'is_active'   => !empty($_POST['is_active']),
            'shared'      => !empty($_POST['shared']),
            'steps'       => $steps,
        ], (int)($_POST['id'] ?? 0) ?: null);

        flash_set($id ? 'success' : 'danger', $id
            ? 'Przepływ zapisany.'
            : 'Nie zapisano — przepływ potrzebuje nazwy i co najmniej jednego kroku z tytułem.');
        header('Location: ' . APP_URL . '/crm/workflows.php'); exit;
    }

    if ($op === 'delete') {
        $ok = crm_workflow_delete((int)($_POST['id'] ?? 0));
        flash_set($ok ? 'success' : 'danger', $ok ? 'Przepływ usunięty.' : 'Nie udało się usunąć.');
        header('Location: ' . APP_URL . '/crm/workflows.php'); exit;
    }
}

// ?edit=new (nowy) albo ?edit=<id> (istniejący). Rzutowanie 'new' na int dawało 0
// i formularz w ogóle się nie renderował — przycisk „Nowy przepływ" wyglądał na martwy.
$edit_param = trim((string)($_GET['edit'] ?? ''));
$edit_id    = ctype_digit($edit_param) ? (int)$edit_param : 0;
$edit       = $edit_id ? crm_workflow($edit_id) : null;
if ($edit && !crm_workflow_can_edit($edit)) { $edit = null; $edit_id = 0; $edit_param = ''; }
$show_form  = $edit_param !== '' && ($edit_id === 0 || $edit !== null);
$edit_steps = $edit ? crm_workflow_steps($edit) : [];

$rows = crm_workflows_for($uid, false);

include __DIR__ . '/includes/header_crm.php';
?>
<style>
.wf-card { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:.9rem 1rem; margin-bottom:.7rem }
.wf-name { font-size:.95rem; font-weight:700; color:#111827; display:flex; align-items:center; gap:.45rem }
.wf-steps { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.5rem }
.wf-step { display:inline-flex; align-items:center; gap:.3rem; font-size:.76rem; color:#374151;
  background:#F3F4F6; border-radius:2rem; padding:.15rem .6rem }
.wf-step b { font-weight:700; color:#111827 }
.wf-arrow { color:#9CA3AF; font-size:.7rem; align-self:center }
.wf-badge { font-size:.68rem; font-weight:700; padding:.05rem .45rem; border-radius:2rem }
.wf-row { display:grid; grid-template-columns:150px 1fr 190px 34px; gap:.4rem; margin-bottom:.4rem; align-items:center }
@media (max-width:767px) { .wf-row { grid-template-columns:1fr } }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-diagram-3 me-2" style="color:#0176D3"></i>Przepływy</h1>
  <span class="text-muted small">szablony akcji — jedno kliknięcie zamiast pięciu formularzy</span>
  <a class="btn btn-crm-primary btn-sm ms-auto" href="<?= APP_URL ?>/crm/workflows.php?edit=new">
    <i class="bi bi-plus-lg me-1"></i>Nowy przepływ</a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <?php if (!$rows): ?>
    <div class="wf-card text-muted small">
      Nie masz jeszcze żadnego przepływu. Przykład, od którego warto zacząć:
      <strong>„Nowy lead"</strong> — krok 1 kontakt <code>{tytul}</code>, krok 2 sprawa
      <code>Zapytanie: {tytul}</code>, krok 3 zadanie <code>Zadzwonić do {kontakt}</code>.
    </div>
    <?php endif; ?>

    <?php foreach ($rows as $wf): $steps = crm_workflow_steps($wf); ?>
    <div class="wf-card">
      <div class="d-flex align-items-start gap-2">
        <div class="flex-grow-1">
          <div class="wf-name">
            <i class="bi <?= h($wf['icon']) ?>" style="color:#0176D3"></i><?= h($wf['name']) ?>
            <?php if (empty($wf['owner_id'])): ?>
            <span class="wf-badge" style="background:#EFF6FF;color:#1D4ED8">wspólny</span>
            <?php endif; ?>
            <?php if ((int)$wf['is_active'] !== 1): ?>
            <span class="wf-badge" style="background:#F3F4F6;color:#6B7280">wyłączony</span>
            <?php endif; ?>
          </div>
          <?php if ($wf['description']): ?>
          <div class="text-muted" style="font-size:.8rem"><?= h($wf['description']) ?></div>
          <?php endif; ?>
          <div class="wf-steps">
            <?php foreach ($steps as $i => $s): ?>
            <?php if ($i): ?><span class="wf-arrow">→</span><?php endif; ?>
            <span class="wf-step"><b><?= h($types[$s['type']]['label'] ?? $s['type']) ?></b><?= h($s['title']) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="d-flex gap-1">
          <?php if (crm_workflow_can_edit($wf)): ?>
          <a class="btn btn-outline-secondary btn-sm py-0 px-2" href="?edit=<?= (int)$wf['id'] ?>" title="Edytuj">
            <i class="bi bi-pencil"></i>
          </a>
          <form method="post" onsubmit="return confirm('Usunąć przepływ „<?= h(addslashes($wf['name'])) ?>”?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_op" value="delete">
            <input type="hidden" name="id" value="<?= (int)$wf['id'] ?>">
            <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
          <button type="button" class="btn btn-crm-outline btn-sm py-0 px-2"
                  title="Uruchom przepływ z widżetu szybkich akcji"
                  onclick="if (window.QuickActions) QuickActions.open('workflow');">
            <i class="bi bi-play-fill"></i> Uruchom
          </button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="col-lg-5">
    <?php if ($show_form): ?>
    <div class="wf-card">
      <h2 class="h6 fw-bold mb-2"><?= $edit ? 'Edycja przepływu' : 'Nowy przepływ' ?></h2>
      <form method="post" id="wfForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="wfName">Nazwa <span class="text-danger">*</span></label>
          <input class="form-control form-control-sm" id="wfName" name="name" required maxlength="120"
                 value="<?= h($edit['name'] ?? '') ?>" placeholder="np. Nowy lead ze strony">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="wfDesc">Opis</label>
          <input class="form-control form-control-sm" id="wfDesc" name="description" maxlength="500"
                 value="<?= h($edit['description'] ?? '') ?>" placeholder="Kiedy tego używać">
        </div>

        <div class="mb-1 d-flex align-items-center">
          <span class="form-label small fw-semibold mb-0">Kroki</span>
          <button type="button" class="btn btn-link btn-sm ms-auto p-0" id="wfAddBtn">
            <i class="bi bi-plus-lg"></i> dodaj krok
          </button>
        </div>
        <div id="wfSteps"></div>
        <div class="form-text mb-2" style="font-size:.72rem">
          W tytule działają znaczniki: <code>{tytul}</code> (to, co wpiszesz przy uruchomieniu),
          <code>{kontakt}</code> (nazwa kontaktu), <code>{data}</code>. Kroki wykonują się po kolei,
          a kontakt utworzony w pierwszym kroku przechodzi do następnych.
        </div>

        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_active" id="wfActive" value="1"
                 <?= (!$edit || (int)$edit['is_active'] === 1) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="wfActive">Aktywny (widoczny w szybkich akcjach)</label>
        </div>
        <?php if (is_admin()): ?>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="shared" id="wfShared" value="1"
                 <?= ($edit && empty($edit['owner_id'])) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="wfShared">Wspólny — dostępny dla całego zespołu</label>
        </div>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-2">
          <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
          <a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/crm/workflows.php">Anuluj</a>
        </div>
      </form>
    </div>
    <?php else: ?>
    <div class="wf-card text-muted small">
      <strong class="d-block mb-1 text-dark">Jak to działa</strong>
      Przepływ uruchamiasz z pływającego widżetu (<span class="ib-no">Alt</span>+<span class="ib-no">N</span>)
      — wpisujesz jedno zdanie, a system zakłada wszystkie wpisy z listy kroków.
      Przepływ nie ma akceptacji ani ról; od procesów z decyzjami są
      <a href="<?= APP_URL ?>/obiegi/">Obiegi</a>, a od reakcji na zdarzenia — automatyzacje CRM.
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
var WF_TYPES = <?= json_encode(array_map(static fn($k, $t) => ['key' => $k, 'label' => $t['label']],
                    array_keys($types), $types), JSON_UNESCAPED_UNICODE) ?>;
var WF_PEOPLE = <?= json_encode(array_map(
    static fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name']], $people), JSON_UNESCAPED_UNICODE) ?>;
var WF_INIT  = <?= json_encode($edit_steps, JSON_UNESCAPED_UNICODE) ?>;

function wfAddStep(step) {
  var wrap = document.getElementById('wfSteps');
  if (!wrap) return;
  var i = wrap.children.length;
  var s = step || {type: 'task', title: '', owner_id: 0};

  var row = document.createElement('div');
  row.className = 'wf-row';
  row.innerHTML =
    '<select name="step_type[]" class="form-select form-select-sm">' +
      WF_TYPES.map(function (t) {
        return '<option value="' + t.key + '"' + (t.key === s.type ? ' selected' : '') + '>' + t.label + '</option>';
      }).join('') +
    '</select>' +
    '<input name="step_title[]" class="form-control form-control-sm" placeholder="Tytuł, np. Zadzwonić do {kontakt}" value="' +
      String(s.title || '').replace(/"/g, '&quot;') + '">' +
    '<select name="step_owner[]" class="form-select form-select-sm">' +
      '<option value="0">— dla mnie —</option>' +
      WF_PEOPLE.map(function (u) {
        return '<option value="' + u.id + '"' + (Number(s.owner_id) === u.id ? ' selected' : '') + '>' +
               String(u.name).replace(/</g, '&lt;') + '</option>';
      }).join('') +
    '</select>' +
    '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń krok">&times;</button>';

  row.querySelector('button').addEventListener('click', function () { row.remove(); });
  wrap.appendChild(row);
}

(function () {
  if (!document.getElementById('wfSteps')) return;
  var add = document.getElementById('wfAddBtn');
  if (add) add.addEventListener('click', function () { wfAddStep(); });

  if (WF_INIT.length) WF_INIT.forEach(function (s) { wfAddStep(s); });
  else { wfAddStep({type: 'contact', title: '{tytul}'}); wfAddStep({type: 'task', title: 'Zadzwonić do {kontakt}'}); }

  // Bez kroku nie ma czego zapisywać — mówimy to od razu, a nie dopiero po POST
  document.getElementById('wfForm').addEventListener('submit', function (e) {
    if (!document.querySelectorAll('#wfSteps .wf-row').length) {
      e.preventDefault();
      alert('Dodaj przynajmniej jeden krok — przepływ bez kroków nic nie zrobi.');
    }
  });
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
