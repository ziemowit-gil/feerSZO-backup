<?php
/**
 * crm/includes/task_modal.php — okno „Zleć zadanie".
 *
 * Jedno okno na cały moduł: kartoteka, sprawa i ekran zadań otwierają to samo,
 * różnią się tylko kontekstem wpisanym w pola ukryte. Formularz idzie POST-em
 * do crm/tasks.php, które po zapisie wraca pod adres z pola `back` — dzięki temu
 * zlecenie zadania nie wyrzuca nikogo z miejsca, w którym pracował.
 *
 * Zmienne opcjonalne przed include:
 *   $tm_contact_id, $tm_case_id — kontekst zadania
 *   $tm_title_hint              — podpowiedź w polu tytułu
 *
 * Zadania CRM to NIE są zadania modułu Zadań — zob. includes/crm_tasks.php.
 */
require_once dirname(dirname(__DIR__)) . '/includes/crm_tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_owner_rules.php';

$tm_contact_id = (int)($tm_contact_id ?? 0);
$tm_case_id    = (int)($tm_case_id    ?? 0);
$tm_people     = crm_owner_candidates();
$tm_me         = (int)(current_user()['id'] ?? 0);
?>
<div class="modal fade" id="crmTaskModal" tabindex="-1" aria-labelledby="crmTaskModalLbl" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= APP_URL ?>/crm/tasks.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="add">
      <input type="hidden" name="contact_id" value="<?= $tm_contact_id ?: '' ?>">
      <input type="hidden" name="case_id"    value="<?= $tm_case_id ?: '' ?>">
      <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="modal-header py-2">
        <h5 class="modal-title" id="crmTaskModalLbl" style="font-size:.95rem">
          <i class="bi bi-person-up me-2" style="color:var(--crm-primary)" aria-hidden="true"></i>Zleć zadanie
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1" for="crmTaskTitle">Co jest do zrobienia <span class="text-danger">*</span></label>
          <input class="form-control form-control-sm" id="crmTaskTitle" name="title" required maxlength="300"
                 placeholder="<?= h($tm_title_hint ?? 'np. Oddzwonić w sprawie oferty') ?>">
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1" for="crmTaskOwner">Komu</label>
          <select class="form-select form-select-sm" id="crmTaskOwner" name="owner_id">
            <?php /* Lista ograniczona do osób, które w ogóle pracują w CRM — zlecenie
                     zadania komuś, kto nie zobaczy kartoteki, nigdy nie zostanie zrobione. */ ?>
            <option value="<?= $tm_me ?>">— mnie —</option>
            <?php foreach ($tm_people as $u): if ((int)$u['id'] === $tm_me) continue; ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" style="font-size:.72rem">
            Tylko osoby z dostępem do CRM. Zlecone zadanie pojawi się na ich liście zadań CRM.
          </div>
        </div>

        <div class="row g-2">
          <div class="col-7">
            <label class="form-label small fw-semibold mb-1" for="crmTaskDue">Termin</label>
            <input type="date" class="form-control form-control-sm" id="crmTaskDue" name="due_date">
          </div>
          <div class="col-5">
            <label class="form-label small fw-semibold mb-1" for="crmTaskPrio">Pilność</label>
            <select class="form-select form-select-sm" id="crmTaskPrio" name="priority">
              <?php foreach (CRM_TASK_PRIORITIES as $pk => $pv): ?>
              <option value="<?= h($pk) ?>" <?= $pk === 'medium' ? 'selected' : '' ?>><?= h($pv['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label small fw-semibold mb-1" for="crmTaskDesc">Szczegóły</label>
          <textarea class="form-control form-control-sm" id="crmTaskDesc" name="description" rows="2"
                    placeholder="Nieobowiązkowe — co trzeba wiedzieć, żeby to zrobić"></textarea>
        </div>

        <?php if ($tm_case_id || $tm_contact_id): ?>
        <p class="text-muted mb-0 mt-3" style="font-size:.74rem">
          <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
          Zadanie zostanie powiązane z <?= $tm_case_id ? 'tą sprawą' : 'tą kartoteką' ?> — widać je będzie w obu miejscach.
        </p>
        <?php endif; ?>
      </div>

      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-crm-outline" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-sm btn-crm-primary"><i class="bi bi-check-lg me-1"></i>Zleć</button>
      </div>
    </form>
  </div>
</div>
