<?php
/**
 * modules/analytics/views/_report.php — jeden raport Analityki TI: filtry, tabela albo lista, eksport.
 * Wejście: $an_key, $an_ds (zestaw danych), $an_filters (znormalizowane), $an_view ('tabela'|'lista'), $an_token (CSRF panelu).
 * Widok listy to pełne zdania bez tabeli — dla czytników ekranu; eksport CSV / Excel to formularze POST
 * (lista zawiera dane osobowe, eksport jest zapisywany w audycie).
 */
$_self  = 'analitykaRaport.php';
$_qs    = fn(array $extra = []) => http_build_query(['r' => $an_key] + $an_filters + $extra);
$_cols  = count($an_ds['headers']);
?>
<div class="d-flex align-items-start gap-2 mb-1 flex-wrap">
  <h1 class="h4 mb-0"><i class="bi bi-<?= h(AnalyticsManager::REPORTS[$an_key][1]) ?> text-primary me-2" aria-hidden="true"></i><?= h($an_ds['title']) ?></h1>
</div>
<p class="text-body-secondary small mb-3" style="max-width:62rem"><?= h(AnalyticsManager::REPORTS[$an_key][2]) ?></p>

<form method="get" action="<?= $_self ?>" class="d-flex align-items-end gap-3 flex-wrap mb-3" aria-label="Filtry raportu">
  <input type="hidden" name="r" value="<?= h($an_key) ?>">
  <input type="hidden" name="view" value="<?= h($an_view) ?>">
  <?php if ($an_key === 'licencje'): ?>
    <div>
      <label class="form-label small mb-1" for="an-days">Wygasa w ciągu</label>
      <select class="form-select form-select-sm" id="an-days" name="days">
        <?php foreach ([14, 30, 60, 90] as $d): ?><option value="<?= $d ?>"<?= (int)$an_filters['days'] === $d ? ' selected' : '' ?>><?= $d ?> dni</option><?php endforeach; ?>
      </select>
    </div>
  <?php elseif ($an_key === 'obciazenie'): ?>
    <div>
      <label class="form-label small mb-1" for="an-ym">Miesiąc</label>
      <input class="form-control form-control-sm" type="month" id="an-ym" name="ym" value="<?= h($an_filters['ym']) ?>" pattern="\d{4}-\d{2}" placeholder="RRRR-MM" required>
    </div>
  <?php elseif ($an_key === 'rozliczenia'): ?>
    <div>
      <label class="form-label small mb-1" for="an-from">Wystawione od</label>
      <input class="form-control form-control-sm" type="date" id="an-from" name="from" value="<?= h($an_filters['from']) ?>" required>
    </div>
    <div>
      <label class="form-label small mb-1" for="an-to">do</label>
      <input class="form-control form-control-sm" type="date" id="an-to" name="to" value="<?= h($an_filters['to']) ?>" required>
    </div>
  <?php endif; ?>
  <?php if ($an_filters): ?><button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Pokaż</button><?php endif; ?>
</form>

<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
  <p class="mb-0 fw-semibold" id="an-summary"><?= h($an_ds['subtitle']) ?></p>
</div>

<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <div class="btn-group btn-group-sm" role="group" aria-label="Sposób wyświetlania">
    <a class="btn btn-outline-secondary<?= $an_view === 'tabela' ? ' active' : '' ?>" href="<?= $_self ?>?<?= h($_qs(['view' => 'tabela'])) ?>"<?= $an_view === 'tabela' ? ' aria-current="true"' : '' ?>><i class="bi bi-table me-1" aria-hidden="true"></i>Tabela</a>
    <a class="btn btn-outline-secondary<?= $an_view === 'lista' ? ' active' : '' ?>" href="<?= $_self ?>?<?= h($_qs(['view' => 'lista'])) ?>"<?= $an_view === 'lista' ? ' aria-current="true"' : '' ?>><i class="bi bi-list-ol me-1" aria-hidden="true"></i>Lista <span class="visually-hidden">(wersja bez tabeli, dla czytników ekranu)</span></a>
  </div>
  <form method="post" action="analitykaEksport.php" class="ms-auto d-flex gap-2 flex-wrap" aria-label="Eksport raportu">
    <input type="hidden" name="_token" value="<?= h($an_token) ?>">
    <input type="hidden" name="r" value="<?= h($an_key) ?>">
    <?php foreach ($an_filters as $_k => $_v): ?><input type="hidden" name="<?= h($_k) ?>" value="<?= h((string)$_v) ?>"><?php endforeach; ?>
    <button type="submit" name="fmt" value="csv" class="btn btn-sm btn-outline-primary"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Pobierz CSV</button>
    <button type="submit" name="fmt" value="xlsx" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Pobierz Excel</button>
  </form>
</div>

<?php if (!$an_ds['rows']): ?>
  <div class="alert alert-light border" role="status">Brak pozycji dla wybranych filtrów.</div>
<?php elseif ($an_view === 'lista'): ?>
  <ol class="an-list" aria-label="<?= h($an_ds['title']) ?> — lista pozycji">
    <?php foreach (AnalyticsExport::sentences($an_ds) as $_s): ?><li><?= h(preg_replace('/^\d+\.\s*/', '', $_s)) ?></li><?php endforeach; ?>
  </ol>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle an-table">
      <caption class="visually-hidden"><?= h($an_ds['title'] . ' — ' . $an_ds['subtitle']) ?></caption>
      <thead class="table-light">
        <tr><?php foreach ($an_ds['headers'] as $_c => $_h): ?><th scope="col"<?= in_array($_c, $an_ds['num'], true) ? ' class="text-end"' : '' ?>><?= h($_h) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($an_ds['rows'] as $_row): ?>
        <tr>
          <?php foreach ($_row as $_c => $_v): $_num = in_array($_c, $an_ds['num'], true);
            $_txt = AnalyticsExport::display($_v, AnalyticsExport::decimals($an_ds, (int)$_c));
            $_flag = $_v === 'Ponad limit'; ?>
            <?php if ($_c === 0): ?><th scope="row" class="fw-normal"><?= h($_txt) ?></th>
            <?php else: ?><td<?= $_num ? ' class="text-end"' : '' ?>><?php if ($_flag): ?><span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= h($_txt) ?></span><?php else: ?><?= h($_txt) ?><?php endif; ?></td><?php endif; ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<p class="small text-body-secondary mt-3 mb-0"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Raport może zawierać dane osobowe kursantów — nie udostępniaj go poza organizacją. Każdy eksport jest zapisywany w audycie (bez treści pozycji).</p>
