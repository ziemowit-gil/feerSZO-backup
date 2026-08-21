<?php
/**
 * Wyszukiwarka EZD — szuka po znaku koszulki, sygnaturoach, tytułach,
 * nadawcach, odbiorcach, treści pism (opcja), załącznikach, RPW, zaświadczeniach.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/includes/zaswiadczenia_ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$user_id = (int)current_user()['id'];
$q       = trim($_GET['q'] ?? '');
$like    = '%' . $q . '%';
$rok     = (int)($_GET['rok'] ?? 0);
$tresc   = !empty($_GET['tresc']);
$scopes  = $_GET['scope'] ?? [];
if (!is_array($scopes)) $scopes = [];

// Wszystkie dostępne typy wyników
$ALL_SCOPES = ['teczki', 'sprawy', 'pisma', 'dokumenty', 'umowy', 'rpw', 'rpwy', 'zalaczniki', 'zaswiadczenia'];
$active_scopes = $scopes ? array_intersect($scopes, $ALL_SCOPES) : $ALL_SCOPES;

$PAGE_TITLE = 'Szukaj w EZD' . ($q !== '' ? ' — ' . $q : '');

/** Podświetla $q (case-insensitive) w tekście. */
function ezd_hl(string $text, string $q): string {
    $esc = h($text);
    if ($q === '' || mb_strlen($q) < 2) return $esc;
    $pat = '/' . preg_quote(h($q), '/') . '/ui';
    return preg_replace($pat, '<mark class="px-0 py-0" style="background:#fef08a">$0</mark>', $esc);
}

$teczki = $sprawy = $pisma = $dokumenty = $umowy = $rpw = $rpwy = $zalaczniki = $zaswiadczenia = [];

if (mb_strlen($q) >= 2) {

    $rok_cond_t  = $rok ? "AND t.rok=$rok"             : '';
    $rok_cond_s  = $rok ? "AND strftime('%Y',s.created_at)='$rok'" : '';
    $rok_cond_p  = $rok ? "AND strftime('%Y',p.created_at)='$rok'" : '';
    $rok_cond_r  = $rok ? "AND r.rok=$rok"             : '';
    $rok_cond_w  = $rok ? "AND w.rok=$rok"             : '';

    // Dostęp do koszulki per-wiersz (cache)
    $access_cache = [];
    $rows_ok = function (array $rows) use ($user_id, &$access_cache): array {
        return array_values(array_filter($rows, function ($r) use ($user_id, &$access_cache) {
            $sid = (int)$r['sprawa_id'];
            if (!isset($access_cache[$sid])) {
                $s = ezd_sprawa_get($sid);
                $access_cache[$sid] = $s ? (ezd_sprawa_access($s, $user_id) !== null) : false;
            }
            return $access_cache[$sid];
        }));
    };

    if (in_array('teczki', $active_scopes, true)) {
        $teczki = db_all(
            "SELECT t.*, j.symbol AS jrwa_symbol
             FROM ezd_teczki t
             LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
             WHERE (t.symbol LIKE ? OR t.title LIKE ?) $rok_cond_t
             ORDER BY t.rok DESC, t.symbol LIMIT 60",
            [$like, $like]
        );
    }

    if (in_array('sprawy', $active_scopes, true)) {
        $sprawy = array_values(array_filter(
            db_all(
                "SELECT s.*, t.symbol AS teczka_symbol, u.name AS owner_name
                 FROM ezd_sprawy s
                 JOIN ezd_teczki t ON t.id=s.teczka_id
                 LEFT JOIN users u ON u.id=s.owner_id
                 WHERE (s.znak_sprawy LIKE ? OR s.title LIKE ? OR s.description LIKE ?) $rok_cond_s
                 ORDER BY s.updated_at DESC LIMIT 100",
                [$like, $like, $like]
            ),
            fn($s) => ezd_sprawa_access($s, $user_id) !== null
        ));
    }

    if (in_array('pisma', $active_scopes, true)) {
        $pismo_tresc_cond = $tresc ? ' OR p.tresc LIKE ?' : '';
        $pismo_params     = $tresc
            ? [$like, $like, $like, $like, $like]
            : [$like, $like, $like, $like];
        $pisma = $rows_ok(db_all(
            "SELECT p.id, p.sprawa_id, p.sygnatura, p.title, p.status, p.kierunek,
                    p.nadawca, p.odbiorca, p.data_pisma, s.znak_sprawy
             FROM ezd_pisma p JOIN ezd_sprawy s ON s.id=p.sprawa_id
             WHERE (p.sygnatura LIKE ? OR p.title LIKE ? OR p.nadawca LIKE ? OR p.odbiorca LIKE ?$pismo_tresc_cond) $rok_cond_p
             ORDER BY p.updated_at DESC LIMIT 100",
            $pismo_params
        ));
    }

    if (in_array('dokumenty', $active_scopes, true)) {
        $dokumenty = $rows_ok(db_all(
            "SELECT d.id, d.sprawa_id, d.sygnatura, d.title, d.status, s.znak_sprawy
             FROM ezd_dokumenty d JOIN ezd_sprawy s ON s.id=d.sprawa_id
             WHERE d.sygnatura LIKE ? OR d.title LIKE ?
             ORDER BY d.updated_at DESC LIMIT 60",
            [$like, $like]
        ));
    }

    if (in_array('umowy', $active_scopes, true)) {
        $umowy = $rows_ok(db_all(
            "SELECT u.id, u.sprawa_id, u.sygnatura, u.title, u.status, u.strona, s.znak_sprawy
             FROM ezd_umowy u JOIN ezd_sprawy s ON s.id=u.sprawa_id
             WHERE u.sygnatura LIKE ? OR u.title LIKE ? OR u.strona LIKE ?
             ORDER BY u.updated_at DESC LIMIT 60",
            [$like, $like, $like]
        ));
    }

    if (in_array('rpw', $active_scopes, true)) {
        $rpw = db_all(
            "SELECT r.id, r.rpw_nr, r.rok, r.data_wplywu, r.typ, r.nadawca,
                    r.znak_obcy, r.opis, r.status, r.sprawa_id, s.znak_sprawy
             FROM ezd_rpw r
             LEFT JOIN ezd_sprawy s ON s.id=r.sprawa_id
             WHERE (r.nadawca LIKE ? OR r.opis LIKE ? OR r.znak_obcy LIKE ?) $rok_cond_r
             ORDER BY r.data_wplywu DESC LIMIT 60",
            [$like, $like, $like]
        );
    }

    if (in_array('rpwy', $active_scopes, true)) {
        $rpwy = db_all(
            "SELECT w.id, w.rpwy_nr, w.rok, w.data_wysylki, w.sposob, w.odbiorca, w.adres,
                    w.nr_nadania, w.status, w.sprawa_id, p.sygnatura AS pismo_sygnatura, s.znak_sprawy
             FROM ezd_rpwy w
             LEFT JOIN ezd_pisma  p ON p.id=w.pismo_id
             LEFT JOIN ezd_sprawy s ON s.id=w.sprawa_id
             WHERE (w.odbiorca LIKE ? OR w.adres LIKE ? OR w.nr_nadania LIKE ? OR w.ade LIKE ?) $rok_cond_w
             ORDER BY w.data_wysylki DESC LIMIT 60",
            [$like, $like, $like, $like]
        );
    }

    if (in_array('zalaczniki', $active_scopes, true)) {
        $zalaczniki = $rows_ok(db_all(
            "SELECT z.id, z.sprawa_id, z.pismo_id, z.original_name, z.file_size, z.uploaded_at,
                    s.znak_sprawy, s.title AS sprawa_title
             FROM ezd_zalaczniki z
             JOIN ezd_sprawy s ON s.id=z.sprawa_id
             WHERE z.original_name LIKE ?
             ORDER BY z.uploaded_at DESC LIMIT 50",
            [$like]
        ));
    }

    if (in_array('zaswiadczenia', $active_scopes, true)) {
        $zaswiadczenia = db_all(
            "SELECT w.id, w.nr_zaswiadczenia, w.wnioskodawca_name, w.status, w.created_at,
                    zt.nazwa AS typ_nazwa
             FROM ezd_zaswiadczenia_wlasne w
             JOIN ezd_zas_typy zt ON zt.id=w.typ_id
             WHERE w.nr_zaswiadczenia LIKE ? OR w.wnioskodawca_name LIKE ? OR zt.nazwa LIKE ?
             ORDER BY w.created_at DESC LIMIT 50",
            [$like, $like, $like]
        );
    }
}

$counts = [
    'teczki'        => count($teczki),
    'sprawy'        => count($sprawy),
    'pisma'         => count($pisma),
    'dokumenty'     => count($dokumenty),
    'umowy'         => count($umowy),
    'rpw'           => count($rpw),
    'rpwy'          => count($rpwy),
    'zalaczniki'    => count($zalaczniki),
    'zaswiadczenia' => count($zaswiadczenia),
];
$total = array_sum($counts);

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.srch-row{display:flex;align-items:center;gap:.75rem;padding:.55rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;font-size:.83rem;transition:background .1s;}
.srch-row:last-child{border-bottom:none;}
.srch-row:hover{background:#f8fafc;}
.srch-sign{font-family:monospace;font-size:.74rem;font-weight:700;color:#2563eb;white-space:nowrap;flex-shrink:0;}
.srch-meta{font-size:.71rem;color:#94a3b8;white-space:nowrap;flex-shrink:0;}
.scope-cb label{cursor:pointer;font-size:.78rem;}
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Szukaj</li>
</ol></nav>

<h4 class="fw-bold mb-3"><i class="bi bi-search text-primary me-2"></i>Wyszukiwanie w EZD</h4>

<form method="get" role="search" aria-label="Wyszukiwanie w EZD" class="mb-4">
  <div class="d-flex gap-2 flex-wrap align-items-start">
    <!-- Pole tekstowe -->
    <div class="flex-grow-1" style="min-width:280px;max-width:680px">
      <div class="input-group">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input type="search" name="q" class="form-control form-control-lg" value="<?= h($q) ?>"
               minlength="2" autofocus placeholder="Numer, znak, tytuł, nadawca…" autocomplete="off">
        <button type="submit" class="btn btn-primary px-4">Szukaj</button>
      </div>
    </div>
    <!-- Rok -->
    <div style="min-width:100px">
      <label class="form-label fw-semibold mb-1" style="font-size:.76rem">Rok</label>
      <select name="rok" class="form-select form-select-sm">
        <option value="">Wszystkie</option>
        <?php for($y=date('Y');$y>=2020;$y--): ?>
        <option value="<?= $y ?>" <?= $rok===$y?'selected':'' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>

  <!-- Filtry typów i opcje -->
  <div class="mt-3 d-flex flex-wrap gap-4 align-items-start">
    <div>
      <div class="fw-semibold mb-1" style="font-size:.76rem">Szukaj w:</div>
      <div class="d-flex flex-wrap gap-2 scope-cb">
        <?php
        $scope_labels = [
          'teczki'        => ['Segregatory',          'bi-archive'],
          'sprawy'        => ['Koszulki',              'bi-folder2-open'],
          'pisma'         => ['Pisma',                 'bi-envelope'],
          'dokumenty'     => ['Dokumenty wewn.',       'bi-file-earmark-text'],
          'umowy'         => ['Umowy',                 'bi-file-text'],
          'rpw'           => ['RPW',                   'bi-inbox-fill'],
          'rpwy'          => ['Książka nadawcza',      'bi-send'],
          'zalaczniki'    => ['Załączniki',             'bi-paperclip'],
          'zaswiadczenia' => ['Zaświadczenia',          'bi-award'],
        ];
        foreach ($scope_labels as $sv => [$sl, $si]):
            $checked = (!$scopes || in_array($sv, $scopes, true)) ? 'checked' : '';
        ?>
        <div class="form-check form-check-inline me-0">
          <input class="form-check-input" type="checkbox" name="scope[]" value="<?= $sv ?>" id="sc-<?= $sv ?>" <?= $checked ?>>
          <label class="form-check-label" for="sc-<?= $sv ?>"><i class="bi <?= $si ?> me-1"></i><?= h($sl) ?></label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <div class="fw-semibold mb-1" style="font-size:.76rem">Opcje:</div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="tresc" value="1" id="chk-tresc" <?= $tresc?'checked':'' ?>>
        <label class="form-check-label" for="chk-tresc" style="font-size:.78rem">Szukaj w treści pism <span class="badge bg-secondary" style="font-size:.62rem">wolniej</span></label>
      </div>
    </div>
  </div>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
<div class="alert alert-warning py-2">Wpisz co najmniej 2 znaki.</div>
<?php elseif ($q !== ''): ?>

<!-- Podsumowanie wyników -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <span class="text-muted" style="font-size:.85rem">Wyników dla „<strong><?= h($q) ?></strong>": <strong><?= $total ?></strong></span>
  <?php foreach($counts as $scope_key => $cnt): if(!$cnt) continue; ?>
  <span class="badge bg-light text-dark border" style="font-size:.72rem"><i class="bi <?= $scope_labels[$scope_key][1] ?> me-1"></i><?= $scope_labels[$scope_key][0] ?>: <?= $cnt ?></span>
  <?php endforeach; ?>
  <?php if($tresc): ?><span class="badge bg-info text-white" style="font-size:.66rem">treść pism</span><?php endif; ?>
</div>

<?php if (!$total): ?>
<div class="card shadow-sm"><div class="card-body text-center text-muted py-5">
  <i class="bi bi-search" style="font-size:3rem;display:block;margin-bottom:.5rem;opacity:.2"></i>
  Nic nie znaleziono. Spróbuj innego fragmentu lub zmień filtry typów.
</div></div>
<?php endif; ?>

<?php if ($teczki): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-archive text-primary"></i><span class="fw-semibold">Segregatory</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($teczki) ?></span>
  </div>
  <div>
  <?php foreach ($teczki as $t): ?>
  <a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= (int)$t['id'] ?>" class="srch-row">
    <span class="srch-sign"><?= ezd_hl($t['symbol'], $q) ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($t['title'], $q) ?></span>
    <span class="srch-meta"><?= h($t['rok']) ?></span>
    <?php if($t['jrwa_symbol']): ?><span class="srch-meta"><i class="bi bi-tag me-1"></i><?= h($t['jrwa_symbol']) ?></span><?php endif; ?>
    <span class="badge bg-<?= $t['status']==='open'?'success':'secondary' ?>" style="font-size:.64rem"><?= $t['status']==='open'?'Otwarty':'Zamknięty' ?></span>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($sprawy): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-folder2-open text-primary"></i><span class="fw-semibold">Koszulki</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($sprawy) ?></span>
  </div>
  <div>
  <?php foreach ($sprawy as $s): ?>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$s['id'] ?>" class="srch-row">
    <span class="srch-sign"><?= ezd_hl($s['znak_sprawy'], $q) ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($s['title'], $q) ?></span>
    <?php if($s['owner_name']): ?><span class="srch-meta"><i class="bi bi-person me-1"></i><?= h($s['owner_name']) ?></span><?php endif; ?>
    <?= ezd_status_badge_sprawa($s['status']) ?>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($pisma): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-envelope text-primary"></i><span class="fw-semibold">Pisma</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($pisma) ?></span>
  </div>
  <div>
  <?php foreach ($pisma as $p): ?>
  <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= (int)$p['id'] ?>" class="srch-row">
    <i class="bi bi-<?= $p['kierunek']==='przychodzace'?'envelope-arrow-down text-info':'envelope-arrow-up text-success' ?>" style="font-size:.9rem;flex-shrink:0"></i>
    <span class="srch-sign"><?= ezd_hl($p['sygnatura'], $q) ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($p['title'], $q) ?></span>
    <?php if($p['nadawca']||$p['odbiorca']): ?>
    <span class="srch-meta text-truncate" style="max-width:180px"><?= ezd_hl($p['nadawca']?:$p['odbiorca'], $q) ?></span>
    <?php endif; ?>
    <span class="font-monospace srch-meta"><?= h($p['znak_sprawy']) ?></span>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($dokumenty): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-file-earmark-text text-primary"></i><span class="fw-semibold">Dokumenty wewnętrzne</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($dokumenty) ?></span>
  </div>
  <div>
  <?php foreach ($dokumenty as $r): ?>
  <a href="<?= APP_URL ?>/ezd/dokumenty/view.php?id=<?= (int)$r['id'] ?>" class="srch-row">
    <span class="srch-sign"><?= ezd_hl($r['sygnatura'], $q) ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($r['title'], $q) ?></span>
    <span class="badge bg-light text-dark border" style="font-size:.62rem"><?= h($r['status']) ?></span>
    <span class="font-monospace srch-meta"><?= h($r['znak_sprawy']) ?></span>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($umowy): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-file-text text-primary"></i><span class="fw-semibold">Umowy EZD</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($umowy) ?></span>
  </div>
  <div>
  <?php foreach ($umowy as $r): ?>
  <a href="<?= APP_URL ?>/ezd/umowy/view.php?id=<?= (int)$r['id'] ?>" class="srch-row">
    <span class="srch-sign"><?= ezd_hl($r['sygnatura'], $q) ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($r['title'], $q) ?></span>
    <?php if($r['strona']): ?><span class="srch-meta text-truncate" style="max-width:160px"><?= ezd_hl($r['strona'], $q) ?></span><?php endif; ?>
    <span class="badge bg-light text-dark border" style="font-size:.62rem"><?= h($r['status']) ?></span>
    <span class="font-monospace srch-meta"><?= h($r['znak_sprawy']) ?></span>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($rpw): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-inbox-fill text-primary"></i><span class="fw-semibold">RPW — Rejestr Przesyłek</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($rpw) ?></span>
  </div>
  <div>
  <?php foreach ($rpw as $r): ?>
  <a href="<?= APP_URL ?>/ezd/rpw/view.php?id=<?= (int)$r['id'] ?>" class="srch-row">
    <span class="srch-sign">RPW/<?= $r['rok'] ?>/<?= str_pad($r['rpw_nr'],4,'0',STR_PAD_LEFT) ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($r['nadawca'] ?: $r['opis'], $q) ?></span>
    <?php if($r['znak_obcy']): ?><span class="srch-meta"><?= ezd_hl($r['znak_obcy'], $q) ?></span><?php endif; ?>
    <span class="srch-meta"><?= h(date_pl($r['data_wplywu'])) ?></span>
    <?php if($r['znak_sprawy']): ?><span class="font-monospace srch-meta"><?= h($r['znak_sprawy']) ?></span><?php endif; ?>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($rpwy): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-send text-primary"></i><span class="fw-semibold">Książka nadawcza — przesyłki wychodzące</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($rpwy) ?></span>
  </div>
  <div>
  <?php foreach ($rpwy as $r): $sp = EZD_RPWY_SPOSOBY[$r['sposob']] ?? ['label'=>$r['sposob']]; ?>
  <a href="<?= APP_URL ?>/ezd/rpwy/view.php?id=<?= (int)$r['id'] ?>" class="srch-row">
    <span class="srch-sign">RPW-W/<?= $r['rok'] ?>/<?= str_pad($r['rpwy_nr'],4,'0',STR_PAD_LEFT) ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($r['odbiorca'] ?: '—', $q) ?></span>
    <span class="srch-meta"><?= h($sp['label']) ?></span>
    <?php if($r['nr_nadania']): ?><span class="font-monospace srch-meta"><?= ezd_hl($r['nr_nadania'], $q) ?></span><?php endif; ?>
    <span class="srch-meta"><?= h(date_pl($r['data_wysylki'])) ?></span>
    <?php if($r['pismo_sygnatura']): ?><span class="font-monospace srch-meta"><?= h($r['pismo_sygnatura']) ?></span><?php endif; ?>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($zalaczniki): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-paperclip text-primary"></i><span class="fw-semibold">Załączniki</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($zalaczniki) ?></span>
  </div>
  <div>
  <?php foreach ($zalaczniki as $z): ?>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$z['sprawa_id'] ?>#tab-akta" class="srch-row">
    <i class="bi bi-file-earmark text-muted" style="font-size:.9rem;flex-shrink:0"></i>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($z['original_name'], $q) ?></span>
    <span class="srch-meta"><?= h(number_format($z['file_size']/1024,0,',','.')).' KB' ?></span>
    <span class="font-monospace srch-meta"><?= h($z['znak_sprawy']) ?></span>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($zaswiadczenia): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
    <i class="bi bi-award text-primary"></i><span class="fw-semibold">Zaświadczenia własne</span>
    <span class="badge bg-primary ms-1" style="font-size:.66rem"><?= count($zaswiadczenia) ?></span>
  </div>
  <div>
  <?php foreach ($zaswiadczenia as $z): ?>
  <a href="<?= APP_URL ?>/ezd/zaswiadczenia/view.php?id=<?= (int)$z['id'] ?>" class="srch-row">
    <span class="srch-sign font-monospace"><?= $z['nr_zaswiadczenia'] ? ezd_hl($z['nr_zaswiadczenia'], $q) : '<span class="text-muted">wniosek</span>' ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= ezd_hl($z['wnioskodawca_name'], $q) ?></span>
    <span class="srch-meta"><?= ezd_hl($z['typ_nazwa'], $q) ?></span>
    <?= ezd_zas_status_badge($z['status']) ?>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
