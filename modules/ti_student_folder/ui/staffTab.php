<?php
/**
 * modules/ti_student_folder/ui/staffTab.php — zakładka „Dokumenty” w szczegółach konta kursanta (dydaktyk/konta.php).
 * Wejście: $sa (wiersz konta). Formularze wysyłane do dydaktyk/dokumentyKursanta.php (back=konta → powrót tutaj).
 * Wygląd: sekcje .kk-sec / .kk-row z konta.php.
 */
require_once dirname(__DIR__) . '/logic/studentFolder.php';

$_sf_aid   = (int)$sa['id'];
$_sf_files = TiStudentFolder::files($_sf_aid);
$_sf_reqs  = TiStudentFolder::requests($_sf_aid);
$_sf_days  = TiStudentFolder::settings()['days'];
$_sf_byId  = [];
foreach ($_sf_files as $_f) $_sf_byId[(int)$_f['id']] = $_f;
$_sf_pend  = array_filter($_sf_files, fn($f) => $f['status'] === 'pending');
$_sf_act   = 'dokumentyKursanta.php';
$_sf_hid   = fn(string $op) => '<input type="hidden" name="_token" value="' . h(dyd_token()) . '"><input type="hidden" name="_op" value="' . $op . '">'
                             . '<input type="hidden" name="account_id" value="' . $_sf_aid . '"><input type="hidden" name="back" value="konta">';
$_sf_valid = function (?string $v) use ($_sf_days): string {
    if (!$v) return '<span class="text-body-secondary">bezterminowo</span>';
    $st = TiStudentFolder::validity($v, $_sf_days);
    $d  = h(date('d.m.Y', strtotime($v)));
    return match ($st) {
        'expired' => '<span class="badge text-bg-danger">wygasł ' . $d . '</span>',
        'soon'    => '<span class="badge text-bg-warning">ważny do ' . $d . '</span>',
        default   => '<span>ważny do ' . $d . '</span>',
    };
};
$_sf_req_badge = ['pending' => ['text-bg-warning', 'czeka na decyzję'], 'accepted' => ['text-bg-success', 'zaakceptowany'], 'declined' => ['text-bg-danger', 'odmowa']];
?>
<?php if ($_sf_pend): ?>
<div class="alert alert-warning py-2 small" role="status">
  <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i><?= count($_sf_pend) === 1 ? 'Jeden dokument od kursanta czeka' : count($_sf_pend) . ' dokumenty od kursanta czekają' ?> na zatwierdzenie — poniżej w teczce.
</div>
<?php endif; ?>

<section class="kk-sec" aria-labelledby="sf-h-req">
  <h3 id="sf-h-req"><i class="bi bi-pen" aria-hidden="true"></i>Do akceptacji przez <?= !empty($sa['is_minor']) ? 'opiekuna' : 'kursanta' ?></h3>
  <?php if (!$_sf_reqs): ?>
  <div class="kk-row"><div class="kk-row-desc">Brak dokumentów do akceptacji.</div></div>
  <?php endif; ?>
  <?php foreach ($_sf_reqs as $_r): [$_bc, $_bl] = $_sf_req_badge[$_r['status']] ?? ['text-bg-secondary', $_r['status']];
        $_over = $_r['status'] === 'pending' && $_r['due_date'] && $_r['due_date'] < date('Y-m-d'); ?>
  <div class="kk-row">
    <div class="kk-row-info">
      <div class="kk-row-title"><?= h($_r['title']) ?> <span class="badge <?= $_bc ?> ms-1"><?= h($_bl) ?></span>
        <?php if ($_over): ?><span class="badge text-bg-danger ms-1">po terminie</span><?php endif; ?></div>
      <div class="kk-row-desc">
        Wystawił(a) <?= h($_r['created_by_name']) ?>, <?= h(date('d.m.Y', strtotime((string)$_r['created_at']))) ?>
        <?= $_r['due_date'] ? ' · termin ' . h(date('d.m.Y', strtotime((string)$_r['due_date']))) : '' ?>
        <?php if ($_r['status'] !== 'pending'): ?> · <?= h($_r['decided_name']) ?> (<?= h(TiStudentFolder::SOURCES[$_r['decided_kind']] ?? $_r['decided_kind']) ?>), <?= h(date('d.m.Y H:i', strtotime((string)$_r['decided_at']))) ?><?php endif; ?>
        <?php if ($_r['status'] === 'declined'): ?><br><strong>Powód odmowy:</strong> <?= h($_r['decline_reason']) ?><?php endif; ?>
      </div>
    </div>
    <div class="kk-row-ctl">
      <?php if ($_r['file_id'] && isset($_sf_byId[(int)$_r['file_id']])): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= $_sf_act ?>?file=<?= (int)$_r['file_id'] ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip me-1" aria-hidden="true"></i>Załącznik</a>
      <?php endif; ?>
      <?php if ($_r['confirm_file_id']): ?>
      <a class="btn btn-sm btn-outline-success" href="<?= $_sf_act ?>?file=<?= (int)$_r['confirm_file_id'] ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-check me-1" aria-hidden="true"></i>Potwierdzenie PDF</a>
      <?php endif; ?>
      <?php if ($_r['status'] === 'pending'): ?>
      <form method="post" action="<?= $_sf_act ?>" onsubmit="return confirm('Anulować ten dokument do akceptacji?')"><?= $_sf_hid('req_cancel') ?>
        <input type="hidden" name="request_id" value="<?= (int)$_r['id'] ?>">
        <button class="btn btn-sm btn-outline-secondary">Anuluj</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <details class="kk-row d-block">
    <summary class="fw-semibold small" style="cursor:pointer"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Wystaw dokument do akceptacji</summary>
    <form method="post" action="<?= $_sf_act ?>" enctype="multipart/form-data" class="row g-2 mt-1"><?= $_sf_hid('req_create') ?>
      <div class="col-md-8"><label class="form-label small mb-0" for="sf-rt">Tytuł</label>
        <input class="form-control form-control-sm" id="sf-rt" name="title" maxlength="200" required placeholder="np. Zgoda na publikację wizerunku"></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="sf-rd">Termin (opcjonalnie)</label>
        <input class="form-control form-control-sm" type="date" id="sf-rd" name="due_date"></div>
      <div class="col-12"><label class="form-label small mb-0" for="sf-rb">Treść</label>
        <textarea class="form-control form-control-sm" id="sf-rb" name="body" rows="5" placeholder="Treść, którą <?= !empty($sa['is_minor']) ? 'opiekun' : 'kursant' ?> przeczyta i zaakceptuje. Może zostać pusta, jeśli dołączasz plik."></textarea></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="sf-rf">Załącznik z teczki</label>
        <select class="form-select form-select-sm" id="sf-rf" name="file_id"><option value="0">— bez załącznika —</option>
          <?php foreach ($_sf_files as $_f): if ($_f['status'] !== 'approved') continue; ?><option value="<?= (int)$_f['id'] ?>"><?= h($_f['title']) ?> (<?= h($_f['file_name']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="sf-ru">albo nowy plik</label>
        <input class="form-control form-control-sm" type="file" id="sf-ru" name="file" accept="<?= TiStudentFolder::ACCEPT_ATTR ?>"></div>
      <div class="col-12 d-flex flex-wrap align-items-center gap-3">
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="notify" value="1" id="sf-rn" checked>
          <label class="form-check-label small" for="sf-rn">Powiadom e-mailem <?= !empty($sa['is_minor']) ? 'opiekuna' : 'kursanta' ?></label></div>
        <button class="btn btn-sm btn-primary ms-auto"><i class="bi bi-send me-1" aria-hidden="true"></i>Wystaw</button>
      </div>
      <?php if (!empty($sa['is_minor'])): ?><div class="col-12 small text-body-secondary">Kursant jest niepełnoletni — zaakceptować może tylko opiekun w panelu rodzica.</div><?php endif; ?>
    </form>
  </details>
</section>

<section class="kk-sec" aria-labelledby="sf-h-files">
  <h3 id="sf-h-files"><i class="bi bi-folder2-open" aria-hidden="true"></i>Teczka (<?= count($_sf_files) ?>)</h3>
  <?php if (!$_sf_files): ?>
  <div class="kk-row"><div class="kk-row-desc">Teczka jest pusta.</div></div>
  <?php endif; ?>
  <?php foreach ($_sf_files as $_f): $_fid = (int)$_f['id']; ?>
  <div class="kk-row">
    <div class="kk-row-info">
      <div class="kk-row-title">
        <a href="<?= $_sf_act ?>?file=<?= $_fid ?>" target="_blank" rel="noopener"><?= h($_f['title']) ?></a>
        <span class="badge text-bg-light border ms-1"><?= h(TiStudentFolder::CATEGORIES[$_f['category']] ?? $_f['category']) ?></span>
        <?php if ($_f['status'] === 'pending'): ?><span class="badge text-bg-warning ms-1">czeka na zatwierdzenie</span>
        <?php elseif ($_f['status'] === 'rejected'): ?><span class="badge text-bg-danger ms-1">odrzucony</span><?php endif; ?>
        <?php if (!(int)$_f['visible_student']): ?><span class="badge text-bg-secondary ms-1" title="Kursant nie widzi tego dokumentu"><i class="bi bi-eye-slash" aria-hidden="true"></i> tylko kadra</span><?php endif; ?>
      </div>
      <div class="kk-row-desc">
        <?= h($_f['file_name']) ?> · <?= h(number_format((int)$_f['size'] / 1024, 0, ',', ' ')) ?> KB ·
        dodał(a) <?= h(TiStudentFolder::SOURCES[$_f['source']] ?? $_f['source']) ?><?= $_f['created_by_name'] !== '' && $_f['source'] === 'staff' ? ' (' . h($_f['created_by_name']) . ')' : '' ?>,
        <?= h(date('d.m.Y', strtotime((string)$_f['created_at']))) ?> · <?= $_sf_valid($_f['valid_until']) ?>
        <?php if ($_f['note'] !== ''): ?><br><?= h($_f['note']) ?><?php endif; ?>
        <?php if ($_f['status'] === 'rejected' && $_f['reject_reason'] !== ''): ?><br><strong>Powód odrzucenia:</strong> <?= h($_f['reject_reason']) ?><?php endif; ?>
      </div>
    </div>
    <div class="kk-row-ctl">
      <?php if ($_f['status'] === 'pending'): ?>
      <form method="post" action="<?= $_sf_act ?>"><?= $_sf_hid('file_approve') ?><input type="hidden" name="file_id" value="<?= $_fid ?>">
        <button class="btn btn-sm btn-outline-success"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zatwierdź</button></form>
      <form method="post" action="<?= $_sf_act ?>" class="d-flex gap-1" onsubmit="var r=this.reason.value.trim(); if(!r){alert('Podaj powód odrzucenia.');return false;} return true;"><?= $_sf_hid('file_reject') ?>
        <input type="hidden" name="file_id" value="<?= $_fid ?>">
        <label class="visually-hidden" for="sf-rj-<?= $_fid ?>">Powód odrzucenia</label>
        <input class="form-control form-control-sm" id="sf-rj-<?= $_fid ?>" name="reason" placeholder="powód odrzucenia" style="width:170px">
        <button class="btn btn-sm btn-outline-danger">Odrzuć</button></form>
      <?php endif; ?>
      <details class="position-relative">
        <summary class="btn btn-sm btn-outline-secondary" aria-label="Edytuj opis: <?= h($_f['title']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edytuj</summary>
        <form method="post" action="<?= $_sf_act ?>" class="border rounded bg-white p-2 mt-1 shadow-sm" style="position:absolute;right:0;z-index:5;width:320px"><?= $_sf_hid('file_update') ?>
          <input type="hidden" name="file_id" value="<?= $_fid ?>">
          <label class="form-label small mb-0" for="sf-et-<?= $_fid ?>">Tytuł</label>
          <input class="form-control form-control-sm mb-1" id="sf-et-<?= $_fid ?>" name="title" value="<?= h($_f['title']) ?>" maxlength="200">
          <label class="form-label small mb-0" for="sf-ec-<?= $_fid ?>">Kategoria</label>
          <select class="form-select form-select-sm mb-1" id="sf-ec-<?= $_fid ?>" name="category">
            <?php foreach (TiStudentFolder::CATEGORIES as $_k => $_l): ?><option value="<?= $_k ?>"<?= $_f['category'] === $_k ? ' selected' : '' ?>><?= h($_l) ?></option><?php endforeach; ?>
          </select>
          <label class="form-label small mb-0" for="sf-ev-<?= $_fid ?>">Ważny do</label>
          <input class="form-control form-control-sm mb-1" type="date" id="sf-ev-<?= $_fid ?>" name="valid_until" value="<?= h((string)$_f['valid_until']) ?>">
          <label class="form-label small mb-0" for="sf-en-<?= $_fid ?>">Opis</label>
          <textarea class="form-control form-control-sm mb-1" id="sf-en-<?= $_fid ?>" name="note" rows="2"><?= h($_f['note']) ?></textarea>
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="visible_student" value="1" id="sf-ew-<?= $_fid ?>"<?= (int)$_f['visible_student'] ? ' checked' : '' ?>>
            <label class="form-check-label small" for="sf-ew-<?= $_fid ?>">Widoczny dla kursanta / opiekuna</label></div>
          <button class="btn btn-sm btn-primary w-100">Zapisz</button>
        </form>
      </details>
      <form method="post" action="<?= $_sf_act ?>" onsubmit="return confirm('Usunąć „<?= h(addslashes((string)$_f['title'])) ?>” z teczki?')"><?= $_sf_hid('file_delete') ?>
        <input type="hidden" name="file_id" value="<?= $_fid ?>">
        <button class="btn btn-sm btn-outline-danger" aria-label="Usuń: <?= h($_f['title']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
    </div>
  </div>
  <?php endforeach; ?>
  <details class="kk-row d-block">
    <summary class="fw-semibold small" style="cursor:pointer"><i class="bi bi-upload me-1" aria-hidden="true"></i>Dodaj dokument do teczki</summary>
    <form method="post" action="<?= $_sf_act ?>" enctype="multipart/form-data" class="row g-2 mt-1"><?= $_sf_hid('file_upload') ?>
      <div class="col-md-6"><label class="form-label small mb-0" for="sf-uf">Plik (PDF, JPG, PNG, DOCX, ODT — do 15 MB)</label>
        <input class="form-control form-control-sm" type="file" id="sf-uf" name="file" required accept="<?= TiStudentFolder::ACCEPT_ATTR ?>"></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="sf-ut">Tytuł (puste = nazwa pliku)</label>
        <input class="form-control form-control-sm" id="sf-ut" name="title" maxlength="200"></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="sf-uc">Kategoria</label>
        <select class="form-select form-select-sm" id="sf-uc" name="category">
          <?php foreach (TiStudentFolder::CATEGORIES as $_k => $_l): ?><option value="<?= $_k ?>"<?= $_k === 'inne' ? ' selected' : '' ?>><?= h($_l) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="sf-uv">Ważny do (opcjonalnie)</label>
        <input class="form-control form-control-sm" type="date" id="sf-uv" name="valid_until"></div>
      <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="visible_student" value="1" id="sf-uw" checked>
        <label class="form-check-label small" for="sf-uw">Widoczny dla kursanta / opiekuna</label></div></div>
      <div class="col-12"><label class="form-label small mb-0" for="sf-un">Opis (opcjonalnie)</label>
        <input class="form-control form-control-sm" id="sf-un" name="note" maxlength="1000"></div>
      <div class="col-12 text-end"><button class="btn btn-sm btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Dodaj</button></div>
    </form>
  </details>
</section>
