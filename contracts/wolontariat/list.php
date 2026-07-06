<?php
/**
 * contracts/wolontariat/list.php — Lista porozumień wolontariackich.
 * Przebudowana od podstaw: statystyki, filtry, nowoczesny układ.
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

// ── Bezpieczeństwo: per-opiekun visibility ────────────────────────────────────
// Editor (nie admin) widzi tylko "swoje" umowy jeśli ustawiono filtr opiekuna
$_cur_user    = current_user();
$_is_my_only  = !is_admin() && !can_delete('wolontariat')
                && org_setting('wolontariat_opiekun_only') === '1';

// ── Filtry podstawowe
$search    = trim($_GET['q']        ?? '');
$status    = $_GET['status']        ?? '';
$view_mode = in_array($_GET['view'] ?? 'table', ['table','cards']) ? ($_GET['view'] ?? 'table') : 'table';

// ── Filtry segmentacji (pierwotne)
$opiekun     = trim($_GET['opiekun']  ?? '');
$projekt     = trim($_GET['projekt']  ?? '');
$wojewodztwo = trim($_GET['woj']      ?? '');
$obszar      = trim($_GET['obszar']   ?? '');
$typ         = trim($_GET['typ']      ?? '');
$email_consent_only = !empty($_GET['zgoda']);

// ── Filtry zaawansowane (nowe)
$forma         = $_GET['forma']          ?? '';
$data_od       = $_GET['data_od']        ?? '';
$data_do       = $_GET['data_do']        ?? '';
$koniec_od     = $_GET['koniec_od']      ?? '';
$koniec_do     = $_GET['koniec_do']      ?? '';
$ubez_nnw      = !empty($_GET['nnw']);
$ubez_oc       = !empty($_GET['oc']);
$niepelnoletni = !empty($_GET['niep']);
$bezterminowa  = !empty($_GET['bezterm']);
$uwagi_only    = !empty($_GET['uwagi']);

// ── Sortowanie
$_sorts   = ['created_at' => 'created_at', 'data_zawarcia' => 'data_zawarcia',
             'data_zakonczenia' => 'data_zakonczenia', 'imie_nazwisko' => 'imie_nazwisko'];
$sort_col = $_sorts[$_GET['sort'] ?? ''] ?? 'created_at';
$sort_dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$page = max(1, intval($_GET['page'] ?? 1));
$per  = 20;

$where  = '1=1';
$params = [];

// Per-opiekun: editor widzi tylko swoje gdy włączone
if ($_is_my_only) {
    $my_name = trim(($_cur_user['first_name'] ?? '') . ' ' . ($_cur_user['last_name'] ?? ''));
    if (!$my_name) $my_name = $_cur_user['name'] ?? '';
    $where .= " AND opiekun LIKE ?";
    $params[] = '%' . $my_name . '%';
}

if ($search) {
    $where .= " AND (numer_umowy LIKE ? OR imie_nazwisko LIKE ? OR nr_rejestru LIKE ?"
            . " OR pesel LIKE ? OR email LIKE ? OR telefon LIKE ?"
            . " OR miejsce_wolontariatu LIKE ? OR opiekun LIKE ? OR projekt_program LIKE ?"
            . " OR przedmiot_porozumienia LIKE ? OR uwagi LIKE ? OR addr_city LIKE ?)";
    $params = array_merge($params, array_fill(0, 12, "%$search%"));
}
if ($status)     { $where .= " AND status = ?"; $params[] = $status; }
if ($opiekun)    { $where .= " AND opiekun LIKE ?"; $params[] = "%$opiekun%"; }
if ($projekt)    { $where .= " AND projekt_program LIKE ?"; $params[] = "%$projekt%"; }
if ($wojewodztwo){ $where .= " AND wojewodztwo = ?"; $params[] = $wojewodztwo; }
if ($typ)        { $where .= " AND wolontariat_typ = ?"; $params[] = $typ; }
if ($obszar)     { $where .= " AND obszar_dzialania LIKE ?"; $params[] = '%"' . $obszar . '"%'; }
if ($email_consent_only) { $where .= " AND email_consent = 1"; }
if ($forma)      { $where .= " AND forma_podpisania = ?"; $params[] = $forma; }
if ($data_od)    { $where .= " AND data_zawarcia >= ?"; $params[] = $data_od; }
if ($data_do)    { $where .= " AND data_zawarcia <= ?"; $params[] = $data_do; }
if ($koniec_od)  { $where .= " AND data_zakonczenia >= ?"; $params[] = $koniec_od; }
if ($koniec_do)  { $where .= " AND data_zakonczenia <= ?"; $params[] = $koniec_do; }
if ($ubez_nnw)   { $where .= " AND ubezpieczenie_nnw = 1"; }
if ($ubez_oc)    { $where .= " AND ubezpieczenie_oc = 1"; }
if ($niepelnoletni) { $where .= " AND niepelnoletni = 1"; }
if ($bezterminowa)  { $where .= " AND bezterminowa = 1"; }
if ($uwagi_only)    { $where .= " AND needs_correction = 1"; }
$where .= ' AND ' . contract_access_where($TYPE);

// Liczba aktywnych filtrów zaawansowanych (nie liczymy opiekuna i projektu – te są w "segmentacji")
$adv_count = (int)!!$forma + (int)!!$data_od + (int)!!$data_do
           + (int)!!$koniec_od + (int)!!$koniec_do
           + (int)$ubez_nnw + (int)$ubez_oc + (int)$niepelnoletni + (int)$bezterminowa
           + (int)$uwagi_only;
$adv_open  = $adv_count > 0 || !empty($_GET['adv']);

$_qs_base = array_filter([
    'q' => $search, 'status' => $status, 'opiekun' => $opiekun, 'projekt' => $projekt,
    'woj' => $wojewodztwo, 'obszar' => $obszar, 'typ' => $typ,
    'zgoda' => $email_consent_only ? '1' : null,
    'forma' => $forma, 'data_od' => $data_od, 'data_do' => $data_do,
    'koniec_od' => $koniec_od, 'koniec_do' => $koniec_do,
    'nnw' => $ubez_nnw ? '1' : null, 'oc' => $ubez_oc ? '1' : null,
    'niep' => $niepelnoletni ? '1' : null, 'bezterm' => $bezterminowa ? '1' : null,
    'uwagi' => $uwagi_only ? '1' : null,
    'sort' => $sort_col !== 'created_at' ? $sort_col : null,
    'dir'  => $sort_dir !== 'DESC' ? 'asc' : null,
    'view' => $view_mode !== 'table' ? $view_mode : null,
]);

$total  = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE {$where}", $params)['c'] ?? 0);
$pag    = paginate($total, $per, $page, APP_URL . "/contracts/{$TYPE}/list.php?" . http_build_query($_qs_base));
$rows   = db_all("SELECT * FROM {$TABLE} WHERE {$where} ORDER BY {$sort_col} {$sort_dir} LIMIT {$per} OFFSET {$pag['offset']}", $params);

// Dane do filtrów select
$woj_in_db   = db_all("SELECT DISTINCT wojewodztwo FROM {$TABLE} WHERE wojewodztwo IS NOT NULL AND wojewodztwo != '' ORDER BY wojewodztwo");
$typ_in_db   = db_all("SELECT DISTINCT wolontariat_typ FROM {$TABLE} WHERE wolontariat_typ IS NOT NULL AND wolontariat_typ != '' ORDER BY wolontariat_typ");
$_typ_labels = ['stały'=>'Stały','jednorazowy'=>'Jednorazowy','projektowy'=>'Projektowy','akcyjny'=>'Akcyjny','wakacyjny'=>'Wakacyjny'];
$_obs_labels = ['społeczny'=>'Społeczny','edukacyjny'=>'Edukacyjny','zdrowotny'=>'Zdrowotny','ekologiczny'=>'Ekologiczny','kulturalny'=>'Kulturalny','sportowy'=>'Sportowy','pomocowy'=>'Pomocowy','zwierzeta'=>'Ochrona zwierząt','cyfrowy'=>'Cyfrowy / IT','inny'=>'Inny'];

// Statystyki globalne
$status_counts = [];
$all_statuses  = ['projekt', 'podpisana', 'w realizacji', 'zakończona', 'rozwiązana', 'anulowana'];
$all_total     = 0;
foreach ($all_statuses as $s) {
    $c = (int)(db_one("SELECT COUNT(*) AS c FROM {$TABLE} WHERE status=?", [$s])['c'] ?? 0);
    $status_counts[$s] = $c;
    $all_total += $c;
}
$active_total = ($status_counts['podpisana'] ?? 0) + ($status_counts['w realizacji'] ?? 0) + ($status_counts['projekt'] ?? 0);

// Etykiety i kolory statusów
$status_cfg = [
    'projekt'     => ['label' => 'Projekt',     'color' => '#6366F1', 'bg' => '#EEF2FF', 'icon' => 'bi-file-earmark-text'],
    'podpisana'   => ['label' => 'Podpisana',   'color' => '#2E844A', 'bg' => '#EFF7ED', 'icon' => 'bi-check-circle'],
    'w realizacji'=> ['label' => 'W realizacji','color' => '#0176D3', 'bg' => '#EEF4FF', 'icon' => 'bi-play-circle'],
    'zakończona'  => ['label' => 'Zakończona',  'color' => '#374151', 'bg' => '#F3F4F6', 'icon' => 'bi-flag'],
    'rozwiązana'  => ['label' => 'Rozwiązana',  'color' => '#D97706', 'bg' => '#FEF3E2', 'icon' => 'bi-x-circle'],
    'anulowana'   => ['label' => 'Anulowana',   'color' => '#DC2626', 'bg' => '#FEF2F2', 'icon' => 'bi-slash-circle'],
];

// Lista opiekunów do filtra
$opiekunowie = db_all("SELECT DISTINCT opiekun FROM {$TABLE} WHERE opiekun IS NOT NULL AND opiekun != '' ORDER BY opiekun");

include dirname(dirname(__DIR__)) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/adv_filter.php';
?>

<style>
/* ── Wolontariat list styles ────────────────────────────── */
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

.wol-filter-bar {
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  padding: .75rem 1rem;
}

.wol-table th {
  font-size: .73rem; font-weight: 700; letter-spacing: .05em;
  text-transform: uppercase; color: #6B7280;
  padding: .6rem .9rem;
  background: #F9FAFB;
  border-bottom: 2px solid #E5E7EB;
  white-space: nowrap;
}
.wol-table td {
  padding: .72rem .9rem;
  vertical-align: middle;
  border-bottom: 1px solid #F3F4F6;
  font-size: .855rem;
}
.wol-table tr:last-child td { border-bottom: none; }
.wol-table tr:hover td { background: #FAFAFA; }

.wol-num  { font-family: monospace; font-weight: 700; font-size: .87rem; color: #111827; }
.wol-name { font-weight: 600; color: #111827; }
.wol-sub  { font-size: .75rem; color: #9CA3AF; margin-top: 1px; }
.wol-status-pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .18rem .6rem; border-radius: 2rem;
  font-size: .72rem; font-weight: 600; white-space: nowrap;
}

/* Karta wolontariusza */
.wol-card {
  background: #fff; border: 1px solid #E5E7EB; border-radius: 12px;
  padding: 1rem 1.1rem; transition: box-shadow .15s, transform .12s;
}
.wol-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); transform: translateY(-2px); }
.wol-card-num { font-family: monospace; font-size: .75rem; color: #9CA3AF; margin-bottom: .2rem; }
.wol-card-name { font-weight: 700; font-size: .95rem; color: #111827; margin-bottom: .15rem; }
.wol-card-meta { font-size: .78rem; color: #6B7280; }
</style>

<!-- ── Nagłówek strony ──────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 d-flex align-items-center gap-2">
      <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:38px;height:38px">
        <i class="bi bi-heart-fill text-primary" style="font-size:1rem"></i>
      </span>
      Porozumienia wolontariackie
      <span class="badge bg-secondary"><?= $all_total ?></span>
    </h4>
    <div class="text-muted small ms-1" style="margin-left:50px">
      Aktywnych: <?= $active_total ?> · zakończonych: <?= ($status_counts['zakończona'] ?? 0) + ($status_counts['rozwiązana'] ?? 0) ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <?php if (module_enabled('dyspozycyjnosc_enabled')): ?>
    <a href="<?= APP_URL ?>/contracts/wolontariat/urlopy.php" class="btn btn-outline-warning position-relative" title="Zatwierdzanie urlopów">
      <i class="bi bi-airplane me-1"></i>Urlopy
      <?php if ($_urlop_pending): ?>
      <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $_urlop_pending ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/new.php" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowe porozumienie
    </a>
    <a href="<?= APP_URL ?>/reports/export.php?type=<?= $TYPE ?>" class="btn btn-outline-secondary btn-sm" title="Eksportuj do CSV">
      <i class="bi bi-download"></i>
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- ── Pasy statusów (klikalne) ───────────────────────────────────────────── -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?<?= http_build_query(array_filter(['q'=>$search,'opiekun'=>$opiekun,'projekt'=>$projekt])) ?>"
     class="wol-stat-pill <?= !$status ? 'selected' : '' ?>"
     style="background:#F3F4F6;color:#374151;border-color:<?= !$status ? '#374151' : 'transparent' ?>">
    <i class="bi bi-list-ul"></i> Wszystkie <strong><?= $all_total ?></strong>
  </a>
  <?php foreach ($status_cfg as $sk => $sv): ?>
  <?php if (!$status_counts[$sk]) continue; ?>
  <a href="?<?= http_build_query(array_filter(['q'=>$search,'status'=>$sk,'opiekun'=>$opiekun,'projekt'=>$projekt])) ?>"
     class="wol-stat-pill <?= $status===$sk ? 'selected' : '' ?>"
     style="background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>;border-color:<?= $status===$sk ? $sv['color'] : 'transparent' ?>">
    <i class="bi <?= $sv['icon'] ?>"></i> <?= $sv['label'] ?> <strong><?= $status_counts[$sk] ?></strong>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── Pasek filtrów ──────────────────────────────────────────────────────── -->
<?php if ($_is_my_only): ?>
<div class="alert alert-info py-2 px-3 small mb-2">
  <i class="bi bi-shield-lock me-1"></i>
  Widzisz tylko <strong>swoje</strong> umowy wolontariatu (tryb per-opiekun). Skontaktuj się z administratorem aby zobaczyć wszystkie.
</div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body p-2">
    <form method="get" id="filter-form">
      <?php if ($view_mode !== 'table'): ?><input type="hidden" name="view" value="<?= h($view_mode) ?>"><?php endif; ?>

      <!-- Podstawowy pasek -->
      <div class="row g-2 align-items-center">
        <div class="col">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input name="q" class="form-control" placeholder="Numer, nazwisko, PESEL, email, telefon, miejsce, projekt, uwagi…"
                   value="<?= h($search) ?>" autocomplete="off">
          </div>
        </div>
        <?php if (!$_is_my_only): ?>
        <div class="col-md-2">
          <select name="opiekun" class="form-select form-select-sm">
            <option value="">— opiekun —</option>
            <?php foreach ($opiekunowie as $op): ?>
            <option value="<?= h($op['opiekun']) ?>" <?= $opiekun===$op['opiekun']?'selected':'' ?>>
              <?= h($op['opiekun']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-auto">
          <select name="woj" class="form-select form-select-sm">
            <option value="">— województwo —</option>
            <?php foreach ($woj_in_db as $w): ?>
            <option value="<?= h($w['wojewodztwo']) ?>" <?= $wojewodztwo===$w['wojewodztwo']?'selected':'' ?>>
              <?= h(ucfirst($w['wojewodztwo'])) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <select name="typ" class="form-select form-select-sm">
            <option value="">— typ —</option>
            <?php foreach ($typ_in_db as $t): ?>
            <option value="<?= h($t['wolontariat_typ']) ?>" <?= $typ===$t['wolontariat_typ']?'selected':'' ?>>
              <?= h($_typ_labels[$t['wolontariat_typ']] ?? $t['wolontariat_typ']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Szukaj</button>
        </div>
        <div class="col-auto">
          <a class="adv-toggle <?= $adv_open ? 'is-active' : '' ?>"
             data-bs-toggle="collapse" href="#advPanel" role="button"
             aria-expanded="<?= $adv_open ? 'true' : 'false' ?>">
            <i class="bi bi-sliders"></i> Zaawansowane
            <?php if ($adv_count): ?>
            <span class="badge rounded-pill bg-primary ms-1" style="font-size:.7rem"><?= $adv_count ?></span>
            <?php endif; ?>
          </a>
        </div>
        <?php if ($search || $status || $opiekun || $projekt || $wojewodztwo || $typ || $obszar || $email_consent_only || $adv_count): ?>
        <div class="col-auto">
          <a href="?<?= $view_mode !== 'table' ? 'view='.$view_mode : '' ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x"></i> Wyczyść
          </a>
        </div>
        <?php endif; ?>
        <div class="col-auto ms-auto">
          <div class="btn-group btn-group-sm" role="group">
            <a href="?<?= http_build_query(array_merge($_qs_base, ['view'=>'table'])) ?>"
               class="btn btn-outline-secondary <?= $view_mode==='table'?'active':'' ?>" title="Tabela">
              <i class="bi bi-table"></i>
            </a>
            <a href="?<?= http_build_query(array_merge($_qs_base, ['view'=>'cards'])) ?>"
               class="btn btn-outline-secondary <?= $view_mode==='cards'?'active':'' ?>" title="Karty">
              <i class="bi bi-grid-3x2-gap"></i>
            </a>
          </div>
        </div>
      </div>

      <!-- Zaawansowany panel -->
      <div class="collapse <?= $adv_open ? 'show' : '' ?>" id="advPanel">
        <div class="adv-panel mt-2">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="adv-label">Projekt / program</label>
              <input name="projekt" class="form-control" placeholder="Projekt, program…" value="<?= h($projekt) ?>">
            </div>
            <div class="col-md-2">
              <label class="adv-label">Forma podpisania</label>
              <select name="forma" class="form-select">
                <option value="">— wszystkie —</option>
                <option value="papierowa"     <?= $forma==='papierowa'?'selected':'' ?>>Papierowa</option>
                <option value="elektroniczna" <?= $forma==='elektroniczna'?'selected':'' ?>>Elektroniczna</option>
                <option value="kwalifikowany" <?= $forma==='kwalifikowany'?'selected':'' ?>>Kwalifikowany e-podpis</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="adv-label">Obszar działania</label>
              <select name="obszar" class="form-select">
                <option value="">— wszystkie —</option>
                <?php foreach ($_obs_labels as $ok => $ov): ?>
                <option value="<?= h($ok) ?>" <?= $obszar===$ok?'selected':'' ?>><?= h($ov) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-3 pb-1">
              <div class="form-check mb-0">
                <input type="checkbox" name="zgoda" value="1" id="f_zgoda" class="form-check-input" <?= $email_consent_only?'checked':'' ?>>
                <label for="f_zgoda" class="form-check-label" style="font-size:.82rem;cursor:pointer"><i class="bi bi-shield-check text-success"></i> RODO</label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="niep" value="1" id="f_niep" class="form-check-input" <?= $niepelnoletni?'checked':'' ?>>
                <label for="f_niep" class="form-check-label" style="font-size:.82rem;cursor:pointer">Niepełnoletni</label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="bezterm" value="1" id="f_bezterm" class="form-check-input" <?= $bezterminowa?'checked':'' ?>>
                <label for="f_bezterm" class="form-check-label" style="font-size:.82rem;cursor:pointer">Bezterminowa</label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="uwagi" value="1" id="f_uwagi" class="form-check-input" <?= $uwagi_only?'checked':'' ?>>
                <label for="f_uwagi" class="form-check-label" style="font-size:.82rem;cursor:pointer"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Z uwagami (do uzupełnienia)</label>
              </div>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-3 pb-1">
              <div class="form-check mb-0">
                <input type="checkbox" name="nnw" value="1" id="f_nnw" class="form-check-input" <?= $ubez_nnw?'checked':'' ?>>
                <label for="f_nnw" class="form-check-label" style="font-size:.82rem;cursor:pointer"><span class="badge bg-success-subtle text-success" style="font-size:.72rem">NNW</span></label>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" name="oc" value="1" id="f_oc" class="form-check-input" <?= $ubez_oc?'checked':'' ?>>
                <label for="f_oc" class="form-check-label" style="font-size:.82rem;cursor:pointer"><span class="badge bg-info-subtle text-info" style="font-size:.72rem">OC</span></label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Data zawarcia</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="data_od" class="form-control" value="<?= h($data_od) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="data_do" class="form-control" value="<?= h($data_do) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <label class="adv-label">Data zakończenia</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">od</span>
                <input type="date" name="koniec_od" class="form-control" value="<?= h($koniec_od) ?>">
                <span class="input-group-text">do</span>
                <input type="date" name="koniec_do" class="form-control" value="<?= h($koniec_do) ?>">
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
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Zastosuj filtry</button>
            </div>
          </div>
        </div>
      </div>

    </form>
  </div>
</div>

<!-- ── Aktywne filtry zaawansowane chips ──────────────────────────────────── -->
<?php
$_base_url = APP_URL . "/contracts/{$TYPE}/list.php";
$_chip_qs  = fn($without) => $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['page'=>null]), fn($v,$k) => $k !== $without, ARRAY_FILTER_USE_BOTH));
if ($adv_count):
?>
<div class="active-chips mb-2">
  <?php if ($forma): ?><a href="<?= $_chip_qs('forma') ?>" class="active-chip">Forma: <?= h(['papierowa'=>'Papierowa','elektroniczna'=>'Elektroniczna','kwalifikowany'=>'Kwalifikowany'][$forma] ?? $forma) ?> <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($ubez_nnw): ?><a href="<?= $_chip_qs('nnw') ?>" class="active-chip">Ubezp. NNW <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($ubez_oc): ?><a href="<?= $_chip_qs('oc') ?>" class="active-chip">Ubezp. OC <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($niepelnoletni): ?><a href="<?= $_chip_qs('niep') ?>" class="active-chip">Niepełnoletni <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($bezterminowa): ?><a href="<?= $_chip_qs('bezterm') ?>" class="active-chip">Bezterminowa <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($uwagi_only): ?><a href="<?= $_chip_qs('uwagi') ?>" class="active-chip">Z uwagami (do uzupełnienia) <span class="chip-x">×</span></a><?php endif; ?>
  <?php if ($data_od || $data_do): ?>
  <a href="<?= $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['data_od'=>null,'data_do'=>null,'page'=>null]))) ?>" class="active-chip">
    Zawarcie: <?= $data_od ?: '…' ?> – <?= $data_do ?: '…' ?> <span class="chip-x">×</span>
  </a>
  <?php endif; ?>
  <?php if ($koniec_od || $koniec_do): ?>
  <a href="<?= $_base_url . '?' . http_build_query(array_filter(array_merge($_qs_base, ['koniec_od'=>null,'koniec_do'=>null,'page'=>null]))) ?>" class="active-chip">
    Zakończenie: <?= $koniec_od ?: '…' ?> – <?= $koniec_do ?: '…' ?> <span class="chip-x">×</span>
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Wyniki ─────────────────────────────────────────────────────────────── -->
<?php if (!$rows): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5">
    <i class="bi bi-heart display-4 text-secondary opacity-25 d-block mb-3"></i>
    <h5 class="text-muted">Brak porozumień wolontariackich</h5>
    <p class="text-muted small mb-3">
      <?= $search || $status || $opiekun || $projekt || $adv_count ? 'Spróbuj zmienić kryteria filtrowania.' : 'Nie dodano jeszcze żadnych porozumień.' ?>
    </p>
    <?php if (!$search && !$status && !$opiekun && !$projekt && !$adv_count && can_edit()): ?>
    <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/new.php" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Dodaj pierwsze porozumienie
    </a>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($view_mode === 'cards'): ?>
<!-- WIDOK KART -->
<div class="row g-3 mb-3">
  <?php foreach ($rows as $r):
    $sc = $status_cfg[$r['status']] ?? ['label'=>$r['status'],'color'=>'#6B7280','bg'=>'#F3F4F6','icon'=>'bi-circle'];
    $bezterm = !empty($r['bezterminowa']);
  ?>
  <div class="col-sm-6 col-xl-4">
    <div class="wol-card h-100">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="wol-card-num"><?= h($r['numer_umowy']) ?></div>
        <span class="wol-status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
          <i class="bi <?= $sc['icon'] ?>"></i><?= $sc['label'] ?>
        </span>
      </div>
      <?= needs_correction_badge($r) ?>
      <div class="wol-card-name"><?= h($r['imie_nazwisko']) ?></div>
      <div class="wol-card-meta mb-2">
        <?php if ($r['miejsce_wolontariatu']): ?>
        <i class="bi bi-geo-alt me-1"></i><?= h($r['miejsce_wolontariatu']) ?>
        <?php endif; ?>
      </div>
      <div class="d-flex justify-content-between align-items-end">
        <div class="text-muted" style="font-size:.75rem">
          <?php if ($r['data_zawarcia']): ?>
          <i class="bi bi-calendar3 me-1"></i><?= date_pl($r['data_zawarcia']) ?>
          <?php endif; ?>
          <?php if ($r['opiekun']): ?>
          <br><i class="bi bi-person me-1"></i><?= h($r['opiekun']) ?>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-1">
          <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= $r['id'] ?>"
             class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i>
          </a>
          <?php if (can_edit()): ?>
          <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= $r['id'] ?>"
             class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-pencil"></i>
          </a>
          <?php endif; ?>
          <?php if (can_edit() && $r['status'] === 'podpisana'): ?>
          <button type="button" class="btn btn-sm btn-outline-info" title="Rozpocznij realizację"
                  onclick="wolQuickStatus(<?= (int)$r['id'] ?>,'w realizacji','Oznaczyć umowę jako „w realizacji"?')">
            <i class="bi bi-play-fill"></i>
          </button>
          <button type="button" class="btn btn-sm btn-outline-danger" title="Zrezygnował z podpisu → anulowana"
                  onclick="wolQuickStatus(<?= (int)$r['id'] ?>,'anulowana','Zrezygnował z podpisu — anulować umowę?')">
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
<div class="card shadow-sm mb-3">
  <div class="table-responsive">
    <table class="table wol-table mb-0">
      <thead>
        <tr>
          <th style="width:2%" class="ps-3">
            <input type="checkbox" id="cb-all" class="form-check-input" title="Zaznacz wszystkie">
          </th>
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
        <tr data-row-href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= $r['id'] ?>">
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
            <span class="wol-status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
              <i class="bi <?= $sc['icon'] ?>"></i><?= $sc['label'] ?>
            </span>
            <?= needs_correction_badge($r) ?>
          </td>
          <td class="text-end" style="white-space:nowrap">
            <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/view.php?id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2" title="Podgląd">
              <i class="bi bi-eye"></i>
            </a>
            <?php if (can_edit()): ?>
            <a href="<?= APP_URL ?>/contracts/<?= $TYPE ?>/edit.php?id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj">
              <i class="bi bi-pencil"></i>
            </a>
            <?php endif; ?>
            <?php if (can_edit() && $r['status'] === 'podpisana'): ?>
            <button type="button" class="btn btn-sm btn-outline-info py-0 px-2" title="Rozpocznij realizację"
                    onclick="event.stopPropagation();wolQuickStatus(<?= (int)$r['id'] ?>,'w realizacji','Oznaczyć umowę jako „w realizacji"?')">
              <i class="bi bi-play-fill"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Zrezygnował z podpisu → anulowana"
                    onclick="event.stopPropagation();wolQuickStatus(<?= (int)$r['id'] ?>,'anulowana','Zrezygnował z podpisu — anulować umowę?')">
              <i class="bi bi-x-circle"></i>
            </button>
            <?php endif; ?>
            <?php if (is_admin() || (can_edit() && (int)($r['created_by'] ?? 0) === (int)current_user()['id'])): ?>
            <form method="post" action="<?= APP_URL ?>/contracts/delete.php" class="d-inline"
                  onsubmit="return confirm('Usunąć umowę <?= h(addslashes($r['numer_umowy'] ?? '#'.$r['id'])) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="type"  value="<?= $TYPE ?>">
              <input type="hidden" name="id"    value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php include dirname(__DIR__) . '/includes/bulk_bar.php'; ?>

  <!-- Stopka z paginacją -->
  <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
    <small class="text-muted">
      Znaleziono: <strong><?= $total ?></strong>
      <?= $search || $status || $opiekun || $projekt || $adv_count ? '— filtrowanie aktywne' : '' ?>
    </small>
    <?php if ($pag['pages'] > 1): ?>
    <?= pagination_html($pag) ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
/* Szybka zmiana statusu na liście — podpisana → w realizacji / anulowana */
function wolQuickStatus(id, status, confirmMsg) {
  if (confirmMsg && !confirm(confirmMsg)) return;
  csrfFetch('<?= APP_URL ?>/api/ajax.php', { action: 'set_status', id: id, type: 'wolontariat', value: status })
    .then(function (res) {
      if (res && res.ok) {
        ajaxToast(res.msg || 'Status zmieniony', 'success');
        setTimeout(function () { location.reload(); }, 650);
      } else {
        ajaxToast((res && res.msg) ? res.msg : 'Nie udało się zmienić statusu', 'error');
      }
    })
    .catch(function () { ajaxToast('Błąd połączenia', 'error'); });
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
