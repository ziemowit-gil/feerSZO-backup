<?php
/**
 * podpisy/index.php — moduł „Podpisz dokument" (widok personelu).
 * Wolontariusze/wykonawcy korzystają z uproszczonej wersji w panelu
 * (panel/sign_document.php) — ta strona pokazuje wszystkie dokumenty
 * w organizacji. Logika i walidacja: includes/doc_signing.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/doc_signing.php';

require_login();
require_module_enabled('doc_signing_enabled', 'Podpisz dokument');
doc_signing_migrate();

if (is_viewer()) { header('Location: ' . APP_URL . '/panel/sign_document.php'); exit; }

$user     = current_user();
$uid      = (int)$user['id'];
$is_staff = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['_action'] ?? '') === 'new') {
        $r = ds_upload_original($uid, $_POST['title'] ?? '', 'doc_file');
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Dokument dodany — pobierz go, podpisz i wgraj podpisaną wersję.' : $r['error']);
    } elseif (($_POST['_action'] ?? '') === 'upload_signed') {
        $r = ds_upload_signed((int)($_POST['id'] ?? 0), $uid, $is_staff, 'signed_file');
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Podpisany dokument zapisany i zweryfikowany.' : $r['error']);
    } elseif (($_POST['_action'] ?? '') === 'delete') {
        $r = ds_delete((int)($_POST['id'] ?? 0), $uid, $is_staff);
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Dokument usunięty.' : $r['error']);
    }
    header('Location: ' . APP_URL . '/podpisy/index.php'); exit;
}

$PAGE_TITLE = 'Podpisz dokument';
$docs = ds_list_all();

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-pen text-primary me-2"></i>Podpisz dokument</h4>
</div>
<?= flash_html() ?>

<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Nowy dokument do podpisania</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="new">
      <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="dsTitle">Nazwa / opis dokumentu</label>
        <input type="text" name="title" id="dsTitle" class="form-control" maxlength="255" required placeholder="np. Uchwała zarządu nr 3/2026">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="dsFile">Plik</label>
        <input type="file" name="doc_file" id="dsFile" class="form-control" required
               accept=".pdf,.doc,.docx,.odt,.xls,.xlsx,.ods,.txt">
      </div>
      <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload me-1"></i>Wgraj</button>
      </div>
      <div class="col-12 text-muted small">Dozwolone: <?= implode(', ', DOC_SIGN_ALLOWED_ORIGINAL_EXT) ?> — max 20 MB. Podpis powstaje poza systemem, własnym certyfikatem X.509 (kwalifikowanym lub niekwalifikowanym) — serwer nie widzi klucza prywatnego.</div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-list-check me-2 text-primary"></i>Wszystkie dokumenty (<?= count($docs) ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Dokument</th>
          <th>Właściciel</th>
          <th>Status</th>
          <th>Dodano</th>
          <th>Podpis</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$docs): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Brak dokumentów.</td></tr>
      <?php else: foreach ($docs as $d): $badge = ds_status_badge($d['status']); ?>
        <tr>
          <td><?= h($d['title']) ?><div class="text-muted small"><?= h($d['original_name']) ?></div></td>
          <td><?= h($d['owner_name'] ?: $d['owner_email'] ?: ('#' . (int)$d['user_id'])) ?></td>
          <td><span class="badge bg-<?= h($badge['class']) ?>"><?= h($badge['label']) ?></span></td>
          <td class="small"><?= h(date_pl($d['created_at'])) ?></td>
          <td class="small">
            <?php if ($d['status'] === 'podpisany'): ?>
              <?= h($d['sig_signer_cn'] ?: '—') ?>
              <?php if ($d['sig_integrity'] === 'unverified'): ?>
                <span class="badge bg-danger ms-1" title="Integralność dokumentu NIE potwierdzona"><i class="bi bi-exclamation-triangle"></i></span>
              <?php elseif ($d['sig_integrity'] === 'verified'): ?>
                <span class="badge bg-success ms-1" title="Integralność potwierdzona"><i class="bi bi-check-lg"></i></span>
              <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a href="<?= APP_URL ?>/podpisy/serve.php?id=<?= (int)$d['id'] ?>&kind=original" class="btn btn-outline-primary btn-sm" title="Pobierz oryginał">
              <i class="bi bi-download"></i>
            </a>
            <?php if ($d['status'] === 'podpisany'): ?>
            <a href="<?= APP_URL ?>/podpisy/serve.php?id=<?= (int)$d['id'] ?>&kind=signed" class="btn btn-outline-success btn-sm" title="Pobierz podpisany">
              <i class="bi bi-patch-check"></i>
            </a>
            <?php else: ?>
            <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="collapse" data-bs-target="#dsUp<?= (int)$d['id'] ?>" title="Wgraj podpisany">
              <i class="bi bi-upload"></i>
            </button>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten dokument?');">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm" title="Usuń"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($d['status'] !== 'podpisany'): ?>
        <tr class="collapse" id="dsUp<?= (int)$d['id'] ?>">
          <td colspan="6" class="bg-light">
            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end py-2">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="upload_signed">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <div class="col-12 col-md-8">
                <input type="file" name="signed_file" class="form-control form-control-sm" required>
              </div>
              <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-patch-check me-1"></i>Wgraj podpisany</button>
              </div>
            </form>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
