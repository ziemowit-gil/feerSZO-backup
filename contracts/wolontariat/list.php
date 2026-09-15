<?php
/**
 * contracts/wolontariat/list.php — Lista porozumień wolontariackich.
 * Przebudowana od podstaw: statystyki, filtry, nowoczesny układ.
 * AJAX: GET ?_ajax=1 → JSON { ok, total, list_html }
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/dyspozycyjnosc.php';
require_once dirname(dirname(__DIR__)) . '/includes/rpts.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/includes/wolontariat_schema.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_correction_schema.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_correction.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_wolontariat', 'Ten typ umowy');

$_urlop_pending = urlop_pending_count();

$PAGE_TITLE = 'Porozumienia wolontariackie';
$TYPE  = 'wolontariat';
$TABLE = 'umowy_wolontariat';

$can_edit_w = can_edit();

// ── Bezpieczeństwo: per-opiekun visibility ────────────────────────────────────
$_cur_user    = current_user();
$_is_my_only  = !is_admin() && !can_delete('wolontariat')
                && org_setting('wolontariat_opiekun_only') === '1';

// ── Filtry
$f = [
    'q'      => trim($_GET['q']        ?? ''),
    'status' => $_GET['status']        ?? '',
    'view'   => in_array($_GET['view'] ?? 'table', ['table','cards']) ? ($_GET['view'] ?? 'table') : 'table',
    'opiekun'     => trim($_GET['opiekun']  ?? ''),
    'projekt'     => trim($_GET['projekt']  ?? ''),
    'woj'         => trim($_GET['woj']      ?? ''),
    'obszar'      => trim($_GET['obszar']   ?? ''),
    'typ'         => trim($_GET['typ']      ?? ''),
    'zgoda'       => !empty($_GET['zgoda'])  ? '1' : '',
    'forma'       => $_GET['forma']          ?? '',
    'data_od'     => $_GET['data_od']        ?? '',
    'data_do'     => $_GET['data_do']        ?? '',
    'koniec_od'   => $_GET['koniec_od']      ?? '',
    'koniec_do'   => $_GET['koniec_do']      ?? '',
    'nnw'         => !empty($_GET['nnw'])     ? '1' : '',
    'oc'          => !empty($_GET['oc'])      ? '1' : '',
    'niep'        => !empty($_GET['niep'])    ? '1' : '',
    'bezterm'     => !empty($_GET['bezterm']) ? '1' : '',
    'uwagi'       => !empty($_GET['uwagi'])   ? '1' : '',
    'sort'        => $_GET['sort']            ?? '',
    'dir'         => ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc',
];

$_sorts   = ['created_at'=>'created_at','data_zawarcia'=>'data_zawarcia',
             'data_zakonczenia'=>'data_zakonczenia','imie_nazwisko'=>'imie_nazwisko'];
$sort_col = $_sorts[$f['sort']] ?? 'created_at';
$sort_dir = $f['dir'] === 'asc' ? 'ASC' : 'DESC';

$page = max(1, intval($_GET['page'] ?? 1));
$per  = 20;

// ── WHERE ─────────────────────────────────────────────────────────────────────
$where  = '1=1';
$params = [];

if ($_is_my_only) {
    $my_name = trim(($_cur_user['first_name'] ?? '') . ' ' . ($_cur_user['last_name'] ?? ''));
    if (!$my_name) $my_name = $_cur_user['name'] ?? '';
    $where .= " AND opiekun LIKE ?";
    $params[] = '%' . $my_name . '%';
}

if ($f['q']) {
    $where .= " AND (numer_umowy LIKE ? OR imie_nazwisko LIKE ? OR nr_rejestru LIKE ?"
            . " OR pesel LIKE ? OR email LIKE ? OR telefon LIKE ?"
            . " OR miejsce_wolontariatu LIKE ? OR opiekun LIKE ? OR projekt_program LIKE ?"
            . " OR przedmiot_porozumienia LIKE ? OR uwagi LIKE ? OR addr_city LIKE ?)";
    $params = array_merge($params, array_fill(0, 12, "%{$f['q']}%"));
}
if ($f['status'])   { $where .= " AND status = ?";                       $params[] = $f['status']; }
if ($f['opiekun'])  { $where .= " AND opiekun LIKE ?";                   $params[] = "%{$f['opiekun']}%"; }
if ($f['projekt'])  { $where .= " AND projekt_program LIKE ?";           $params[] = "%{$f['projekt']}%"; }
if ($f['woj'])      { $where .= " AND wojewodztwo = ?";                  $params[] = $f['woj']; }
if ($f['typ'])      { $where .= " AND wolontariat_typ = ?";              $params[] = $f['typ']; }
if ($f['obszar'])   { $where .= " AND obszar_dzialania LIKE ?";          $params[] = '%"' . $f['obszar'] . '"%'; }
if ($f['zgoda'])    { $where .= " AND email_consent = 1"; }
if ($f['forma'])    { $where .= " AND forma_podpisania = ?";             $params[] = $f['forma']; }
if ($f['data_od'])  { $where .= " AND data_zawarcia >= ?";               $params[] = $f['data_od']; }
if ($f['data_do'])  { $where .= " AND data_zawarcia <= ?";               $params[] = $f['data_do']; }
if ($f['koniec_od']){ $where .= " AND data_zakonczenia >= ?";            $params[] = $f['koniec_od']; }
if ($f['koniec_do']){ $where .= " AND data_zakonczenia <= ?";            $params[] = $f['koniec_do']; }
if ($f['nnw'])      { $where .= " AND ubezpieczenie_nnw = 1"; }
if ($f['oc'])       { $where .= " AND ubezpieczenie_oc = 1"; }
if ($f['niep'])     { $where .= " AND niepelnoletni = 1"; }
if ($f['bezterm'])  { $where .= " AND bezterminowa = 1"; }
if ($f['uwagi'])    { $where .= " AND needs_correction = 1"; }
$where .= ' AND ' . contract_access_where($TYPE);

$adv_count = (int)!!$f['forma'] + (int)!!$f['data_od'] + (int)!!$f['data_do']
           + (int)!!$f['koniec_od'] + (int)!!$f['koniec_do']
           + (int)!!$f['nnw'] + (int)!!$f['oc'] + (int)!!$f['niep'] + (int)!!$f['bezterm']
           + (int)!!$f['uwagi'];
$adv_open  = $adv_count > 0 || !empty($_GET['adv']);

$qs_base = array_filter([
    'q'=>$f['q'], 'status'=>$f['status'], 'opiekun'=>$f['opiekun'], 'projekt'=>$f['projekt'],
    'woj'=>$f['woj'], 'obszar'=>$f['obszar'], 'typ'=>$f['typ'],
    'zgoda'=>$f['zgoda'] ?: null,
    'forma'=>$f['forma'], 'data_od'=>$f['data_od'], 'data_do'=>$f['data_do'],
    'koniec_od'=>$f['koniec_od'], 'koniec_do'=>$f['koniec_do'],
    'nnw'=>$f['nnw'] ?: null, 'oc'=>$f['oc'] ?: null,
    'niep'=>$f['niep'] ?: null, 'bezterm'=>$f['bezterm'] ?: null,
    'uwagi'=>$f['uwagi'] ?: null,
    'sort'=>$sort_col !== 'created_at' ? $sort_col : null,
    'dir' =>$sort_dir !== 'DESC' ? 'asc' : null,
    'view'=>$f['view'] !== 'table' ? $f['view'] : null,
], fn($v) => $v !== '' && $v !== null);

$total  = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);
$pag    = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query($qs_base));
$rows   = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY {$sort_col} {$sort_dir} LIMIT {$per} OFFSET {$pag['offset']}", $params);

$woj_in_db   = db_all("SELECT DISTINCT wojewodztwo FROM {$TABLE} WHERE wojewodztwo IS NOT NULL AND wojewodztwo != '' ORDER BY wojewodztwo");
$typ_in_db   = db_all("SELECT DISTINCT wolontariat_typ FROM {$TABLE} WHERE wolontariat_typ IS NOT NULL AND wolontariat_typ != '' ORDER BY wolontariat_typ");
$_typ_labels = ['stały'=>'Stały','jednorazowy'=>'Jednorazowy','projektowy'=>'Projektowy','akcyjny'=>'Akcyjny','wakacyjny'=>'Wakacyjny'];
$_obs_labels = ['społeczny'=>'Społeczny','edukacyjny'=>'Edukacyjny','zdrowotny'=>'Zdrowotny','ekologiczny'=>'Ekologiczny','kulturalny'=>'Kulturalny','sportowy'=>'Sportowy','pomocowy'=>'Pomocowy','zwierzeta'=>'Ochrona zwierząt','cyfrowy'=>'Cyfrowy / IT','inny'=>'Inny'];

$status_counts = [];
$all_statuses  = ['projekt','podpisana','w realizacji','do rozliczenia','zakończona','rozwiązana','anulowana'];
$all_total     = 0;
foreach ($all_statuses as $s) {
    $c = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE status=?", [$s])['c'] ?? 0);
    $status_counts[$s] = $c;
    $all_total += $c;
}
$active_total = ($status_counts['podpisana'] ?? 0) + ($status_counts['w realizacji'] ?? 0)
              + ($status_counts['projekt'] ?? 0) + ($status_counts['do rozliczenia'] ?? 0);

$status_cfg = [
    'projekt'        => ['label'=>'Projekt',        'color'=>'#6366F1','bg'=>'#EEF2FF','icon'=>'bi-file-earmark-text'],
    'podpisana'      => ['label'=>'Podpisana',      'color'=>'#2E844A','bg'=>'#EFF7ED','icon'=>'bi-check-circle'],
    'w realizacji'   => ['label'=>'W realizacji',   'color'=>'#0176D3','bg'=>'#EEF4FF','icon'=>'bi-play-circle'],
    'do rozliczenia' => ['label'=>'Do rozliczenia', 'color'=>'#7C3AED','bg'=>'#F5F3FF','icon'=>'bi-cash-coin'],
    'zakończona'     => ['label'=>'Zakończona',     'color'=>'#374151','bg'=>'#F3F4F6','icon'=>'bi-flag'],
    'rozwiązana'     => ['label'=>'Rozwiązana',     'color'=>'#D97706','bg'=>'#FEF3E2','icon'=>'bi-x-circle'],
    'anulowana'      => ['label'=>'Anulowana',      'color'=>'#DC2626','bg'=>'#FEF2F2','icon'=>'bi-slash-circle'],
];

$opiekunowie = db_all("SELECT DISTINCT opiekun FROM {$TABLE} WHERE opiekun IS NOT NULL AND opiekun != '' ORDER BY opiekun");

// ── Renderer fragmentu listy (status pills + wyniki) ──────────────────────────
function _wolontariat_list_html(array $ctx): string {
    extract($ctx); // $f, $status_cfg, $status_counts, $all_total, $active_total,
                   // $rows, $total, $pag, $adv_count, $_is_my_only,
                   // $qs_base, $TYPE, $can_edit_w
    $base_url = APP_URL . "/contracts/{$TYPE}/list.php";
    $qs       = $qs_base;
    $chip     = fn($drop) => $base_url . '?' . http_build_query(
        array_diff_key($qs, array_flip(array_merge((array)$drop, ['page'])))
    );

    $filtering = $f['q'] !== '' || $f['status'] !== '' || $adv_count > 0
              || $f['opiekun'] !== '' || $f['projekt'] !== ''
              || $f['woj'] !== '' || $f['typ'] !== '' || $f['obszar'] !== '' || $f['zgoda'] !== '';

    ob_start();
    ?>
    <!-- ── Pasy statusów (klikalne) ──────────────────────────────────────────── -->
    <div class="d-flex flex-wrap gap-2 mb-3">
      <a href="?<?= http_build_query(array_filter(['q'=>$f['q'],'opiekun'=>$f['opiekun'],'projekt'=>$f['projekt'],'view'=>$f['view']!=='table'?$f['view']:null])) ?>"
         class="wol-stat-pill <?= !$f['status']?'selected':'' ?>"
         style="background:#F3F4F6;color:#374151;border-color:<?= !$f['status']?'#374151':'transparent' ?>">
        <i class="bi bi-list-ul"></i> Wszystkie <strong><?= $all_total ?></strong>
      </a>
      <?php foreach ($status_cfg as $sk => $sv): ?>
      <?php if (!($status_counts[$sk] ?? 0)) continue; ?>
      <a href="?<?= http_build_query(array_filter(['q'=>$f['q'],'status'=>$sk,'opiekun'=>$f['opiekun'],'projekt'=>$f['projekt'],'view'=>$f['view']!=='table'?$f['view']:null])) ?>"
         class="wol-stat-pill <?= $f['status']===$sk?'selected':'' ?>"
         style="background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>;border-color:<?= $f['status']===$sk?$sv['color']:'transparent' ?>">
        <i class="bi <?= $sv['icon'] ?>"></i> <?= $sv['label'] ?> <strong><?= $status_counts[$sk] ?></strong>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if ($_is_my_only): ?>
    <div class="alert alert-info py-2 px-3 small mb-2">
      <i class="bi bi-shield-lock me-1"></i>
      Widzisz tylko <strong>swoje</strong> umowy wolontariatu (tryb per-opiekun).
    </div>
    <?php endif; ?>

    <!-- ── Aktywne filtry zaawansowane chips ─────────────────────────────────── -->
    <?php if ($adv_count): ?>
    <div class="active-chips mb-2">
      <?php if ($f['forma']): ?><a href="<?= h($chip('forma')) ?>" class="active-chip">Forma: <?= h(['papierowa'=>'Papierowa','elektroniczna'=>'Elektroniczna','kwalifikowany'=>'Kwalifikowany'][$f['forma']] ?? $f['forma']) ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['nnw']): ?><a href="<?= h($chip('nnw')) ?>" class="active-chip">Ubezp. NNW <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['oc']): ?><a href="<?= h($chip('oc')) ?>" class="active-chip">Ubezp. OC <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['niep']): ?><a href="<?= h($chip('niep')) ?>" class="active-chip">Niepełnoletni <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['bezterm']): ?><a href="<?= h($chip('bezterm')) ?>" class="active-chip">Bezterminowa <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['uwagi']): ?><a href="<?= h($chip('uwagi')) ?>" class="active-chip">Z uwagami <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['data_od'] || $f['data_do']): ?><a href="<?= h($chip(['data_od','data_do'])) ?>" class="active-chip">Zawarcie: <?= h($f['data_od'] ?: '…') ?> – <?= h($f['data_do'] ?: '…') ?> <span class="chip-x">×</span></a><?php endif; ?>
      <?php if ($f['koniec_od'] || $f['koniec_do']): ?><a href="<?= h($chip(['koniec_od','koniec_do'])) ?>" class="active-chip">Zakończenie: <?= h($f['koniec_od'] ?: '…') ?> – <?= h($f['koniec_do'] ?: '…') ?> <span class="chip-x">×</span></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Wyniki ─────────────────────────────────────────────────────────────── -->
    <?php if (!$rows): ?>
    <div class="tz-empty">
      <i class="bi bi-heart" aria-hidden="true"></i>
      <h5 class="mb-1" style="color:var(--tz-ink)">Brak porozumień wolontariackich</h5>
      <p class="mb-3">
        <?= $filtering ? 'Spróbuj zmienić kryteria filtrowania.' : 'Nie dodano jeszcze żadnych porozumień.' ?>
      </p>
      <?php if (!$filtering && $can_edit_w): ?>
      <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/new.php" class="tz-btn">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>Dodaj pierwsze porozumienie
      </a>
      <?php endif; ?>
    </div>

    <?php elseif ($f['view'] === 'cards'): ?>
    <!-- WIDOK KART -->
    <div class="row g-3 mb-3">
      <?php foreach ($rows as $r):
        $sc = $status_cfg[$r['status']] ?? ['label'=>$r['status'],'color'=>'#6B7280','bg'=>'#F3F4F6','icon'=>'bi-circle'];
      ?>
      <div class="col-sm-6 col-xl-4">
        <div class="wol-card h-100">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="wol-card-num"><?= h($r['numer_umowy']) ?></div>
            <span class="wol-status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
              <i class="bi <?= $sc['icon'] ?>"></i><?= $sc['label'] ?>
            </span>
          </div>
          <?php if (!empty($r['needs_correction']) || trim((string)($r['notatka_do_realizacji'] ?? '')) !== ''): ?>
          <div class="d-flex flex-wrap align-items-center gap-1 mb-1"><?= needs_correction_badge($r) ?><?= notatka_realizacji_badge($r) ?></div>
          <?php endif; ?>
          <div class="wol-card-name"><?= h($r['imie_nazwisko']) ?></div>
          <div class="wol-card-meta mb-2">
            <?php if ($r['miejsce_wolontariatu']): ?><i class="bi bi-geo-alt me-1"></i><?= h($r['miejsce_wolontariatu']) ?><?php endif; ?>
          </div>
          <div class="d-flex justify-content-between align-items-end">
            <div class="text-muted" style="font-size:.75rem">
              <?php if ($r['data_zawarcia']): ?><i class="bi bi-calendar3 me-1"></i><?= date_pl($r['data_zawarcia']) ?><?php endif; ?>
              <?php if ($r['opiekun']): ?><br><i class="bi bi-person me-1"></i><?= h($r['opiekun']) ?><?php endif; ?>
            </div>
            <div class="d-flex gap-1">
              <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
              <?php if ($can_edit_w): ?>
              <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
              <?php endif; ?>
              <?php if ($can_edit_w && $r['status'] === 'podpisana'): ?>
              <button type="button" class="btn btn-sm btn-outline-info" title="Rozpocznij realizację"
                      data-wol-status="<?= (int)$r['id'] ?>" data-wol-new="w realizacji" data-wol-msg="Oznaczyć umowę jako „w realizacji"?">
                <i class="bi bi-play-fill"></i>
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger" title="Anuluj"
                      data-wol-status="<?= (int)$r['id'] ?>" data-wol-new="anulowana" data-wol-msg="Zrezygnował z podpisu — anulować umowę?">
                <i class="bi bi-x-circle"></i>
              </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- WIDOK TABELI -->
    <div class="tz-card mb-3">
      <div class="pv-table-wrap" style="border:none;border-radius:0;margin-bottom:0">
        <table class="pv-table wol-table mb-0">
          <thead>
            <tr>
              <th style="width:2%" class="ps-3"><input type="checkbox" id="cb-all" class="form-check-input" title="Zaznacz wszystkie"></th>
              <th>Numer umowy</th>
              <th class="d-none d-md-table-cell">Nr rejestru</th>
              <th>Wolontariusz</th>
              <th class="d-none d-lg-table-cell">Miejsce / projekt</th>
              <th class="d-none d-sm-table-cell">Data zawarcia</th>
              <th class="d-none d-xl-table-cell">Opiekun</th>
              <th>Status</th>
              <th class="text-end">Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $sc = $status_cfg[$r['status']] ?? ['label'=>$r['status'],'color'=>'#6B7280','bg'=>'#F3F4F6','icon'=>'bi-circle'];
              $bezterm = !empty($r['bezterminowa']);
            ?>
            <tr data-row-href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= (int)$r['id'] ?>">
              <td class="ps-3" onclick="event.stopPropagation()">
                <input type="checkbox" class="cb-row form-check-input" value="<?= (int)$r['id'] ?>" aria-label="Zaznacz">
              </td>
              <td>
                <div class="wol-num"><?= h($r['numer_umowy']) ?></div>
                <?php if (!empty($r['ubezpieczenie_nnw']) || !empty($r['ubezpieczenie_oc'])): ?>
                <div class="wol-sub">
                  <?= !empty($r['ubezpieczenie_nnw']) ? '<span class="badge bg-success-subtle text-success" style="font-size:.65rem">NNW</span> ' : '' ?>
                  <?= !empty($r['ubezpieczenie_oc'])  ? '<span class="badge bg-info-subtle text-info" style="font-size:.65rem">OC</span>' : '' ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="font-monospace text-muted d-none d-md-table-cell" style="font-size:.78rem">
                <?= h($r['nr_rejestru'] ?? '') ?: '—' ?>
              </td>
              <td>
                <div class="wol-name"><?= h($r['imie_nazwisko']) ?></div>
                <?php if (!empty($r['niepelnoletni']) || !empty($r['rpts_wymagana'])): ?>
                <div class="wol-sub">
                  <?php if (!empty($r['niepelnoletni'])): ?><span class="badge bg-warning-subtle text-warning" style="font-size:.65rem">niepełnoletni</span><?php endif; ?>
                  <?= rpts_list_badge($r) ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="d-none d-lg-table-cell">
                <?php if ($r['miejsce_wolontariatu']): ?>
                <div style="font-size:.83rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <i class="bi bi-geo-alt text-muted me-1"></i><?= h($r['miejsce_wolontariatu']) ?>
                </div>
                <?php endif; ?>
                <?php if ($r['projekt_program']): ?>
                <div class="wol-sub"><i class="bi bi-folder2 me-1"></i><?= h($r['projekt_program']) ?></div>
                <?php endif; ?>
              </td>
              <td class="d-none d-sm-table-cell" style="font-size:.82rem;white-space:nowrap">
                <?= date_pl($r['data_zawarcia']) ?>
                <?php if ($r['data_zakonczenia'] && !$bezterm): ?>
                <div class="wol-sub">→ <?= date_pl($r['data_zakonczenia']) ?></div>
                <?php elseif ($bezterm): ?>
                <div class="wol-sub"><i class="bi bi-infinity"></i> bezterminowa</div>
                <?php endif; ?>
              </td>
              <td class="d-none d-xl-table-cell" style="font-size:.8rem;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= h($r['opiekun'] ?? '—') ?>
              </td>
              <td>
                <div class="d-flex flex-wrap align-items-center gap-1">
                  <span class="wol-status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                    <i class="bi <?= $sc['icon'] ?>"></i><?= $sc['label'] ?>
                  </span>
                  <?= needs_correction_badge($r) ?><?= notatka_realizacji_badge($r) ?>
                </div>
              </td>
              <td class="text-end" style="white-space:nowrap" onclick="event.stopPropagation()">
                <div class="dropdown">
                  <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 wol-actions-toggle"
                          data-bs-toggle="dropdown" aria-expanded="false" title="Akcje">
                    <i class="bi bi-three-dots-vertical"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye me-2"></i>Podgląd</a></li>
                    <?php if ($can_edit_w): ?>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil me-2"></i>Edytuj</a></li>
                    <?php endif; ?>
                    <?php if ($can_edit_w && $r['status'] === 'podpisana'): ?>
                    <li>
                      <button type="button" class="dropdown-item"
                              data-wol-status="<?= (int)$r['id'] ?>" data-wol-new="w realizacji" data-wol-msg="Oznaczyć umowę jako „w realizacji"?">
                        <i class="bi bi-play-fill me-2 text-info"></i>Rozpocznij realizację
                      </button>
                    </li>
                    <li>
                      <button type="button" class="dropdown-item"
                              data-wol-status="<?= (int)$r['id'] ?>" data-wol-new="anulowana" data-wol-msg="Zrezygnował z podpisu — anulować umowę?">
                        <i class="bi bi-x-circle me-2 text-danger"></i>Zrezygnował z podpisu
                      </button>
                    </li>
                    <?php endif; ?>
                    <?php if (is_admin() || ($can_edit_w && (int)($r['created_by'] ?? 0) === (int)current_user()['id'])): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <form method="post" action="<?= APP_URL ?>/contracts/delete.php"
                            onsubmit="return confirm('Usunąć umowę <?= h(addslashes($r['numer_umowy'] ?? '#'.$r['id'])) ?>?')">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="type"  value="<?= $TYPE ?>">
                        <input type="hidden" name="id"    value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Usuń</button>
                      </form>
                    </li>
                    <?php endif; ?>
                  </ul>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php include dirname(__DIR__) . '/includes/bulk_bar.php'; ?>
      <div class="tz-card__ft d-flex justify-content-between align-items-center">
        <small style="color:var(--tz-muted)">
          Znaleziono: <strong style="color:var(--tz-ink)"><?= $total ?></strong>
          <?= $filtering ? '— filtrowanie aktywne' : '' ?>
        </small>
        <?php if ($pag['pages'] > 1): ?><?= pagination_html($pag) ?><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// ── AJAX fragment ─────────────────────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    $ctx = compact('f','status_cfg','status_counts','all_total','active_total',
                   'rows','total','pag','adv_count','_is_my_only',
                   'qs_base','TYPE','TABLE','can_edit_w');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'        => true,
        'total'     => $total,
        'list_html' => _wolontariat_list_html($ctx),
    ]);
    exit;
}

$ctx = compact('f','status_cfg','status_counts','all_total','active_total',
               'rows','total','pag','adv_count','_is_my_only',
               'qs_base','TYPE','TABLE','can_edit_w');

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(dirname(__DIR__)) . '/panel/includes/pv_ui.php';
require_once dirname(__DIR__) . '/includes/adv_filter.php';
pv_ui_styles();
?>

<style>
/* ── Pigułki statusu (klikalny filtr) — kolor per-status, reszta pod tz ──── */
.wol-stat-pill {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: .35rem .85rem;
  border-radius: 2rem;
  font-size: .78rem; font-weight: 600;
  text-decoration: none; cursor: pointer;
  border: 2px solid transparent;
  transition: all .12s;
}
.wol-stat-pill:hover { filter: brightness(.95); transform: translateY(-1px); }
.wol-stat-pill.selected { box-shadow: 0 0 0 3px rgba(0,0,0,.15); }

/* Przełącznik widoku tabela/karty — stan wybrany dla tz-btn--ghost */
.tz-btn--ghost.active { background: var(--tz); color: var(--tz-on, #fff); border-color: var(--tz); }
.wol-table tr[data-row-href] { cursor: pointer; }

.wol-num  { font-family: monospace; font-weight: 700; font-size: .87rem; color: var(--tz-ink); }
.wol-name { font-weight: 600; color: var(--tz-ink); }
.wol-sub  { font-size: .75rem; color: var(--tz-muted); margin-top: 1px; }
.wol-status-pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .18rem .6rem; border-radius: 2rem;
  font-size: .72rem; font-weight: 600; white-space: nowrap;
}

.wol-card {
  background: var(--tz-bg); border: 1px solid var(--tz-line); border-radius: 14px;
  padding: 1rem 1.1rem; transition: box-shadow .15s, transform .12s;
}
.wol-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); transform: translateY(-2px); }
.wol-card-num  { font-family: monospace; font-size: .75rem; color: var(--tz-muted); margin-bottom: .2rem; }
.wol-card-name { font-weight: 700; font-size: .95rem; color: var(--tz-ink); margin-bottom: .15rem; }
.wol-card-meta { font-size: .78rem; color: var(--tz-muted); }
</style>

<?php pv_page_header('Porozumienia wolontariackie', [
    'icon' => 'bi-heart-fill',
    'sub'  => 'Aktywnych: ' . $active_total . ' · zakończonych: ' . (($status_counts['zakończona'] ?? 0) + ($status_counts['rozwiązana'] ?? 0)),
    'actions' => (function () use ($_urlop_pending, $can_edit_w, $TYPE) {
        ob_start();
        if (module_enabled('dyspozycyjnosc_enabled')): ?>
        <a href="<?= APP_URL ?>/contracts/wolontariat/urlopy.php" class="tz-btn tz-btn--ghost position-relative" title="Zatwierdzanie urlopów">
          <i class="bi bi-airplane" aria-hidden="true"></i>Urlopy
          <?php if ($_urlop_pending): ?>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $_urlop_pending ?></span>
          <?php endif; ?>
        </a>
        <?php endif;
        if ($can_edit_w): ?>
        <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/new.php" class="tz-btn">
          <i class="bi bi-plus-lg" aria-hidden="true"></i>Nowe porozumienie
        </a>
        <a href="<?= APP_URL ?>/reports/export.php?type=<?= $TYPE ?>" class="tz-btn tz-btn--ghost" title="Eksportuj do CSV">
          <i class="bi bi-download" aria-hidden="true"></i>
        </a>
        <?php endif;
        return ob_get_clean();
    })(),
]); ?>
<span id="wol-total-badge" class="visually-hidden"><?= $all_total ?></span>

<!-- ── Pasek filtrów ──────────────────────────────────────────────────────── -->
<div class="tz-card mb-3">
  <div class="tz-card__bd">
    <form method="get" id="wol-filter-form">
      <?php if ($f['view'] !== 'table'): ?><input type="hidden" name="view" value="<?= h($f['view']) ?>"><?php endif; ?>

      <div class="row g-2 align-items-center">
        <div class="col">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input name="q" class="form-control" placeholder="Numer, nazwisko, PESEL, email, telefon, miejsce, projekt, uwagi…"
                   value="<?= h($f['q']) ?>" autocomplete="off">
          </div>
        </div>
        <?php if (!$_is_my_only): ?>
        <div class="col-md-2">
          <select name="opiekun" class="form-select form-select-sm">
            <option value="">— opiekun —</option>
            <?php foreach ($opiekunowie as $op): ?>
            <option value="<?= h($op['opiekun']) ?>" <?= $f['opiekun']===$op['opiekun']?'selected':'' ?>><?= h($op['opiekun']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-auto">
          <select name="woj" class="form-select form-select-sm">
            <option value="">— województwo —</option>
            <?php foreach ($woj_in_db as $w): ?>
            <option value="<?= h($w['wojewodztwo']) ?>" <?= $f['woj']===$w['wojewodztwo']?'selected':'' ?>><?= h(ucfirst($w['wojewodztwo'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <select name="typ" class="form-select form-select-sm">
            <option value="">— typ —</option>
            <?php foreach ($typ_in_db as $t): ?>
            <option value="<?= h($t['wolontariat_typ']) ?>" <?= $f['typ']===$t['wolontariat_typ']?'selected':'' ?>><?= h($_typ_labels[$t['wolontariat_typ']] ?? $t['wolontariat_typ']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="tz-btn" type="submit" style="min-height:31px;padding:.35rem 1rem"><i class="bi bi-search" aria-hidden="true"></i> Szukaj</button>
        </div>
        <div class="col-auto">
          <a class="adv-toggle <?= $adv_open?'is-active':'' ?>"
             data-bs-toggle="collapse" href="#advPanel" role="button"
             aria-expanded="<?= $adv_open?'true':'false' ?>">
            <i class="bi bi-sliders"></i> Zaawansowane
            <?php if ($adv_count): ?><span class="badge rounded-pill bg-primary ms-1" style="font-size:.7rem"><?= $adv_count ?></span><?php endif; ?>
          </a>
        </div>
        <?php if ($f['q'] || $f['status'] || $f['opiekun'] || $f['projekt'] || $f['woj'] || $f['typ'] || $f['obszar'] || $f['zgoda'] || $adv_count): ?>
        <div class="col-auto">
          <a href="?<?= $f['view'] !== 'table' ? 'view='.$f['view'] : '' ?>" class="tz-btn tz-btn--ghost" style="min-height:31px;padding:.35rem 1rem"><i class="bi bi-x" aria-hidden="true"></i> Wyczyść</a>
        </div>
        <?php endif; ?>
        <div class="col-auto ms-auto">
          <div class="d-flex gap-1" role="group" aria-label="Widok listy">
            <a href="?<?= http_build_query(array_merge($qs_base, ['view'=>'table'])) ?>"
               class="tz-btn tz-btn--ghost <?= $f['view']==='table'?'active':'' ?>" style="min-height:31px;padding:.35rem .7rem" title="Tabela">
              <i class="bi bi-table" aria-hidden="true"></i>
            </a>
            <a href="?<?= http_build_query(array_merge($qs_base, ['view'=>'cards'])) ?>"
               class="tz-btn tz-btn--ghost <?= $f['view']==='cards'?'active':'' ?>" style="min-height:31px;padding:.35rem .7rem" title="Karty">
              <i class="bi bi-grid-3x2-gap" aria-hidden="true"></i>
            </a>
          </div>
        </div>
      </div>

      <div class="collapse <?= $adv_open?'show':'' ?>" id="advPanel">
        <div class="adv-panel mt-2">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="adv-label">Projekt / program</label>
              <input name="projekt" class="form-control" placeholder="Projekt, program…" value="<?= h($f['projekt']) ?>">
            </div>
            <div class="col-md-2">
              <label class="adv-label">Forma podpisania</label>
              <select name="forma" class="form-select">
                <option value="">— wszystkie —</option>
                <option value="papierowa"     <?= $f['forma']==='papierowa'?'selected':'' ?>>Papierowa</option>
                <option value="elektroniczna" <?= $f['forma']==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
                <option value="kwalifikowany" <?= $f['forma']==='kwalifikowany'?'selected':'' ?>>Kwalifikowany e-podpis</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="adv-label">Obszar działania</label>
              <select name="obszar" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($_obs_labels as $ok => $ov): ?>
                <option value="<?= h($ok) ?>" <?= $f['obszar']===$ok?'selected':'' ?>><?= h($ov) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-3 pb-1">
              <div class="form-check mb-0">
                <input type="checkbox" name="zgoda" value="1" id="f_zgoda" class="form-check-input" <?= $f['zgoda']?'checked':'' ?>>
                <label for="f_zgoda" class="form-check-label" style="font-size:.82rem;cursor:pointer"><i class="bi bi-shield-check text-success"></i> RODO</label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="niep" value="1" id="f_niep" class="form-check-input" <?= $f['niep']?'checked':'' ?>>
                <label for="f_niep" class="form-check-label" style="font-size:.82rem;cursor:pointer">Niepełnoletni</label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="bezterm" value="1" id="f_bezterm" class="form-check-input" <?= $f['bezterm']?'checked':'' ?>>
                <label for="f_bezterm" class="form-check-label" style="font-size:.82rem;cursor:pointer">Bezterminowa</label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="uwagi" value="1" id="f_uwagi" class="form-check-input" <?= $f['uwagi']?'checked':'' ?>>
                <label for="f_uwagi" class="form-check-label" style="font-size:.82rem;cursor:pointer"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Z uwagami</label>
              </div>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-3 pb-1">
              <div class="form-check mb-0">
                <input type="checkbox" name="nnw" value="1" id="f_nnw" class="form-check-input" <?= $f['nnw']?'checked':'' ?>>
                <label for="f_nnw" class="form-check-label" style="font-size:.82rem;cursor:pointer"><span class="badge bg-success-subtle text-success" style="font-size:.72rem">NNW</span></label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="oc" value="1" id="f_oc" class="form-check-input" <?= $f['oc']?'checked':'' ?>>
                <label for="f_oc" class="form-check-label" style="font-size:.82rem;cursor:pointer"><span class="badge bg-info-subtle text-info" style="font-size:.72rem">OC</span></label>
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
            <div class="col-md-3">
              <label class="adv-label">Sortuj według</label>
              <div class="input-group input-group-sm">
                <select name="sort" class="form-select">
                  <option value="created_at"      <?= $sort_col==='created_at'?'selected':'' ?>>Data dodania</option>
                  <option value="data_zawarcia"    <?= $sort_col==='data_zawarcia'?'selected':'' ?>>Data zawarcia</option>
                  <option value="data_zakonczenia" <?= $sort_col==='data_zakonczenia'?'selected':'' ?>>Data zakończenia</option>
                  <option value="imie_nazwisko"    <?= $sort_col==='imie_nazwisko'?'selected':'' ?>>Nazwisko</option>
                </select>
                <select name="dir" class="form-select" style="max-width:5rem">
                  <option value="desc" <?= $sort_dir==='DESC'?'selected':'' ?>>↓</option>
                  <option value="asc"  <?= $sort_dir==='ASC'?'selected':'' ?>>↑</option>
                </select>
              </div>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
              <button type="button" class="tz-btn tz-btn--ghost" style="min-height:31px;padding:.35rem 1rem" id="wol-adv-clear"><i class="bi bi-eraser" aria-hidden="true"></i> Wyczyść zaawansowane</button>
              <button type="submit" class="tz-btn" style="min-height:31px;padding:.35rem 1rem"><i class="bi bi-funnel" aria-hidden="true"></i> Zastosuj filtry</button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div id="wol-list-region" aria-busy="false">
  <?= _wolontariat_list_html($ctx) ?>
</div>

<div id="wol-live" class="visually-hidden" aria-live="polite"></div>

<script>
/* ── AJAX: filtry, paginacja, szybkie akcje statusu ──────────────────────── */
(function () {
  'use strict';
  var region  = document.getElementById('wol-list-region');
  var form    = document.getElementById('wol-filter-form');
  var badgeEl = document.getElementById('wol-total-badge');
  var liveEl  = document.getElementById('wol-live');
  if (!region || !form) return;

  var abort = null, debounce = null;

  function formParams() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (v) p.set(k, v); });
    return p;
  }

  function syncForm(params) {
    form.querySelectorAll('select, input[type="text"], input[type="date"]').forEach(function (el) {
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
        if (push !== false) {
          var histUrl = new URL(window.location.href);
          histUrl.search = params.toString();
          history.pushState({ wol: params.toString() }, '', histUrl.toString());
        }
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
        bindRegion();
        if (window.bulkClear) window.bulkClear();
        if (liveEl) {
          liveEl.textContent = '';
          setTimeout(function () { liveEl.textContent = 'Załadowano ' + (data.total || 0) + ' porozumień.'; }, 50);
        }
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;
        region.removeAttribute('aria-busy');
        region.style.opacity = '1';
        region.style.pointerEvents = '';
      });
  }

  function bindRegion() {
    // Paginacja
    region.querySelectorAll('a.page-link').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        load(new URLSearchParams(new URL(a.getAttribute('href'), window.location.href).search), true);
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
    // Status pills
    region.querySelectorAll('.wol-stat-pill').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var p = new URLSearchParams(new URL(a.getAttribute('href'), window.location.href).search);
        syncForm(p);
        load(p, true);
      });
    });
    // Szybka zmiana statusu (data-wol-status)
    region.querySelectorAll('[data-wol-status]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        var msg = btn.dataset.wolMsg;
        if (msg && !confirm(msg)) return;
        csrfFetch('<?= APP_URL ?>/api/ajax.php', {
          action: 'set_status',
          id: btn.dataset.wolStatus,
          type: 'wolontariat',
          value: btn.dataset.wolNew,
        }).then(function (res) {
          if (res && res.ok) {
            if (window.ajaxToast) ajaxToast(res.msg || 'Status zmieniony', 'success');
            load(formParams(), false);
          } else {
            if (window.ajaxToast) ajaxToast((res && res.msg) ? res.msg : 'Nie udało się zmienić statusu', 'error');
          }
        }).catch(function () {
          if (window.ajaxToast) ajaxToast('Błąd połączenia', 'error');
        });
      });
    });
    // Klik w wiersz tabeli
    region.querySelectorAll('tr[data-row-href]').forEach(function (tr) {
      tr.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,label,.dropdown')) return;
        window.location.href = tr.dataset.rowHref;
      });
    });
    // Dropdowny akcji — popper "fixed"
    region.querySelectorAll('.wol-actions-toggle').forEach(function (el) {
      new bootstrap.Dropdown(el, { popperConfig: { strategy: 'fixed' } });
    });
  }

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

  var advPanel = document.getElementById('advPanel');
  if (advPanel) {
    advPanel.querySelectorAll('input[type="date"], input[type="checkbox"]').forEach(function (el) {
      el.addEventListener('change', function () { load(formParams(), true); });
    });
    advPanel.querySelectorAll('input[type="text"]').forEach(function (el) {
      el.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { load(formParams(), true); }, 380);
      });
    });
    var advClear = document.getElementById('wol-adv-clear');
    if (advClear) advClear.addEventListener('click', function () {
      advPanel.querySelectorAll('input, select').forEach(function (el) {
        if (el.type === 'checkbox') el.checked = false;
        else if (el.name === 'sort') el.value = 'created_at';
        else if (el.name === 'dir')  el.value = 'desc';
        else el.value = '';
      });
      load(formParams(), true);
    });
  }

  window.addEventListener('popstate', function (e) {
    var p = (e.state && e.state.wol !== undefined)
      ? new URLSearchParams(e.state.wol)
      : new URLSearchParams(window.location.search);
    syncForm(p);
    load(p, false);
  });

  bindRegion();
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
