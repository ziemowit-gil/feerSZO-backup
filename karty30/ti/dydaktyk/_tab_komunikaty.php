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
