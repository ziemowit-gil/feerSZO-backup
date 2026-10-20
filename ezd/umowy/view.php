<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_kopia.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_zal_menu.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko'); ezd_require_access();

$id    = (int)($_GET['id'] ?? 0);
$umowa = ezd_umowa_get($id);
if (!$umowa) { flash_set('error','Umowa nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }

$sprawa_id = (int)$umowa['sprawa_id'];
$user_id   = (int)current_user()['id'];
$_sprawa   = ezd_sprawa_get($sprawa_id);
$_access   = $_sprawa ? ezd_sprawa_access($_sprawa, $user_id) : null;
if (!$_access) { flash_set('error','Brak dostępu do tej koszulki.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }

$PAGE_TITLE = $umowa['sygnatura'];
$zal = ezd_zalaczniki_by($sprawa_id, null, $id);
$can_act = $_access === 'write' && $umowa['sprawa_status'] !== 'closed';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    if ($action === 'upload' && $can_act) {
        $err = ezd_upload('file', $sprawa_id, $user_id, null, $id);
        flash_set($err ? 'error' : 'success', $err ?? 'Plik dodany.');
    }
    if ($action === 'del_file' && $can_act) {
        ezd_zal_delete((int)($_POST['zid'] ?? 0), $user_id);
        flash_set('success','Plik usunięty.');
    }
    header('Location:'.APP_URL.'/ezd/umowy/view.php?id='.$id); exit;
}

$is_active = $umowa['sprawa_status'] !== 'closed';
$typ_label = EZD_UMOWA_TYPY[$umowa['typ']] ?? $umowa['typ'];

// Status color mapping
$status_colors = ['projekt'=>'secondary','aktywna'=>'success','wygasla'=>'warning','rozwiazana'=>'info','anulowana'=>'danger'];
$status_color  = $status_colors[$umowa['status']] ?? 'secondary';

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
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($umowa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active"><?= h($umowa['sygnatura']) ?></li>
</ol></nav>

<?= flash_html() ?>

<!-- ══ Umowa — układ Dynamics 365 (assets/css/dynamics.css, includes/dynRecord.php; kolory EZD w ezd_metro.php) ══ -->
<?php
require_once dirname(__DIR__, 2) . '/includes/dynRecord.php';
$_um_d = fn($v) => $v ? date('d.m.Y', strtotime((string)$v)) : '';
$_um_color = ['success' => 'var(--m-green)', 'warning' => 'var(--m-orange)', 'info' => 'var(--m-teal)', 'danger' => 'var(--m-red)'][$status_color] ?? '';
$_um_expired = $umowa['data_do'] && $umowa['data_do'] < date('Y-m-d');
$_um_fields = [['Status', ucfirst((string)$umowa['status']), $_um_color]];
if ($umowa['wartosc'] !== null) $_um_fields[] = ['Wartość', number_format((float)$umowa['wartosc'], 2, ',', ' ') . ' ' . $umowa['waluta']];
if ($umowa['data_zawarcia']) $_um_fields[] = ['Zawarta', $_um_d($umowa['data_zawarcia'])];
if ($umowa['data_od'] || $umowa['data_do']) $_um_fields[] = [$_um_expired ? 'Wygasła' : 'Obowiązuje', trim($_um_d($umowa['data_od']) . ' – ' . $_um_d($umowa['data_do']), ' –'), $_um_expired ? 'var(--m-red)' : ''];
$_um_fields[] = ['Koszulka', ['html' => '<a href="' . APP_URL . '/ezd/sprawy/view.php?id=' . (int)$sprawa_id . '" class="font-monospace">' . h($umowa['znak_sprawy']) . '</a>']];
?>
<div class="dyn-cmdbar" role="toolbar" aria-label="Polecenia umowy">
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$sprawa_id ?>" class="btn btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i>Koszulka <?= h($umowa['znak_sprawy']) ?></a>
  <span class="dyn-sep" aria-hidden="true"></span>
  <?php ezd_kopia_menu_btn('umowa', $id, $umowa['sygnatura']); ?>
  <?php if(can_edit() && $is_active): ?>
  <a href="<?= APP_URL ?>/ezd/umowy/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edytuj</a>
  <?php endif; ?>
</div>

<section class="dyn-rec" aria-labelledby="um-title">
  <div class="dyn-head">
    <div class="dyn-head__main">
      <div class="dyn-entity"><i class="bi bi-file-earmark-ruled" aria-hidden="true"></i> <?= h($typ_label) ?> · <code><?= h($umowa['sygnatura']) ?></code></div>
      <h1 id="um-title" class="dyn-title"><?= h($umowa['title']) ?></h1>
      <?php if($umowa['strona']): ?><div class="text-muted mt-1" style="font-size:.85rem"><i class="bi bi-building me-1" aria-hidden="true"></i><?= h($umowa['strona']) ?></div><?php endif; ?>
    </div>
    <?= dynHFields($_um_fields) ?>
  </div>
</section>

<div class="row g-4 dyn-form">
  <!-- Lewa: szczegóły + załączniki -->
  <div class="col-lg-8">
    <!-- Warunki płatności -->
    <?php if($umowa['warunki_platnosci']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-credit-card me-1 text-primary"></i>Warunki płatności</div>
      <div class="card-body" style="font-size:.88rem;white-space:pre-wrap"><?= h($umowa['warunki_platnosci']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Załączniki -->
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-paperclip me-1 text-primary"></i>Załączniki (<?= count($zal) ?>)</span>
      </div>
      <?php if($zal): ?>
      <div>
        <?php foreach($zal as $z):
          $sig = ['signed'=>false];
          $zext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
          if (in_array($zext, EZD_SIG_EXTS, true)) {
              $sig = ezd_signature_info(UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'], $z['original_name']);
          }
        ?>
        <div class="zal-row">
          <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" target="_blank" class="text-decoration-none fw-semibold text-truncate d-block" style="font-size:.82rem"><?= h($z['original_name']) ?>
              <?php if(!empty($sig['signed'])): ?><span class="badge bg-success bg-opacity-15 text-success border border-success ms-1" style="font-size:.6rem"><i class="bi bi-patch-check-fill me-1"></i>Podpis el.</span><?php endif; ?>
            </a>
            <div class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?> · v<?= $z['wersja'] ?> · <?= h($z['uploader']??'—') ?> · <?= date('d.m.Y H:i',strtotime($z['uploaded_at'])) ?></div>
          </div>
          <?php ezd_zal_menu($z, [
            'can_act'   => can_edit() && $is_active,
            'sprawa_id' => (int)$z['sprawa_id'],
            'sig'       => $sig,
            'del_field' => 'zid',
            'allow'     => ['obiegi'],
          ]); ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if(can_edit() && $is_active): ?>
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
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-info-circle me-1 text-primary"></i>Metadane</div>
      <div class="card-body">
        <dl class="meta-dl mb-0">
          <dt>Sprawa</dt>
          <dd><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="font-monospace text-decoration-none"><?= h($umowa['znak_sprawy']) ?></a></dd>
          <dt>Typ</dt><dd><?= h($typ_label) ?></dd>
          <dt>Status</dt><dd><span class="badge bg-<?= $status_color ?>"><?= h(ucfirst($umowa['status'])) ?></span></dd>
          <?php if($umowa['strona']): ?><dt>Strona umowy</dt><dd><?= h($umowa['strona']) ?></dd><?php endif; ?>
          <dt>Referent</dt><dd><?= h($umowa['owner_name']??'—') ?></dd>
          <dt>Dodane przez</dt><dd><?= h($umowa['creator_name']??'—') ?></dd>
          <dt>Dodane</dt><dd><?= date('d.m.Y H:i',strtotime($umowa['created_at'])) ?></dd>
          <dt>Ostatnia zmiana</dt><dd><?= date('d.m.Y H:i',strtotime($umowa['updated_at'])) ?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/ezd_sig_modal.php'; ?>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
