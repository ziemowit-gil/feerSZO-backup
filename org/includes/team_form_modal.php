<?php
/**
 * org/includes/team_form_modal.php — okno „Nowy zespół / Edytuj zespół” (org/teams.php, org/team.php).
 * Wejście: $TF_TEAM (wiersz zespołu albo null dla nowego), $TF_UNITS (org_units), $TF_TEAMS (wszystkie zespoły).
 */
$tf   = $TF_TEAM ?? null;
$tfId = (int)($tf['id'] ?? 0);
$tfIcon = preg_replace('/^bi-/', '', (string)($tf['icon'] ?? 'bi-people-fill'));
$tfErr = fn(string $f) => '<div id="teamForm-err-' . $f . '" class="team-field-error invalid-feedback d-block" hidden></div>';
?>
<div class="modal fade" id="teamFormModal" tabindex="-1" aria-labelledby="teamFormTitle" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" id="teamForm" data-team-form="team_save" data-success="url" novalidate>
      <div class="modal-header py-2">
        <h2 class="h5 modal-title" id="teamFormTitle"><i class="bi bi-people-fill me-1 text-primary" aria-hidden="true"></i><?= $tfId ? 'Edytuj zespół' : 'Nowy zespół' ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="team_id" value="<?= $tfId ?>">
        <div class="team-form-error alert alert-danger py-2 small" role="alert" tabindex="-1" hidden></div>
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label fw-semibold" for="tf-name">Nazwa <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(wymagane)</span></label>
            <input type="text" id="tf-name" name="name" class="form-control" maxlength="100" required value="<?= h($tf['name'] ?? '') ?>" aria-describedby="teamForm-err-name">
            <?= $tfErr('name') ?>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="tf-code">Kod</label>
            <input type="text" id="tf-code" name="code" class="form-control text-uppercase" maxlength="40" value="<?= h($tf['code'] ?? '') ?>" aria-describedby="tf-code-hint teamForm-err-code" autocomplete="off">
            <div id="tf-code-hint" class="form-text">Krótki, unikalny, np. ZARZAD, PROJ-FERS.</div>
            <?= $tfErr('code') ?>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="tf-type">Typ</label>
            <select id="tf-type" name="type" class="form-select">
              <?php foreach (TeamService::TYPES as $k => $l): ?>
              <option value="<?= $k ?>"<?= ($tf['type'] ?? 'permanent') === $k ? ' selected' : '' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="tf-status">Status</label>
            <select id="tf-status" name="status" class="form-select" aria-describedby="teamForm-err-status">
              <?php foreach (TeamService::STATUSES as $k => $l): if ($k === 'archived' && !is_admin()) continue; ?>
              <option value="<?= $k ?>"<?= ($tf['status'] ?? 'active') === $k ? ' selected' : '' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
            <?= $tfErr('status') ?>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="tf-unit">Jednostka</label>
            <select id="tf-unit" name="unit_id" class="form-select">
              <option value="0">— bez jednostki —</option>
              <?php foreach ($TF_UNITS as $u): ?>
              <option value="<?= (int)$u['id'] ?>"<?= (int)($tf['unit_id'] ?? 0) === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="tf-parent">Zespół nadrzędny</label>
            <select id="tf-parent" name="parent_id" class="form-select" aria-describedby="teamForm-err-parent_id">
              <option value="0">— brak (zespół główny) —</option>
              <?php foreach ($TF_TEAMS as $o): if ((int)$o['id'] === $tfId) continue; ?>
              <option value="<?= (int)$o['id'] ?>"<?= (int)($tf['parent_id'] ?? 0) === (int)$o['id'] ? ' selected' : '' ?>><?= h($o['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?= $tfErr('parent_id') ?>
          </div>
          <div class="col-md-6" data-project-only>
            <fieldset>
              <legend class="form-label fw-semibold fs-6 mb-2">Okres zespołu projektowego</legend>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label small" for="tf-start">Początek</label>
                  <input type="date" id="tf-start" name="starts_at" class="form-control" value="<?= h($tf['starts_at'] ?? '') ?>" aria-describedby="teamForm-err-starts_at">
                  <?= $tfErr('starts_at') ?>
                </div>
                <div class="col-6">
                  <label class="form-label small" for="tf-end">Koniec</label>
                  <input type="date" id="tf-end" name="ends_at" class="form-control" value="<?= h($tf['ends_at'] ?? '') ?>" aria-describedby="teamForm-err-ends_at">
                  <?= $tfErr('ends_at') ?>
                </div>
              </div>
            </fieldset>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold" for="tf-desc">Zakres odpowiedzialności</label>
            <textarea id="tf-desc" name="description" class="form-control" rows="2"><?= h($tf['description'] ?? '') ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold" for="tf-goal">Cel i założenia</label>
            <textarea id="tf-goal" name="goal" class="form-control" rows="2"><?= h($tf['goal'] ?? '') ?></textarea>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold" for="tf-color">Kolor</label>
            <input type="color" id="tf-color" name="color" class="form-control form-control-color" value="<?= h($tf['color'] ?? '#1E6DFF') ?>">
          </div>
          <div class="col-md-9">
            <fieldset>
              <legend class="form-label fw-semibold fs-6 mb-2">Ikona</legend>
              <div class="d-flex flex-wrap gap-1 team-icon-pick">
                <?php foreach (TeamService::ICONS as $ic): ?>
                <input type="radio" class="btn-check" name="icon" id="tf-ic-<?= $ic ?>" value="<?= $ic ?>"<?= $tfIcon === $ic ? ' checked' : '' ?>>
                <label class="btn btn-outline-secondary btn-sm" for="tf-ic-<?= $ic ?>" title="<?= h($ic) ?>"><i class="bi bi-<?= $ic ?>" aria-hidden="true"></i><span class="visually-hidden">Ikona <?= h($ic) ?></span></label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button>
      </div>
    </form>
  </div>
</div>
