<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }

$req_id = intval($_GET['id'] ?? $_POST['req_id'] ?? 0);
$req    = get_certificate_request($req_id);
if (!$req) { http_response_code(404); die('Nie znaleziono wniosku.'); }

$type  = $req['contract_type'];
$cid   = $req['contract_id'];
$TABLE = table_for_type($type);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$cid]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $user   = current_user();

    if ($action === 'issue') {
        $mode    = $_POST['issue_mode'] ?? 'text';    // text | file | both
        $content = trim($_POST['certificate_content'] ?? '');
        $file_path = handle_certificate_upload('certificate_file');

        $need_text = in_array($mode, ['text', 'both'], true);
        $need_file = in_array($mode, ['file', 'both'], true);

        if ($need_text && !$content) {
            $error = 'Treść zaświadczenia nie może być pusta przy wybranym trybie.';
        } elseif ($need_file && !$file_path) {
            $error = 'Nie udało się zapisać pliku. Sprawdź, czy załączyłeś/aś plik PDF/JPG/PNG (max 30 MB).';
        } else {
            $sign_type = in_array($_POST['sign_type'] ?? '', ['elektroniczne','papierowe'], true)
                ? $_POST['sign_type'] : 'papierowe';
            issue_certificate(
                $req_id,
                $user['id'],
                $need_text ? $content : '',
                $need_file ? $file_path : null,
                $sign_type
            );
            flash_set('success', 'Zaświadczenie zostało wydane i wysłane na adres e-mail wnioskodawcy.');
            header('Location: ' . APP_URL . '/admin/certificates.php');
            exit;
        }
    } elseif ($action === 'reject') {
        $note = trim($_POST['rejection_note'] ?? '');
        reject_certificate_request($req_id, $user['id'], $note);
        flash_set('success', 'Wniosek odrzucony. Wnioskodawca otrzymał powiadomienie e-mail.');
        header('Location: ' . APP_URL . '/admin/certificates.php');
        exit;
    }
}

$default_content = generate_certificate_content($type, $row, $req);
$PAGE_TITLE = 'Wydaj zaświadczenie';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-award text-success"></i> Wydaj zaświadczenie</h4>
  <a href="<?= APP_URL ?>/admin/certificates.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Wróć do wniosków
  </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3">

<!-- ── Wniosek + umowa ──────────────────────────────────────────────────────── -->
<div class="col-lg-4">

  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold small">Wniosek #<?= $req_id ?></div>
    <div class="card-body small">
      <div class="row g-2">
        <div class="col-12">
          <div class="text-muted">Wnioskodawca</div>
          <div class="fw-semibold"><?= h($req['requester_name']) ?></div>
        </div>
        <div class="col-12">
          <div class="text-muted">E-mail (odbiorca)</div>
          <div><?= h($req['requester_email']) ?></div>
        </div>
        <div class="col-12">
          <div class="text-muted">Cel</div>
          <div><?= nl2br(h($req['cel'])) ?></div>
        </div>
        <div class="col-6">
          <div class="text-muted">Złożono</div>
          <div><?= date_pl($req['created_at']) ?></div>
        </div>
        <div class="col-6">
          <div class="text-muted">Status</div>
          <div><?= certificate_status_badge($req['status']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header fw-semibold small">Umowa</div>
    <div class="card-body small">
      <div class="mb-1"><span class="text-muted">Typ:</span> <?= h(CONTRACT_TYPES[$type] ?? $type) ?></div>
      <div class="mb-1"><span class="text-muted">Nr:</span> <strong><?= h($row['numer_umowy']) ?></strong></div>
      <div class="mb-1"><span class="text-muted">Osoba:</span> <?= h(get_contract_person_name($type, $row)) ?: '—' ?></div>
      <div class="mb-1"><span class="text-muted">Okres:</span>
        <?= date_pl($row['data_rozpoczecia']) ?> – <?= date_pl($row['data_zakonczenia']) ?></div>
      <div class="mb-3"><span class="text-muted">Status:</span> <?= status_badge($row['status']) ?></div>
      <a href="<?= h(contract_url($type, $cid)) ?>" class="btn btn-sm btn-outline-secondary w-100">
        <i class="bi bi-eye"></i> Otwórz umowę
      </a>
    </div>
  </div>

</div>

<!-- ── Formularz wydawania ──────────────────────────────────────────────────── -->
<div class="col-lg-8">

  <?php if ($req['status'] === 'oczekuje'): ?>

  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold text-success">
      <i class="bi bi-check-circle"></i> Wydaj zaświadczenie
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" id="form-issue">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="req_id" value="<?= $req_id ?>">
        <input type="hidden" name="action" value="issue">

        <!-- Forma podpisania -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Forma podpisania</label>
          <div class="d-flex gap-3 flex-wrap">
            <?php $sel_sign = $_POST['sign_type'] ?? 'papierowe'; ?>
            <label class="d-flex align-items-center gap-2 p-2 border rounded" style="cursor:pointer;font-size:.88rem">
              <input type="radio" name="sign_type" value="papierowe" <?= $sel_sign === 'papierowe' ? 'checked' : '' ?>>
              <span><i class="bi bi-pen me-1"></i>Papierowe</span>
            </label>
            <label class="d-flex align-items-center gap-2 p-2 border rounded" style="cursor:pointer;font-size:.88rem">
              <input type="radio" name="sign_type" value="elektroniczne" <?= $sel_sign === 'elektroniczne' ? 'checked' : '' ?>>
              <span><i class="bi bi-shield-lock me-1"></i>Elektroniczne (ePodpis)</span>
            </label>
          </div>
        </div>

        <!-- Tryb wydania -->
        <div class="mb-4">
          <label class="form-label fw-semibold">Tryb wydania</label>
          <div class="d-flex gap-2 flex-wrap">
            <?php
            $modes = [
                'text' => ['icon' => 'bi-file-text',        'label' => 'Generuj tekst',        'desc' => 'Formularz tekstowy + link do wydruku'],
                'file' => ['icon' => 'bi-file-earmark-pdf', 'label' => 'Uploaduj plik',        'desc' => 'Skan lub dokument z ePodpisem (PDF/JPG/PNG)'],
                'both' => ['icon' => 'bi-files',            'label' => 'Tekst + plik',         'desc' => 'Oba — tekst i plik w jednym e-mailu'],
            ];
            $sel_mode = $_POST['issue_mode'] ?? 'text';
            foreach ($modes as $val => $m): ?>
            <div class="form-check mode-card <?= $sel_mode === $val ? 'selected' : '' ?>"
                 style="border:2px solid <?= $sel_mode === $val ? '#198754' : '#dee2e6' ?>;
                        border-radius:.5rem;padding:.75rem 1rem;cursor:pointer;
                        min-width:160px;transition:border-color .15s">
              <input class="form-check-input" type="radio" name="issue_mode"
                     id="mode_<?= $val ?>" value="<?= $val ?>"
                     <?= $sel_mode === $val ? 'checked' : '' ?> onchange="updateMode()">
              <label class="form-check-label" for="mode_<?= $val ?>" style="cursor:pointer">
                <i class="bi <?= $m['icon'] ?> d-block fs-4 mb-1"></i>
                <strong><?= $m['label'] ?></strong>
                <div class="small text-muted"><?= $m['desc'] ?></div>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Upload pliku -->
        <div id="section-file" class="mb-3 <?= $sel_mode === 'text' ? 'd-none' : '' ?>">
          <label class="form-label fw-semibold">
            <i class="bi bi-file-earmark-arrow-up"></i> Plik zaświadczenia
            <span class="text-muted fw-normal">(PDF / JPG / PNG, max 30 MB)</span>
          </label>
          <input type="file" name="certificate_file" id="cert_file"
                 class="form-control" accept=".pdf,.jpg,.jpeg,.png">
          <div class="form-text">
            Wgraj skan podpisanego zaświadczenia lub plik z kwalifikowanym podpisem elektronicznym (ePUAP, DocuSign itp.).
          </div>
          <div id="file-preview" class="mt-2 d-none">
            <span class="badge bg-secondary"><i class="bi bi-paperclip"></i> <span id="file-name"></span></span>
          </div>
        </div>

        <!-- Treść tekstowa -->
        <div id="section-text" class="mb-3 <?= $sel_mode === 'file' ? 'd-none' : '' ?>">
          <label class="form-label fw-semibold">
            <i class="bi bi-file-text"></i> Treść zaświadczenia
          </label>
          <textarea name="certificate_content" id="cert_content"
                    class="form-control font-monospace" rows="18"
                    style="font-size:.85rem"><?= h($_POST['certificate_content'] ?? $default_content) ?></textarea>
          <div class="d-flex justify-content-end mt-1">
            <button type="button" class="btn btn-sm btn-link text-muted p-0"
                    onclick="resetContent()">
              <i class="bi bi-arrow-counterclockwise"></i> Przywróć domyślny tekst
            </button>
          </div>
        </div>

        <div class="d-flex gap-2 pt-1">
          <button type="submit" class="btn btn-success">
            <i class="bi bi-send-check"></i> Wydaj zaświadczenie 
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Odrzuć -->
  <div class="card shadow-sm border-danger-subtle">
    <div class="card-header fw-semibold text-danger small">
      <i class="bi bi-x-circle"></i> Odrzuć wniosek
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="req_id" value="<?= $req_id ?>">
        <input type="hidden" name="action" value="reject">
        <div class="row g-2 align-items-end">
          <div class="col">
            <input type="text" name="rejection_note" class="form-control form-control-sm"
                   placeholder="Powód odrzucenia (opcjonalnie)">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    onclick="return confirm('Odrzucić wniosek? Wnioskodawca otrzyma powiadomienie.')">
              <i class="bi bi-x-lg"></i> Odrzuć
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php else: ?>
  <!-- Podgląd wydanego/odrzuconego -->
  <div class="card shadow-sm">
    <div class="card-header fw-semibold">
      Zaświadczenie — <?= certificate_status_badge($req['status']) ?>
    </div>
    <div class="card-body">

      <?php if (!empty($req['certificate_file'])): ?>
      <div class="mb-3">
        <div class="fw-semibold small mb-2"><i class="bi bi-paperclip"></i> Plik</div>
        <div class="d-flex gap-2">
          <a href="<?= h(certificate_file_url($req['certificate_file'])) ?>"
             target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-download"></i> Pobierz plik
          </a>
          <?php if (str_ends_with(strtolower($req['certificate_file']), '.pdf')): ?>
          <a href="<?= h(certificate_file_url($req['certificate_file'])) ?>"
             target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-eye"></i> Otwórz PDF
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($req['certificate_content'])): ?>
      <div class="mb-3">
        <div class="fw-semibold small mb-2"><i class="bi bi-file-text"></i> Treść tekstowa</div>
        <pre class="bg-light border rounded p-3" style="white-space:pre-wrap;font-size:.85rem"><?= h($req['certificate_content']) ?></pre>
        <a href="<?= APP_URL ?>/certificates/print.php?id=<?= $req_id ?>"
           target="_blank" class="btn btn-sm btn-outline-success">
          <i class="bi bi-printer"></i> Podgląd wydruku
        </a>
      </div>
      <?php endif; ?>

      <?php if ($req['rejection_note']): ?>
      <div class="alert alert-warning">
        <strong>Powód odrzucenia:</strong> <?= h($req['rejection_note']) ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
  <?php endif; ?>

</div>
</div>

<script>
const defaultContent = <?= json_encode($default_content) ?>;

function updateMode() {
    const mode = document.querySelector('[name="issue_mode"]:checked')?.value ?? 'text';
    document.getElementById('section-file').classList.toggle('d-none', mode === 'text');
    document.getElementById('section-text').classList.toggle('d-none', mode === 'file');

    document.querySelectorAll('.mode-card').forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        card.style.borderColor = radio.checked ? '#198754' : '#dee2e6';
    });
}

function resetContent() {
    if (confirm('Przywrócić domyślny tekst? Zmiany zostaną utracone.')) {
        document.getElementById('cert_content').value = defaultContent;
    }
}

document.getElementById('cert_file')?.addEventListener('change', function() {
    const preview = document.getElementById('file-preview');
    const nameEl  = document.getElementById('file-name');
    if (this.files.length) {
        nameEl.textContent = this.files[0].name;
        preview.classList.remove('d-none');
    } else {
        preview.classList.add('d-none');
    }
});

document.querySelectorAll('[name="issue_mode"]').forEach(r => r.addEventListener('change', updateMode));
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
