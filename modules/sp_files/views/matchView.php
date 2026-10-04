<?php
/**
 * modules/sp_files/views/matchView.php — widok ekranu admin/sp_contract_match.php.
 *
 * Zmienne od kontrolera: $self, $labels (CONTRACT_TYPES), $filters (scope, type, min), $url(), $fmtSize(), $ready (SharePoint skonfigurowany),
 * $result (flash + lines z poprzedniego żądania), $st (SpIndex::status()), $folder (zakres indeksowania), $hasIndex,
 * $plan (rows, unmatched, stats), $pageRows / $page / $pages (bieżąca strona), $rejCount.
 * Dostępność: tabela z podpisem i nagłówkami, grupy przycisków opcji z legendą, pewność podana słowem (nie tylko kolorem),
 * ostrzeżenia z ikoną i tekstem dla czytnika ekranu, licznik zaznaczeń w obszarze aria-live.
 */
$levelUi = [
    'high'   => ['label' => 'wysoka pewność',  'class' => 'text-bg-success',   'icon' => 'bi-check-circle-fill'],
    'medium' => ['label' => 'średnia pewność', 'class' => 'text-bg-warning',   'icon' => 'bi-question-circle-fill'],
    'low'    => ['label' => 'niska pewność',   'class' => 'text-bg-secondary', 'icon' => 'bi-dash-circle-fill'],
];
$scopeText = [
    'empty' => 'umowy, które nie mają żadnego pliku umowy',
    'lost'  => 'umowy ze wskazaniem pliku, którego nie ma ani na serwerze, ani na SharePoincie',
    'all'   => 'umowy bez pliku oraz z utraconym plikiem',
];
$stats    = $plan['stats'];
$run      = $st['run'];
$last     = $st['last'];
$canAttachAny = $ready && $hasIndex;
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 mb-0"><i class="bi bi-file-earmark-arrow-down text-primary" aria-hidden="true"></i> Skany z SharePointa — umowy bez pliku</h1>
  <a href="sp_contract_files.php" class="btn btn-sm btn-outline-secondary ms-auto">Pliki umów — przywracanie</a>
  <a href="sharepoint_settings.php" class="btn btn-sm btn-outline-secondary">Ustawienia SharePoint</a>
</div>

<?php if ($result): ?>
  <div class="alert alert-<?= h($result['flash'][0]) ?> py-2 small" role="status">
    <?= h($result['flash'][1]) ?>
    <?php if (!empty($result['lines'])): ?>
      <ul class="mb-0 mt-1">
        <?php foreach ($result['lines'] as $ln): ?>
          <li class="<?= $ln['ok'] ? 'text-success-emphasis' : 'text-danger-emphasis' ?>"><i class="bi <?= $ln['ok'] ? 'bi-check-circle' : 'bi-x-circle' ?>" aria-hidden="true"></i> <span class="visually-hidden"><?= $ln['ok'] ? 'Udało się:' : 'Błąd:' ?></span> <?= h($ln['text']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="alert alert-warning small">
    <strong>SharePoint jest wyłączony lub niekompletnie skonfigurowany.</strong> Indeksowanie i przypisywanie plików wymaga włączonej synchronizacji
    (witryna, biblioteka, konto aplikacji M365) — uzupełnij ją w <a href="sharepoint_settings.php">ustawieniach SharePoint</a>.
  </div>
<?php endif; ?>

<p class="text-muted small" style="max-width:62rem">
  Skan umowy położony na SharePoincie ręcznie nie ma nazwy znanej systemowi. Ten ekran porównuje <strong>umowy bez pliku</strong> z listą plików biblioteki
  (po numerze umowy, numerze z rejestru, nazwisku, roku i dacie) i <strong>proponuje</strong> dopasowania. Nic nie jest przypisywane samo —
  zaznacz właściwy plik przy umowie i kliknij „Przypisz”: plik zostanie pobrany z SharePointa, sprawdzony i zapisany jako plik umowy.
</p>

<section class="card shadow-sm mb-3" aria-labelledby="spm-index-h">
  <div class="card-body py-3">
    <h2 class="h6 mb-2" id="spm-index-h">Indeks plików SharePointa</h2>
    <?php if ($st['running']): ?>
      <p class="small mb-2"><i class="bi bi-hourglass-split" aria-hidden="true"></i> <strong>Indeksowanie w toku</strong> — przejrzano <?= (int)$run['folders_done'] ?> folderów,
        zindeksowano <?= (int)$run['files_seen'] ?> plików, w kolejce <?= (int)$st['queue'] ?> folderów.<?php if (!empty($run['message'])): ?> <span class="text-danger"><?= h($run['message']) ?></span><?php endif; ?></p>
    <?php elseif ($last): ?>
      <p class="small mb-2">
        W indeksie: <strong><?= (int)$st['files'] ?></strong> plików do dopasowania (pdf, jpg, png, docx)<?php if ($st['app_files']): ?> i <?= (int)$st['app_files'] ?> kopii plików wgranych przez system<?php endif; ?>.
        Ostatni przebieg: <?= h(substr((string)$last['finished_at'], 0, 16)) ?><?= $st['age_hours'] !== null ? ' (' . h(number_format((float)$st['age_hours'], 1, ',', ' ')) . ' godz. temu)' : '' ?>,
        <?= (int)$last['folders_done'] ?> folderów<?= $last['status'] === 'partial' ? ' — <span class="text-warning-emphasis">niepełny: ' . h((string)$last['message']) . '</span>' : '' ?>.
        <?php if ($st['age_hours'] !== null && $st['age_hours'] > 24): ?><span class="text-warning-emphasis">Indeks jest starszy niż doba — odśwież go przed zatwierdzaniem.</span><?php endif; ?>
      </p>
    <?php elseif ($run && in_array($run['status'], ['error', 'aborted'], true)): ?>
      <p class="small mb-2 text-danger">Ostatni przebieg: <?= h($run['status'] === 'error' ? 'błąd — ' . (string)$run['message'] : 'przerwany') ?>.</p>
    <?php else: ?>
      <p class="small mb-2 text-muted">Indeks jest pusty — odśwież go, żeby zobaczyć propozycje.</p>
    <?php endif; ?>

    <div class="d-flex gap-2 flex-wrap align-items-end">
      <form method="post" class="d-flex gap-2 flex-wrap">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
        <?php if ($st['running']): ?>
          <button class="btn btn-sm btn-primary" type="submit" name="_action" value="reindex_continue"<?= $ready ? '' : ' disabled' ?>><i class="bi bi-play-fill" aria-hidden="true"></i> Kontynuuj indeksowanie</button>
          <button class="btn btn-sm btn-outline-secondary" type="submit" name="_action" value="reindex"<?= $ready ? '' : ' disabled' ?>>Zacznij od nowa</button>
          <button class="btn btn-sm btn-outline-danger" type="submit" name="_action" value="reindex_abort">Przerwij</button>
        <?php else: ?>
          <button class="btn btn-sm btn-primary" type="submit" name="_action" value="reindex"<?= $ready ? '' : ' disabled' ?>><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Odśwież indeks z SharePointa</button>
        <?php endif; ?>
      </form>
      <form method="post" class="d-flex gap-2 flex-wrap align-items-end ms-md-auto">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
        <div>
          <label class="form-label small mb-1" for="spm-folder">Folder do przeszukania (puste = cała biblioteka)</label>
          <input class="form-control form-control-sm" id="spm-folder" name="folder" type="text" maxlength="300" value="<?= h($folder) ?>" placeholder="np. Skany umów" style="min-width:16rem">
        </div>
        <button class="btn btn-sm btn-outline-secondary" type="submit" name="_action" value="save_folder">Zapisz zakres</button>
      </form>
    </div>
    <p class="small text-muted mb-0 mt-2">Przeglądanie biblioteki trwa kilkadziesiąt sekund na kilka tysięcy plików; przy dużej bibliotece kliknij „Kontynuuj”, aż indeks będzie gotowy
      (albo użyj konsoli: <code>php cli/sp_match_contracts.php --reindex</code>). Pobierane są tylko nazwy i rozmiary — nie treść plików.</p>
  </div>
</section>

<?php if ($hasIndex): ?>
<form method="get" class="d-flex align-items-end gap-2 mb-3 flex-wrap" aria-label="Filtry propozycji">
  <div>
    <label class="form-label small mb-1" for="spm-scope">Którym umowom szukać pliku</label>
    <select class="form-select form-select-sm" id="spm-scope" name="scope">
      <option value="empty"<?= $filters['scope'] === 'empty' ? ' selected' : '' ?>>Bez pliku</option>
      <option value="lost"<?= $filters['scope'] === 'lost' ? ' selected' : '' ?>>Z utraconym plikiem</option>
      <option value="all"<?= $filters['scope'] === 'all' ? ' selected' : '' ?>>Bez pliku i z utraconym</option>
    </select>
  </div>
  <div>
    <label class="form-label small mb-1" for="spm-type">Rodzaj umowy</label>
    <select class="form-select form-select-sm" id="spm-type" name="type">
      <option value="">wszystkie</option>
      <?php foreach ($labels as $slug => $lab): ?><option value="<?= h($slug) ?>"<?= $filters['type'] === $slug ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label small mb-1" for="spm-min">Pokaż propozycje o pewności</label>
    <select class="form-select form-select-sm" id="spm-min" name="min">
      <option value="high"<?= $filters['min'] === 'high' ? ' selected' : '' ?>>tylko wysokiej</option>
      <option value="medium"<?= $filters['min'] === 'medium' ? ' selected' : '' ?>>średniej i wysokiej</option>
      <option value="low"<?= $filters['min'] === 'low' ? ' selected' : '' ?>>także niskiej</option>
    </select>
  </div>
  <button class="btn btn-sm btn-outline-primary" type="submit">Filtruj</button>
</form>

<p class="small mb-2" role="status">
  Szukamy pliku dla: <?= h($scopeText[$filters['scope']]) ?>. Umów do uzupełnienia: <strong><?= (int)$stats['targets'] ?></strong>;
  z propozycją: <strong><?= (int)$stats['with_proposals'] ?></strong>
  (<?= (int)$stats['high'] ?> wysoka, <?= (int)$stats['medium'] ?> średnia<?= $filters['min'] === 'low' ? ', ' . (int)$stats['low'] . ' niska' : '' ?>);
  bez propozycji: <strong><?= count($plan['unmatched']) ?></strong>.
</p>

<?php if (!$pageRows): ?>
  <div class="alert alert-light border small">
    <?php if ($stats['targets'] === 0): ?>
      Nie ma umów do uzupełnienia dla wybranego zakresu.
    <?php else: ?>
      Brak propozycji o wybranej pewności. Zmień filtr na „także niskiej” albo odśwież indeks po dodaniu skanów na SharePoint.
    <?php endif; ?>
  </div>
<?php else: ?>
<form method="post" id="spm-form">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
  <input type="hidden" name="page" value="<?= (int)$page ?>">

  <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
    <button type="button" class="btn btn-sm btn-outline-success" id="spm-pick-high"<?= $canAttachAny ? '' : ' disabled' ?>><i class="bi bi-check2-all" aria-hidden="true"></i> Zaznacz najlepszy plik przy pewnych dopasowaniach</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="spm-pick-none">Wyczyść zaznaczenie</button>
    <span class="small text-muted" id="spm-count" aria-live="polite">Zaznaczono: 0</span>
  </div>

  <div class="table-responsive">
    <table class="table table-sm align-top">
      <caption class="small text-muted">Propozycje dla umów bez pliku — strona <?= (int)$page ?> z <?= (int)$pages ?> (<?= count($plan['rows']) ?> umów). Przy każdej umowie wybierz właściwy plik albo zostaw „Nie przypisuj”.</caption>
      <thead class="table-light">
        <tr><th scope="col" style="width:20rem">Umowa</th><th scope="col">Proponowany plik z SharePointa (wybierz jeden)</th></tr>
      </thead>
      <tbody>
      <?php foreach ($pageRows as $row):
        $c = $row['contract']; $key = $c['key']; $nid = 'spm-none-' . md5($key); ?>
        <tr data-level="<?= h($row['best_level']) ?>">
          <th scope="row" class="fw-normal">
            <a href="<?= h(rtrim(APP_URL, '/') . '/contracts/' . $c['type'] . '/view.php?id=' . $c['id']) ?>" target="_blank" rel="noopener"><?= h($c['number'] !== '' ? $c['number'] : 'umowa #' . $c['id']) ?><span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>
            <span class="badge text-bg-light border"><?= h($labels[$c['type']] ?? $c['type']) ?></span>
            <div class="small text-muted">
              <?= h(implode(', ', $c['names']) ?: 'brak nazwy') ?>
              <?= $c['signed'] !== '' ? ' · ' . h(substr($c['signed'], 0, 10)) : '' ?>
              <?= $c['status'] !== '' ? ' · ' . h($c['status']) : '' ?>
              <?php if ($c['register'] !== ''): ?> · rejestr: <?= h($c['register']) ?><?php endif; ?>
            </div>
            <?php if ($c['lost']): ?><div class="small text-danger-emphasis"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Utracony plik: <code><?= h($c['current']) ?></code></div><?php endif; ?>
          </th>
          <td>
            <fieldset>
              <legend class="visually-hidden">Plik dla umowy <?= h($c['number'] !== '' ? $c['number'] : '#' . $c['id']) ?></legend>
              <?php foreach ($row['candidates'] as $i => $cd):
                $f = $cd['file']; $u = $levelUi[$cd['level']]; $rid = 'spm-' . md5($key . '|' . $f['item_id']);
                $tooBig = $f['size'] > SpMatcher::ATTACH_MAX_BYTES; ?>
                <div class="form-check mb-2 pb-2 border-bottom">
                  <input class="form-check-input" type="radio" name="pick[<?= h($key) ?>]" id="<?= h($rid) ?>" value="<?= h($f['item_id']) ?>" data-rank="<?= (int)$i ?>"<?= ($tooBig || !$canAttachAny) ? ' disabled' : '' ?>>
                  <label class="form-check-label" for="<?= h($rid) ?>">
                    <span class="badge <?= h($u['class']) ?>"><i class="bi <?= h($u['icon']) ?>" aria-hidden="true"></i> <?= h($u['label']) ?> (<?= (int)$cd['score'] ?>)</span>
                    <span class="fw-semibold ms-1"><?= h($f['name']) ?></span>
                    <span class="d-block small text-muted">Folder: <?= h($f['folder'] !== '' ? $f['folder'] : '(korzeń biblioteki)') ?> · <?= h($fmtSize($f['size'])) ?><?= $f['modified_at'] !== '' ? ' · zmieniony ' . h(substr($f['modified_at'], 0, 10)) : '' ?></span>
                  </label>
                  <?php if ($f['web_url'] !== ''): ?>
                    <a class="small" href="<?= h($f['web_url']) ?>" target="_blank" rel="noopener noreferrer">Otwórz na SharePoincie<span class="visually-hidden"> (nowa karta): <?= h($f['name']) ?></span></a>
                  <?php endif; ?>
                  <?php if ($cd['evidence']): ?>
                    <ul class="small mb-1 ps-3 text-body-secondary">
                      <?php foreach ($cd['evidence'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <?php if ($cd['warnings']): ?>
                    <ul class="small mb-0 list-unstyled text-warning-emphasis">
                      <?php foreach ($cd['warnings'] as $w): ?><li><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> <span class="visually-hidden">Uwaga:</span> <?= h($w) ?></li><?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="pick[<?= h($key) ?>]" id="<?= h($nid) ?>" value="" checked>
                <label class="form-check-label text-muted" for="<?= h($nid) ?>">Nie przypisuj</label>
              </div>
            </fieldset>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="d-flex gap-2 flex-wrap align-items-center">
    <button type="submit" name="_action" value="attach" class="btn btn-sm btn-success"<?= $canAttachAny ? '' : ' disabled' ?>
            onclick="return confirm('Przypisać zaznaczone pliki do umów? Każdy plik zostanie pobrany z SharePointa i zapisany jako plik umowy.');"><i class="bi bi-paperclip" aria-hidden="true"></i> Przypisz zaznaczone</button>
    <button type="submit" name="_action" value="reject" class="btn btn-sm btn-outline-danger"
            onclick="return confirm('Odrzucić zaznaczone propozycje? Te pary umowa–plik nie pojawią się ponownie.');"><i class="bi bi-hand-thumbs-down" aria-hidden="true"></i> To nie ten plik — odrzuć zaznaczone</button>
    <span class="small text-muted">Do <?= (int)SPM_ATTACH_BATCH ?> przypisań na raz. Przypisanie nie nadpisuje istniejącego pliku umowy.</span>
  </div>
</form>

<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Strony propozycji">
  <ul class="pagination pagination-sm">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= h($url(['page' => $i])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php if ($plan['unmatched']): ?>
<details class="mt-4">
  <summary class="small fw-semibold">Umowy bez propozycji (<?= count($plan['unmatched']) ?>)</summary>
  <div class="table-responsive mt-2">
    <table class="table table-sm small">
      <caption class="visually-hidden">Umowy, dla których nie znaleziono pasującego pliku</caption>
      <thead class="table-light"><tr><th scope="col">Umowa</th><th scope="col">Rodzaj</th><th scope="col">Strona umowy</th><th scope="col">Uwagi</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($plan['unmatched'], 0, 200) as $u): ?>
          <tr>
            <th scope="row" class="fw-normal"><a href="<?= h(rtrim(APP_URL, '/') . '/contracts/' . $u['type'] . '/view.php?id=' . $u['id']) ?>" target="_blank" rel="noopener"><?= h($u['number'] !== '' ? $u['number'] : 'umowa #' . $u['id']) ?><span class="visually-hidden"> (nowa karta)</span></a></th>
            <td><?= h($labels[$u['type']] ?? $u['type']) ?></td>
            <td><?= h(implode(', ', $u['names']) ?: '—') ?></td>
            <td><?= $u['hidden'] > 0 ? h('ukryte słabsze dopasowania: ' . $u['hidden'] . ' — zmień filtr na „także niskiej”') : 'brak pasującego pliku w indeksie' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($plan['unmatched']) > 200): ?><p class="small text-muted">Pokazano 200 z <?= count($plan['unmatched']) ?>. Pełną listę da konsola: <code>php cli/sp_match_contracts.php</code>.</p><?php endif; ?>
  </div>
</details>
<?php endif; ?>

<?php if ($rejCount > 0): ?>
<form method="post" class="mt-3 small">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
  Odrzuconych propozycji: <strong><?= (int)$rejCount ?></strong>.
  <button type="submit" name="_action" value="clear_rejections" class="btn btn-sm btn-link p-0 align-baseline"
          onclick="return confirm('Przywrócić wszystkie odrzucone propozycje?');">Przywróć wszystkie</button>
</form>
<?php endif; ?>
<?php endif; ?>

<p class="small text-muted mt-4 mb-0">
  Z konsoli (podgląd, bez przypisywania): <code>php cli/sp_match_contracts.php</code> — opcje <code>--reindex</code>, <code>--type=</code>, <code>--scope=empty|lost|all</code>, <code>--min=high|medium|low</code>.
  Każde przypisanie zapisuje się w audycie (<code>sp_files.attached</code>); plik trafia też, jak każdy upload, na SharePoint pod nazwą systemową.
</p>

<script>
(function () {
  var form = document.getElementById('spm-form');
  if (!form) return;
  var out = document.getElementById('spm-count');
  function count() {
    var n = form.querySelectorAll('input[type=radio]:checked:not([value=""])').length;
    out.textContent = 'Zaznaczono: ' + n;
  }
  form.addEventListener('change', count);
  document.getElementById('spm-pick-high').addEventListener('click', function () {
    form.querySelectorAll('tr[data-level="high"]').forEach(function (tr) {
      var r = tr.querySelector('input[type=radio][data-rank="0"]:not(:disabled)');
      if (r) r.checked = true;
    });
    count();
  });
  document.getElementById('spm-pick-none').addEventListener('click', function () {
    form.querySelectorAll('input[type=radio][value=""]').forEach(function (r) { r.checked = true; });
    count();
  });
})();
</script>
