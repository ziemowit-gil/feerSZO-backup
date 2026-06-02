<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';

require_login();
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }

$type = $_GET['type'] ?? $_POST['contract_type'] ?? '';
$cid  = intval($_GET['id'] ?? $_POST['contract_id'] ?? 0);

if (!array_key_exists($type, CONTRACT_TYPES) || !$cid) {
    http_response_code(400); die('Nieprawidłowe parametry.');
}

$TABLE = table_for_type($type);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id=?", [$cid]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }

$user  = current_user();
$error = '';

// Domyślne dane
$default_nadawca  = defined('ORG_NAME') ? ORG_NAME : '';
$default_odbiorca = $row['imie_nazwisko'] ?? $row['nazwa_firmy'] ?? $row['strona_umowy'] ?? '';
$default_email    = $row['email'] ?? '';

$sel_typ      = $_POST['typ_pisma']  ?? 'inne';
$sel_dir      = $_POST['kierunek']   ?? 'wychodzące';
$sel_data     = $_POST['data_pisma'] ?? date('Y-m-d');
$default_text = generate_letter_content($sel_typ, $type, $row, ['data_pisma' => $sel_data]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $kierunek       = $_POST['kierunek']       ?? 'wychodzące';
    $typ_pisma      = $_POST['typ_pisma']      ?? 'inne';
    $tytul          = trim($_POST['tytul']     ?? '');
    $tresc          = trim($_POST['tresc']     ?? ''); // CKEditor przesyła HTML
    $data_pisma     = trim($_POST['data_pisma']?? '');
    $nadawca        = trim($_POST['nadawca']   ?? '');
    $odbiorca       = trim($_POST['odbiorca']  ?? '');
    $odbiorca_email = trim($_POST['odbiorca_email'] ?? '');
    $uwagi          = trim($_POST['uwagi']     ?? '');
    $wyslij_email   = !empty($_POST['wyslij_email']);
    $content_mode   = $_POST['content_mode']   ?? 'text';

    $plik = handle_letter_upload('plik');

    if (!$tytul) {
        $error = 'Tytuł / temat pisma jest wymagany.';
    } elseif ($content_mode !== 'file' && !$tresc) {
        $error = 'Treść pisma jest wymagana.';
    } elseif ($content_mode !== 'text' && !$plik) {
        $error = 'Załącznik jest wymagany dla tego trybu lub wystąpił błąd uploadu.';
    } else {
        $letter_id = create_letter([
            'contract_type'  => $type,
            'contract_id'    => $cid,
            'kierunek'       => $kierunek,
            'typ_pisma'      => $typ_pisma,
            'tytul'          => $tytul,
            'tresc'          => in_array($content_mode, ['text','both']) ? $tresc : null,
            'plik'           => in_array($content_mode, ['file','both']) ? $plik : null,
            'data_pisma'     => $data_pisma ?: date('Y-m-d'),
            'nadawca'        => $nadawca,
            'odbiorca'       => $odbiorca,
            'odbiorca_email' => $odbiorca_email,
            'uwagi'          => $uwagi,
            'email_sent'     => 0,
            'created_by'     => $user['id'],
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $sent = false;
        if ($wyslij_email && $odbiorca_email) {
            $new_letter = get_letter($letter_id);
            $sent = $new_letter ? letter_send_email($new_letter, $row) : false;
            if ($sent) {
                db()->prepare("UPDATE contract_letters SET email_sent=1 WHERE id=?")->execute([$letter_id]);
            }
        }

        flash_set('success', 'Pismo zapisane pomyślnie.');
        header('Location: ' . contract_url($type, $cid));
        exit;
    }
}

$PAGE_TITLE = 'Dodaj pismo';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

<style>
    .ck-editor__editable_inline { min-height: 450px; font-family: 'Inter', sans-serif; }
    .card-context { background: #f8f9fa; border-left: 4px solid #0d6efd; }
    .mode-active { border: 2px solid #0d6efd !important; background: rgba(13, 110, 253, 0.05); }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-plus me-2"></i>Nowe pismo do umowy</h4>
        <a href="<?= h(contract_url($type, $cid)) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-lg me-1"></i>Anuluj
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger shadow-sm border-0 mb-4"><i class="bi bi-exclamation-circle me-2"></i><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="letterForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="contract_type" value="<?= h($type) ?>">
        <input type="hidden" name="contract_id" value="<?= $cid ?>">

        <div class="row g-4">
            <div class="col-lg-3">
                <div class="card card-context shadow-sm mb-4 border-0">
                    <div class="card-body">
                        <label class="small text-muted text-uppercase fw-bold mb-2 d-block">Dotyczy umowy</label>
                        <h6 class="fw-bold mb-1"><?= h($row['numer_umowy']) ?></h6>
                        <p class="small mb-0 text-truncate"><?= h($default_odbiorca) ?></p>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-bold small py-3">SZABLONY PISM</div>
                    <div class="list-group list-group-flush small">
                        <?php foreach (LETTER_TYPES as $k => $lt): ?>
                            <button type="button" class="list-group-item list-group-item-action py-3" onclick="loadTemplate(<?= json_encode($k) ?>)">
                                <i class="bi <?= $lt['icon'] ?> me-2 text-primary"></i><?= h($lt['label']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-9">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body p-4">
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">KIERUNEK</label>
                                <select name="kierunek" class="form-select" onchange="updateUI()">
                                    <option value="wychodzące" <?= $sel_dir == 'wychodzące' ? 'selected' : '' ?>>Wychodzące (do pracownika)</option>
                                    <option value="przychodzące" <?= $sel_dir == 'przychodzące' ? 'selected' : '' ?>>Przychodzące (od pracownika)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">TYP PISMA</label>
                                <select name="typ_pisma" id="typ_pisma" class="form-select" onchange="syncType(this.value)">
                                    <?php foreach (LETTER_TYPES as $k => $lt): ?>
                                        <option value="<?= $k ?>" <?= ($sel_typ === $k) ? 'selected' : '' ?>><?= h($lt['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">DATA PISMA</label>
                                <input type="date" name="data_pisma" class="form-control" value="<?= h($sel_data) ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold">TYTUŁ / TEMAT PISMA</label>
                            <input type="text" name="tytul" id="tytul_input" class="form-control form-control-lg fw-bold"
                                   value="<?= h($_POST['tytul'] ?? (LETTER_TYPES[$sel_typ]['label'] . ': ' . $row['numer_umowy'])) ?>" required>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">NADAWCA</label>
                                <input type="text" name="nadawca" class="form-control" value="<?= h($_POST['nadawca'] ?? $default_nadawca) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">ODBIORCA</label>
                                <input type="text" name="odbiorca" class="form-control" value="<?= h($_POST['odbiorca'] ?? $default_odbiorca) ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold d-block mb-3">TRYB TREŚCI</label>
                            <div class="btn-group w-100 shadow-sm" role="group">
                                <input type="radio" class="btn-check" name="content_mode" id="mode_text" value="text" checked onchange="updateContentMode()">
                                <label class="btn btn-outline-primary py-2" for="mode_text"><i class="bi bi-textarea-t me-2"></i>Tylko tekst</label>

                                <input type="radio" class="btn-check" name="content_mode" id="mode_file" value="file" onchange="updateContentMode()">
                                <label class="btn btn-outline-primary py-2" for="mode_file"><i class="bi bi-file-earmark-arrow-up me-2"></i>Tylko skan/plik</label>

                                <input type="radio" class="btn-check" name="content_mode" id="mode_both" value="both" onchange="updateContentMode()">
                                <label class="btn btn-outline-primary py-2" for="mode_both"><i class="bi bi-layers me-2"></i>Tekst i załącznik</label>
                            </div>
                        </div>

                        <div id="wrapper_editor" class="mb-4">
                            <textarea name="tresc" id="editor"><?= h($_POST['tresc'] ?? $default_text) ?></textarea>
                            <div class="text-end mt-2">
                                <button type="button" class="btn btn-link btn-sm text-decoration-none text-muted" onclick="resetTemplate()">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć domyślny szablon
                                </button>
                            </div>
                        </div>

                        <div id="wrapper_file" class="mb-4 d-none">
                            <div class="p-4 border border-dashed rounded-3 bg-light text-center">
                                <i class="bi bi-cloud-upload fs-1 text-primary"></i>
                                <label class="d-block mb-2 fw-bold">Wybierz plik PDF lub skan (JPG/PNG)</label>
                                <input type="file" name="plik" class="form-control mx-auto" style="max-width: 400px;" accept=".pdf,.jpg,.jpeg,.png,.docx">
                            </div>
                        </div>

                        <div class="p-3 rounded-3 bg-light border mb-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="wyslij_email" id="wyslij_email" value="1" onchange="toggleEmail()" checked>
                                <label class="form-check-label fw-bold" for="wyslij_email">Wyślij powiadomienie e-mail do adresata</label>
                            </div>
                            <div id="email_box">
                                <input type="email" name="odbiorca_email" class="form-control" placeholder="Adres e-mail..." value="<?= h($default_email) ?>">
                                <div class="form-text small">Dokument zostanie wysłany automatycznie po zapisaniu.</div>
                            </div>
                        </div>

                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary btn-lg px-5">
                                <i class="bi bi-check-lg me-2"></i>Zapisz i zakończ
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let editor;

ClassicEditor
    .create(document.querySelector('#editor'), {
        toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'insertTable', '|', 'undo', 'redo'],
        language: 'pl'
    })
    .then(newEditor => { editor = newEditor; })
    .catch(err => { console.error(err); });

const templates = <?= json_encode(array_combine(
    array_keys(LETTER_TYPES),
    array_map(fn($k) => nl2br(generate_letter_content($k, $type, $row, ['data_pisma' => $sel_data])), array_keys(LETTER_TYPES))
)) ?>;

function loadTemplate(typ) {
    if(editor) editor.setData(templates[typ] || '');
    document.getElementById('typ_pisma').value = typ;
    const titleInput = document.getElementById('tytul_input');
    if(!titleInput.dataset.custom) {
        titleInput.value = (typ.charAt(0).toUpperCase() + typ.slice(1)) + ': <?= $row['numer_umowy'] ?>';
    }
}

function syncType(val) { loadTemplate(val); }

function resetTemplate() {
    if(confirm('Czy na pewno chcesz usunąć obecną treść i przywrócić szablon?')) {
        loadTemplate(document.getElementById('typ_pisma').value);
    }
}

function updateContentMode() {
    const mode = document.querySelector('input[name="content_mode"]:checked').value;
    document.getElementById('wrapper_editor').classList.toggle('d-none', mode === 'file');
    document.getElementById('wrapper_file').classList.toggle('d-none', mode === 'text');
}

function toggleEmail() {
    const isChecked = document.getElementById('wyslij_email').checked;
    document.getElementById('email_box').classList.toggle('d-none', !isChecked);
}

function updateUI() {
    // Logika zmiany labeli Nadawca/Odbiorca zależnie od kierunku
}

document.getElementById('tytul_input').addEventListener('input', function() {
    this.dataset.custom = 'true';
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
