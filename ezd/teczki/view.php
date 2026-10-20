<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko'); ezd_require_access();
$id     = (int)($_GET['id']??0);
$teczka = ezd_teczka_get($id);
if (!$teczka) { flash_set('error','Segregator nie istnieje.'); header('Location:'.APP_URL.'/ezd/teczki/index.php'); exit; }

$user_id       = (int)current_user()['id'];
$can_mgr_share = ezd_is_manager() || (int)($teczka['owner_id'] ?? 0) === $user_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    if ($action === 'teczka_share_add' && $can_mgr_share) {
        $su_id  = (int)($_POST['share_user_id'] ?? 0);
        $su_upr = $_POST['share_uprawnienie'] ?? 'odczyt';
        if ($su_id) { ezd_teczka_share_add($id, $su_id, $su_upr, $user_id); flash_set('success','Dostęp nadany.'); }
    }
    if ($action === 'teczka_share_del' && $can_mgr_share) {
        ezd_teczka_share_del($id, (int)($_POST['share_user_id'] ?? 0), $user_id);
        flash_set('success','Dostęp odebrany.');
    }
    header('Location:'.APP_URL.'/ezd/teczki/view.php?id='.$id.'#access'); exit;
}

$shares   = $can_mgr_share ? ezd_teczka_share_list($id) : [];
$all_users = $can_mgr_share ? db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name") : [];
$sprawy   = ezd_sprawy_by_teczka($id);
$PAGE_TITLE = $teczka['symbol'].' — '.$teczka['title'];
$status_f = $_GET['status'] ?? '';
if ($status_f) $sprawy = array_filter($sprawy, fn($s)=>$s['status']===$status_f);
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.sprawa-row{display:flex;align-items:center;gap:.75rem;padding:.65rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .12s;}
.sprawa-row:last-child{border-bottom:none;}
.sprawa-row:hover{background:#f8fafc;}
.sprawa-znak{font-family:monospace;font-size:.8rem;font-weight:700;color:#2563eb;white-space:nowrap;}
</style>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/teczki/index.php">Segregatory</a></li>
  <li class="breadcrumb-item active"><?= h($teczka['symbol']) ?></li>
</ol></nav>

<?= flash_html() ?>

<!-- ══ Segregator aktowy — układ Dynamics 365 (assets/css/dynamics.css, includes/dynRecord.php; kolory EZD w ezd_metro.php) ══ -->
<?php
require_once dirname(__DIR__, 2) . '/includes/dynRecord.php';
$_tk_fields = [
    ['Status', $teczka['status'] === 'open' ? 'Otwarty' : 'Zamknięty', $teczka['status'] === 'open' ? 'var(--m-green)' : ''],
    ['Rok', (string)$teczka['rok']],
];
if ($teczka['jrwa_title']) $_tk_fields[] = ['Wykaz akt', ['html' => '<span title="' . h($teczka['jrwa_title']) . '">' . h($teczka['jrwa_symbol']) . '</span>']];
if ($teczka['kat_arch']) $_tk_fields[] = ['Kat. archiwalna', (string)$teczka['kat_arch']];
if (!empty($teczka['arch_status'])) $_tk_fields[] = ['Archiwum', ['html' => ezd_arch_teczka_badge($teczka['arch_status']) . (!empty($teczka['rok_brakowania']) ? ' <span class="text-muted" style="font-size:.78rem">brak. ' . (int)$teczka['rok_brakowania'] . '</span>' : '')]];
$_tk_fields[] = ['Prowadzący', (string)($teczka['owner_name'] ?? '')];
?>
<div class="dyn-cmdbar" role="toolbar" aria-label="Polecenia segregatora">
  <a href="<?= APP_URL ?>/ezd/teczki/index.php" class="btn btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i>Segregatory</a>
  <span class="dyn-sep" aria-hidden="true"></span>
  <?php if(can_edit()): ?>
  <a href="<?= APP_URL ?>/ezd/sprawy/add.php?teczka_id=<?= $id ?>" class="btn btn-sm"><i class="bi bi-folder-plus" aria-hidden="true"></i>Nowa koszulka</a>
  <?php endif; ?>
  <a href="<?= APP_URL ?>/ezd/teczki/spis.php?id=<?= $id ?>" class="btn btn-sm"><i class="bi bi-list-ol" aria-hidden="true"></i>Spis koszulek</a>
  <?php if(is_admin()): ?>
  <a href="<?= APP_URL ?>/ezd/teczki/edit.php?id=<?= $id ?>" class="btn btn-sm"><i class="bi bi-pencil" aria-hidden="true"></i>Edytuj</a>
  <?php endif; ?>
</div>

<section class="dyn-rec" aria-labelledby="tk-title">
  <div class="dyn-head">
    <div class="dyn-head__main">
      <div class="dyn-entity"><i class="bi bi-archive" aria-hidden="true"></i> Segregator aktowy · <code><?= h($teczka['symbol']) ?></code></div>
      <h1 id="tk-title" class="dyn-title"><?= h($teczka['title']) ?></h1>
      <?php if($teczka['jrwa_title']): ?><div class="text-muted mt-1" style="font-size:.85rem"><i class="bi bi-tag me-1" aria-hidden="true"></i><?= h($teczka['jrwa_symbol'] . ' — ' . $teczka['jrwa_title']) ?></div><?php endif; ?>
    </div>
    <?= dynHFields($_tk_fields) ?>
  </div>
</section>

<!-- Filtry statusu spraw -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach ([''=>'Wszystkie']+array_map(fn($s)=>$s['label'], EZD_STATUSES_SPRAWA) as $sv=>$sl): ?>
  <a href="?id=<?= $id ?>&status=<?= $sv ?>" class="btn btn-sm <?= $status_f===$sv?'btn-primary':'btn-outline-secondary' ?>"><?= h($sl) ?></a>
  <?php endforeach; ?>
</div>

<!-- Lista spraw -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-folder2-open me-1 text-primary"></i>Koszulki (<?= count($sprawy) ?>)</span>
  </div>
  <div>
    <?php foreach ($sprawy as $s): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>" class="sprawa-row">
      <div class="sprawa-znak"><?= h($s['znak_sprawy']) ?></div>
      <div class="flex-grow-1 overflow-hidden">
        <div class="fw-semibold text-truncate" style="font-size:.88rem"><?= h($s['title']) ?></div>
        <?php if($s['description']): ?>
        <div class="text-muted text-truncate" style="font-size:.74rem"><?= h($s['description']) ?></div>
        <?php endif; ?>
      </div>
      <?= ezd_priority_badge($s['priority']) ?>
      <?= ezd_status_badge_sprawa($s['status']) ?>
      <div class="text-muted" style="font-size:.73rem;white-space:nowrap">
        <?php if($s['deadline']): ?><span class="<?= $s['deadline']<date('Y-m-d')?'text-danger fw-bold':'' ?>"><i class="bi bi-calendar-event me-1"></i><?= date_pl($s['deadline']) ?></span><?php endif; ?>
      </div>
      <div class="text-muted" style="font-size:.73rem;white-space:nowrap"><?= h($s['owner_name']??'—') ?></div>
      <i class="bi bi-chevron-right text-muted" style="font-size:.75rem"></i>
    </a>
    <?php endforeach; ?>
    <?php if (!$sprawy): ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-folder2" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
      Brak koszulek w tym segregatorze.
      <?php if(can_edit()&&$teczka['status']==='open'): ?><br><a href="<?= APP_URL ?>/ezd/sprawy/add.php?teczka_id=<?= $id ?>">Załóż pierwszą koszulkę.</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if($can_mgr_share): ?>
<div class="card shadow-sm mt-4" id="access">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-person-lock me-1 text-secondary"></i>Dostęp do segregatora</span>
  </div>
  <div class="card-body">
    <p class="text-muted mb-3" style="font-size:.81rem">
      Osoby wymienione poniżej widzą <strong>wszystkie koszulki</strong> tego segregatora niezależnie od współdzielenia per-koszulka.
    </p>
    <?php if($shares): ?>
    <ul class="list-group list-group-flush mb-3">
      <?php foreach($shares as $sh): ?>
      <li class="list-group-item d-flex align-items-center gap-2 px-0" style="font-size:.84rem">
        <i class="bi bi-person-circle text-muted"></i>
        <div class="flex-grow-1">
          <div class="fw-semibold"><?= h($sh['user_name']) ?></div>
          <div class="text-muted" style="font-size:.72rem"><?= h(EZD_TECZKA_UPRAWNIENIA[$sh['uprawnienie']] ?? $sh['uprawnienie']) ?></div>
        </div>
        <form method="post" onsubmit="return confirm('Odebrać dostęp?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="teczka_share_del">
          <input type="hidden" name="share_user_id" value="<?= (int)$sh['user_id'] ?>">
          <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="text-muted" style="font-size:.82rem">Brak dodatkowego dostępu per-segregator.</p>
    <?php endif; ?>
    <form method="post" class="d-flex gap-2 flex-wrap align-items-end border-top pt-3">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="teczka_share_add">
      <div>
        <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Osoba</label>
        <select name="share_user_id" class="form-select form-select-sm" required style="min-width:200px">
          <option value="">— wybierz osobę —</option>
          <?php foreach($all_users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Uprawnienie</label>
        <select name="share_uprawnienie" class="form-select form-select-sm">
          <?php foreach(EZD_TECZKA_UPRAWNIENIA as $v=>$l): ?><option value="<?= $v ?>"><?= h($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i>Nadaj dostęp</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
