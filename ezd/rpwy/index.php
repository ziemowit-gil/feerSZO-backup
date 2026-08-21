<?php
/**
 * Książka nadawcza — Rejestr Przesyłek Wychodzących (RPW-W).
 * Lista wszystkich nadanych i przygotowanych do nadania przesyłek.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$f = [
    'rok'    => $_GET['rok']    ?? (string)date('Y'),
    'status' => $_GET['status'] ?? '',
    'sposob' => $_GET['sposob'] ?? '',
    'q'      => trim($_GET['q'] ?? ''),
];
$rows  = ezd_rpwy_all($f);
$stats = ezd_rpwy_stats();
$lata  = db_all("SELECT DISTINCT rok FROM ezd_rpwy ORDER BY rok DESC");
$PAGE_TITLE = 'Książka nadawcza';

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Książka nadawcza</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-send text-primary me-2"></i>Książka nadawcza</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Rejestr Przesyłek Wychodzących (RPW-W) — nadania, potwierdzenia odbioru, zwroty</div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/ezd/rpwy/ksiazka.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-printer me-1"></i>Wydruk książki
    </a>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/rpwy/add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Zarejestruj wysyłkę
    </a>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="row g-3 mb-3">
  <?php $cards = [
    ['Do nadania',                $stats['do_nadania'], 'bi-box-seam',            'warning',   '?status=przygotowana&rok='],
    ['Oczekuje potwierdzenia',    $stats['oczek_zpo'],  'bi-hourglass-split',     'info',      '?status=nadana&rok='],
    ['Zwroty',                    $stats['zwroty'],     'bi-arrow-counterclockwise','danger',  '?status=zwrocona&rok='],
    ['Nadano w '.date('Y'),       $stats['rok'],        'bi-send-check',          'secondary', ''],
  ]; foreach ($cards as [$lbl,$val,$icon,$col,$lnk]): ?>
  <div class="col-6 col-xl-3">
    <?php if ($lnk): ?><a href="<?= $lnk ?>" class="text-decoration-none text-reset"><?php endif; ?>
    <div class="d-flex align-items-center gap-3 bg-white border rounded-3 p-3 h-100" style="border-color:#e2e8f0!important">
      <div class="d-flex align-items-center justify-content-center rounded-3 bg-<?= $col ?> bg-opacity-10 flex-shrink-0" style="width:44px;height:44px">
        <i class="bi <?= $icon ?> text-<?= $col ?>" style="font-size:1.2rem"></i>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:700;line-height:1"><?= (int)$val ?></div>
        <div style="font-size:.72rem;color:#64748b"><?= h($lbl) ?></div>
      </div>
    </div>
    <?php if ($lnk): ?></a><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($stats['koszt_rok'] > 0): ?>
<div class="text-muted mb-3" style="font-size:.78rem">
  <i class="bi bi-cash-coin me-1"></i>Koszty wysyłki w <?= date('Y') ?> r.:
  <strong><?= number_format($stats['koszt_rok'], 2, ',', ' ') ?> zł</strong>
</div>
<?php endif; ?>

<!-- Filtry -->
<form method="get" class="card shadow-sm mb-3"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
      <label class="form-label mb-1" style="font-size:.72rem">Rok</label>
      <select name="rok" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Wszystkie</option>
        <?php $years = array_unique(array_merge([date('Y')], array_column($lata,'rok'))); foreach($years as $y): ?>
        <option value="<?= $y ?>" <?= (string)$f['rok']===(string)$y?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label mb-1" style="font-size:.72rem">Status</label>
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Wszystkie</option>
        <?php foreach(EZD_RPWY_STATUSY as $sv=>$si): ?>
        <option value="<?= $sv ?>" <?= $f['status']===$sv?'selected':'' ?>><?= h($si['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label mb-1" style="font-size:.72rem">Sposób wysyłki</label>
      <select name="sposob" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Wszystkie</option>
        <?php foreach(EZD_RPWY_SPOSOBY as $sv=>$si): ?>
        <option value="<?= $sv ?>" <?= $f['sposob']===$sv?'selected':'' ?>><?= h($si['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label mb-1" style="font-size:.72rem">Szukaj (odbiorca, nr nadania, pismo)</label>
      <div class="input-group input-group-sm">
        <input type="text" name="q" class="form-control" value="<?= h($f['q']) ?>" placeholder="np. ZUS, 00259…">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
      </div>
    </div>
  </div>
</div></form>

<!-- Tabela -->
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem">
      <thead class="table-light">
        <tr>
          <th style="white-space:nowrap">Nr RPW-W</th>
          <th>Data nadania</th>
          <th>Sposób</th>
          <th>Odbiorca</th>
          <th>Pismo</th>
          <th>Nr nadania</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $sp  = EZD_RPWY_SPOSOBY[$r['sposob']] ?? ['label'=>$r['sposob'],'icon'=>'bi-envelope'];
        $trm = ezd_rpwy_termin($r); ?>
        <tr onclick="location='<?= APP_URL ?>/ezd/rpwy/view.php?id=<?= $r['id'] ?>'" style="cursor:pointer<?= $r['status']==='anulowana'?';opacity:.55':'' ?>">
          <td class="font-monospace fw-bold text-primary" style="white-space:nowrap"><?= h(ezd_rpwy_label($r)) ?>
            <?php if($r['epo_file']): ?><i class="bi bi-paperclip text-muted ms-1" title="Dowód doręczenia"></i><?php endif; ?>
          </td>
          <td class="text-nowrap"><?= date_pl($r['data_wysylki']) ?></td>
          <td class="text-nowrap"><i class="bi <?= $sp['icon'] ?> text-muted me-1"></i><span class="text-muted" style="font-size:.76rem"><?= h($sp['label']) ?></span></td>
          <td><?= h(mb_substr($r['odbiorca'] ?: '—', 0, 32)) ?>
            <?php if($r['liczba_szt'] > 1): ?><span class="badge bg-light text-dark border ms-1" style="font-size:.62rem"><?= (int)$r['liczba_szt'] ?> szt.</span><?php endif; ?>
          </td>
          <td>
            <?php if($r['pismo_id']): ?>
            <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $r['pismo_id'] ?>" onclick="event.stopPropagation()" class="font-monospace text-decoration-none" style="font-size:.74rem"><?= h($r['pismo_sygnatura']) ?></a>
            <div class="text-muted" style="font-size:.7rem"><?= h(mb_substr($r['pismo_title'] ?? '', 0, 40)) ?></div>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="font-monospace" style="font-size:.74rem"><?= h($r['nr_nadania'] ?: '—') ?></td>
          <td><?= ezd_rpwy_status_badge($r['status']) ?>
            <?php if($r['data_doreczenia']): ?>
            <div class="text-muted" style="font-size:.68rem"><i class="bi bi-check2-circle me-1"></i><?= date_pl($r['data_doreczenia']) ?></div>
            <?php endif; ?>
            <?php if($trm): ?>
            <div class="<?= $trm['po_terminie'] ? 'text-danger fw-semibold' : 'text-muted' ?>" style="font-size:.68rem">
              <i class="bi bi-clock me-1"></i>termin <?= date_pl($trm['do']) ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="text-end"><i class="bi bi-chevron-right text-muted"></i></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">
          <i class="bi bi-send" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Brak przesyłek dla wybranych filtrów.
          <?php if(can_edit()): ?><br><a href="<?= APP_URL ?>/ezd/rpwy/add.php">Zarejestruj pierwszą wysyłkę</a><?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
