<?php
/**
 * crm/includes/case/parties.php — panel „Podmioty sprawy” (jak „Uczestnicy” w Dynamics 365): kontakt główny
 * + wpięte kontakty/firmy z rolą, wpinanie z wyszukiwarką (crm/api/contacts_search.php), a po wpięciu — inne
 * sprawy podmiotu z przeniesieniem ich dokumentacji (includes/crm_case_parties.php). Włączany w crm/cases/view.php.
 */
if (!isset($case)) { http_response_code(400); exit; }
$_pp = crm_case_parties((int)$case['id']);
$_pp_move_cid = (int)($_GET['przenies'] ?? 0);
$_pp_other = $_pp_move_cid ? crm_case_party_other_cases((int)$case['id'], $_pp_move_cid) : [];
$_pp_move_name = $_pp_move_cid ? (string)(db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id = ?", [$_pp_move_cid])['imie_nazwisko'] ?? '') : '';
?>
<div class="cv-panel" id="podmioty"><div class="cv-panel__body">
  <div class="cv-shead">
    <i class="bi bi-diagram-3 cv-shead__icon" aria-hidden="true"></i>
    <h2 class="cv-shead__title">Podmioty sprawy</h2>
    <div class="cv-shead__aside"><span class="cv-count"><?= count($_pp) ?></span></div>
  </div>
  <ul class="list-unstyled mb-2" style="font-size:.86rem">
    <?php foreach ($_pp as $p): ?>
    <li class="d-flex align-items-start gap-2 py-1" style="border-bottom:1px solid #F3F4F6">
      <i class="bi <?= ($p['type'] ?? '') === 'organizacja' ? 'bi-building' : 'bi-person' ?> mt-1" style="color:#2563EB" aria-hidden="true"></i>
      <div class="flex-grow-1" style="min-width:0">
        <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$p['id'] ?>" class="fw-semibold" style="text-decoration:none"><?= h($p['imie_nazwisko']) ?></a>
        <?= !empty($p['organizacja']) ? '<span class="text-muted"> · ' . h($p['organizacja']) . '</span>' : '' ?>
        <div class="text-muted" style="font-size:.74rem"><?= h($p['role_label']) ?><?= !empty($p['note']) ? ' — ' . h($p['note']) : '' ?></div>
      </div>
      <?php if ($can_write && !$p['is_main']): ?>
      <form method="post" class="d-flex gap-1" onsubmit="return confirm('Odpiąć podmiot od sprawy? Dokumentacja zostaje w sprawie.')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="remove_party"><input type="hidden" name="party_id" value="<?= (int)$p['party_id'] ?>">
        <a class="btn btn-sm btn-link py-0 px-1" href="?id=<?= (int)$case['id'] ?>&amp;przenies=<?= (int)$p['id'] ?>#podmioty" title="Inne sprawy podmiotu — przenieś dokumentację" aria-label="Inne sprawy podmiotu <?= h($p['imie_nazwisko']) ?>"><i class="bi bi-box-arrow-in-down-left" aria-hidden="true"></i></a>
        <button class="btn btn-sm btn-link text-danger py-0 px-1" aria-label="Odepnij podmiot <?= h($p['imie_nazwisko']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($can_write && $_pp_move_cid): ?>
  <div class="p-2 mb-2" style="background:#EFF6FF;border-left:3px solid #2563EB" role="region" aria-labelledby="ppMoveH">
    <div id="ppMoveH" class="fw-semibold small mb-1">Inne sprawy podmiotu <?= h($_pp_move_name) ?></div>
    <?php if (!$_pp_other): ?>
      <div class="small text-muted">Ten podmiot nie ma innych spraw.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="move_docs">
      <?php foreach ($_pp_other as $o): $d = $o['docs']; ?>
      <div class="form-check small">
        <input class="form-check-input" type="checkbox" name="src_case[]" value="<?= (int)$o['id'] ?>" id="ppSrc<?= (int)$o['id'] ?>"<?= $o['docs_total'] && !crm_case_is_closed($o['status']) ? ' checked' : '' ?>>
        <label class="form-check-label" for="ppSrc<?= (int)$o['id'] ?>"><span class="font-monospace"><?= h($o['case_number'] ?: '#' . $o['id']) ?></span> <?= h($o['title']) ?><?= crm_case_is_closed($o['status']) ? ' <span class="badge text-bg-secondary">' . h(mb_strtolower(crm_case_status_label($o['status']))) . '</span>' : '' ?>
          <span class="text-muted d-block" style="font-size:.72rem"><?= (int)$d['pisma'] ?> pism · <?= (int)$d['pliki'] ?> plików · <?= (int)$d['notatki'] ?> notatek · <?= (int)$d['wiadomosci'] ?> wiadomości · <?= (int)$d['zadania'] ?> zadań</span></label>
      </div>
      <?php endforeach; ?>
      <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="close_src" value="1" id="ppClose" checked><label class="form-check-label" for="ppClose">Zamknij sprawy źródłowe (powód: duplikat innej sprawy)</label></div>
      <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary">Przenieś dokumentację tutaj</button><a class="btn btn-sm btn-link" href="?id=<?= (int)$case['id'] ?>#podmioty">Pomiń</a></div>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($can_write): ?>
  <details class="small"<?= $_pp_move_cid ? '' : '' ?>>
    <summary class="fw-semibold" style="cursor:pointer;color:#2563EB">+ Wepnij podmiot</summary>
    <form method="post" class="mt-2" id="ppAddForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="add_party">
      <label class="form-label small mb-1" for="ppSearch">Kontakt lub firma z CRM</label>
      <div class="position-relative">
        <input type="text" id="ppSearch" class="form-control form-control-sm" autocomplete="off" placeholder="Wpisz nazwisko, e-mail albo organizację…" role="combobox" aria-expanded="false" aria-autocomplete="list">
        <input type="hidden" name="party_contact_id" id="ppId" value="">
        <div id="ppDrop" class="list-group shadow-sm" style="display:none;position:absolute;z-index:1050;width:100%;max-height:240px;overflow-y:auto;top:calc(100% + 2px)"></div>
      </div>
      <div id="ppPicked" class="small mt-1 fw-semibold" style="color:#15803D" aria-live="polite"></div>
      <div class="row g-2 mt-1">
        <div class="col-6"><label class="form-label small mb-1" for="ppRole">Rola</label>
          <select class="form-select form-select-sm" id="ppRole" name="party_role"><?php foreach (CRM_CASE_PARTY_ROLES as $rk => $rl): ?><option value="<?= h($rk) ?>"><?= h($rl) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small mb-1" for="ppNote">Uwaga</label><input class="form-control form-control-sm" id="ppNote" name="party_note" maxlength="300"></div>
      </div>
      <button class="btn btn-sm btn-outline-primary mt-2">Wepnij</button>
      <div class="form-text" style="font-size:.72rem">Po wpięciu pokażemy inne sprawy tego podmiotu — ich dokumentację można przenieść do tej sprawy.</div>
    </form>
  </details>
  <script>
  (function () {
    var inp = document.getElementById('ppSearch'), hid = document.getElementById('ppId'), drop = document.getElementById('ppDrop'), picked = document.getElementById('ppPicked');
    if (!inp) return;
    var timer = null, rows = [], active = -1;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function close() { drop.style.display = 'none'; inp.setAttribute('aria-expanded', 'false'); active = -1; }
    function pick(r) { hid.value = r.id; picked.textContent = 'Wybrano: ' + r.name + (r.organizacja ? ' · ' + r.organizacja : ''); inp.value = ''; close(); }
    function render() {
      drop.innerHTML = rows.length ? rows.map(function (r, i) { return '<button type="button" class="list-group-item list-group-item-action py-1' + (i === active ? ' active' : '') + '" data-i="' + i + '"><span style="font-size:.84rem">' + esc(r.name) + '</span>' + (r.organizacja ? '<span class="text-muted ms-1" style="font-size:.74rem">' + esc(r.organizacja) + '</span>' : '') + '</button>'; }).join('') : '<div class="list-group-item text-muted small">Brak pasujących kontaktów</div>';
      drop.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { pick(rows[Number(this.dataset.i)]); }); });
      drop.style.display = ''; inp.setAttribute('aria-expanded', 'true');
    }
    inp.addEventListener('input', function () {
      var q = this.value.trim(); clearTimeout(timer); if (q.length < 2) { close(); return; }
      timer = setTimeout(function () {
        fetch(<?= json_encode(APP_URL . '/crm/api/contacts_search.php') ?> + '?q=' + encodeURIComponent(q) + '&limit=10&exclude=<?= (int)$case['ct_id'] ?>')
          .then(function (r) { return r.json(); }).then(function (d) { rows = d || []; active = -1; render(); }).catch(close);
      }, 220);
    });
    inp.addEventListener('keydown', function (e) {
      if (drop.style.display === 'none') return;
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, rows.length - 1); render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(rows[active]); }
      else if (e.key === 'Escape') close();
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('#ppDrop') && e.target !== inp) close(); });
    document.getElementById('ppAddForm').addEventListener('submit', function (e) { if (!hid.value) { e.preventDefault(); picked.textContent = 'Najpierw wybierz kontakt z listy.'; inp.focus(); } });
  })();
  </script>
  <?php endif; ?>
</div></div>
