<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko');

$id    = (int)($_GET['id'] ?? 0);
$pismo = ezd_pismo_get($id);
if (!$pismo) { flash_set('error','Pismo nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }

$sprawa_id = (int)$pismo['sprawa_id'];
$_sprawa_pisma = ezd_sprawa_get($sprawa_id);
$_access = $_sprawa_pisma ? ezd_sprawa_access($_sprawa_pisma, (int)current_user()['id']) : null;
if (!$_access) { flash_set('error','Brak dostępu do tej sprawy.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }
$can_act = $_access === 'write';
$PAGE_TITLE = $pismo['sygnatura'];

// Integracja z korespondencją
$linked_corr = null;
$corr_enabled = module_enabled('correspondence_enabled');
if ($corr_enabled) {
    require_once dirname(dirname(__DIR__)) . '/includes/correspondence.php';
    $linked_corr = ezd_get_linked_corr($id);
}
$zal  = ezd_zalaczniki_by($sprawa_id, $id);
$user_id = (int)current_user()['id'];

// POST: upload, del_file
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'upload' && $can_act) {
        $err = ezd_upload('file', $sprawa_id, $user_id, $id);
        flash_set($err ? 'error' : 'success', $err ?? 'Plik dodany.');
    }
    if ($action === 'del_file' && $can_act) {
        $zid = (int)($_POST['zid'] ?? 0);
        ezd_zal_delete($zid, $user_id);
        flash_set('success','Plik usunięty.');
    }
    header('Location:'.APP_URL.'/ezd/pisma/view.php?id='.$id); exit;
}

$kier = EZD_KIERUNKI[$pismo['kierunek']] ?? ['label'=>$pismo['kierunek'],'icon'=>'bi-envelope','class'=>'secondary'];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.meta-dl dt{font-size:.7rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.1rem}
.meta-dl dd{font-size:.84rem;color:#1e293b;margin-bottom:.75rem}
.zal-row{display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.8rem}
.zal-row:last-child{border-bottom:none}
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($pismo['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active"><?= h($pismo['sygnatura']) ?></li>
</ol></nav>

<?= flash_html() ?>

<div class="row g-4">
  <!-- Lewa: treść + załączniki -->
  <div class="col-lg-8">
    <!-- Nagłówek pisma -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
          <div>
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="badge bg-<?= $kier['class'] ?> bg-opacity-15 text-<?= $kier['class'] ?> border border-<?= $kier['class'] ?>">
                <i class="bi <?= $kier['icon'] ?> me-1"></i><?= h($kier['label']) ?>
              </span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary border font-monospace" style="font-size:.72rem"><?= h($pismo['sygnatura']) ?></span>
              <span class="badge bg-light text-dark border" style="font-size:.68rem"><?= h($pismo['status']) ?></span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.68rem"><i class="bi <?= EZD_MEDIA[$pismo['rodzaj_medium']]['icon'] ?? 'bi-question-circle' ?> me-1"></i><?= h(EZD_MEDIA[$pismo['rodzaj_medium']]['label'] ?? $pismo['rodzaj_medium']) ?></span>
            </div>
            <h5 class="fw-bold mb-0"><?= h($pismo['title']) ?></h5>
          </div>
          <?php if($can_act && $pismo['sprawa_status'] !== 'closed'): ?>
          <a href="<?= APP_URL ?>/ezd/pisma/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edytuj</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Treść / notatka -->
    <?php if($pismo['tresc']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-body-text me-1 text-primary"></i>Treść / notatka</div>
      <div class="card-body" style="font-size:.88rem;white-space:pre-wrap"><?= h($pismo['tresc']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Załączniki -->
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-paperclip me-1 text-primary"></i>Załączniki (<?= count($zal) ?>)</span>
      </div>
      <?php if($zal): ?>
      <div>
        <?php foreach($zal as $z): ?>
        <div class="zal-row">
          <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" target="_blank" class="text-decoration-none fw-semibold text-truncate d-block" style="font-size:.82rem"><?= h($z['original_name']) ?></a>
            <div class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?> · v<?= $z['wersja'] ?> · <?= h($z['uploader']??'—') ?> · <?= date('d.m.Y H:i',strtotime($z['uploaded_at'])) ?></div>
          </div>
          <?php if (in_array(strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION)), EZD_OFFICE_ONLINE_EXT, true)): ?>
          <a href="<?= APP_URL ?>/ezd/office_online.php?id=<?= $z['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="Otwórz w Word Online"><i class="bi bi-microsoft"></i></a>
          <?php if (!empty($z['sp_web_url'])): ?>
          <button type="button" class="btn btn-sm btn-outline-success ezd-oop-btn" title="Zapisz zmiany z Office Online"
                  data-bs-toggle="modal" data-bs-target="#officeOnlinePullModal"
                  data-zal="<?= $z['id'] ?>" data-name="<?= h($z['original_name']) ?>"><i class="bi bi-cloud-arrow-down"></i></button>
          <?php endif; ?>
          <?php elseif (!empty($z['sp_web_url'])): ?>
          <a href="<?= h($z['sp_web_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Otwórz na SharePoint"><i class="bi bi-cloud-check"></i></a>
          <?php endif; ?>
          <?php if($can_act && $pismo['sprawa_status']!=='closed'): ?>
          <form method="post" onsubmit="return confirm('Usunąć plik?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="del_file">
            <input type="hidden" name="zid" value="<?= $z['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" title="Usuń"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if($can_act && $pismo['sprawa_status']!=='closed'): ?>
      <div class="card-footer">
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="upload">
          <input type="file" name="file" class="form-control form-control-sm" required>
          <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload me-1"></i>Dodaj</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Prawa: metadane -->
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-info-circle me-1 text-primary"></i>Metadane</div>
      <div class="card-body">
        <dl class="meta-dl mb-0">
          <dt>Sprawa</dt>
          <dd><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="font-monospace text-decoration-none"><?= h($pismo['znak_sprawy']) ?></a></dd>
          <?php if($pismo['nadawca']): ?>
          <dt>Nadawca</dt><dd><?= h($pismo['nadawca']) ?></dd>
          <?php endif; ?>
          <?php if($pismo['odbiorca']): ?>
          <dt>Odbiorca</dt><dd><?= h($pismo['odbiorca']) ?></dd>
          <?php endif; ?>
          <?php if($pismo['data_pisma']): ?>
          <dt>Data pisma</dt><dd><?= date_pl($pismo['data_pisma']) ?></dd>
          <?php endif; ?>
          <?php if($pismo['data_wplywu']): ?>
          <dt>Data wpływu</dt><dd><?= date_pl($pismo['data_wplywu']) ?></dd>
          <?php endif; ?>
          <?php if($pismo['data_wysylki']): ?>
          <dt>Data wysyłki</dt><dd><?= date_pl($pismo['data_wysylki']) ?></dd>
          <?php endif; ?>
          <dt>Referent</dt><dd><?= h($pismo['owner_name']??'—') ?></dd>
          <dt>Dodane przez</dt><dd><?= h($pismo['creator_name']??'—') ?></dd>
          <dt>Dodane</dt><dd><?= date('d.m.Y H:i',strtotime($pismo['created_at'])) ?></dd>
        </dl>
      </div>
    </div>

    <!-- Powiązana korespondencja -->
    <?php if ($corr_enabled): ?>
    <div class="card shadow-sm <?= $linked_corr ? 'border-success' : '' ?>">
      <div class="card-header fw-semibold" style="font-size:.82rem">
        <i class="bi bi-mailbox2 me-1 text-<?= $linked_corr ? 'success' : 'secondary' ?>"></i>
        Rejestr korespondencji
        <?php if ($linked_corr): ?>
        <span class="badge bg-success ms-1" style="font-size:.6rem">Połączone</span>
        <?php endif; ?>
      </div>
      <div class="card-body" style="font-size:.82rem">
        <?php if ($linked_corr): ?>
        <?php [$dlabel, $dicon, $dcolor] = corr_direction_label($linked_corr['direction']);
              [$slabel, $scolor] = corr_status_label($linked_corr['status']); ?>
        <div class="d-flex align-items-start gap-2 mb-2">
          <i class="bi <?= $dicon ?> text-<?= $dcolor ?> mt-1 flex-shrink-0"></i>
          <div>
            <a href="<?= APP_URL ?>/correspondence/view.php?id=<?= $linked_corr['id'] ?>"
               class="fw-semibold text-decoration-none"><?= h($linked_corr['subject']) ?></a>
            <div class="text-muted" style="font-size:.75rem">
              <?= h($linked_corr['correspondent']) ?> · <?= h(date_pl($linked_corr['date'])) ?>
            </div>
            <span class="badge bg-<?= $scolor ?> bg-opacity-15 text-<?= $scolor ?>" style="font-size:.65rem"><?= $slabel ?></span>
          </div>
        </div>
        <a href="<?= APP_URL ?>/correspondence/view.php?id=<?= $linked_corr['id'] ?>"
           class="btn btn-outline-success btn-sm w-100">
          <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz w Korespondencji
        </a>
        <?php else: ?>
        <div class="text-muted" style="font-size:.78rem">
          Brak powiązanego wpisu w rejestrze korespondencji.
        </div>
        <?php if(can_edit()): ?>
        <a href="<?= APP_URL ?>/correspondence/add.php?from_ezd=<?= $id ?>"
           class="btn btn-outline-secondary btn-sm w-100 mt-2">
          <i class="bi bi-plus me-1"></i>Utwórz w Korespondencji
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
