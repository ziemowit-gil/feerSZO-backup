<?php
/**
 * Rejestr pełnomocnictw — sprawy prowadzone w JRWA „013" (Pełnomocnictwa i upoważnienia)
 * wraz z metadanymi: mocodawca, pełnomocnik, zakres, daty, status.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { http_response_code(403); exit; }
    if (($_POST['_action'] ?? '') === 'save') {
        try {
            ezd_pelnomocnictwo_save((int)($_POST['sprawa_id'] ?? 0), $_POST, $user_id);
            flash_set('success', 'Dane pełnomocnictwa zapisane.');
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
    }
    header('Location:'.APP_URL.'/ezd/pelnomocnictwa/index.php'); exit;
}

$f = ['status' => $_GET['status'] ?? '', 'q' => trim($_GET['q'] ?? '')];
$rows  = ezd_pelnomocnictwa_all($f);
$stats = ezd_peln_stats();
$jrwa  = ezd_peln_jrwa();
$edit  = !empty($_GET['edit']) ? ezd_pelnomocnictwo_get((int)$_GET['edit']) : null;
$new_teczka = ezd_peln_teczka_id();
$PAGE_TITLE = 'Rejestr pełnomocnictw';

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Rejestr pełnomocnictw</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-person-vcard text-primary me-2"></i>Rejestr pełnomocnictw</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Sprawy prowadzone w JRWA <span class="font-monospace fw-semibold"><?= h($jrwa) ?></span> — Pełnomocnictwa i upoważnienia</div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/ezd/pelnomocnictwa/print.php" class="btn btn-outline-secondary btn-sm" target="_blank"><i class="bi bi-printer me-1"></i>Drukuj rejestr</a>
    <?php if (can_edit()): ?>
      <?php if ($new_teczka): ?>
      <a href="<?= APP_URL ?>/ezd/sprawy/add.php?teczka_id=<?= $new_teczka ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowe pełnomocnictwo</a>
      <?php else: ?>
      <a href="<?= APP_URL ?>/ezd/teczki/add.php" class="btn btn-outline-primary btn-sm" title="Najpierw załóż segregator w JRWA <?= h($jrwa) ?>"><i class="bi bi-archive me-1"></i>Załóż segregator <?= h($jrwa) ?></a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="row g-3 mb-3">
  <?php foreach ([
    ['Wszystkie', $stats['total'], 'bi-person-vcard', 'primary', ''],
    ['Ważne', $stats['wazne'], 'bi-check-circle', 'success', 'wazne'],
    ['Wygasłe', $stats['wygasle'], 'bi-clock-history', 'secondary', 'wygasle'],
    ['Odwołane', $stats['odwolane'], 'bi-x-circle', 'danger', 'odwolane'],
  ] as [$lbl,$val,$icon,$col,$sf]): ?>
  <div class="col-6 col-xl-3">
    <a href="?status=<?= $sf ?>" class="text-decoration-none text-reset">
      <div class="d-flex align-items-center gap-3 bg-white border rounded-3 p-3 <?= $f['status']===$sf && $sf!==''?'border-'.$col:'' ?>" style="border-color:#e2e8f0">
        <div class="d-flex align-items-center justify-content-center rounded-3 bg-<?= $col ?> bg-opacity-10 flex-shrink-0" style="width:44px;height:44px">
          <i class="bi <?= $icon ?> text-<?= $col ?>" style="font-size:1.2rem"></i>
        </div>
        <div><div style="font-size:1.5rem;font-weight:700;line-height:1"><?= (int)$val ?></div>
          <div style="font-size:.72rem;color:#64748b"><?= h($lbl) ?></div></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if (can_edit() && $edit): ?>
<!-- Formularz edycji metadanych pełnomocnictwa -->
<div class="card shadow-sm mb-3 border-primary" id="edit-form">
  <div class="card-header bg-primary bg-opacity-10 py-2"><span class="fw-semibold text-primary" style="font-size:.84rem">
    <i class="bi bi-pencil me-1"></i>Pełnomocnictwo: <span class="font-monospace"><?= h($edit['znak_sprawy']) ?></span> — <?= h($edit['title']) ?>
  </span></div>
  <div class="card-body">
    <form method="post" class="row g-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="sprawa_id" value="<?= (int)$edit['id'] ?>">
      <div class="col-md-3"><label class="form-label mb-1" style="font-size:.74rem">Numer (opcjonalnie)</label>
        <input type="text" name="numer" class="form-control form-control-sm" value="<?= h($edit['numer']) ?>" placeholder="np. 5/2026"></div>
      <div class="col-md-4"><label class="form-label mb-1" style="font-size:.74rem">Mocodawca</label>
        <input type="text" name="mocodawca" class="form-control form-control-sm" value="<?= h($edit['mocodawca']) ?>" placeholder="Kto udziela pełnomocnictwa"></div>
      <div class="col-md-5"><label class="form-label mb-1" style="font-size:.74rem">Pełnomocnik</label>
        <input type="text" name="pelnomocnik" class="form-control form-control-sm" value="<?= h($edit['pelnomocnik']) ?>" placeholder="Komu udzielono"></div>
      <div class="col-12"><label class="form-label mb-1" style="font-size:.74rem">Zakres pełnomocnictwa</label>
        <input type="text" name="zakres" class="form-control form-control-sm" value="<?= h($edit['zakres']) ?>" placeholder="Czego dotyczy / do jakich czynności"></div>
      <div class="col-md-4"><label class="form-label mb-1" style="font-size:.74rem">Data udzielenia</label>
        <input type="date" name="data_udzielenia" class="form-control form-control-sm" value="<?= h($edit['data_udzielenia']) ?>"></div>
      <div class="col-md-4"><label class="form-label mb-1" style="font-size:.74rem">Ważne do <span class="text-muted">(puste = bezterminowe)</span></label>
        <input type="date" name="data_waznosci" class="form-control form-control-sm" value="<?= h($edit['data_waznosci']) ?>"></div>
      <div class="col-md-4"><label class="form-label mb-1" style="font-size:.74rem">Data odwołania</label>
        <input type="date" name="data_odwolania" class="form-control form-control-sm" value="<?= h($edit['data_odwolania']) ?>"></div>
      <div class="col-12"><label class="form-label mb-1" style="font-size:.74rem">Uwagi</label>
        <input type="text" name="uwagi" class="form-control form-control-sm" value="<?= h($edit['peln_uwagi']) ?>"></div>
      <div class="col-12 d-flex gap-2 mt-1">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
        <a href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php" class="btn btn-outline-secondary btn-sm">Anuluj</a>
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$edit['id'] ?>" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-folder2-open me-1"></i>Otwórz sprawę</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Filtry -->
<form method="get" class="card shadow-sm mb-3"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label mb-1" style="font-size:.72rem">Status</label>
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Wszystkie</option>
        <?php foreach(['wazne'=>'Ważne','wygasle'=>'Wygasłe','odwolane'=>'Odwołane'] as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $f['status']===$sv?'selected':'' ?>><?= $sl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-8">
      <label class="form-label mb-1" style="font-size:.72rem">Szukaj (mocodawca, pełnomocnik, znak, numer)</label>
      <div class="input-group input-group-sm">
        <input type="text" name="q" class="form-control" value="<?= h($f['q']) ?>" placeholder="np. Kowalski">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
      </div>
    </div>
  </div>
</div></form>

<!-- Rejestr -->
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem">
      <thead class="table-light">
        <tr>
          <th style="width:40px">Lp.</th>
          <th>Znak sprawy</th>
          <th>Mocodawca</th>
          <th>Pełnomocnik</th>
          <th>Zakres</th>
          <th class="text-nowrap">Udzielono</th>
          <th class="text-nowrap">Ważne do</th>
          <th>Status</th>
          <?php if(can_edit()): ?><th style="width:40px"></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php $lp=0; foreach ($rows as $r): $lp++; $st=$r['_status']; ?>
        <tr>
          <td class="text-muted"><?= $lp ?></td>
          <td class="text-nowrap">
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $r['sprawa_id'] ?>" class="font-monospace fw-semibold text-decoration-none"><?= h($r['znak_sprawy']) ?></a>
            <?php if($r['numer']): ?><div class="text-muted" style="font-size:.68rem">nr <?= h($r['numer']) ?></div><?php endif; ?>
          </td>
          <td><?= h($r['mocodawca'] ?: '—') ?></td>
          <td><?= h($r['pelnomocnik'] ?: '—') ?></td>
          <td><?= h(mb_substr($r['zakres'] ?: '—', 0, 50)) ?></td>
          <td class="text-nowrap"><?= $r['data_udzielenia'] ? date_pl($r['data_udzielenia']) : '—' ?></td>
          <td class="text-nowrap"><?= $r['data_odwolania'] ? '<span class="text-danger">odwołane '.date_pl($r['data_odwolania']).'</span>' : ($r['data_waznosci'] ? date_pl($r['data_waznosci']) : 'bezterminowe') ?></td>
          <td><span class="badge bg-<?= $st['class'] ?> bg-opacity-15 text-<?= $st['class'] ?> border border-<?= $st['class'] ?>" style="font-size:.65rem"><?= $st['label'] ?></span></td>
          <?php if(can_edit()): ?>
          <td><a href="?edit=<?= $r['sprawa_id'] ?>#edit-form" class="btn btn-xs btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i></a></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <tr><td colspan="<?= can_edit()?9:8 ?>" class="text-center text-muted py-5">
          <i class="bi bi-person-vcard" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Brak pełnomocnictw<?= $f['status']||$f['q']?' dla wybranych filtrów':'' ?>.
          <?php if(can_edit() && !$f['status'] && !$f['q']): ?>
            <br><?php if($new_teczka): ?><a href="<?= APP_URL ?>/ezd/sprawy/add.php?teczka_id=<?= $new_teczka ?>">Dodaj pierwsze pełnomocnictwo</a>
            <?php else: ?>Załóż najpierw segregator w JRWA <?= h($jrwa) ?> (Pełnomocnictwa).<?php endif; ?>
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="text-muted mt-2" style="font-size:.74rem"><i class="bi bi-info-circle me-1"></i>Rejestr obejmuje wszystkie koszulki z segregatorów sklasyfikowanych w JRWA <?= h($jrwa) ?>. Status wyliczany automatycznie z dat ważności i odwołania.</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
