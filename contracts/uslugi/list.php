<?php
/**
 * contracts/uslugi/list.php — Rejestr umów o świadczenie usług.
 *
 * Przebudowany na wzorzec AJAX (jak CRM):
 *   GET  ?_ajax=1                          → JSON { ok, total, list_html }
 *   POST + X-Requested-With: XMLHttpRequest → JSON { ok }  (szybkie akcje: status / usuń)
 * Pełna strona renderuje ten sam fragment przez _uslugi_table_html(), więc działa
 * też bez JS. Nad listą karty statystyk, kolumna wskaźnika dokumentów,
 * filtr stanu rozliczenia.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_uslugi', 'Ten typ umowy');
$PAGE_TITLE = 'Umowy o świadczenie usług';
$TYPE  = 'uslugi';
$TABLE = 'umowy_uslugi';

$can_edit_uslugi = can_edit();

// ── Filtry ──────────────────────────────────────────────────────────────────
$f = [
    'q'          => trim($_GET['q']          ?? ''),
    'status'     => $_GET['status']          ?? '',
    'rozl'       => $_GET['rozl']            ?? '',
    'opiekun'    => trim($_GET['opiekun']    ?? ''),
    'projekt'    => trim($_GET['projekt']    ?? ''),
    'forma'      => $_GET['forma']           ?? '',
    'waluta'     => $_GET['waluta']          ?? '',
    'data_od'    => $_GET['data_od']         ?? '',
    'data_do'    => $_GET['data_do']         ?? '',
    'koniec_od'  => $_GET['koniec_od']       ?? '',
    'koniec_do'  => $_GET['koniec_do']       ?? '',
    'wartosc_od' => $_GET['wartosc_od']      ?? '',
    'wartosc_do' => $_GET['wartosc_do']      ?? '',
    'bezterm'    => !empty($_GET['bezterm']) ? '1' : '',
    'sort'       => $_GET['sort']            ?? '',
    'dir'        => ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc',
];

$_sorts   = ['created_at' => 'created_at', 'data_zawarcia' => 'data_zawarcia',
             'data_zakonczenia' => 'data_zakonczenia', 'nazwa_wykonawcy' => 'nazwa_wykonawcy',
             'wartosc_brutto' => 'wartosc_brutto'];
$sort_col = $_sorts[$f['sort']] ?? 'created_at';
$sort_dir = $f['dir'] === 'asc' ? 'ASC' : 'DESC';

$page = max(1, intval($_GET['page'] ?? 1));
$per  = 20;

$statuses = ['projekt' => 'Projekt', 'do podpisu' => 'Do podpisu', 'podpisana' => 'Podpisana',
             'w realizacji' => 'W realizacji', 'zawieszona' => 'Zawieszona', 'do rozliczenia' => 'Do rozliczenia',
             'zakończona' => 'Zakończona', 'rozwiązana' => 'Rozwiązana', 'anulowana' => 'Anulowana'];
$formy    = ['papierowa' => 'Papierowa', 'elektroniczna' => 'Elektroniczna',
             'epodpis_kwalifikowany' => 'ePodpis kwalifikowany', 'kwalifikowany' => 'Kwalifikowany e-podpis'];
$rozl_lab = ['nierozliczone' => 'Nierozliczone', 'częściowo' => 'Częściowo rozliczone', 'rozliczone' => 'Rozliczone'];

// ── POST — szybkie akcje (status / usuń) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $action = $_POST['_action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $reply = function (bool $ok, array $extra = []) use ($xhr, $f) {
        if ($xhr) { header('Content-Type: application/json'); echo json_encode(['ok' => $ok] + $extra); exit; }
        header('Location: ' . APP_URL . '/contracts/uslugi/list.php?' . http_build_query(array_filter($f)));
        exit;
    };

    $row = $id ? db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]) : null;
    $owns = $row && (is_admin() || (int)($row['created_by'] ?? 0) === (int)current_user()['id']);

    if ($action === 'quick_status' && $row && $can_edit_uslugi) {
        $new = trim($_POST['new_status'] ?? '');
        $allowed = is_admin()
            ? array_keys($statuses)
            : status_allowed_next($row['status'] ?? '', false);
        if ($new !== '' && ($new === ($row['status'] ?? '') || in_array($new, $allowed, true)) && isset($statuses[$new])) {
            db_update($TABLE, ['status' => $new, 'updated_at' => date('Y-m-d H:i:s')], $id);
            require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
            log_contract_action($TYPE, $id, current_user()['id'], 'status', 'Status → ' . $new);
            $reply(true, ['new_status' => $new]);
        }
        $reply(false);
    }

    if ($action === 'delete' && $row && $owns) {
        // Spójnie z resztą systemu — przez wspólny endpoint logiki kasowania.
        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        db()->prepare("DELETE FROM {$TABLE} WHERE id = ?")->execute([$id]);
        log_contract_action($TYPE, $id, current_user()['id'], 'delete', 'Usunięto umowę');
        $reply(true);
    }

    $reply(false);
}

// ── WHERE ─────────────────────────────────────────────────────────────────────
$where  = '1=1';
$params = [];
if ($f['q']) {
    $where .= " AND (numer_umowy LIKE ? OR nazwa_wykonawcy LIKE ? OR nr_rejestru LIKE ?"
            . " OR nip_pesel LIKE ? OR email LIKE ? OR telefon LIKE ? OR przedmiot_uslugi LIKE ?"
            . " OR zakres_uslug LIKE ? OR numer_projektu LIKE ? OR opiekun LIKE ? OR uwagi LIKE ?)";
    $params = array_merge($params, array_fill(0, 11, "%{$f['q']}%"));
}
if ($f['status'])  { $where .= " AND status = ?";              $params[] = $f['status']; }
if ($f['rozl'])    { $where .= " AND status_rozliczenia = ?";  $params[] = $f['rozl']; }
if ($f['opiekun']) { $where .= " AND opiekun LIKE ?";          $params[] = "%{$f['opiekun']}%"; }
if ($f['projekt']) { $where .= " AND numer_projektu LIKE ?";   $params[] = "%{$f['projekt']}%"; }
if ($f['forma'])   { $where .= " AND forma_podpisania = ?";    $params[] = $f['forma']; }
if ($f['waluta'])  { $where .= " AND waluta = ?";              $params[] = $f['waluta']; }
if ($f['data_od']) { $where .= " AND data_zawarcia >= ?";      $params[] = $f['data_od']; }
if ($f['data_do']) { $where .= " AND data_zawarcia <= ?";      $params[] = $f['data_do']; }
if ($f['koniec_od']) { $where .= " AND data_zakonczenia >= ?"; $params[] = $f['koniec_od']; }
if ($f['koniec_do']) { $where .= " AND data_zakonczenia <= ?"; $params[] = $f['koniec_do']; }
if ($f['wartosc_od'] !== '') { $where .= " AND CAST(wartosc_brutto AS REAL) >= ?"; $params[] = (float)$f['wartosc_od']; }
if ($f['wartosc_do'] !== '') { $where .= " AND CAST(wartosc_brutto AS REAL) <= ?"; $params[] = (float)$f['wartosc_do']; }
if ($f['bezterm']) { $where .= " AND czas_nieokreslony = 1"; }

$adv_count = (int)!!$f['rozl'] + (int)!!$f['opiekun'] + (int)!!$f['projekt'] + (int)!!$f['forma']
           + (int)!!$f['waluta'] + (int)!!$f['data_od'] + (int)!!$f['data_do']
           + (int)!!$f['koniec_od'] + (int)!!$f['koniec_do']
           + (int)($f['wartosc_od'] !== '') + (int)($f['wartosc_do'] !== '') + (int)!!$f['bezterm'];
$adv_open  = $adv_count > 0 || !empty($_GET['adv']);

$total = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);

// Bazowy querystring do paginacji/chipów (tylko aktywne wartości)
$qs_base = array_filter([
    'q' => $f['q'], 'status' => $f['status'], 'rozl' => $f['rozl'],
    'opiekun' => $f['opiekun'], 'projekt' => $f['projekt'], 'forma' => $f['forma'], 'waluta' => $f['waluta'],
    'data_od' => $f['data_od'], 'data_do' => $f['data_do'], 'koniec_od' => $f['koniec_od'], 'koniec_do' => $f['koniec_do'],
    'wartosc_od' => $f['wartosc_od'], 'wartosc_do' => $f['wartosc_do'],
    'bezterm' => $f['bezterm'] ?: null,
    'sort' => $sort_col !== 'created_at' ? $sort_col : null,
    'dir'  => $sort_dir !== 'DESC' ? 'asc' : null,
], fn($v) => $v !== '' && $v !== null);

$pag  = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query($qs_base));
$rows = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY {$sort_col} {$sort_dir} LIMIT {$per} OFFSET {$pag['offset']}", $params);

// ── Statystyki (globalne, jak w CRM) ──────────────────────────────────────────
$stats = [
    'total'  => (int)(db_one("SELECT COUNT(*) c FROM {$TABLE}")['c'] ?? 0),
    'realiz' => (int)(db_one("SELECT COUNT(*) c FROM {$TABLE} WHERE status = 'w realizacji'")['c'] ?? 0),
    'rozl'   => (int)(db_one("SELECT COUNT(*) c FROM {$TABLE} WHERE status = 'do rozliczenia'")['c'] ?? 0),
    'suma'   => (float)(db_one("SELECT COALESCE(SUM(CAST(wartosc_brutto AS REAL)),0) s FROM {$TABLE}
                                WHERE (waluta IS NULL OR waluta = '' OR waluta = 'PLN')
                                  AND status NOT IN ('anulowana','rozwiązana')")['s'] ?? 0),
];

$waluty = db_all("SELECT DISTINCT waluta FROM {$TABLE} WHERE waluta IS NOT NULL AND waluta != '' ORDER BY waluta");

/**
 * Renderer fragmentu listy — używany przez pełną stronę I przez AJAX (?_ajax=1).
 * Zwraca chipy aktywnych filtrów + kartę z tabelą + paginacją.
 */
function _uslugi_table_html(array $rows, int $total, array $pag, int $per, array $f,
                            array $statuses, array $formy, array $rozl_lab,
                            bool $can_edit, string $sort_col, string $sort_dir): string {
    $TYPE = 'uslugi';
    $base = APP_URL . "/contracts/{$TYPE}/list.php";
    $qs = array_filter([
        'q' => $f['q'], 'status' => $f['status'], 'rozl' => $f['rozl'],
        'opiekun' => $f['opiekun'], 'projekt' => $f['projekt'], 'forma' => $f['forma'], 'waluta' => $f['waluta'],
        'data_od' => $f['data_od'], 'data_do' => $f['data_do'], 'koniec_od' => $f['koniec_od'], 'koniec_do' => $f['koniec_do'],
        'wartosc_od' => $f['wartosc_od'], 'wartosc_do' => $f['wartosc_do'], 'bezterm' => $f['bezterm'] ?: null,
        'sort' => $sort_col !== 'created_at' ? $sort_col : null, 'dir' => $sort_dir !== 'DESC' ? 'asc' : null,
    ], fn($v) => $v !== '' && $v !== null);
    $chip = fn($drop) => $base . '?' . http_build_query(array_diff_key($qs, array_flip(array_merge((array)$drop, ['page']))));

    $adv_count = (int)!!$f['rozl'] + (int)!!$f['opiekun'] + (int)!!$f['projekt'] + (int)!!$f['forma']
               + (int)!!$f['waluta'] + (int)!!$f['data_od'] + (int)!!$f['data_do']
               + (int)!!$f['koniec_od'] + (int)!!$f['koniec_do']
               + (int)($f['wartosc_od'] !== '') + (int)($f['wartosc_do'] !== '') + (int)!!$f['bezterm'];
    $filtering = $f['q'] !== '' || $f['status'] !== '' || $adv_count > 0;

    ob_start();
    ?>
    <?php if ($adv_count): ?>
    <div class="active-chips mb-2">
      <?php if ($f['rozl']): ?><a href="<?= h($chip('rozl')) ?>" class="active-chip">Rozliczenie: <?= h($rozl_lab[$f['rozl']] ?? $f['rozl']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['opiekun']): ?><a href="<?= h($chip('opiekun')) ?>" class="active-chip">Opiekun: <?= h($f['opiekun']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['projekt']): ?><a href="<?= h($chip('projekt')) ?>" class="active-chip">Projekt: <?= h($f['projekt']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['waluta']): ?><a href="<?= h($chip('waluta')) ?>" class="active-chip">Waluta: <?= h($f['waluta']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['forma']): ?><a href="<?= h($chip('forma')) ?>" class="active-chip">Forma: <?= h($formy[$f['forma']] ?? $f['forma']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['bezterm']): ?><a href="<?= h($chip('bezterm')) ?>" class="active-chip">Bezterminowe <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['data_od'] || $f['data_do']): ?><a href="<?= h($chip(['data_od','data_do'])) ?>" class="active-chip">Zawarcie: <?= h($f['data_od'] ?: '…') ?> – <?= h($f['data_do'] ?: '…') ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['koniec_od'] || $f['koniec_do']): ?><a href="<?= h($chip(['koniec_od','koniec_do'])) ?>" class="active-chip">Zakończenie: <?= h($f['koniec_od'] ?: '…') ?> – <?= h($f['koniec_do'] ?: '…') ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['wartosc_od'] !== '' || $f['wartosc_do'] !== ''): ?><a href="<?= h($chip(['wartosc_od','wartosc_do'])) ?>" class="active-chip">Wartość: <?= $f['wartosc_od'] !== '' ? number_format((float)$f['wartosc_od'],0,',',' ') : '0' ?> – <?= $f['wartosc_do'] !== '' ? number_format((float)$f['wartosc_do'],0,',',' ') : '∞' ?> <span class="chip-x">×</span></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover contracts-table mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:2%" class="ps-3"><input type="checkbox" id="cb-all" class="form-check-input" title="Zaznacz wszystkie"></th>
              <th>Numer</th>
              <th class="d-none d-md-table-cell">Nr rejestru</th>
              <th>Wykonawca</th>
              <th class="d-none d-lg-table-cell">Przedmiot usługi</th>
              <th class="d-none d-sm-table-cell">Data zawarcia</th>
              <th class="d-none d-sm-table-cell">Do</th>
              <th class="d-none d-xl-table-cell text-end">Brutto</th>
              <th class="text-center" title="Dokumenty: umowa · protokół · faktura">Dok.</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $vurl = APP_URL . "/contracts/{$TYPE}/view.php?id=" . (int)$r['id'];
            ?>
            <tr data-row-href="<?= h($vurl) ?>" style="cursor:pointer">
              <td class="ps-3"><input type="checkbox" class="cb-row form-check-input" value="<?= (int)$r['id'] ?>" aria-label="Zaznacz"></td>
              <td class="fw-semibold"><?= h($r['numer_umowy']) ?></td>
              <td class="font-monospace small d-none d-md-table-cell"><?= h($r['nr_rejestru'] ?? '') ?: '—' ?></td>
              <td>
                <?= h($r['nazwa_wykonawcy'] ?: ($r['imie_nazwisko'] ?? '')) ?: '<span class="text-muted">—</span>' ?>
                <?php if ($r['email'] ?? ''): ?><div class="text-muted" style="font-size:.75rem"><?= h($r['email']) ?></div><?php endif; ?>
              </td>
              <td class="d-none d-lg-table-cell" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem"><?= h($r['przedmiot_uslugi']) ?></td>
              <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem"><?= date_pl($r['data_zawarcia']) ?></td>
              <td class="d-none d-sm-table-cell" style="white-space:nowrap;font-size:.82rem"><?= $r['czas_nieokreslony'] ? '<span class="text-muted fst-italic small">bezterminowo</span>' : date_pl($r['data_zakonczenia']) ?></td>
              <td class="d-none d-xl-table-cell text-end" style="white-space:nowrap"><?= money($r['wartosc_brutto'], $r['waluta'] ?: 'PLN') ?></td>
              <td class="text-center" style="white-space:nowrap"><?= _uslugi_doc_indicators($r) ?></td>
              <td>
                <?php if ($can_edit): ?>
                <div class="dropdown d-inline-block">
                  <a href="#" class="text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false" onclick="event.stopPropagation()" title="Zmień status">
                    <?= status_badge($r['status']) ?> <i class="bi bi-caret-down-fill" style="font-size:.6rem;opacity:.5"></i>
                  </a>
                  <ul class="dropdown-menu shadow-sm" style="font-size:.85rem">
                    <?php
                      $cur = $r['status'] ?? '';
                      $next = is_admin()
                          ? array_keys($statuses)
                          : array_values(array_unique(array_merge([$cur], status_allowed_next($cur, false))));
                      foreach ($next as $st):
                        if (!isset($statuses[$st])) continue;
                        $lbl = $statuses[$st];
                    ?>
                    <li><a class="dropdown-item <?= $st === $cur ? 'active' : '' ?>" href="#"
                           data-quick-status="<?= h($st) ?>" data-id="<?= (int)$r['id'] ?>"
                           onclick="event.stopPropagation()"><?= h($lbl) ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <?php else: ?>
                <?= status_badge($r['status']) ?>
                <?php endif; ?>
              </td>
              <td class="text-end" style="white-space:nowrap">
                <a href="<?= h($vurl) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="event.stopPropagation()"><i class="bi bi-eye"></i></a>
                <?php if ($can_edit): ?>
                <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="event.stopPropagation()"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <?php if (is_admin() || ($can_edit && (int)($r['created_by'] ?? 0) === (int)current_user()['id'])): ?>
                <form method="post" class="d-inline" data-ajax-action="delete"
                      data-contract-name="<?= h($r['numer_umowy'] ?? ('#'.$r['id'])) ?>" onclick="event.stopPropagation()">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
            <tr><td colspan="11" class="text-center text-muted py-4">
              <i class="bi bi-briefcase display-6 d-block mb-2 opacity-25"></i>
              Brak umów o usługi<?= $filtering ? ' spełniających kryteria' : '' ?>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
        <small class="text-muted">Znaleziono: <strong><?= $total ?></strong><?= $filtering ? ' — filtrowanie aktywne' : '' ?></small>
        <?php if ($pag['pages'] > 1): ?><?= pagination_html($pag) ?><?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Mini-wskaźniki dokumentów (umowa / protokół / faktura) jako ikony z tooltipem. */
function _uslugi_doc_indicators(array $r): string {
    $ic = [];
    // Plik umowy
    $ic[] = ($r['plik_umowy'] ?? '')
        ? '<i class="bi bi-file-earmark-check-fill text-success" title="Plik umowy dołączony"></i>'
        : '<i class="bi bi-file-earmark text-muted opacity-50" title="Brak pliku umowy"></i>';
    // Protokół odbioru
    if (!empty($r['wymagany_protokol'])) {
        $ic[] = ($r['data_odbioru'] ?? '')
            ? '<i class="bi bi-clipboard-check-fill text-success" title="Protokół odbioru: ' . h(date_pl($r['data_odbioru'])) . '"></i>'
            : '<i class="bi bi-clipboard-x text-warning" title="Wymagany protokół — brak odbioru"></i>';
    }
    // Faktura
    if (!empty($r['wymagana_faktura'])) {
        $rl = $r['status_rozliczenia'] ?? 'nierozliczone';
        $cls = $rl === 'rozliczone' ? 'text-success' : ($rl === 'częściowo' ? 'text-warning' : 'text-muted opacity-50');
        $ic[] = '<i class="bi bi-receipt ' . $cls . '" title="Wymagana faktura — rozliczenie: ' . h($rl) . '"></i>';
    }
    return '<span class="d-inline-flex gap-1" style="font-size:.95rem">' . implode('', $ic) . '</span>';
}

// ── AJAX fragment ─────────────────────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'        => true,
        'total'     => $total,
        'list_html' => _uslugi_table_html($rows, $total, $pag, $per, $f, $statuses, $formy, $rozl_lab, $can_edit_uslugi, $sort_col, $sort_dir),
    ]);
    exit;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/adv_filter.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-briefcase text-primary"></i> Umowy o świadczenie usług
    <span class="badge bg-secondary ms-1" id="uslugi-total-badge"><?= $total ?></span>
  </h4>
  <?php if ($can_edit_uslugi): ?>
  <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/add.php" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Nowa umowa
  </a>
  <?php endif; ?>
</div>

<!-- ══ STATYSTYKI ═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body py-2">
      <div class="h4 mb-0"><?= number_format($stats['total'], 0, ',', ' ') ?></div>
      <div class="text-muted small"><i class="bi bi-briefcase me-1 text-primary"></i>Wszystkich umów</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body py-2">
      <div class="h4 mb-0"><?= number_format($stats['realiz'], 0, ',', ' ') ?></div>
      <div class="text-muted small"><i class="bi bi-gear me-1" style="color:#0dcaf0"></i>W realizacji</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body py-2">
      <div class="h4 mb-0"><?= number_format($stats['rozl'], 0, ',', ' ') ?></div>
      <div class="text-muted small"><i class="bi bi-cash-coin me-1" style="color:#6366f1"></i>Do rozliczenia</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body py-2">
      <div class="h4 mb-0" style="font-size:1.25rem"><?= number_format($stats['suma'], 0, ',', ' ') ?> <span class="small text-muted">PLN</span></div>
      <div class="text-muted small"><i class="bi bi-coin me-1 text-success"></i>Suma brutto (aktywne)</div>
    </div></div>
  </div>
</div>

<!-- ══ FILTRY ═══════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3">
  <div class="card-body p-2">
    <form method="get" id="uslugi-filter-form">
      <div class="row g-2 align-items-center">
        <div class="col">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input name="q" class="form-control" placeholder="Numer, wykonawca, NIP/PESEL, e-mail, telefon, przedmiot, projekt…"
                   value="<?= h($f['q']) ?>" autocomplete="off">
          </div>
        </div>
        <div class="col-auto">
          <select name="status" class="form-select form-select-sm">
            <option value="">— status —</option>
            <?php foreach ($statuses as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <select name="rozl" class="form-select form-select-sm">
            <option value="">— rozliczenie —</option>
            <?php foreach ($rozl_lab as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $f['rozl'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Szukaj</button>
        </div>
        <div class="col-auto">
          <a class="adv-toggle <?= $adv_open ? 'is-active' : '' ?>" id="uslugi-adv-toggle"
             data-bs-toggle="collapse" href="#advPanel" role="button"
             aria-expanded="<?= $adv_open ? 'true' : 'false' ?>" aria-controls="advPanel">
            <i class="bi bi-sliders"></i> Zaawansowane
            <?php if ($adv_count): ?><span class="badge rounded-pill bg-primary ms-1" style="font-size:.7rem"><?= $adv_count ?></span><?php endif; ?>
          </a>
        </div>
        <?php if ($f['q'] || $f['status'] || $adv_count): ?>
        <div class="col-auto">
          <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i> Wyczyść</a>
        </div>
        <?php endif; ?>
      </div>

      <div class="collapse <?= $adv_open ? 'show' : '' ?>" id="advPanel">
        <div class="adv-panel mt-2">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="adv-label">Opiekun</label>
              <input name="opiekun" class="form-control" placeholder="Nazwisko…" value="<?= h($f['opiekun']) ?>">
            </div>
            <div class="col-md-3">
              <label class="adv-label">Nr projektu</label>
              <input name="projekt" class="form-control" placeholder="Projekt, program…" value="<?= h($f['projekt']) ?>">
            </div>
            <div class="col-md-2">
              <label class="adv-label">Waluta</label>
              <select name="waluta" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($waluty as $w): ?>
                <option value="<?= h($w['waluta']) ?>" <?= $f['waluta'] === $w['waluta'] ? 'selected' : '' ?>><?= h($w['waluta']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="adv-label">Forma podpisania</label>
              <select name="forma" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($formy as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $f['forma'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2 d-flex align-items-end pb-1">
              <div class="form-check mb-0">
                <input type="checkbox" name="bezterm" value="1" id="f_bezterm" class="form-check-input" <?= $f['bezterm'] ? 'checked' : '' ?>>
                <label for="f_bezterm" class="form-check-label" style="font-size:.82rem;cursor:pointer">Bezterminowe</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Data zawarcia</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="data_od" class="form-control" value="<?= h($f['data_od']) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="data_do" class="form-control" value="<?= h($f['data_do']) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Data zakończenia</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="koniec_od" class="form-control" value="<?= h($f['koniec_od']) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="koniec_do" class="form-control" value="<?= h($f['koniec_do']) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Wartość brutto</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="number" name="wartosc_od" class="form-control" placeholder="0" min="0" step="100" value="<?= h($f['wartosc_od']) ?>">
                <span class="input-group-text">do</span>
                <input type="number" name="wartosc_do" class="form-control" placeholder="∞" min="0" step="100" value="<?= h($f['wartosc_do']) ?>">
              </div>
            </div>
            <div class="col-md-3">
              <label class="adv-label">Sortuj według</label>
              <div class="input-group input-group-sm">
                <select name="sort" class="form-select">
                  <option value="created_at"      <?= $sort_col==='created_at'?'selected':'' ?>>Data dodania</option>
                  <option value="data_zawarcia"    <?= $sort_col==='data_zawarcia'?'selected':'' ?>>Data zawarcia</option>
                  <option value="data_zakonczenia" <?= $sort_col==='data_zakonczenia'?'selected':'' ?>>Data zakończenia</option>
                  <option value="nazwa_wykonawcy"  <?= $sort_col==='nazwa_wykonawcy'?'selected':'' ?>>Wykonawca</option>
                  <option value="wartosc_brutto"   <?= $sort_col==='wartosc_brutto'?'selected':'' ?>>Wartość</option>
                </select>
                <select name="dir" class="form-select" style="max-width:5rem">
                  <option value="desc" <?= $sort_dir==='DESC'?'selected':'' ?>>↓</option>
                  <option value="asc"  <?= $sort_dir==='ASC'?'selected':'' ?>>↑</option>
                </select>
              </div>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="uslugi-adv-clear"><i class="bi bi-eraser"></i> Wyczyść zaawansowane</button>
              <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Zastosuj filtry</button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div id="uslugi-list-region" aria-busy="false">
  <?= _uslugi_table_html($rows, $total, $pag, $per, $f, $statuses, $formy, $rozl_lab, $can_edit_uslugi, $sort_col, $sort_dir) ?>
</div>

<div id="uslugi-live" class="visually-hidden" aria-live="polite"></div>

<?php include dirname(__DIR__) . '/includes/bulk_bar.php'; ?>

<script>
/* ── AJAX: filtry, paginacja, szybkie akcje (status / usuń) ───────────────── */
(function () {
  'use strict';
  var region  = document.getElementById('uslugi-list-region');
  var form    = document.getElementById('uslugi-filter-form');
  var badgeEl = document.getElementById('uslugi-total-badge');
  var liveEl  = document.getElementById('uslugi-live');
  if (!region || !form) return;

  var abort = null, debounce = null;

  function formParams() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (v) p.set(k, v); });
    return p;
  }

  function syncForm(params) {
    form.querySelectorAll('select, input[type="text"], input[type="date"], input[type="number"], input[type="search"]').forEach(function (el) {
      if (el.name) el.value = params.get(el.name) || '';
    });
    form.querySelectorAll('input[type="checkbox"]').forEach(function (el) {
      if (el.name) el.checked = params.get(el.name) === el.value;
    });
  }

  function load(params, push) {
    if (abort) { try { abort.abort(); } catch (e) {} }
    abort = (typeof AbortController !== 'undefined') ? new AbortController() : null;

    region.setAttribute('aria-busy', 'true');
    region.style.opacity = '0.5';
    region.style.transition = 'opacity .15s';
    region.style.pointerEvents = 'none';

    var url = new URL(window.location.pathname, window.location.origin);
    params.forEach(function (v, k) { if (v) url.searchParams.set(k, v); });
    url.searchParams.set('_ajax', '1');

    fetch(url.toString(), abort ? { signal: abort.signal } : {})
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        region.innerHTML = data.list_html;
        if (badgeEl && data.total !== undefined) badgeEl.textContent = data.total;
        if (push !== false) {
          var histUrl = new URL(window.location.href);
          histUrl.search = params.toString();
          history.pushState({ uslugi: params.toString() }, '', histUrl.toString());
        }
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
        bindRegion();
        if (window.bulkClear) window.bulkClear();
        if (liveEl) {
          liveEl.textContent = '';
          setTimeout(function () { liveEl.textContent = 'Załadowano ' + (data.total || 0) + ' umów.'; }, 50);
        }
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
      });
  }

  function postAction(fd) {
    return fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    }).then(function (r) { return r.json(); });
  }

  function bindRegion() {
    // Paginacja
    region.querySelectorAll('a.page-link').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var href = a.getAttribute('href');
        if (!href) return;
        load(new URLSearchParams(new URL(href, window.location.href).search), true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
    // Chipy aktywnych filtrów
    region.querySelectorAll('.active-chip').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var p = new URLSearchParams(new URL(a.getAttribute('href'), window.location.href).search);
        syncForm(p);
        load(p, true);
      });
    });
    // Szybka zmiana statusu
    region.querySelectorAll('a[data-quick-status]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('_action', 'quick_status');
        fd.append('id', a.dataset.id);
        fd.append('new_status', a.dataset.quickStatus);
        postAction(fd).then(function (d) { if (d.ok) load(formParams(), false); }).catch(function () {});
      });
    });
    // Usunięcie umowy
    region.querySelectorAll('form[data-ajax-action="delete"]').forEach(function (frm) {
      frm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!confirm('Usunąć umowę ' + (frm.dataset.contractName || '') + '?')) return;
        postAction(new FormData(frm)).then(function (d) { if (d.ok) load(formParams(), false); }).catch(function () {});
      });
    });
    // Klik w wiersz → karta umowy
    region.querySelectorAll('tr[data-row-href]').forEach(function (tr) {
      tr.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,label,.dropdown')) return;
        window.location.href = tr.dataset.rowHref;
      });
    });
  }

  var CSRF = <?= json_encode(csrf_token()) ?>;

  form.addEventListener('submit', function (e) { e.preventDefault(); load(formParams(), true); });
  form.querySelectorAll('select').forEach(function (sel) {
    sel.addEventListener('change', function () { load(formParams(), true); });
  });
  var searchInp = form.querySelector('input[name="q"]');
  if (searchInp) {
    searchInp.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () { load(formParams(), true); }, 380);
    });
    searchInp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); clearTimeout(debounce); load(formParams(), true); }
    });
  }

  // Panel zaawansowany — toggle (collapse Bootstrap) + auto-load + wyczyść
  var advPanel = document.getElementById('advPanel');
  if (advPanel) {
    advPanel.querySelectorAll('input[type="date"], input[type="number"], input[type="checkbox"]').forEach(function (el) {
      el.addEventListener('change', function () { load(formParams(), true); });
    });
    advPanel.querySelectorAll('input[type="text"]').forEach(function (el) {
      el.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { load(formParams(), true); }, 380);
      });
    });
    var advClear = document.getElementById('uslugi-adv-clear');
    if (advClear) advClear.addEventListener('click', function () {
      advPanel.querySelectorAll('input, select').forEach(function (el) {
        if (el.type === 'checkbox') el.checked = false;
        else if (el.name === 'sort') el.value = 'created_at';
        else if (el.name === 'dir') el.value = 'desc';
        else el.value = '';
      });
      load(formParams(), true);
    });
  }

  window.addEventListener('popstate', function (e) {
    var p = (e.state && e.state.uslugi !== undefined)
      ? new URLSearchParams(e.state.uslugi)
      : new URLSearchParams(window.location.search);
    syncForm(p);
    load(p, false);
  });

  bindRegion();
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
