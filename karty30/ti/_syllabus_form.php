<?php
/**
 * karty30/ti/_syllabus_form.php — formularz nagłówka sylabusa (nowy / edycja).
 * Włączany przez syllabi.php; korzysta z $hf, $subjects, $periods, $syl.
 */
?>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_op" value="save_syllabus">
  <input type="hidden" name="syllabus_id" value="<?= (int)$hf['id'] ?>">
  <div class="mb-2">
    <label class="form-label fw-semibold" for="s-subject">Przedmiot (rodzaj zajęć) <span class="text-danger" aria-hidden="true">*</span></label>
    <select class="form-select" id="s-subject" name="subject_type_id" required>
      <option value="">— wybierz —</option>
      <?php foreach ($subjects as $st): ?>
      <option value="<?= (int)$st['id'] ?>" <?= (int)$hf['subject_type_id']===(int)$st['id']?'selected':'' ?>>
        <?= h($st['abbreviation'].' — '.$st['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text"><a href="subject_types.php" target="_blank" rel="noopener">Zarządzaj rodzajami zajęć</a></div>
  </div>
  <div class="mb-2">
    <label class="form-label fw-semibold" for="s-title">Nazwa sylabusa <span class="text-danger" aria-hidden="true">*</span></label>
    <input type="text" class="form-control" id="s-title" name="title" value="<?= h($hf['title']) ?>" required maxlength="255"
           placeholder="np. Podstawy obsługi komputera dla osób niewidomych">
  </div>
  <div class="row g-2">
    <div class="col-6 mb-2">
      <label class="form-label" for="s-version">Wersja</label>
      <input type="text" class="form-control" id="s-version" name="version" value="<?= h($hf['version']) ?>" maxlength="40" placeholder="1">
    </div>
    <div class="col-6 mb-2">
      <label class="form-label" for="s-status">Status</label>
      <select class="form-select" id="s-status" name="status">
        <?php foreach (TI_SYLLABUS_STATUSES as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= (string)$hf['status']===$k?'selected':'' ?>><?= h($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="mb-2">
    <label class="form-label" for="s-period">Okres nauczania <span class="text-muted small">(opcjonalnie)</span></label>
    <select class="form-select" id="s-period" name="period_id">
      <option value="">— nie wskazano —</option>
      <?php foreach ($periods as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= (int)$hf['period_id']===(int)$p['id']?'selected':'' ?>>
        <?= h($p['name']) ?> (<?= h(date('d.m.Y', strtotime($p['date_from']))) ?>–<?= h(date('d.m.Y', strtotime($p['date_to']))) ?>)
      </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Kurs bez własnego przypisania dziedziczy sylabus <strong>obowiązujący</strong> dla swojego przedmiotu.</div>
  </div>
  <div class="mb-3">
    <label class="form-label" for="s-note">Uwagi</label>
    <textarea class="form-control" id="s-note" name="note" rows="2" placeholder="np. podstawa prawna, źródło programu"><?= h($hf['note']) ?></textarea>
  </div>
  <button class="btn btn-primary"><?= !empty($syl) ? 'Zapisz zmiany' : 'Utwórz sylabus' ?></button>
</form>
