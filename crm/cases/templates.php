<?php
/**
 * crm/cases/templates.php — szablony spraw.
 *
 * Powtarzalne sprawy (skarga, wniosek o darowiznę, zgłoszenie beneficjenta) mają
 * za każdym razem ten sam tytuł, opis i listę kroków. Szablon wypełnia je przy
 * zakładaniu sprawy; kroki trafiają do opisu jako lista „[ ]" do odhaczenia.
 *
 * Szablony są wspólne dla zespołu — sprawa to obiekt zespołowy, a nie prywatna
 * notatka, więc dzielenie ich per użytkownik tylko mnożyłoby kopie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_case_extras.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('cases', 'write');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}

$PAGE_TITLE = 'Szablony spraw';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'save') {
        $id = crm_case_template_save([
            'name'        => $_POST['name']        ?? '',
            'title_tpl'   => $_POST['title_tpl']   ?? '',
            'description' => $_POST['description'] ?? '',
            'checklist'   => $_POST['checklist']   ?? '',
            'priority'    => $_POST['priority']    ?? 'medium',
            'is_active'   => !empty($_POST['is_active']),
        ], (int)($_POST['id'] ?? 0) ?: null);
        flash_set($id ? 'success' : 'danger', $id ? 'Szablon zapisany.' : 'Szablon musi mieć nazwę.');
        header('Location: ' . APP_URL . '/crm/cases/templates.php'); exit;
    }

    if ($op === 'delete') {
        crm_case_template_delete((int)($_POST['id'] ?? 0));
        flash_set('success', 'Szablon usunięty.');
        header('Location: ' . APP_URL . '/crm/cases/templates.php'); exit;
    }
}

$edit_param = trim((string)($_GET['edit'] ?? ''));
$edit_id    = ctype_digit($edit_param) ? (int)$edit_param : 0;
$edit       = $edit_id ? crm_case_template($edit_id) : null;
$show_form  = $edit_param !== '' && ($edit_id === 0 || $edit !== null);
$rows       = crm_case_templates(false);

$PRIOS = ['low' => 'Niski', 'medium' => 'Średni', 'high' => 'Wysoki'];

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<style>
.ct-card { background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1rem 1.15rem;margin-bottom:.8rem }
.ct-name { font-size:.95rem;font-weight:700;color:#111827 }
.ct-meta { font-size:.78rem;color:#9CA3AF }
.ct-steps { font-size:.78rem;color:#374151;margin:.4rem 0 0;padding-left:1.1rem }
.ct-badge { font-size:.68rem;font-weight:700;padding:.05rem .45rem;border-radius:2rem;background:#F3F4F6;color:#6B7280 }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-journal-text me-2" style="color:#0176D3"></i>Szablony spraw</h1>
  <span class="text-muted small">powtarzalne sprawy zakładane jednym kliknięciem</span>
  <a class="btn btn-crm-primary btn-sm ms-auto" href="<?= APP_URL ?>/crm/cases/templates.php?edit=new">
    <i class="bi bi-plus-lg me-1"></i>Nowy szablon
  </a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <?php if (!$rows): ?>
    <div class="ct-card text-muted small">
      Nie ma jeszcze żadnego szablonu. Dobry pierwszy: <strong>„Zgłoszenie beneficjenta"</strong> —
      tytuł <code>Zgłoszenie: {kontakt}</code>, kroki: sprawdzić dane, umówić rozmowę, założyć teczkę.
    </div>
    <?php endif; ?>

    <?php foreach ($rows as $t): $steps = array_filter(array_map('trim', preg_split('/\R/', (string)$t['checklist']))); ?>
    <div class="ct-card">
      <div class="d-flex align-items-start gap-2">
        <div class="flex-grow-1">
          <div class="ct-name">
            <?= h($t['name']) ?>
            <span class="ct-badge ms-1"><?= h($PRIOS[$t['priority']] ?? $t['priority']) ?></span>
            <?php if ((int)$t['is_active'] !== 1): ?>
            <span class="ct-badge" style="background:#FEF2F2;color:#B91C1C">wyłączony</span>
            <?php endif; ?>
          </div>
          <div class="ct-meta"><?= h($t['title_tpl'] ?: '(tytuł = nazwa szablonu)') ?></div>
          <?php if ($steps): ?>
          <ul class="ct-steps">
            <?php foreach (array_slice($steps, 0, 6) as $st): ?><li><?= h($st) ?></li><?php endforeach; ?>
            <?php if (count($steps) > 6): ?><li class="text-muted">…i <?= count($steps) - 6 ?> więcej</li><?php endif; ?>
          </ul>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-1">
          <a class="btn btn-outline-secondary btn-sm py-0 px-2" href="?edit=<?= (int)$t['id'] ?>" title="Edytuj">
            <i class="bi bi-pencil"></i>
          </a>
          <form method="post" onsubmit="return confirm('Usunąć szablon „<?= h(addslashes($t['name'])) ?>”?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_op" value="delete">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="col-lg-5">
    <?php if ($show_form): ?>
    <div class="ct-card">
      <h2 class="h6 fw-bold mb-2"><?= $edit ? 'Edycja szablonu' : 'Nowy szablon' ?></h2>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="ctName">Nazwa <span class="text-danger">*</span></label>
          <input class="form-control form-control-sm" id="ctName" name="name" required maxlength="120"
                 value="<?= h($edit['name'] ?? '') ?>" placeholder="np. Skarga na usługę">
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="ctTitle">Tytuł zakładanej sprawy</label>
          <input class="form-control form-control-sm" id="ctTitle" name="title_tpl" maxlength="200"
                 value="<?= h($edit['title_tpl'] ?? '') ?>" placeholder="np. Skarga: {kontakt}">
          <div class="form-text" style="font-size:.72rem">Znaczniki: <code>{kontakt}</code>, <code>{data}</code>.</div>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="ctDesc">Opis</label>
          <textarea class="form-control form-control-sm" id="ctDesc" name="description" rows="3"
                    placeholder="Kontekst, na co uważać, czego wymaga procedura"><?= h($edit['description'] ?? '') ?></textarea>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="ctSteps">Kroki (jeden w wierszu)</label>
          <textarea class="form-control form-control-sm" id="ctSteps" name="checklist" rows="5"
                    placeholder="Potwierdzić przyjęcie zgłoszenia&#10;Zebrać dokumenty&#10;Odpowiedzieć w 14 dni"><?= h($edit['checklist'] ?? '') ?></textarea>
          <div class="form-text" style="font-size:.72rem">
            Trafią do opisu sprawy jako lista <code>[ ]</code> — sprawy nie mają własnych zadań,
            więc odhaczasz je w treści albo zakładasz zadania w module Zadań.
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="ctPrio">Priorytet</label>
          <select class="form-select form-select-sm" id="ctPrio" name="priority">
            <?php foreach ($PRIOS as $pk => $pl): ?>
            <option value="<?= h($pk) ?>" <?= ($edit['priority'] ?? 'medium') === $pk ? 'selected' : '' ?>><?= h($pl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="is_active" id="ctActive" value="1"
                 <?= (!$edit || (int)$edit['is_active'] === 1) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ctActive">Aktywny (widoczny przy zakładaniu sprawy)</label>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
          <a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/crm/cases/templates.php">Anuluj</a>
        </div>
      </form>
    </div>
    <?php else: ?>
    <div class="ct-card text-muted small">
      <strong class="d-block mb-1 text-dark">Jak to działa</strong>
      Przy zakładaniu sprawy wybierasz szablon — tytuł, opis i kroki wypełniają się same,
      a Ty poprawiasz, co trzeba. Szablon niczego nie wymusza: to punkt startowy, nie procedura
      z akceptacjami (od tego są <a href="<?= APP_URL ?>/obiegi/">Obiegi</a>).
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
