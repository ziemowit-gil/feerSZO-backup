<?php
/**
 * Dziennik podawczy — Rejestr Przesyłek Wpływających (RPW) + koszulka.
 * Lista wszystkich zarejestrowanych przesyłek z filtrami.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$f = [
    'rok'    => $_GET['rok']    ?? (string)date('Y'),
    'status' => $_GET['status'] ?? '',
    'typ'    => $_GET['typ']    ?? '',
    'q'      => trim($_GET['q'] ?? ''),
];
$rows  = ezd_rpw_all($f);
$stats = ezd_rpw_stats();
$lata  = db_all("SELECT DISTINCT rok FROM ezd_rpw ORDER BY rok DESC");
$PAGE_TITLE = 'Dziennik podawczy';

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
  <li class="breadcrumb-item active">Dziennik podawczy</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-mailbox2 text-primary me-2"></i>Dziennik podawczy</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Rejestr Przesyłek Wpływających (RPW) — punkt kancelaryjny</div>
  </div>
  <?php if (can_edit()): ?>
  <a href="<?= APP_URL ?>/ezd/rpw/add.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Zarejestruj przesyłkę
  </a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="row g-3 mb-3">
  <?php $cards = [
    ['W koszulce (niezałatwione)', $stats['koszulka'], 'bi-inbox-fill', 'info', '?status=nowa&rok='],
    ['Nowe (nieprzydzielone)',     $stats['nowa'],     'bi-envelope-exclamation', 'warning', '?status=nowa&rok='],
    ['Wpłynęło dziś',              $stats['rpw_dzis'], 'bi-calendar-day', 'primary', ''],
    ['Wpłynęło w '.date('Y'),      $stats['rpw_rok'],  'bi-archive', 'secondary', ''],
  ]; foreach ($cards as [$lbl,$val,$icon,$col,$lnk]): ?>
  <div class="col-6 col-xl-3">
    <div class="d-flex align-items-center gap-3 bg-white border rounded-3 p-3" style="border-color:#e2e8f0!important">
      <div class="d-flex align-items-center justify-content-center rounded-3 bg-<?= $col ?> bg-opacity-10 flex-shrink-0" style="width:44px;height:44px">
        <i class="bi <?= $icon ?> text-<?= $col ?>" style="font-size:1.2rem"></i>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:700;line-height:1"><?= (int)$val ?></div>
        <div style="font-size:.72rem;color:#64748b"><?= h($lbl) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

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
        <?php foreach(EZD_RPW_STATUSES as $sv=>$si): ?>
        <option value="<?= $sv ?>" <?= $f['status']===$sv?'selected':'' ?>><?= h($si['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label mb-1" style="font-size:.72rem">Sposób doręczenia</label>
      <select name="typ" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Wszystkie</option>
        <?php foreach(EZD_RPW_TYPY as $tv=>$ti): ?>
        <option value="<?= $tv ?>" <?= $f['typ']===$tv?'selected':'' ?>><?= h($ti['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label mb-1" style="font-size:.72rem">Szukaj (opis, nadawca, znak)</label>
      <div class="input-group input-group-sm">
        <input type="text" name="q" class="form-control" value="<?= h($f['q']) ?>" placeholder="np. faktura, ZUS…">
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
          <th style="white-space:nowrap">Nr RPW</th>
          <th>Data wpływu</th>
          <th>Sposób</th>
          <th>Nadawca</th>
          <th>Opis / przedmiot</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $typ = EZD_RPW_TYPY[$r['typ']] ?? ['label'=>$r['typ'],'icon'=>'bi-envelope']; ?>
        <tr onclick="location='<?= APP_URL ?>/ezd/rpw/view.php?id=<?= $r['id'] ?>'" style="cursor:pointer">
          <td class="font-monospace fw-bold text-primary" style="white-space:nowrap"><?= h(ezd_rpw_label($r)) ?>
            <?php if($r['scan_file'] || $r['pismo_id']): ?><i class="bi bi-paperclip text-muted ms-1" title="Skan / dokument"></i><?php endif; ?>
          </td>
          <td class="text-nowrap"><?= date_pl($r['data_wplywu']) ?></td>
          <td class="text-nowrap"><i class="bi <?= $typ['icon'] ?> text-muted me-1"></i><span class="text-muted" style="font-size:.76rem"><?= h($typ['label']) ?></span></td>
          <td><?= h(mb_substr($r['nadawca'] ?: '—', 0, 35)) ?></td>
          <td><?= h(mb_substr($r['opis'] ?: '—', 0, 55)) ?>
            <?php if($r['znak_sprawy']): ?><br><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $r['sprawa_id'] ?>" onclick="event.stopPropagation()" class="font-monospace text-decoration-none" style="font-size:.72rem"><i class="bi bi-folder2-open me-1"></i><?= h($r['znak_sprawy']) ?></a><?php endif; ?>
          </td>
          <td><?= ezd_rpw_status_badge($r['status']) ?>
            <?php if($r['przekazano_name'] && $r['status']==='przekazana'): ?><div class="text-muted" style="font-size:.68rem"><?= h($r['przekazano_name']) ?></div><?php endif; ?>
          </td>
          <td class="text-end"><i class="bi bi-chevron-right text-muted"></i></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-5">
          <i class="bi bi-mailbox" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Brak przesyłek dla wybranych filtrów.
          <?php if(can_edit()): ?><br><a href="<?= APP_URL ?>/ezd/rpw/add.php">Zarejestruj pierwszą przesyłkę</a><?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
