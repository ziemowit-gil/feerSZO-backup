<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

kdok_require_role('upload');
kdok_migrate();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Wykryj przekroczenie post_max_size — PHP wyrzuca wtedy cały $_POST,
    // więc CSRF token znika. Sprawdzamy to PRZED csrf_check().
    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_max       = _kdok_parse_size(ini_get('post_max_size'));
    if ($content_length > $post_max && $post_max > 0) {
        $errors[] = sprintf(
            'Plik jest za duży dla serwera (%.1f MB). Limit PHP post_max_size wynosi %s. '
            . 'Skontaktuj się z administratorem lub skompresuj plik.',
            $content_length / 1024 / 1024,
            ini_get('post_max_size')
        );
        goto render;
    }

    csrf_check();

    $type        = $_POST['type']        ?? '';
    $title       = trim($_POST['title']  ?? '');
    $description = trim($_POST['description'] ?? '');
    $uwagi       = trim($_POST['uwagi']       ?? '');
    $kwota       = trim($_POST['kwota']       ?? '');
    $grant_name  = trim($_POST['grant_name']  ?? '');
    $mpk         = trim($_POST['mpk']         ?? '');

    if (!isset(KDOK_TYPES[$type]))    $errors[] = 'Wybierz typ dokumentu.';
    if ($title === '')                $errors[] = 'Tytuł jest wymagany.';
    if (empty($_FILES['file']['tmp_name'])) $errors[] = 'Plik PDF jest wymagany.';

    if (!$errors) {
        // Upload pliku
        $file_path = null;
        $file_sha256 = null;
        $file_size = null;

        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Błąd uploadu pliku (kod: ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $errors[] = 'Dozwolony jest tylko format PDF.';
            } elseif ($f['size'] > 30 * 1024 * 1024) {
                $errors[] = 'Plik nie może przekraczać 30 MB.';
            } else {
                $dir = UPLOAD_DIR . 'kdok_docs/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.pdf';
                $dest = $dir . $name;
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    $errors[] = 'Nie można zapisać pliku.';
                } else {
                    $file_path   = 'kdok_docs/' . $name;
                    $file_sha256 = hash_file('sha256', $dest);
                    $file_size   = filesize($dest);
                }
            }
        }
    }

    if (!$errors) {
        $number = kdok_next_number();
        $cu = current_user();
        $doc_id = kdok_insert('kdok_documents', [
            'number'       => $number,
            'type'         => $type,
            'title'        => $title,
            'description'  => $description,
            'uwagi'        => $uwagi,
            'kwota'        => $kwota,
            'grant_name'   => $grant_name,
            'mpk'          => $mpk,
            'creator_name' => $cu['name'] ?? '',
            'file_path'    => $file_path,
            'file_sha256'  => $file_sha256,
            'file_size'    => $file_size,
            'status'       => 'w_obiegu',
            'created_by'   => $cu['id'],
        ]);

        foreach (array_keys(KDOK_STEPS) as $step) {
            kdok_insert('kdok_steps', ['doc_id' => $doc_id, 'step_type' => $step]);
        }

        kdok_log($doc_id, 'Dokument dodany do obiegu', 'SHA-256: ' . $file_sha256);

        flash_set('success', 'Dokument ' . $number . ' dodany do obiegu.');
        header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $doc_id);
        exit;
    }
}

function _kdok_parse_size(string $s): int {
    $s = trim($s);
    if ($s === '') return 0;
    $unit = strtolower($s[strlen($s) - 1]);
    $val  = (int) $s;
    return match($unit) {
        'g' => $val * 1024 * 1024 * 1024,
        'm' => $val * 1024 * 1024,
        'k' => $val * 1024,
        default => $val,
    };
}

render:
$PAGE_TITLE = 'Dodaj dokument księgowy';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0">Dodaj dokument księgowy</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:680px">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Typ dokumentu <span class="text-danger">*</span></label>
        <div class="d-flex gap-3 flex-wrap">
          <?php foreach (KDOK_TYPES as $k => $t): ?>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="type" id="type_<?= $k ?>" value="<?= $k ?>"
              <?= ($_POST['type'] ?? '') === $k ? 'checked' : '' ?> required>
            <label class="form-check-label" for="type_<?= $k ?>">
              <i class="<?= $t['icon'] ?>"></i> <?= h($t['label']) ?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mb-3">
        <label for="title" class="form-label fw-semibold">Tytuł / opis skrócony <span class="text-danger">*</span></label>
        <input type="text" id="title" name="title" class="form-control"
          value="<?= h($_POST['title'] ?? '') ?>" required maxlength="255"
          placeholder="np. Faktura FV 01/2026 – Dostawca XYZ">
      </div>

      <div class="mb-3">
        <label for="description" class="form-label fw-semibold">Opis merytoryczny</label>
        <textarea id="description" name="description" class="form-control" rows="4"
          placeholder="Cel wydatku, powiązanie z projektem/działaniem, uzasadnienie merytoryczne…"><?= h($_POST['description'] ?? '') ?></textarea>
        <div class="form-text">Pojawi się w historii obiegu i na wygenerowanym PDF.</div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label for="kwota" class="form-label fw-semibold">Kwota (PLN)</label>
          <input type="text" id="kwota" name="kwota" class="form-control"
            value="<?= h($_POST['kwota'] ?? '') ?>" maxlength="50"
            placeholder="np. 1 234,56">
        </div>
        <div class="col-sm-6">
          <label for="uwagi" class="form-label fw-semibold">Uwagi</label>
          <input type="text" id="uwagi" name="uwagi" class="form-control"
            value="<?= h($_POST['uwagi'] ?? '') ?>" maxlength="500"
            placeholder="Dodatkowe uwagi do dokumentu…">
        </div>
      </div>

      <div class="mb-3">
        <label for="grant_name" class="form-label fw-semibold">
          <i class="bi bi-award"></i> Finansowanie z grantu
          <span class="text-muted fw-normal small">(opcjonalnie)</span>
        </label>
        <input type="text" id="grant_name" name="grant_name" class="form-control"
          value="<?= h($_POST['grant_name'] ?? '') ?>" maxlength="255"
          placeholder="Nazwa grantu / projektu, z którego finansowany jest wydatek">
      </div>

      <?php if (kdok_mpk_enabled()): ?>
      <div class="mb-3">
        <label for="mpk" class="form-label fw-semibold">
          <i class="bi bi-diagram-3"></i> MPK / Centrum kosztów
        </label>
        <?php $mpk_list = kdok_mpk_list(); ?>
        <?php if ($mpk_list): ?>
        <select id="mpk" name="mpk" class="form-select">
          <option value="">— wybierz —</option>
          <?php foreach ($mpk_list as $m): ?>
          <option value="<?= h($m) ?>" <?= ($_POST['mpk'] ?? '') === $m ? 'selected' : '' ?>><?= h($m) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" id="mpk" name="mpk" class="form-control"
          value="<?= h($_POST['mpk'] ?? '') ?>" maxlength="100"
          placeholder="Kod lub nazwa centrum kosztów">
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="mb-4">
        <label for="file" class="form-label fw-semibold">Plik PDF <span class="text-danger">*</span></label>
        <input type="file" id="file" name="file" class="form-control" accept=".pdf" required>
        <div class="form-text">Maks. 64 MB. Wyłącznie PDF. Suma SHA-256 zostanie wyliczona automatycznie.</div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Dodaj do obiegu</button>
        <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
