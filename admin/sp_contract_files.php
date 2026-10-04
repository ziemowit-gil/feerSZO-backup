<?php
/**
 * admin/sp_contract_files.php — pliki umów, których brak lokalnie: dopasowanie z SharePointem i przywrócenie.
 *
 * Lista = odwołania do plików z rekordów umów (plik_umowy, potwierdzenie, załączniki, zgody, aneksy, dokumenty
 * dodatkowe, pisma), dla których nie ma pliku w uploads/. „Szukaj na SharePoint” sprawdza, gdzie leży każdy z nich
 * (ścieżka dokładna → ścieżka bez folderu bazowego → nazwa pliku w bibliotece), niczego nie zapisując; „Przywróć”
 * pobiera znalezione pliki do uploads/ pod tą samą ścieżką. Przy kilku plikach o tej samej nazwie administrator
 * wskazuje właściwy. Logika: modules/sp_files; to samo robi automatycznie contracts/file.php przy otwarciu linku
 * i cli/sp_restore_files.php hurtowo.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/modules/sp_files/logic/spFiles.php';
require_once dirname(__DIR__) . '/modules/sp_connection/logic/spConnection.php';

require_role('admin');
ika_require(APP_URL . '/admin/sp_contract_files.php', 3600);
$PAGE_TITLE = 'Pliki umów — dopasowanie z SharePointa';

const SPF_BATCH = 25;      // plików na jedno żądanie (każdy to kilka zapytań do Graph)

// Bez połączenia z SharePointem nie ma skąd przywracać plików — zamiast listy i przycisków panel blokady z linkiem do ustawień
if (!SpConnection::isConnected()) {
    include dirname(__DIR__) . '/includes/header.php';
    echo '<h1 class="h4 mb-3"><i class="bi bi-cloud-arrow-down text-primary" aria-hidden="true"></i> Pliki umów — dopasowanie z SharePointa</h1>';
    SpConnection::renderBlocked('Przywracanie plików umów z SharePointa', 'Bez połączenia nie można wyszukać ani pobrać brakujących plików. Linki do plików w umowach działają dalej, o ile plik jest na serwerze.');
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$ready   = SpFiles::canRestore();
$labels  = defined('CONTRACT_TYPES') ? CONTRACT_TYPES : [];
$type_f  = (string)($_GET['type'] ?? $_POST['type'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
$results = [];            // rel => wynik locate()/restore() z tego żądania
$flash   = null;

/** Brakujące odwołania pogrupowane po ścieżce (jeden plik może być wskazany z kilku miejsc). @return array<string,array> */
$load_missing = function () use ($type_f): array {
    $by = [];
    foreach (SpFiles::missing() as $r) {
        if ($type_f !== '' && $r['type'] !== $type_f && $r['kind'] !== $type_f) continue;
        $by[$r['rel']]['refs'][] = $r;
    }
    ksort($by);
    return $by;
};
$missing = $load_missing();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['_action'] ?? '');
    // tylko ścieżki, które faktycznie są na liście brakujących (nie przyjmujemy dowolnych z formularza)
    $rels = array_values(array_intersect(array_map('strval', (array)($_POST['rels'] ?? [])), array_keys($missing)));
    $rels = array_slice($rels, 0, SPF_BATCH);
    if (!$ready) {
        $flash = ['danger', 'Integracja SharePoint jest wyłączona lub niekompletna — włącz ją w Administracja → SharePoint.'];
    } elseif (!$rels) {
        $flash = ['warning', 'Nie zaznaczono żadnego pliku.'];
    } elseif ($action === 'search') {
        foreach ($rels as $rel) $results[$rel] = SpFiles::locate($rel);
        $flash = ['info', 'Sprawdzono ' . count($rels) . ' plików na SharePoincie (nic nie zapisano).'];
    } elseif ($action === 'restore') {
        $pick = (array)($_POST['pick'] ?? []);
        $ok = 0;
        foreach ($rels as $rel) {
            $results[$rel] = SpFiles::restore($rel, isset($pick[$rel]) ? (string)$pick[$rel] : null);
            if (in_array($results[$rel]['status'], ['restored', 'exists'], true)) $ok++;
        }
        $flash = [$ok === count($rels) ? 'success' : 'warning', "Przywrócono {$ok} z " . count($rels) . ' zaznaczonych plików.'];
        $missing_after = $load_missing();
        $missing = $missing_after + array_intersect_key($missing, $results);   // przywrócone zostają w tabeli z wynikiem
        ksort($missing);
    }
}

$total_refs   = count(SpFiles::references());
$all_missing  = SpFiles::missing();
$by_type      = [];
foreach ($all_missing as $r) {
    $k = in_array($r['kind'], ['pismo', 'wniosek'], true) ? $r['kind'] : $r['type'];       // umowy wg rodzaju, pisma i wnioski osobno
    $by_type[$k] = ($by_type[$k] ?? 0) + 1;
}
$pages        = max(1, (int)ceil(count($missing) / SPF_BATCH));
$page         = min($page, $pages);
$rows         = array_slice($missing, ($page - 1) * SPF_BATCH, SPF_BATCH, true);
$fmt_size     = fn(int $b) => $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' MB' : ($b >= 1024 ? number_format($b / 1024, 0, ',', ' ') . ' KB' : $b . ' B');
$type_label   = function (array $r) use ($labels): string {
    $t = (string)$r['type'];
    return match ($r['kind']) {
        'pismo'   => 'Pismo' . ($t !== '' ? ' — ' . ($labels[$t] ?? ($t === 'crm_case' ? 'sprawa CRM' : $t)) : ''),
        'wniosek' => 'Wniosek wolontariusza',
        default   => $labels[$t] ?? $t,
    };
};
$method_label = ['path' => 'dokładna ścieżka', 'search' => 'po nazwie pliku'];

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 mb-0"><i class="bi bi-cloud-arrow-down text-primary" aria-hidden="true"></i> Pliki umów — dopasowanie z SharePointa</h1>
  <a href="sp_contract_match.php" class="btn btn-sm btn-outline-secondary ms-auto">Skany z SharePointa → umowy bez pliku</a>
  <a href="sharepoint_settings.php" class="btn btn-sm btn-outline-secondary">Ustawienia SharePoint</a>
</div>

<?php if ($flash): ?><div class="alert alert-<?= h($flash[0]) ?> py-2 small" role="status"><?= h($flash[1]) ?></div><?php endif; ?>

<?php if (!$ready): ?>
<div class="alert alert-warning small">
  <strong>SharePoint jest wyłączony lub niekompletnie skonfigurowany.</strong> Szukanie i przywracanie plików wymaga włączonej
  synchronizacji (witryna, biblioteka, konto aplikacji M365) — uzupełnij ją w <a href="sharepoint_settings.php">ustawieniach SharePoint</a>.
  Poniższa lista pokazuje mimo to, których plików umów brakuje na serwerze.
</div>
<?php endif; ?>

<p class="text-muted small" style="max-width:62rem">
  Pliki umów są wysyłane na SharePoint przy uploadzie, pod tą samą ścieżką względną (<?= h(trim(m365_setting('sp_base_folder'), '/') ?: 'korzeń biblioteki') ?>/…).
  Gdy pliku brakuje na serwerze, system szuka go po dokładnej ścieżce, a potem po nazwie pliku w całej bibliotece. Link do pliku w umowie robi to samo
  automatycznie, a tu można dopasować hurtowo i rozstrzygnąć przypadki niejednoznaczne.
</p>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body py-2">
    <div class="small text-muted">Odwołań do plików w umowach</div><div class="fs-4 fw-bold"><?= (int)$total_refs ?></div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body py-2">
    <div class="small text-muted">Brak pliku na serwerze</div><div class="fs-4 fw-bold <?= $all_missing ? 'text-danger' : 'text-success' ?>"><?= count($all_missing) ?></div>
    <?php if ($by_type): ?><div class="small text-muted"><?= h(implode(' · ', array_map(fn($t, $n) => ($labels[$t] ?? (SpFiles::KINDS[$t] ?? $t)) . ': ' . $n, array_keys($by_type), $by_type))) ?></div><?php endif; ?>
  </div></div></div>
</div>

<form method="get" class="d-flex align-items-end gap-2 mb-3 flex-wrap">
  <div>
    <label class="form-label small mb-1" for="spf-type">Rodzaj</label>
    <select class="form-select form-select-sm" id="spf-type" name="type">
      <option value="">wszystkie</option>
      <?php foreach ($labels as $slug => $lab): ?><option value="<?= h($slug) ?>"<?= $type_f === $slug ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?>
      <option value="pismo"<?= $type_f === 'pismo' ? ' selected' : '' ?>>Pisma</option>
      <option value="wniosek"<?= $type_f === 'wniosek' ? ' selected' : '' ?>>Wnioski wolontariuszy</option>
    </select>
  </div>
  <button class="btn btn-sm btn-outline-primary" type="submit">Filtruj</button>
</form>

<?php if (!$missing): ?>
  <div class="alert alert-success small">Wszystkie pliki umów (dla wybranego filtra) są na serwerze.</div>
<?php else: ?>
<form method="post" id="spf-form">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="type" value="<?= h($type_f) ?>">
  <input type="hidden" name="page" value="<?= (int)$page ?>">
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <caption class="small text-muted">Pliki umów, których brakuje na serwerze — strona <?= (int)$page ?> z <?= (int)$pages ?> (<?= count($missing) ?> plików)</caption>
      <thead class="table-light">
        <tr><th scope="col" style="width:2rem"><span class="visually-hidden">Wybierz</span></th><th scope="col">Umowa</th><th scope="col">Rodzaj pliku</th><th scope="col">Ścieżka w uploads/</th><th scope="col">Wynik dopasowania</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $rel => $g):
        $first = $g['refs'][0]; $res = $results[$rel] ?? null; $checked = $res === null || in_array($res['status'], ['found', 'ambiguous'], true); ?>
        <tr>
          <td><input type="checkbox" name="rels[]" value="<?= h($rel) ?>" id="spf-<?= h(md5($rel)) ?>"<?= $checked ? ' checked' : '' ?> aria-label="Wybierz plik <?= h(basename($rel)) ?>"></td>
          <td>
            <?= h($type_label($first)) ?>
            <?php if ($first['view'] !== ''): ?><a href="<?= h(rtrim(APP_URL, '/') . '/' . $first['view']) ?>" target="_blank" rel="noopener"><?= h($first['number'] !== '' ? $first['number'] : (['pismo' => 'pismo #', 'wniosek' => 'wniosek #'][$first['kind']] ?? '#') . $first['id']) ?></a><?php endif; ?>
            <?php if (count($g['refs']) > 1): ?><span class="text-muted small">(+<?= count($g['refs']) - 1 ?>)</span><?php endif; ?>
          </td>
          <td><?= h($first['column_label']) ?></td>
          <td class="font-monospace small"><?= h($rel) ?></td>
          <td class="small">
            <?php if ($res === null): ?><span class="text-muted">— jeszcze nie sprawdzano</span>
            <?php elseif ($res['status'] === 'restored'): ?><span class="text-success"><i class="bi bi-check-circle" aria-hidden="true"></i> Przywrócono (<?= h($fmt_size((int)($res['bytes'] ?? 0))) ?>, <?= h($method_label[$res['method']] ?? '') ?>)</span>
            <?php elseif ($res['status'] === 'exists'): ?><span class="text-success">Plik jest już na serwerze</span>
            <?php elseif ($res['status'] === 'found'): ?>
              <span class="text-success"><i class="bi bi-search" aria-hidden="true"></i> Znaleziono (<?= h($method_label[$res['method']] ?? '') ?>):</span>
              <?= h($res['item']['path']) ?> <span class="text-muted">· <?= h($fmt_size($res['item']['size'])) ?></span>
            <?php elseif ($res['status'] === 'ambiguous'): ?>
              <span class="text-warning-emphasis"><?= h($res['message']) ?> Wskaż właściwy:</span>
              <?php foreach ($res['candidates'] as $i => $c): ?>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="pick[<?= h($rel) ?>]" value="<?= h($c['id']) ?>" id="pick-<?= h(md5($rel)) ?>-<?= $i ?>">
                  <label class="form-check-label" for="pick-<?= h(md5($rel)) ?>-<?= $i ?>"><?= h($c['path']) ?> <span class="text-muted">· <?= h($fmt_size($c['size'])) ?></span></label>
                </div>
              <?php endforeach; ?>
            <?php elseif ($res['status'] === 'missing'): ?><span class="text-danger"><i class="bi bi-x-circle" aria-hidden="true"></i> Nie znaleziono na SharePoincie</span>
            <?php else: ?><span class="text-danger"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= h($res['message'] ?: 'Błąd') ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <button type="submit" name="_action" value="search" class="btn btn-sm btn-primary"<?= $ready ? '' : ' disabled' ?>><i class="bi bi-search" aria-hidden="true"></i> Szukaj na SharePoint (zaznaczone)</button>
    <button type="submit" name="_action" value="restore" class="btn btn-sm btn-success"<?= $ready ? '' : ' disabled' ?>
            onclick="return confirm('Pobrać zaznaczone pliki z SharePointa do uploads/? Istniejące pliki nie zostaną nadpisane.');"><i class="bi bi-cloud-arrow-down" aria-hidden="true"></i> Przywróć zaznaczone</button>
    <span class="small text-muted">Do <?= SPF_BATCH ?> plików na raz. Przy kilku trafieniach wskaż właściwe i przywróć.</span>
  </div>
</form>

<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Strony listy">
  <ul class="pagination pagination-sm">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&amp;type=<?= h(urlencode($type_f)) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>
<?php endif; ?>

<p class="small text-muted mt-4 mb-0">
  Hurtowo z konsoli: <code>php cli/sp_restore_files.php</code> (podgląd) i <code>php cli/sp_restore_files.php --apply</code> (przywracanie).
  Każde przywrócenie zapisuje się w audycie (<code>sp_files.restored</code>).
</p>
<p class="small text-muted mb-0">
  Kierunek przeciwny — pliki, które są na serwerze, a nie mają kopii na SharePoincie (np. pisma sprzed synchronizacji pism):
  <code>php cli/sp_push_files.php</code> (podgląd) i <code>php cli/sp_push_files.php --apply</code> (wysyłka; opcje <code>--kind=pismo</code>, <code>--since=7</code>).
  Nowo wgrane pliki umów i pism wysyłają się na SharePoint automatycznie.
</p>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
