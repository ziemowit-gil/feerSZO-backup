<?php
/**
 * Podgląd dokumentu wewnętrznego sprawy: treść, załączniki, akcje.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_kopia.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_zal_menu.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko'); ezd_require_access();

$id  = (int)($_GET['id'] ?? 0);
$doc = ezd_dokument_get($id);
if (!$doc) { flash_set('error','Dokument nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }

$sprawa_id = (int)$doc['sprawa_id'];
$user_id   = (int)current_user()['id'];
$_sprawa   = ezd_sprawa_get($sprawa_id);
$_access   = $_sprawa ? ezd_sprawa_access($_sprawa, $user_id) : null;
if (!$_access) { flash_set('error','Brak dostępu do tej koszulki.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }
$can_act   = $_access === 'write' && $doc['sprawa_status'] !== 'closed';
$PAGE_TITLE = $doc['sygnatura'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_act && $_POST['_action'] !== 'delete') { http_response_code(403); exit; }
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'upload') {
            $err = ezd_upload('file', $sprawa_id, $user_id, null, null, $id);
            flash_set($err ? 'error' : 'success', $err ?: 'Plik dodany.');
        } elseif ($action === 'del_file') {
            ezd_zal_delete((int)($_POST['zid'] ?? 0), $user_id);
            flash_set('success','Plik usunięty.');
        } elseif ($action === 'delete') {
            if (!is_admin() && (int)$doc['created_by'] !== $user_id) throw new \RuntimeException('Brak uprawnień do usunięcia dokumentu.');
            ezd_dokument_delete($id, $user_id);
            flash_set('success','Dokument usunięty.');
            header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.$sprawa_id.'#dokumenty'); exit;
        }
    } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
    header('Location:'.APP_URL.'/ezd/dokumenty/view.php?id='.$id); exit;
}

$zal = ezd_zalaczniki_by($sprawa_id, null, null, $id);
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
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($doc['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active"><?= h($doc['sygnatura']) ?></li>
</ol></nav>

<?= flash_html() ?>

<!-- ══ Dokument wewnętrzny — układ Dynamics 365 (assets/css/dynamics.css, includes/dynRecord.php; kolory EZD w ezd_metro.php) ══ -->
<?php
require_once dirname(__DIR__, 2) . '/includes/dynRecord.php';
$_dk_meta = EZD_DOK_STATUSY[$doc['status']] ?? ['label' => $doc['status'], 'class' => 'secondary'];
?>
<div class="dyn-cmdbar" role="toolbar" aria-label="Polecenia dokumentu">
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$sprawa_id ?>" class="btn btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i>Koszulka <?= h($doc['znak_sprawy']) ?></a>
  <span class="dyn-sep" aria-hidden="true"></span>
  <?php ezd_kopia_menu_btn('dokument', $id, $doc['sygnatura']); ?>
  <?php if($can_act): ?>
  <a href="<?= APP_URL ?>/ezd/dokumenty/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edytuj</a>
  <?php endif; ?>
</div>

<section class="dyn-rec" aria-labelledby="dk-title">
  <div class="dyn-head">
    <div class="dyn-head__main">
      <div class="dyn-entity"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> <?= h(EZD_DOK_RODZAJE[$doc['rodzaj']] ?? $doc['rodzaj']) ?> · <code><?= h($doc['sygnatura']) ?></code></div>
      <h1 id="dk-title" class="dyn-title"><?= h($doc['title']) ?></h1>
    </div>
    <?= dynHFields([
        ['Status', $_dk_meta['label'], $_dk_meta['class'] === 'success' ? 'var(--m-green)' : ''],
        ['Autor / referent', (string)($doc['owner_name'] ?? '')],
        ['Utworzono', date('d.m.Y', strtotime((string)$doc['created_at']))],
        ['Koszulka', ['html' => '<a href="' . APP_URL . '/ezd/sprawy/view.php?id=' . (int)$sprawa_id . '" class="font-monospace">' . h($doc['znak_sprawy']) . '</a>']],
    ]) ?>
  </div>
  <?= dynStages(['projekt' => 'Projekt', 'zatwierdzony' => 'Zatwierdzony', 'archiwalny' => 'Archiwalny'], (string)$doc['status'], ['label' => 'Etapy dokumentu']) ?>
</section>

<div class="row g-4 dyn-form">
  <div class="col-lg-8">
    <?php if(trim($doc['tresc']) !== ''): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-body-text me-1 text-primary"></i>Treść</div>
      <div class="card-body" style="font-size:.88rem;white-space:pre-wrap"><?= h($doc['tresc']) ?></div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-paperclip me-1 text-primary"></i>Załączniki (<?= count($zal) ?>)</div>
      <?php if($zal): ?>
      <div>
        <?php foreach($zal as $z): ?>
        <div class="zal-row">
          <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" target="_blank" class="text-decoration-none fw-semibold text-truncate d-block" style="font-size:.82rem"><?= h($z['original_name']) ?></a>
            <div class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?> · <?= h($z['uploader']??'—') ?> · <?= date('d.m.Y H:i',strtotime($z['uploaded_at'])) ?></div>
          </div>
          <?php
            $_zext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
            $_sigd = ['signed' => false];
            if (in_array($_zext, EZD_SIG_EXTS, true)) {
                $_sigd = ezd_signature_info(UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/' . $z['filename'], $z['original_name']);
            }
            ezd_zal_menu($z, [
              'can_act'   => $can_act,
              'sprawa_id' => $sprawa_id,
              'sig'       => $_sigd,
              'del_field' => 'zid',
              'allow'     => ['rsign','obiegi'],
            ]);
          ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if($can_act): ?>
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

  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-info-circle me-1 text-primary"></i>Metadane</div>
      <div class="card-body">
        <dl class="meta-dl mb-0">
          <dt>Sprawa</dt>
          <dd><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="font-monospace text-decoration-none"><?= h($doc['znak_sprawy']) ?></a></dd>
          <dt>Rodzaj</dt><dd><?= h(EZD_DOK_RODZAJE[$doc['rodzaj']] ?? $doc['rodzaj']) ?></dd>
          <dt>Status</dt><dd><?= ezd_dok_status_badge($doc['status']) ?></dd>
          <dt>Autor / referent</dt><dd><?= h($doc['owner_name']??'—') ?></dd>
          <dt>Utworzył</dt><dd><?= h($doc['creator_name']??'—') ?></dd>
          <dt>Utworzono</dt><dd><?= date('d.m.Y H:i',strtotime($doc['created_at'])) ?></dd>
        </dl>
      </div>
    </div>

    <?php if(can_edit() && (is_admin() || (int)$doc['created_by']===$user_id)): ?>
    <form method="post" onsubmit="return confirm('Usunąć dokument wraz z załącznikami?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="delete">
      <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash3 me-1"></i>Usuń dokument</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/ezd_sig_modal.php'; ?>
<?php include dirname(dirname(__DIR__)) . '/includes/ezd_rsign.php'; ?>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
