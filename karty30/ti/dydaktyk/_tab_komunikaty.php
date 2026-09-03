<?php /* ═══════════════════ TAB: KOMUNIKATY ═══════════════════ */
$_notices_js = json_encode(array_map(fn($dn) => [
    'id'      => (int)$dn['id'],
    'title'   => (string)($dn['title'] ?? ''),
    'body'    => (string)($dn['body'] ?? ''),
    'author'  => (string)($dn['author_name'] ?? '—'),
    'created' => (string)($dn['created_at'] ?? ''),
    'expires' => (string)($dn['expires_at'] ?? ''),
    'pinned'  => (bool)(int)$dn['is_pinned'],
    'read'    => (bool)(int)($dn['is_read'] ?? 0),
], $dyd_notices), JSON_UNESCAPED_UNICODE);
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center gap-2">
    <span class="fw-semibold"><i class="bi bi-megaphone text-warning me-2" aria-hidden="true"></i>Komunikaty placówki</span>
    <?php if ($dyd_notices_unread > 0): ?>
    <span class="badge text-bg-warning"><?= (int)$dyd_notices_unread ?></span>
    <?php endif; ?>
    <?php if ($dyd_notices_unread > 0): ?>
    <div class="ms-auto">
      <form method="post" class="d-inline">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="mark_all_notices">
        <button type="submit" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Wszystkie przeczytane
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$dyd_notices): ?>
  <div class="card-body text-body-secondary py-5 text-center">
    <i class="bi bi-megaphone fs-2 d-block mb-2 opacity-30" aria-hidden="true"></i>
    Brak aktywnych komunikatów.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" style="font-size:.875rem" aria-label="Lista komunikatów">
      <thead class="table-light">
        <tr>
          <th scope="col" style="width:36px"></th>
          <th scope="col">Temat</th>
          <th scope="col" style="width:150px" class="d-none d-md-table-cell">Autor</th>
          <th scope="col" style="width:120px" class="d-none d-sm-table-cell">Data</th>
          <th scope="col" style="width:52px" class="text-center">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($dyd_notices as $dn):
        $is_pinned = (int)$dn['is_pinned'];
        $is_read   = (int)($dn['is_read'] ?? 0);
      ?>
      <tr class="dyd-notice-row"
          style="cursor:pointer"
          onclick="dydOpenNotice(<?= (int)$dn['id'] ?>)"
          tabindex="0" role="button"
          onkeydown="if(event.key==='Enter'||event.key===' ')dydOpenNotice(<?= (int)$dn['id'] ?>)">
        <td class="text-center px-2">
          <?php if ($is_pinned): ?>
          <i class="bi bi-pin-angle-fill text-warning" title="Przypięty" aria-hidden="true"></i>
          <?php else: ?>
          <i class="bi bi-megaphone text-body-tertiary" aria-hidden="true" style="font-size:.85rem;opacity:.4"></i>
          <?php endif; ?>
        </td>
        <td class="<?= !$is_read ? 'fw-semibold' : '' ?>">
          <?php if (!$is_read): ?>
          <span class="d-inline-block rounded-circle bg-primary me-1 flex-shrink-0" style="width:7px;height:7px;vertical-align:middle" aria-label="Nieprzeczytane"></span>
          <?php endif; ?>
          <?= h($dn['title']) ?>
        </td>
        <td class="text-body-secondary d-none d-md-table-cell" style="font-size:.82rem">
          <?= h($dn['author_name'] ?? '—') ?>
        </td>
        <td class="text-body-secondary d-none d-sm-table-cell" style="font-size:.82rem">
          <?= $dn['created_at'] ? date('d.m.Y H:i', strtotime($dn['created_at'])) : '—' ?>
        </td>
        <td class="text-center">
          <?php if (!$is_read): ?>
          <span class="badge text-bg-primary" style="font-size:.62rem">nowy</span>
          <?php else: ?>
          <i class="bi bi-check2 text-success opacity-50" aria-label="Przeczytane"></i>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Modal: podgląd komunikatu -->
<div class="modal fade" id="dydNoticeModal" tabindex="-1"
     aria-labelledby="dydNoticeLbl" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="dydNoticeLbl"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div id="dydNoticeBody" class="mb-3" style="white-space:pre-wrap;font-size:.9rem"></div>
        <div id="dydNoticeMeta" class="text-body-secondary border-top pt-2" style="font-size:.78rem"></div>
      </div>
      <div class="modal-footer">
        <form method="post" id="dydNoticeMarkForm" class="d-none me-auto">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="mark_notice">
          <input type="hidden" name="notice_id" id="dydNoticeMarkId" value="">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-check2 me-1"></i>Oznacz jako przeczytane
          </button>
        </form>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>

<script>
var _DYD_NOTICES = <?= $_notices_js ?>;
function dydOpenNotice(id) {
  var n = _DYD_NOTICES.find(function(x){ return x.id === id; });
  if (!n) return;
  document.getElementById('dydNoticeLbl').textContent = n.title;
  document.getElementById('dydNoticeBody').textContent = n.body || '(brak treści)';
  var meta = [];
  if (n.author) meta.push('<i class="bi bi-person me-1" aria-hidden="true"></i>' + _dne(n.author));
  if (n.created) meta.push('<span class="ms-3"><i class="bi bi-clock me-1" aria-hidden="true"></i>' + _dne(n.created.substring(0,16)) + '</span>');
  if (n.expires) meta.push('<span class="ms-3"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i>Wygasa: ' + _dne(n.expires) + '</span>');
  if (n.pinned) meta.push('<span class="ms-3"><i class="bi bi-pin-angle-fill text-warning me-1" aria-hidden="true"></i>Przypięty</span>');
  document.getElementById('dydNoticeMeta').innerHTML = meta.join('');
  var form = document.getElementById('dydNoticeMarkForm');
  if (!n.read) {
    document.getElementById('dydNoticeMarkId').value = id;
    form.classList.remove('d-none');
  } else {
    form.classList.add('d-none');
  }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('dydNoticeModal')).show();
}
function _dne(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php if (dyd_is_staff()): ?>
<!-- ═══════════════════ Zarządzanie komunikatami (kierownik) ═══════════════════ -->
<div class="card border-0 shadow-sm mt-3">
  <div class="card-header bg-transparent d-flex align-items-center gap-2">
    <span class="fw-semibold"><i class="bi bi-megaphone-fill text-warning me-2" aria-hidden="true"></i>Zarządzanie komunikatami</span>
    <button class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#dydNoticeAdminModal" onclick="dydNoticeFormReset()">
      <i class="bi bi-plus-lg me-1"></i>Nowy komunikat
    </button>
  </div>
  <?php if (!$dyd_notices_admin): ?>
  <div class="card-body text-body-secondary py-3">Brak komunikatów. Kliknij „Nowy komunikat”, aby opublikować pierwszy.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.875rem">
      <caption class="visually-hidden">Zarządzanie komunikatami placówki</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Temat</th>
          <th scope="col" class="d-none d-md-table-cell">Autor</th>
          <th scope="col" class="d-none d-sm-table-cell">Data</th>
          <th scope="col" class="text-center">Odczytania</th>
          <th scope="col" class="text-center">Status</th>
          <th scope="col" class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($dyd_notices_admin as $n):
        $is_active = (int)$n['is_active'];
        $is_pinned = (int)$n['is_pinned'];
        $expired   = $n['expires_at'] && $n['expires_at'] < date('Y-m-d');
      ?>
      <tr class="<?= !$is_active ? 'opacity-50' : '' ?>">
        <td>
          <?php if ($is_pinned): ?><i class="bi bi-pin-angle-fill text-warning me-1" title="Przypięty" aria-hidden="true"></i><?php endif; ?>
          <?= h($n['title']) ?>
          <?php if ($expired): ?><span class="badge text-bg-warning ms-1">wygasł</span><?php endif; ?>
        </td>
        <td class="text-body-secondary d-none d-md-table-cell"><?= h($n['author_name'] ?? '—') ?></td>
        <td class="text-body-secondary d-none d-sm-table-cell"><?= substr($n['created_at'],0,16) ?></td>
        <td class="text-center"><?= (int)$n['reads_count'] ?></td>
        <td class="text-center"><?= $is_active ? '<span class="badge text-bg-success">aktywny</span>' : '<span class="badge text-bg-secondary">nieaktywny</span>' ?></td>
        <td class="text-end text-nowrap">
          <form method="post" class="d-inline">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="notice_toggle_pin">
            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
            <input type="hidden" name="val" value="<?= $is_pinned ? 0 : 1 ?>">
            <button class="btn btn-sm <?= $is_pinned?'btn-warning':'btn-outline-secondary' ?> py-0 px-2" title="<?= $is_pinned?'Odepnij':'Przypnij' ?>">
              <i class="bi bi-pin-angle<?= $is_pinned?'-fill':'' ?>" aria-hidden="true"></i>
            </button>
          </form>
          <form method="post" class="d-inline" <?= $is_active?'onsubmit="return confirm(\'Dezaktywować?\')"':'' ?>>
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="notice_toggle_active">
            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
            <input type="hidden" name="val" value="<?= $is_active ? 0 : 1 ?>">
            <button class="btn btn-sm <?= $is_active?'btn-outline-secondary':'btn-outline-success' ?> py-0 px-2" title="<?= $is_active?'Dezaktywuj':'Aktywuj' ?>">
              <i class="bi bi-<?= $is_active?'eye-slash':'eye' ?>" aria-hidden="true"></i>
            </button>
          </form>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                  onclick="dydNoticeFormEdit(<?= (int)$n['id'] ?>,<?= htmlspecialchars(json_encode($n['title']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($n['body']),ENT_QUOTES) ?>,<?= (int)$n['is_pinned'] ?>,<?= (int)$n['is_active'] ?>,<?= htmlspecialchars(json_encode($n['expires_at']??''),ENT_QUOTES) ?>)"
                  title="Edytuj">
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
          <form method="post" class="d-inline" onsubmit="return confirm('Trwale usunąć komunikat?')">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="notice_delete">
            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Modal dodaj/edytuj (kierownik) -->
<div class="modal fade" id="dydNoticeAdminModal" tabindex="-1" aria-labelledby="dydNoticeAdminTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post" id="dydNoticeAdminForm">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="notice_add">
        <input type="hidden" name="id" id="dnf_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="dydNoticeAdminTitle"><i class="bi bi-megaphone me-2" aria-hidden="true"></i><span id="dnf_title_label">Nowy komunikat</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="dnf_title">Tytuł <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title" id="dnf_title" required placeholder="np. Przerwa świąteczna, Ważna informacja organizacyjna">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="dnf_body">Treść</label>
            <textarea class="form-control" name="body" id="dnf_body" rows="5" placeholder="Treść komunikatu (opcjonalnie)…"></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-sm-6">
              <label class="form-label" for="dnf_expires">Data wygaśnięcia</label>
              <input type="date" class="form-control" name="expires_at" id="dnf_expires" min="<?= date('Y-m-d') ?>">
              <div class="form-text">Zostaw puste = bez terminu wygaśnięcia.</div>
            </div>
            <div class="col-sm-6 d-flex flex-column gap-2 justify-content-center pt-3">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_pinned" id="dnf_pinned" value="1">
                <label class="form-check-label" for="dnf_pinned"><i class="bi bi-pin-angle me-1" aria-hidden="true"></i>Przypnij na górze</label>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" id="dnf_active" value="1" checked>
                <label class="form-check-label" for="dnf_active">Aktywny (widoczny dla kursantów)</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Opublikuj</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function dydNoticeFormReset() {
  document.getElementById('dnf_id').value = '0';
  document.getElementById('dnf_title').value = '';
  document.getElementById('dnf_body').value = '';
  document.getElementById('dnf_expires').value = '';
  document.getElementById('dnf_pinned').checked = false;
  document.getElementById('dnf_active').checked = true;
  document.getElementById('dnf_title_label').textContent = 'Nowy komunikat';
  document.querySelector('#dydNoticeAdminForm [name="_op"]').value = 'notice_add';
}
function dydNoticeFormEdit(id, title, body, pinned, active, expires) {
  document.getElementById('dnf_id').value = id;
  document.getElementById('dnf_title').value = title;
  document.getElementById('dnf_body').value = body;
  document.getElementById('dnf_expires').value = expires;
  document.getElementById('dnf_pinned').checked = !!pinned;
  document.getElementById('dnf_active').checked = !!active;
  document.getElementById('dnf_title_label').textContent = 'Edytuj komunikat';
  document.querySelector('#dydNoticeAdminForm [name="_op"]').value = 'notice_edit';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('dydNoticeAdminModal')).show();
}
</script>
<?php endif; ?>

