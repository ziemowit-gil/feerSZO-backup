<?php
/**
 * panel/sign_document.php — „Podpisz dokument" w panelu wolontariusza.
 * User wgrywa dokument, pobiera go, podpisuje SAMODZIELNIE własnym certyfikatem
 * X.509 (poza systemem — serwer nigdy nie widzi klucza prywatnego), a następnie
 * wgrywa podpisaną wersję z powrotem. Logika i walidacja: includes/doc_signing.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/doc_signing.php';

require_login();
require_module_enabled('doc_signing_enabled', 'Podpisz dokument');
doc_signing_migrate();

$user = current_user();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['_action'] ?? '') === 'new') {
        $r = ds_upload_original($uid, $_POST['title'] ?? '', 'doc_file');
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Dokument dodany — pobierz go, podpisz i wgraj podpisaną wersję.' : $r['error']);
    } elseif (($_POST['_action'] ?? '') === 'upload_signed') {
        $r = ds_upload_signed((int)($_POST['id'] ?? 0), $uid, false, 'signed_file');
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Podpisany dokument zapisany i zweryfikowany.' : $r['error']);
    } elseif (($_POST['_action'] ?? '') === 'delete') {
        $r = ds_delete((int)($_POST['id'] ?? 0), $uid, false);
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Dokument usunięty.' : $r['error']);
    }
    header('Location: ' . APP_URL . '/panel/sign_document.php'); exit;
}

$PAGE_TITLE = 'Podpisz dokument';
$docs = ds_list_for_user($uid);

include __DIR__ . '/includes/header_panel.php';
?>

<div class="pv-wrap">

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-pen" aria-hidden="true"></i>Podpisz dokument</h1>
    <p class="pv-page-sub">Elektroniczne podpisanie dokumentu</p>
  </div>
</div>

<?= flash_html() ?>

<div class="tz-card mb-3">
  <div class="tz-card__hd"><i class="bi bi-file-earmark-plus me-2" aria-hidden="true"></i>Nowy dokument do podpisania</div>
  <div class="tz-card__bd">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="new">
      <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="dsTitle">Nazwa / opis dokumentu</label>
        <input type="text" name="title" id="dsTitle" class="form-control" maxlength="255" required placeholder="np. Zgoda na przetwarzanie danych">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="dsFile">Plik</label>
        <input type="file" name="doc_file" id="dsFile" class="form-control" required
               accept=".pdf,.doc,.docx,.odt,.xls,.xlsx,.ods,.txt">
      </div>
      <div class="col-12 col-md-2">
        <button type="submit" class="tz-btn w-100"><i class="bi bi-upload me-1" aria-hidden="true"></i>Wgraj</button>
      </div>
      <div class="col-12 text-muted small">Dozwolone: <?= implode(', ', DOC_SIGN_ALLOWED_ORIGINAL_EXT) ?> — max 20 MB.</div>
    </form>
  </div>
</div>

<?php if (!$docs): ?>
<div class="tz-card"><div class="tz-card__bd text-center text-muted py-4">
  <i class="bi bi-pen d-block mb-2 fs-3" aria-hidden="true"></i>
  Brak dokumentów do podpisania.
</div></div>
<?php else: foreach ($docs as $d): $badge = ds_status_badge($d['status']); ?>
<div class="tz-card mb-3">
  <div class="tz-card__hd d-flex align-items-center gap-2 flex-wrap">
    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>
    <span class="flex-grow-1"><?= h($d['title']) ?></span>
    <span class="badge bg-<?= h($badge['class']) ?>"><?= h($badge['label']) ?></span>
  </div>
  <div class="tz-card__bd">
    <div class="d-flex flex-wrap gap-2 mb-2 small text-muted align-items-center">
      <span><i class="bi bi-clock me-1" aria-hidden="true"></i>dodano: <?= h(date_pl($d['created_at'])) ?></span>
      <?php if ($d['original_size']): ?><span><?= h(ds_filesize_human((int)$d['original_size'])) ?></span><?php endif; ?>
    </div>

    <a href="<?= APP_URL ?>/podpisy/serve.php?id=<?= (int)$d['id'] ?>&kind=original" class="tz-btn tz-btn--ghost mb-2">
      <i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz oryginał
    </a>

    <?php if ($d['status'] === 'podpisany'): ?>
    <div class="pv-alert pv-alert-success mt-2 mb-2" role="alert">
      <div class="fw-semibold mb-1"><i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>Podpis zweryfikowany</div>
      <div class="small">
        Format: <?= h($d['sig_format'] ?: '—') ?><br>
        Podpisujący: <?= h($d['sig_signer_cn'] ?: '—') ?><br>
        Wystawca certyfikatu: <?= h($d['sig_issuer'] ?: '—') ?><br>
        Ważność certyfikatu: <?= h($d['sig_valid_from'] ?: '—') ?> — <?= h($d['sig_valid_to'] ?: '—') ?><br>
        Integralność dokumentu:
        <?= match($d['sig_integrity']) {
            'verified'   => '<span class="text-success fw-semibold">potwierdzona</span>',
            'unverified' => '<span class="text-danger fw-semibold">NIE potwierdzona — dokument mógł być zmieniony po podpisaniu</span>',
            default      => '<span class="text-muted">nie sprawdzono (brak narzędzia po stronie serwera)</span>',
        } ?>
      </div>
    </div>
    <a href="<?= APP_URL ?>/podpisy/serve.php?id=<?= (int)$d['id'] ?>&kind=signed" class="tz-btn">
      <i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz podpisany dokument
    </a>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mt-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="upload_signed">
      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
      <div class="col-12 col-md-8">
        <label class="form-label small mb-1" for="signedFile_<?= (int)$d['id'] ?>">Wgraj podpisany plik</label>
        <input type="file" name="signed_file" id="signedFile_<?= (int)$d['id'] ?>" class="form-control" required>
      </div>
      <div class="col-12 col-md-4">
        <button type="submit" class="tz-btn w-100"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Wgraj podpisany</button>
      </div>
    </form>
    <form method="post" class="mt-2" onsubmit="return confirm('Usunąć ten dokument?');">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
      <button type="submit" class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; endif; ?>

</div><!-- .pv-wrap -->

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
