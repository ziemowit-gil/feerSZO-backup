<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';

require_login();
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }
require_module_enabled('letters_enabled', 'Moduł pism');

$letter_id = intval($_GET['id'] ?? $_POST['letter_id'] ?? 0);
$L = $letter_id ? get_letter($letter_id) : null;
if (!$L) { http_response_code(404); die('Nie znaleziono pisma.'); }

$type = $L['contract_type'];
$cid  = (int)$L['contract_id'];
$TABLE = table_for_type($type);
$row  = db_one("SELECT * FROM {$TABLE} WHERE id=?", [$cid]);

$user    = current_user();
$error   = '';
$signers = letter_signers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $kierunek       = $_POST['kierunek']        ?? $L['kierunek'];
    $typ_pisma      = $_POST['typ_pisma']       ?? $L['typ_pisma'];
    $tytul          = trim($_POST['tytul']      ?? '');
    $tresc          = trim($_POST['tresc']      ?? '');
    $data_pisma     = trim($_POST['data_pisma'] ?? '');
    $nadawca        = trim($_POST['nadawca']    ?? '');
    $odbiorca       = trim($_POST['odbiorca']   ?? '');
    $odbiorca_email = trim($_POST['odbiorca_email'] ?? '');
    $uwagi          = trim($_POST['uwagi']      ?? '');

    // Załącznik: nowy plik zastępuje istniejący; „usuń" czyści; inaczej bez zmian.
    $new_file = handle_letter_upload('plik');
    if ($new_file) {
        $plik = $new_file;
    } elseif (!empty($_POST['remove_plik'])) {
        $plik = null;
    } else {
        $plik = $L['plik'];
    }

    if (!array_key_exists($kierunek, LETTER_DIRECTIONS)) $kierunek = 'wychodzące';
    if (!array_key_exists($typ_pisma, LETTER_TYPES))     $typ_pisma = 'inne';

    if (!$tytul) {
        $error = 'Tytuł / temat pisma jest wymagany.';
    } elseif (!$tresc && !$plik) {
        $error = 'Pismo musi mieć treść lub załącznik.';
    } else {
        update_letter($letter_id, array_merge([
            'kierunek'       => $kierunek,
            'typ_pisma'      => $typ_pisma,
            'tytul'          => $tytul,
            'tresc'          => $tresc ?: null,
            'plik'           => $plik,
            'data_pisma'     => $data_pisma ?: $L['data_pisma'],
            'nadawca'        => $nadawca,
            'odbiorca'       => $odbiorca,
            'odbiorca_email' => $odbiorca_email,
            'uwagi'          => $uwagi,
        ], letter_meta_from_post()));

        flash_set('success', 'Pismo zaktualizowane.');
        header('Location: ' . APP_URL . '/contracts/letters/view.php?id=' . $letter_id);
        exit;
    }
}

$PAGE_TITLE = 'Edytuj pismo';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
<style>
    .ck-editor__editable_inline { min-height: 420px; font-family: 'Inter', sans-serif; }
    .card-context { background: #f8f9fa; border-left: 4px solid #0d6efd; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-pencil-square me-2"></i>Edytuj pismo</h4>
        <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $letter_id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-lg me-1"></i>Anuluj
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger shadow-sm border-0 mb-4"><i class="bi bi-exclamation-circle me-2"></i><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="letterForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="letter_id" value="<?= $letter_id ?>">

        <div class="row g-4">
            <div class="col-lg-3">
                <div class="card card-context shadow-sm mb-4 border-0">
                    <div class="card-body">
                        <label class="small text-muted text-uppercase fw-bold mb-2 d-block">Dotyczy umowy</label>
                        <h6 class="fw-bold mb-1"><?= h($row['numer_umowy'] ?? '') ?></h6>
                        <p class="small mb-0 text-truncate"><?= h($L['odbiorca'] ?: $L['nadawca']) ?></p>
                        <a href="<?= h(contract_url($type, $cid)) ?>" class="small text-decoration-none">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz umowę
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-9">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body p-4">
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">KIERUNEK</label>
                                <select name="kierunek" class="form-select">
                                    <?php foreach (LETTER_DIRECTIONS as $k => $d): ?>
                                    <option value="<?= h($k) ?>" <?= ($_POST['kierunek'] ?? $L['kierunek']) === $k ? 'selected' : '' ?>><?= h($d['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">TYP PISMA</label>
                                <select name="typ_pisma" class="form-select">
                                    <?php foreach (LETTER_TYPES as $k => $lt): ?>
                                    <option value="<?= h($k) ?>" <?= ($_POST['typ_pisma'] ?? $L['typ_pisma']) === $k ? 'selected' : '' ?>><?= h($lt['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">DATA PISMA</label>
                                <input type="date" name="data_pisma" class="form-control" value="<?= h($_POST['data_pisma'] ?? $L['data_pisma']) ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold">TYTUŁ / TEMAT PISMA</label>
                            <input type="text" name="tytul" class="form-control form-control-lg fw-bold"
                                   value="<?= h($_POST['tytul'] ?? $L['tytul']) ?>" required>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">NADAWCA</label>
                                <input type="text" name="nadawca" class="form-control" value="<?= h($_POST['nadawca'] ?? $L['nadawca']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">ODBIORCA</label>
                                <input type="text" name="odbiorca" class="form-control" value="<?= h($_POST['odbiorca'] ?? $L['odbiorca']) ?>">
                            </div>
                        </div>

                        <?php include __DIR__ . '/_meta_fields.php'; ?>

                        <div class="mb-4">
                            <label class="form-label small fw-bold">TREŚĆ PISMA</label>
                            <textarea name="tresc" id="editor"><?= h($_POST['tresc'] ?? $L['tresc']) ?></textarea>
                        </div>

                        <div class="p-3 rounded-3 bg-light border mb-4">
                            <label class="form-label small fw-bold d-block">ZAŁĄCZNIK (PDF / SKAN)</label>
                            <?php if (!empty($L['plik'])): ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <a href="<?= h(letter_file_url($L['plik'])) ?>" target="_blank" class="text-decoration-none">
                                    <i class="bi bi-paperclip me-1"></i>Bieżący plik
                                </a>
                                <div class="form-check ms-3">
                                    <input class="form-check-input" type="checkbox" name="remove_plik" id="remove_plik" value="1">
                                    <label class="form-check-label text-danger small" for="remove_plik">Usuń załącznik</label>
                                </div>
                            </div>
                            <?php endif; ?>
                            <input type="file" name="plik" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx">
                            <div class="form-text small">Nowy plik zastąpi obecny. Wymagane do wysyłki listem poleconym (Postivo.pl).</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold">UWAGI (wewnętrzne)</label>
                            <textarea name="uwagi" class="form-control" rows="2"><?= h($_POST['uwagi'] ?? $L['uwagi']) ?></textarea>
                        </div>

                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary btn-lg px-5">
                                <i class="bi bi-check-lg me-2"></i>Zapisz zmiany
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
ClassicEditor
    .create(document.querySelector('#editor'), {
        toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'insertTable', '|', 'undo', 'redo'],
        language: 'pl'
    })
    .catch(err => console.error(err));
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
