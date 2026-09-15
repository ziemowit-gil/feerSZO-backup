<?php
/**
 * Szczegóły prośby o podpis: podgląd, upload podpisanego pliku, potwierdzenie, anulowanie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$id      = (int)($_GET['id'] ?? 0);
$req     = ezd_sign_request_get($id);
if (!$req) { flash_set('error', 'Wniosek nie istnieje.'); header('Location:'.APP_URL.'/ezd/podpis/index.php'); exit; }

$user_id    = (int)current_user()['id'];
$is_signer  = (int)$req['requested_to'] === $user_id;
$is_sender  = (int)$req['requested_by'] === $user_id;
$is_admin_u = is_admin();
if (!$is_signer && !$is_sender && !$is_admin_u) {
    flash_set('error', 'Brak dostępu.'); header('Location:'.APP_URL.'/ezd/podpis/index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'upload_signed' && $is_signer && $req['status'] === 'oczekuje') {
        if (empty($_FILES['plik']['tmp_name']) || $_FILES['plik']['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Nie wybrano pliku lub błąd przesyłania.');
        } else {
            $res = ezd_sign_request_upload($id, $user_id,
                $_FILES['plik']['tmp_name'],
                $_FILES['plik']['name'],
                $_FILES['plik']['type'] ?: 'application/pdf'
            );
            flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Podpisany dokument wgrany. Nadawca zostanie powiadomiony.' : $res['error']);
        }
    }

    if ($act === 'confirm' && ($is_sender || $is_admin_u) && $req['status'] === 'podpisane') {
        ezd_sign_request_confirm($id, $user_id);
        flash_set('success', 'Potwierdzono zwrot podpisanego dokumentu.');
    }

    if ($act === 'cancel' && ($is_sender || $is_admin_u) && $req['status'] === 'oczekuje') {
        ezd_sign_request_cancel($id, $user_id);
        flash_set('success', 'Prośba o podpis anulowana.');
    }

    if ($act === 'reject' && $is_signer && $req['status'] === 'oczekuje') {
        ezd_sign_request_reject($id, $user_id, trim($_POST['reject_notes'] ?? ''));
        flash_set('success', 'Prośba odrzucona. Nadawca zostanie powiadomiony.');
    }

    header('Location:'.APP_URL.'/ezd/podpis/view.php?id='.$id); exit;
}

// Ścieżka do oryginalnego pliku
$zal_dir  = UPLOAD_DIR . 'ezd/' . $req['zal_sprawa_id'] . '/';
$zal_path = $zal_dir . ($req['zal_filename'] ?? '');

$PAGE_TITLE = 'Prośba o podpis — ' . ($req['zal_name'] ?? '—');
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.sign-status-box { border-radius: 10px; padding: 14px 18px; font-size: .88rem; }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/podpis/index.php">Dokumenty do podpisu</a></li>
  <li class="breadcrumb-item active"><?= h($req['zal_name'] ?? '—') ?></li>
</ol></nav>

<?= flash_html() ?>

<div class="row g-4">
  <div class="col-lg-7">

    <!-- Status -->
    <?php
    $sclass = EZD_SIGN_STATUSES[$req['status']]['class'] ?? 'secondary';
    $slabel = EZD_SIGN_STATUSES[$req['status']]['label'] ?? $req['status'];
    ?>
    <div class="alert alert-<?= $sclass === 'primary' ? 'primary' : ($sclass === 'success' ? 'success' : ($sclass === 'danger' ? 'danger' : ($sclass === 'warning' ? 'warning' : 'secondary'))) ?> mb-3" style="font-size:.88rem">
      <strong><?= h($slabel) ?></strong>
      <?php if($req['status']==='oczekuje' && $is_signer): ?> — wgraj podpisany PDF poniżej<?php endif; ?>
      <?php if($req['status']==='podpisane' && $is_sender): ?> — potwierdź odbiór poniżej<?php endif; ?>
    </div>

    <!-- Metadane -->
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-info-circle me-1 text-primary"></i>Szczegóły</div>
      <div class="card-body">
        <dl class="row mb-0" style="font-size:.83rem">
          <dt class="col-sm-4 text-muted fw-normal">Dokument</dt>
          <dd class="col-sm-8 fw-semibold"><?= h($req['zal_name']) ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">Koszulka</dt>
          <dd class="col-sm-8"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $req['sprawa_id'] ?>" class="font-monospace"><?= h(db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id=?",[$req['sprawa_id']])['znak_sprawy'] ?? '—') ?></a></dd>
          <dt class="col-sm-4 text-muted fw-normal">Prośba od</dt>
          <dd class="col-sm-8"><?= h($req['requested_by_name']) ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">Do podpisu przez</dt>
          <dd class="col-sm-8"><?= h($req['requested_to_name']) ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">Data prośby</dt>
          <dd class="col-sm-8"><?= h(substr($req['requested_at'],0,16)) ?></dd>
          <?php if($req['notes']): ?>
          <dt class="col-sm-4 text-muted fw-normal">Uwagi nadawcy</dt>
          <dd class="col-sm-8"><?= nl2br(h($req['notes'])) ?></dd>
          <?php endif; ?>
          <?php if($req['signed_at']): ?>
          <dt class="col-sm-4 text-muted fw-normal">Podpisano</dt>
          <dd class="col-sm-8"><?= h(substr($req['signed_at'],0,16)) ?></dd>
          <?php endif; ?>
          <?php if($req['signed_name']): ?>
          <dt class="col-sm-4 text-muted fw-normal">Podpisany plik</dt>
          <dd class="col-sm-8 fw-semibold text-success"><i class="bi bi-file-earmark-check me-1"></i><?= h($req['signed_name']) ?></dd>
          <?php endif; ?>
          <?php if($req['confirmed_at']): ?>
          <dt class="col-sm-4 text-muted fw-normal">Potwierdzono</dt>
          <dd class="col-sm-8"><?= h(substr($req['confirmed_at'],0,16)) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <!-- Podgląd oryginalnego pliku -->
    <?php if(file_exists($zal_path) && str_ends_with(strtolower($zal_path), '.pdf')): ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Oryginał do podpisu</div>
      <div class="card-body p-0">
        <iframe src="<?= APP_URL ?>/ezd/file.php?zal_id=<?= $req['zal_id'] ?>" style="width:100%;height:600px;border:none" title="Podgląd PDF"></iframe>
      </div>
    </div>
    <?php else: ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-file-earmark me-1"></i>Oryginał</div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/ezd/file.php?zal_id=<?= $req['zal_id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-download me-1"></i>Pobierz <?= h($req['zal_name']) ?>
        </a>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <div class="col-lg-5">

    <!-- Akcje podpisującego -->
    <?php if($is_signer && $req['status'] === 'oczekuje'): ?>
    <div class="card shadow-sm mb-3 border-warning" style="border-width:1.5px!important">
      <div class="card-header fw-semibold text-warning" style="font-size:.88rem"><i class="bi bi-pen me-1"></i>Wgraj podpisany dokument</div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.8rem">Podpisz dokument (np. kwalifikowanym podpisem elektronicznym lub wydrukiem z odręcznym podpisem → skan PDF), a następnie wgraj go tutaj.</p>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="upload_signed">
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem">Podpisany plik (PDF) <span class="text-danger">*</span></label>
            <input type="file" name="plik" class="form-control form-control-sm" accept=".pdf,application/pdf" required>
          </div>
          <button class="btn btn-warning w-100"><i class="bi bi-upload me-1"></i>Wgraj podpisany plik</button>
        </form>
        <hr class="my-3">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="reject">
          <div class="mb-2">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem">Powód odrzucenia (opcjonalny)</label>
            <textarea name="reject_notes" class="form-control form-control-sm" rows="2" placeholder="np. dokument wymaga zmian…"></textarea>
          </div>
          <button class="btn btn-outline-danger w-100 btn-sm" onclick="return confirm('Na pewno odrzucić prośbę o podpis?')">
            <i class="bi bi-x-circle me-1"></i>Odrzuć prośbę
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Akcje nadawcy: potwierdzenie zwrotu -->
    <?php if(($is_sender || $is_admin_u) && $req['status'] === 'podpisane'): ?>
    <div class="card shadow-sm mb-3 border-success" style="border-width:1.5px!important">
      <div class="card-header fw-semibold text-success" style="font-size:.88rem"><i class="bi bi-check2-circle me-1"></i>Potwierdź zwrot dokumentu</div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.8rem">
          Dokument został podpisany przez <?= h($req['requested_to_name']) ?> i wgrany do repozytorium koszulki.
          <?php if($req['signed_name']): ?><strong><?= h($req['signed_name']) ?></strong><?php endif; ?>
          Potwierdź, że odebrałeś podpisany dokument.
        </p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="confirm">
          <button class="btn btn-success w-100"><i class="bi bi-check-lg me-1"></i>Potwierdź odbiór</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Anulowanie (nadawca, gdy oczekuje) -->
    <?php if(($is_sender || $is_admin_u) && $req['status'] === 'oczekuje'): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="cancel">
          <button class="btn btn-outline-secondary w-100 btn-sm" onclick="return confirm('Anulować prośbę o podpis?')">
            <i class="bi bi-x me-1"></i>Anuluj prośbę o podpis
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Widok tylko informacyjny -->
    <?php if($req['status'] === 'potwierdzone'): ?>
    <div class="alert alert-success"><i class="bi bi-patch-check-fill me-2"></i>Cykl podpisu zakończony i potwierdzony.</div>
    <?php elseif($req['status'] === 'odrzucone'): ?>
    <div class="alert alert-danger"><i class="bi bi-x-circle-fill me-2"></i>Prośba odrzucona przez podpisującego.</div>
    <?php elseif($req['status'] === 'anulowane'): ?>
    <div class="alert alert-secondary"><i class="bi bi-slash-circle me-2"></i>Prośba anulowana przez nadawcę.</div>
    <?php endif; ?>

  </div>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
