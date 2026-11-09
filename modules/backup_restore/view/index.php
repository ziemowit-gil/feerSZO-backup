<?php
/**
 * modules/backup_restore/view/index.php — wybór kopii, porównanie tabel i formularz przywracania.
 * Zmienne: $candidates, $cand (wybrana kopia albo null), $error, $compare (wynik backupRestoreCompare), $result (z sesji).
 */
$PAGE_TITLE = 'Przywróć bazę SQL z kopii';
include dirname(__DIR__, 3) . '/includes/header.php';

$rows      = ($compare && $compare['ok']) ? $compare['rows'] : [];
$diffCount = count(array_filter($rows, fn($r) => $r['diff'] && $r['skip'] === null));
$fmtN      = fn($n) => $n === null ? '—' : number_format((int)$n, 0, ',', ' ');
?>
<nav aria-label="Ścieżka nawigacji" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/index.php">Admin</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/backups.php">Kopie zapasowe</a></li>
    <li class="breadcrumb-item active" aria-current="page">Przywracanie bazy</li>
  </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-database-fill-gear text-warning" aria-hidden="true"></i> Przywróć bazę SQL z kopii</h1>
  <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/backups.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Kopie zapasowe</a>
</div>
<p class="text-body-secondary small mb-3">
  Narzędzie awaryjne administratora. Najpierw wybierz kopię (domyślnie najnowsza z wczoraj), porównaj liczbę wierszy
  w tabelach i przywróć tylko te tabele, w których brakuje danych — reszta systemu zostaje bez zmian.
  Przed każdym przywróceniem system sam robi kopię bieżącej bazy.
</p>

<?php if ($result): ?>
<div class="alert alert-<?= $result['ok'] ? 'success' : 'danger' ?> small" role="<?= $result['ok'] ? 'status' : 'alert' ?>">
  <strong><?= $result['ok'] ? 'Gotowe.' : 'Nie przywrócono.' ?></strong> <?= h($result['msg']) ?>
  <?php if (!$result['ok'] && !empty($result['safety'])): ?>
    <br>Kopia bieżącej bazy zrobiona przed próbą: <code><?= h($result['safety']) ?></code>.
  <?php endif; ?>
  <?php if (!empty($result['tables'])): ?>
  <details class="mt-2"><summary style="cursor:pointer">Szczegóły: <?= count($result['tables']) ?> tabel(e)</summary>
    <div class="table-responsive mt-1" style="max-height:320px;overflow:auto">
      <table class="table table-sm mb-0 small">
        <caption class="visually-hidden">Liczba wierszy przed i po przywróceniu</caption>
        <thead><tr><th scope="col">Tabela</th><th scope="col" class="text-end">Przed</th><th scope="col" class="text-end">Po</th><th scope="col">Uwagi</th></tr></thead>
        <tbody>
        <?php foreach ($result['tables'] as $tName => $t): ?>
          <tr<?= $t['before'] !== $t['after'] ? ' class="fw-semibold"' : '' ?>>
            <td><code><?= h($tName) ?></code></td>
            <td class="text-end"><?= $fmtN($t['before']) ?></td>
            <td class="text-end"><?= $fmtN($t['after']) ?></td>
            <td><?= !empty($t['missing']) ? 'kolumny nowsze niż kopia (wartości domyślne): ' . h(implode(', ', $t['missing'])) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>
  <?php if (!empty($result['created'])): ?><div class="mt-1">Utworzone tabele (brakowało ich w bazie): <?= h(implode(', ', $result['created'])) ?></div><?php endif; ?>
  <?php if (!empty($result['newer'])): ?><div class="mt-1">Tabele nowsze niż kopia — pozostawione bez zmian (<?= count($result['newer']) ?>): <?= h(implode(', ', $result['newer'])) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($error): ?><div class="alert alert-danger small" role="alert"><?= h($error) ?></div><?php endif; ?>

<?php if (!$candidates): ?>
<div class="alert alert-info small">Brak lokalnych kopii bazy w katalogu <code>backups/</code>. Uruchom backup w panelu kopii zapasowych.</div>
<?php else: ?>

<div class="card shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold"><span aria-hidden="true">1.</span> Wybierz kopię bazy</div>
  <div class="card-body small">
    <form method="get" action="<?= h(route('backupRestore.index')) ?>" class="d-flex flex-wrap gap-2 align-items-end">
      <div class="flex-grow-1" style="max-width:720px">
        <label class="form-label mb-1" for="brFile">Kopia bazy (data i godzina wykonania)</label>
        <select id="brFile" name="file" class="form-select form-select-sm">
          <?php foreach ($candidates as $c): ?>
          <option value="<?= h($c['rel']) ?>"<?= $cand && $cand['rel'] === $c['rel'] ? ' selected' : '' ?>><?= h(backupRestoreLabel($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-sm btn-primary"><i class="bi bi-search" aria-hidden="true"></i> Porównaj z bieżącą bazą</button>
    </form>
  </div>
</div>

<?php if ($cand && $compare && !$compare['ok']): ?>
<div class="alert alert-danger small" role="alert"><?= h($compare['msg']) ?></div>
<?php elseif ($cand && $rows): ?>

<form method="post" action="<?= h(route('backupRestore.restore')) ?>" id="brForm" novalidate>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="file" value="<?= h($cand['rel']) ?>">

  <div class="card shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold"><span aria-hidden="true">2.</span> Zakres przywracania — kopia z <?= h(date('d.m.Y H:i', $cand['ts'])) ?></div>
    <div class="card-body small">
      <fieldset class="mb-3">
        <legend class="fs-6 fw-semibold mb-2">Co przywrócić?</legend>
        <div class="form-check mb-1">
          <input class="form-check-input" type="radio" name="mode" id="brModeTables" value="tables" checked aria-describedby="brModeTablesHelp">
          <label class="form-check-label fw-semibold" for="brModeTables">Tylko wybrane tabele (zalecane)</label>
          <div id="brModeTablesHelp" class="text-body-secondary">Np. same dokumenty EODoK (<code>edok_*</code>). Pozostałe tabele zostają w obecnym stanie.</div>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="mode" id="brModeFull" value="full" aria-describedby="brModeFullHelp">
          <label class="form-check-label fw-semibold" for="brModeFull">Cała baza</label>
          <div id="brModeFullHelp" class="text-body-secondary">Wszystkie tabele z kopii. Dziennik audytu (<code>audit_logs</code>, <code>admin_audit_log</code>) i sesje zalogowanych osób (<code>php_sessions</code>) zostają z bieżącej bazy; tabele nowsze niż kopia — bez zmian.</div>
        </div>
      </fieldset>

      <div id="brTablesBox">
        <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
          <div>
            <label class="form-label mb-1" for="brFilter">Filtruj tabele</label>
            <input type="search" id="brFilter" class="form-control form-control-sm" placeholder="np. edok_" style="width:220px" aria-controls="brTable">
          </div>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="brOnlyDiff" checked aria-controls="brTable">
            <label class="form-check-label" for="brOnlyDiff">Pokaż tylko tabele, które się różnią (<?= (int)$diffCount ?>)</label>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" id="brSelectDiff">Zaznacz różniące się (widoczne)</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="brSelectNone">Odznacz wszystkie</button>
          <span class="ms-auto" aria-live="polite"><strong id="brSelCount">0</strong> zaznaczonych</span>
        </div>
        <div class="table-responsive border" style="max-height:480px;overflow:auto">
          <table class="table table-sm table-hover mb-0 align-middle" id="brTable">
            <caption class="visually-hidden">Tabele bazy: liczba wierszy teraz i w kopii</caption>
            <thead class="table-light" style="position:sticky;top:0;z-index:1">
              <tr>
                <th scope="col" style="width:2.5rem"><span class="visually-hidden">Wybór</span></th>
                <th scope="col">Tabela</th>
                <th scope="col" class="text-end">Teraz</th>
                <th scope="col" class="text-end">W kopii</th>
                <th scope="col" class="text-end">Różnica</th>
                <th scope="col">Uwagi</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
              $delta  = ($r['cur'] !== null && $r['bak'] !== null) ? $r['bak'] - $r['cur'] : null;
              $rowCls = $r['skip'] !== null ? 'text-body-secondary' : ($delta !== null && $delta > 0 ? 'table-warning' : ''); ?>
              <tr class="<?= $rowCls ?>" data-name="<?= h($r['name']) ?>" data-diff="<?= $r['diff'] ? '1' : '0' ?>" data-skip="<?= $r['skip'] !== null ? '1' : '0' ?>">
                <td>
                  <?php if ($r['skip'] === null): ?>
                  <input class="form-check-input br-tbl" type="checkbox" name="tables[]" value="<?= h($r['name']) ?>" id="brT<?= $i ?>">
                  <?php endif; ?>
                </td>
                <td><?php if ($r['skip'] === null): ?><label for="brT<?= $i ?>" class="mb-0"><code><?= h($r['name']) ?></code></label><?php else: ?><code><?= h($r['name']) ?></code><?php endif; ?></td>
                <td class="text-end"><?= $fmtN($r['cur']) ?></td>
                <td class="text-end"><?= $fmtN($r['bak']) ?></td>
                <td class="text-end"><?= $delta === null || $delta === 0 ? '' : ($delta > 0 ? '<span class="text-danger fw-semibold" title="W kopii jest więcej wierszy niż teraz">+' . $fmtN($delta) . ' w kopii</span>' : '<span title="Teraz jest więcej wierszy niż w kopii">' . $fmtN(-$delta) . ' nowszych</span>') ?></td>
                <td class="small"><?= $r['skip'] !== null ? h($r['skip']) : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div id="brTablesError" class="text-danger small mt-1" role="alert" hidden>Zaznacz co najmniej jedną tabelę albo wybierz „Cała baza”.</div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-3 border-danger">
    <div class="card-header py-2 fw-semibold text-danger"><span aria-hidden="true">3.</span> Potwierdzenie</div>
    <div class="card-body small">
      <div class="alert alert-danger py-2 mb-3" id="brWarning">
        <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
        <strong>Uwaga:</strong> wszystko, co zapisano w przywracanych tabelach po <strong><?= h(date('d.m.Y H:i', $cand['ts'])) ?></strong>, zostanie zastąpione stanem z kopii.
        Przed przywróceniem system automatycznie zrobi kopię bieżącej bazy (<code>umowy_pre-restore_…</code>) — tylko z niej da się potem odzyskać te zmiany.
        Na czas przywracania (zwykle kilka sekund) zapis do bazy przez innych użytkowników jest wstrzymany.
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="confirm_check" value="1" id="brConfirmCheck" required aria-describedby="brWarning">
        <label class="form-check-label" for="brConfirmCheck">Rozumiem, że dane zapisane po godzinie kopii w przywracanych tabelach zostaną nadpisane.</label>
      </div>
      <div class="mb-3">
        <label class="form-label mb-1" for="brConfirmText">Aby potwierdzić, wpisz słowo <strong>PRZYWRÓĆ</strong></label>
        <input type="text" class="form-control form-control-sm" name="confirm_text" id="brConfirmText" autocomplete="off" required style="max-width:220px" aria-describedby="brConfirmError">
        <div id="brConfirmError" class="text-danger small mt-1" role="alert" hidden>Zaznacz pole „Rozumiem…” i wpisz słowo PRZYWRÓĆ.</div>
      </div>
      <button type="submit" class="btn btn-danger btn-sm" id="brSubmit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Przywróć z kopii</button>
    </div>
  </div>
</form>

<script>
(function () {
  const form = document.getElementById('brForm');
  const rows = Array.from(document.querySelectorAll('#brTable tbody tr'));
  const filter = document.getElementById('brFilter');
  const onlyDiff = document.getElementById('brOnlyDiff');
  const box = document.getElementById('brTablesBox');
  const cnt = document.getElementById('brSelCount');
  const modeFull = document.getElementById('brModeFull');
  const boxes = () => Array.from(document.querySelectorAll('.br-tbl'));
  const visible = (tr) => !tr.hidden;

  function applyFilter() {
    const q = filter.value.trim().toLowerCase();
    rows.forEach(tr => {
      const okName = !q || tr.dataset.name.toLowerCase().includes(q);
      const okDiff = !onlyDiff.checked || tr.dataset.diff === '1';
      tr.hidden = !(okName && okDiff);
    });
  }
  function updateCount() { cnt.textContent = boxes().filter(b => b.checked).length; }
  function updateMode() {
    const full = modeFull.checked;
    box.style.opacity = full ? .45 : 1;
    boxes().forEach(b => b.disabled = full);
    filter.disabled = onlyDiff.disabled = full;
    document.getElementById('brSelectDiff').disabled = document.getElementById('brSelectNone').disabled = full;
  }
  filter.addEventListener('input', applyFilter);
  onlyDiff.addEventListener('change', applyFilter);
  document.getElementById('brSelectDiff').addEventListener('click', () => {
    rows.forEach(tr => { if (visible(tr) && tr.dataset.diff === '1' && tr.dataset.skip === '0') { const b = tr.querySelector('.br-tbl'); if (b) b.checked = true; } });
    updateCount();
  });
  document.getElementById('brSelectNone').addEventListener('click', () => { boxes().forEach(b => b.checked = false); updateCount(); });
  document.addEventListener('change', e => { if (e.target.classList && e.target.classList.contains('br-tbl')) updateCount(); });
  document.querySelectorAll('input[name=mode]').forEach(r => r.addEventListener('change', updateMode));

  form.addEventListener('submit', e => {
    const tErr = document.getElementById('brTablesError');
    const cErr = document.getElementById('brConfirmError');
    const text = document.getElementById('brConfirmText');
    const check = document.getElementById('brConfirmCheck');
    const sel = boxes().filter(b => b.checked).map(b => b.value);
    const full = modeFull.checked;
    tErr.hidden = full || sel.length > 0;
    const typed = text.value.trim().toUpperCase();
    cErr.hidden = check.checked && (typed === 'PRZYWRÓĆ' || typed === 'PRZYWROC');
    text.setAttribute('aria-invalid', cErr.hidden ? 'false' : 'true');
    if (!tErr.hidden || !cErr.hidden) { e.preventDefault(); (!tErr.hidden ? filter : (check.checked ? text : check)).focus(); return; }
    const what = full ? 'CAŁĄ BAZĘ (wszystkie tabele z kopii)' : sel.length + ' tabel(e): ' + sel.slice(0, 12).join(', ') + (sel.length > 12 ? '…' : '');
    if (!confirm('PRZYWRÓCIĆ ' + what + '\nz kopii z <?= h(date('d.m.Y H:i', $cand['ts'])) ?>?\n\nDane zapisane po tej godzinie w tych tabelach zostaną nadpisane (zostaną tylko w automatycznej kopii pre-restore).')) { e.preventDefault(); return; }
    const btn = document.getElementById('brSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Przywracam…';
  });

  applyFilter(); updateMode(); updateCount();
})();
</script>

<?php endif; ?>
<?php endif; ?>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
