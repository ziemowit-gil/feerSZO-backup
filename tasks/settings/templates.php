<?php
/**
 * tasks/settings/templates.php
 * Zarządzanie szablonami zadań (checklistami wielokrotnego użytku).
 * Dostęp: tylko is_admin() — jak Zespoły/Obszary zadań.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

if (!is_admin()) {
    flash_set('error', 'Brak uprawnień do zarządzania szablonami.');
    header('Location: ' . APP_URL . '/tasks/dashboard.php'); exit;
}

$templates = task_get_templates(false);
$template_items = [];
foreach ($templates as $t) {
    $template_items[$t['id']] = task_get_template_items($t['id']);
}

$PAGE_TITLE       = 'Szablony zadań';
$TASKS_BREADCRUMB = 'Szablony zadań';
require_once dirname(__DIR__) . '/includes/header_tasks.php';
?>

<style type="text/tailwindcss">
.tp-card { @apply tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-p-4 tw-flex tw-flex-col tw-gap-3; }
.tp-card.tp-inactive { @apply tw-opacity-55; }
.tp-item-row { @apply tw-flex tw-items-center tw-gap-2 tw-py-[.4rem] tw-px-2 tw-rounded-lg tw-bg-slate-50 tw-text-[.83rem]; }
.tp-pri-dot { @apply tw-w-2 tw-h-2 tw-rounded-full tw-shrink-0; }
</style>

<?= flash_html() ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h1 class="tw-text-lg tw-font-bold tw-mb-0 tw-flex tw-items-center tw-gap-2">
      <i class="bi bi-list-check tw-text-blue-600" aria-hidden="true"></i>Szablony zadań
    </h1>
    <p class="tw-text-slate-500 tw-text-sm tw-mb-0">Gotowe zestawy zadań (checklisty) — lider obszaru może je zastosować w wybranej kolumnie z widoku "Wszystkie zadania".</p>
  </div>
  <button type="button" class="btn btn-primary btn-sm" onclick="tpOpenTemplateModal()">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy szablon
  </button>
</div>

<?php if (!$templates): ?>
<div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-p-8 tw-text-center tw-text-slate-500">
  <i class="bi bi-list-check tw-text-3xl tw-block tw-mb-2 tw-opacity-40" aria-hidden="true"></i>
  Brak szablonów. Utwórz pierwszy, np. "Onboarding wolontariusza".
</div>
<?php else: ?>
<div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-2 tw-gap-3" id="tp-template-grid">
  <?php foreach ($templates as $t):
    $pri_dot = [1=>'#94a3b8',2=>'#3b82f6',3=>'#f59e0b',4=>'#dc2626'];
  ?>
  <div class="tp-card <?= $t['is_active'] ? '' : 'tp-inactive' ?>" data-template-id="<?= (int)$t['id'] ?>">
    <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
      <div>
        <div class="tw-font-semibold tw-text-slate-900"><?= h($t['name']) ?></div>
        <?php if ($t['description']): ?>
        <div class="tw-text-[.78rem] tw-text-slate-500 tw-mt-[.1rem]"><?= h($t['description']) ?></div>
        <?php endif; ?>
      </div>
      <?php if (!$t['is_active']): ?>
      <span class="badge bg-secondary" style="font-size:.65rem">Nieaktywny</span>
      <?php endif; ?>
    </div>

    <div class="tw-flex tw-flex-col tw-gap-1" data-item-list>
      <?php foreach ($template_items[$t['id']] as $item): ?>
      <div class="tp-item-row" data-item-id="<?= (int)$item['id'] ?>">
        <span class="tp-pri-dot" style="background:<?= $pri_dot[(int)$item['priority']] ?? '#3b82f6' ?>" aria-hidden="true"></span>
        <span class="tw-flex-1 tw-truncate"><?= h($item['title']) ?></span>
        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:.7rem" onclick="tpDeleteItem(this, <?= (int)$item['id'] ?>)">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <?php endforeach; ?>
      <?php if (!$template_items[$t['id']]): ?>
      <div class="tw-text-[.78rem] tw-text-slate-400">Brak pozycji.</div>
      <?php endif; ?>
    </div>

    <form class="tw-flex tw-gap-2" onsubmit="tpAddItem(event, <?= (int)$t['id'] ?>)">
      <input type="text" class="form-control form-control-sm" placeholder="Nowa pozycja…" maxlength="255" required>
      <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i></button>
    </form>

    <div class="tw-flex tw-gap-2 tw-pt-2 tw-border-t tw-border-slate-100">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick='tpOpenTemplateModal(<?= json_encode(["id"=>(int)$t["id"],"name"=>$t["name"],"description"=>$t["description"]]) ?>)'>
        <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="tpToggleTemplate(<?= (int)$t['id'] ?>)">
        <i class="bi bi-<?= $t['is_active'] ? 'pause' : 'play' ?>-fill me-1" aria-hidden="true"></i><?= $t['is_active'] ? 'Wyłącz' : 'Włącz' ?>
      </button>
      <button type="button" class="btn btn-outline-danger btn-sm tw-ml-auto" onclick="tpDeleteTemplate(<?= (int)$t['id'] ?>, <?= json_encode($t['name']) ?>)">
        <i class="bi bi-trash3" aria-hidden="true"></i>
      </button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Modal: dodaj/edytuj szablon ─────────────────────────────────────────── -->
<div class="modal fade" id="tpTemplateModal" tabindex="-1" aria-labelledby="tpTemplateModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="h6 modal-title fw-bold mb-0" id="tpTemplateModalLabel"><i class="bi bi-list-check me-1 text-primary" aria-hidden="true"></i><span id="tp-template-modal-title">Nowy szablon</span></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="tp-template-id" value="0">
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="tp-name">Nazwa <span class="text-danger">*</span></label>
          <input type="text" id="tp-name" class="form-control" maxlength="150" placeholder="np. Onboarding wolontariusza">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="tp-desc">Opis <span class="text-muted fw-normal">(opcjonalnie)</span></label>
          <textarea id="tp-desc" class="form-control" rows="2"></textarea>
        </div>
        <div id="tp-template-error" class="alert alert-danger py-2 small d-none" role="alert"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm" id="tp-template-save" onclick="tpSaveTemplate()">
          <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  window.TSK_TEMPLATES = { csrf: <?= json_encode(csrf_token()) ?>, base: <?= json_encode(rtrim(APP_URL, '/')) ?> };
</script>
<script src="<?= APP_URL ?>/assets/js/tasks-settings-templates.js" defer></script>

<?php require_once dirname(__DIR__) . '/includes/footer_tasks.php'; ?>
