<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';
require_once __DIR__ . '/../includes/kdok_archive.php';
require_once __DIR__ . '/../includes/webauthn.php';

webauthn_migrate();

kdok_require_access();
kdok_migrate();

$id  = (int)($_GET['id'] ?? 0);
$doc = kdok_get($id);
if (!$doc) { http_response_code(404); die('Dokument nie istnieje.'); }

$user    = current_user();
$errors  = [];

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Aktualizacja metadanych dokumentu — zablokowana po zatwierdzeniu/odrzuceniu,
    // żeby nie dało się po fakcie zmienić kwoty/opisu na dokumencie podpisanym
    // już X.509 + WebAuthn/IKAKS (naruszałoby integralność zapisanej decyzji).
    if ($action === 'update_meta' && (is_admin() || kdok_has_role('upload'))) {
        if (in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true)) {
            flash_set('danger', 'Dokument jest już ' . ($doc['status'] === 'zaakceptowany' ? 'zaakceptowany' : 'odrzucony') . ' — edycja danych jest zablokowana.');
            header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
            exit;
        }

        $contract_type = trim($_POST['contract_type'] ?? '');
        $contract_id   = (int)($_POST['contract_id'] ?? 0);
        if ($doc['type'] !== 'rachunek' || !$contract_type || !$contract_id || !kdok_contract_label($contract_type, $contract_id)) {
            $contract_type = null; $contract_id = null;
        }

        kdok_exec(
            "UPDATE kdok_documents SET description=?, uwagi=?, kwota=?, grant_name=?, mpk=?, contract_type=?, contract_id=?, updated_at=datetime('now') WHERE id=?",
            [trim($_POST['description'] ?? ''), trim($_POST['uwagi'] ?? ''), trim($_POST['kwota'] ?? ''),
             trim($_POST['grant_name'] ?? ''), trim($_POST['mpk'] ?? ''), $contract_type, $contract_id, $id]
        );
        kdok_log($id, 'Zaktualizowano metadane dokumentu');
        flash_set('success', 'Dane zaktualizowane.');
        header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
        exit;
    }

    // Krok akceptacji: meryt / formal / zatwierdza
    if (in_array($action, ['meryt', 'formal', 'zatwierdza'], true)) {
        kdok_require_role($action);

        // Blokada: dokument już zaakceptowany lub odrzucony
        if (in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true)) {
            $errors[] = 'Dokument jest już ' . ($doc['status'] === 'zaakceptowany' ? 'zaakceptowany' : 'odrzucony') . ' — decyzja jest zablokowana.';
        }

        // Blokada wycofania: krok już podjęty
        $existing_step = $doc['steps'][$action] ?? null;
        if (!$errors && $existing_step && in_array($existing_step['status'], ['ok', 'uwagi', 'odrzucono'], true)) {
            $errors[] = 'Decyzja dla tego etapu została już podjęta i nie może być zmieniona.';
        }

        $status = $_POST['step_status'] ?? '';
        $notes  = trim($_POST['step_notes'] ?? '');
        $auth   = null;

        if (!$errors) {
            if (!in_array($status, ['ok', 'uwagi', 'odrzucono'], true)) {
                $errors[] = 'Wybierz decyzję.';
            } else {
                // Weryfikacja kluczem WebAuthn (albo, gdy brak klucza, kodem IKAKS) + certyfikat X.509
                $auth = kdok_auth_verify((int)$user['id'], $_POST['ikaks'] ?? '', $_POST['ikaks_reason'] ?? '', $_POST['bypass_ika'] ?? '');
                if (!$auth['ok']) {
                    $errors[] = $auth['error'];
                }
            }
        }

        if (!$errors) {
            $result = kdok_decide_step($doc, $action, $status, (int)$user['id'], $notes, $auth);
            flash_set($result['rejected'] ? 'warning' : 'success', $result['rejected'] ? 'Dokument odrzucony.' : 'Decyzja zapisana.');
            header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
            exit;
        }
    }

    // Zatwierdź wszystkie oczekujące kroki naraz — jedna autoryzacja kluczem WebAuthn
    if ($action === 'accept_all_steps') {
        if (in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true)) {
            $errors[] = 'Dokument jest już ' . ($doc['status'] === 'zaakceptowany' ? 'zaakceptowany' : 'odrzucony') . ' — decyzja jest zablokowana.';
        }

        $auth = null;
        if (!$errors) {
            $auth = kdok_auth_verify((int)$user['id'], $_POST['ikaks'] ?? '', $_POST['ikaks_reason'] ?? '', $_POST['bypass_ika'] ?? '');
            if (!$auth['ok']) {
                $errors[] = $auth['error'];
            }
        }

        if (!$errors) {
            $acted = false;
            foreach (array_keys(KDOK_STEPS) as $step_key) {
                if (!kdok_has_role($step_key)) continue;
                $existing = $doc['steps'][$step_key] ?? null;
                if ($existing && in_array($existing['status'], ['ok', 'uwagi', 'odrzucono'], true)) continue;

                kdok_decide_step($doc, $step_key, 'ok', (int)$user['id'], '', $auth);
                $acted = true;
                $doc = kdok_get($id);
                if (in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true)) break;
            }
            flash_set($acted ? 'success' : 'warning', $acted ? 'Zatwierdzono wszystkie oczekujące kroki.' : 'Brak kroków do zatwierdzenia.');
            header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
            exit;
        }
    }

    // Generuj PDF
    if ($action === 'generate_pdf') {
        kdok_require_role('zatwierdza');
        try {
            kdok_generate_final_pdf($id);
            flash_set('success', 'Dokument końcowy został wygenerowany.');
        } catch (Throwable $e) {
            flash_set('danger', 'Błąd generowania PDF: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
        exit;
    }

    // Odrzucenie dokumentu — zablokowane, gdy dokument jest już zaakceptowany
    // (zatwierdzenie jest ostateczne; cofnięcie po fakcie wymagałoby osobnej,
    // świadomej procedury, nie zwykłego przycisku "Odrzuć").
    if ($action === 'toggle_wyklucz' && (is_admin() || kdok_has_role('zatwierdza'))) {
        $nowy = (int)$doc['wyklucz_z_preliminarza'] ? 0 : 1;
        kdok_exec("UPDATE kdok_documents SET wyklucz_z_preliminarza=?, updated_at=datetime('now') WHERE id=?", [$nowy, $id]);
        kdok_log($id, $nowy ? 'Wyłączono z Preliminarza Płatności' : 'Przywrócono do Preliminarza Płatności');
        flash_set($nowy ? 'warning' : 'success', $nowy ? 'Dokument wykluczony z Preliminarza Płatności.' : 'Dokument przywrócony do Preliminarza Płatności.');
        header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
        exit;
    }

    if ($action === 'reject' && is_admin()) {
        if ($doc['status'] === 'zaakceptowany') {
            flash_set('danger', 'Dokument jest już zaakceptowany — nie można go odrzucić.');
            header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
            exit;
        }
        $note = trim($_POST['reject_note'] ?? '');
        kdok_exec("UPDATE kdok_documents SET status='odrzucony', updated_at=datetime('now') WHERE id=?", [$id]);
        kdok_log($id, 'Dokument odrzucony', $note);
        flash_set('warning', 'Dokument oznaczony jako odrzucony.');
        header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
        exit;
    }
}

$doc     = kdok_get($id);
$history = kdok_get_history($id);
$PAGE_TITLE = 'Dokument ' . $doc['number'];
// Dokument zatwierdzony lub odrzucony jest zablokowany do edycji (patrz guardy
// przy akcjach update_meta/reject powyżej — to tylko lustrzana blokada w UI).
$kdok_locked = in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0 me-auto"><i class="bi bi-file-earmark-check"></i> <?= h($doc['number']) ?></h4>
  <?= kdok_status_badge($doc['status']) ?>
  <?php if (is_admin() || kdok_has_role('zatwierdza')): ?>
  <?php if (!empty($doc['wyklucz_z_preliminarza'])): ?>
  <span class="badge bg-secondary"><i class="bi bi-eye-slash me-1"></i>Poza Preliminarzem</span>
  <?php endif; ?>
  <form method="post" class="d-inline">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="toggle_wyklucz">
    <button type="submit" class="btn btn-sm <?= !empty($doc['wyklucz_z_preliminarza']) ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
            title="<?= !empty($doc['wyklucz_z_preliminarza']) ? 'Przywróć do Preliminarza' : 'Nie dodawaj do Preliminarza' ?>">
      <i class="bi <?= !empty($doc['wyklucz_z_preliminarza']) ? 'bi-eye' : 'bi-eye-slash' ?>"></i>
      <?= !empty($doc['wyklucz_z_preliminarza']) ? 'Przywróć do Preliminarza' : 'Nie dodawaj do Preliminarza' ?>
    </button>
  </form>
  <?php endif; ?>
  <?php if (is_admin()): ?>
  <a href="<?= APP_URL ?>/ksiegowosc/delete.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger"
     title="Usuń dokument (wymagany powód + protokół PDF)"><i class="bi bi-trash"></i></a>
  <?php endif; ?>
</div>

<?= flash_html() ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
  <!-- Lewa kolumna: info + akceptacje -->
  <div class="col-lg-8">

    <!-- Dane dokumentu -->
    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span><i class="<?= h(KDOK_TYPES[$doc['type']]['icon'] ?? 'bi-file') ?>"></i>
          <strong><?= h(KDOK_TYPES[$doc['type']]['label'] ?? $doc['type']) ?></strong></span>
        <span class="text-muted small">Dodano: <?= date_pl($doc['created_at']) ?> przez <?= h($doc['creator_name']) ?></span>
      </div>
      <div class="card-body">
        <div class="mb-2">
          <strong>Tytuł:</strong> <?= h($doc['title']) ?>
        </div>
        <?php if ($doc['file_path']): ?>
        <div class="mb-2">
          <strong>Plik:</strong>
          <a href="<?= APP_URL ?>/ksiegowosc/download.php?id=<?= $id ?>&type=orig" class="btn btn-sm btn-outline-secondary ms-1" target="_blank">
            <i class="bi bi-file-earmark-pdf text-danger"></i> Pobierz oryginał
          </a>
          <span class="text-muted small ms-2"><?= number_format(($doc['file_size'] ?? 0) / 1024, 1) ?> KB</span>
        </div>
        <div class="mb-2 small text-muted">
          <strong>SHA-256:</strong> <code><?= h($doc['file_sha256']) ?></code>
        </div>
        <?php endif; ?>

        <!-- Metadane dokumentu -->
        <hr class="my-2">
        <?php if ($doc['kwota'] || $doc['mpk'] || $doc['grant_name']): ?>
        <div class="row g-2 mb-2 small">
          <?php if ($doc['kwota']): ?>
          <div class="col-auto"><span class="text-muted">Kwota:</span> <strong><?= h($doc['kwota']) ?> PLN</strong></div>
          <?php endif; ?>
          <?php if ($doc['mpk']): ?>
          <div class="col-auto"><span class="text-muted">MPK:</span> <strong><?= h($doc['mpk']) ?></strong></div>
          <?php endif; ?>
          <?php if ($doc['grant_name']): ?>
          <div class="col-auto"><span class="bi bi-award text-warning"></span> <span class="text-muted">Grant:</span> <strong><?= h($doc['grant_name']) ?></strong></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php $linked_contract = kdok_contract_label($doc['contract_type'] ?? null, $doc['contract_id'] ?? null); ?>
        <?php if ($linked_contract): ?>
        <div class="mb-2 small">
          <i class="bi bi-person-vcard text-primary"></i> <span class="text-muted">Umowa:</span>
          <a href="<?= h($linked_contract['url']) ?>" target="_blank"><?= h($linked_contract['label']) ?></a>
        </div>
        <?php endif; ?>

        <?php if ($kdok_locked && (is_admin() || kdok_has_role('upload'))): ?>
        <div class="small text-muted mb-2">
          <i class="bi bi-lock-fill"></i> Dokument <?= $doc['status'] === 'zaakceptowany' ? 'zaakceptowany' : 'odrzucony' ?> — dane nie mogą już być zmieniane.
        </div>
        <?php if ($doc['description']): ?>
        <p class="mb-0 small"><strong>Opis merytoryczny:</strong> <?= nl2br(h($doc['description'])) ?></p>
        <?php endif; ?>
        <?php elseif (!$errors && (is_admin() || kdok_has_role('upload'))): ?>
        <button class="btn btn-sm btn-outline-secondary mb-2" type="button"
          data-bs-toggle="collapse" data-bs-target="#metaForm">
          <i class="bi bi-pencil"></i> Edytuj dane dokumentu
        </button>
        <div class="collapse" id="metaForm">
        <form method="post" class="border rounded p-2 bg-light mb-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="update_meta">
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Opis merytoryczny</label>
            <textarea name="description" class="form-control form-control-sm" rows="3"
              placeholder="Cel wydatku, powiązanie z projektem…"><?= h($doc['description']) ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Uwagi</label>
            <input type="text" name="uwagi" class="form-control form-control-sm"
              value="<?= h($doc['uwagi']) ?>" maxlength="500">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-sm-6">
              <label class="form-label small fw-semibold mb-1">Kwota (PLN)</label>
              <input type="text" name="kwota" class="form-control form-control-sm"
                value="<?= h($doc['kwota']) ?>" maxlength="50">
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold mb-1"><i class="bi bi-award"></i> Grant</label>
              <input type="text" name="grant_name" class="form-control form-control-sm"
                value="<?= h($doc['grant_name']) ?>" maxlength="255"
                placeholder="Nazwa grantu (opcjonalnie)">
            </div>
          </div>
          <?php if (kdok_mpk_enabled()): ?>
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">MPK / Centrum kosztów</label>
            <?php $mpk_list = kdok_mpk_list(); ?>
            <?php if ($mpk_list): ?>
            <select name="mpk" class="form-select form-select-sm">
              <option value="">— wybierz —</option>
              <?php foreach ($mpk_list as $m): ?>
              <option value="<?= h($m) ?>" <?= $doc['mpk'] === $m ? 'selected' : '' ?>><?= h($m) ?></option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" name="mpk" class="form-control form-control-sm"
              value="<?= h($doc['mpk']) ?>" maxlength="100">
            <?php endif; ?>
          </div>
          <?php else: ?>
          <input type="hidden" name="mpk" value="<?= h($doc['mpk']) ?>">
          <?php endif; ?>
          <?php if ($doc['type'] === 'rachunek'): ?>
          <?php $cur_contract = kdok_contract_label($doc['contract_type'] ?? null, $doc['contract_id'] ?? null); ?>
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1"><i class="bi bi-person-vcard"></i> Umowa <span class="text-muted fw-normal">(opcjonalnie)</span></label>
            <input type="text" id="metaContractSearch" class="form-control form-control-sm" autocomplete="off"
              value="<?= h($cur_contract['label'] ?? '') ?>" placeholder="Szukaj po numerze umowy lub nazwisku…">
            <input type="hidden" id="metaContractType" name="contract_type" value="<?= h($doc['contract_type'] ?? '') ?>">
            <input type="hidden" id="metaContractId"   name="contract_id"   value="<?= h($doc['contract_id']   ?? '') ?>">
            <div id="metaContractResults" class="list-group mt-1" style="display:none;position:absolute;z-index:20;max-width:600px"></div>
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Zapisz</button>
        </form>
        </div>
        <?php elseif ($doc['description']): ?>
        <p class="mb-0 small"><strong>Opis merytoryczny:</strong> <?= nl2br(h($doc['description'])) ?></p>
        <?php else: ?>
        <p class="text-muted small mb-0"><em>Brak opisu merytorycznego.</em></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Kroki akceptacji -->
    <?php
    $steps_config = [
        'formal'     => ['label' => 'Sprawdzono pod kątem formalnym i rachunkowym', 'icon' => 'bi-calculator', 'color' => 'info'],
        'meryt'      => ['label' => 'Sprawdzono pod kątem merytorycznym', 'icon' => 'bi-patch-check', 'color' => 'primary'],
        'zatwierdza' => ['label' => 'Zatwierdzam do wypłaty', 'icon' => 'bi-cash-coin', 'color' => 'success'],
    ];

    // Czy zalogowany użytkownik ma więcej niż jeden nierozstrzygnięty krok — pod zbiorczą akceptację
    $can_accept_all = 0;
    if (!in_array($doc['status'], ['zaakceptowany', 'odrzucony'], true)) {
        foreach (array_keys($steps_config) as $step_key_chk) {
            if (!kdok_has_role($step_key_chk)) continue;
            $st_chk = $doc['steps'][$step_key_chk]['status'] ?? null;
            if (!in_array($st_chk, ['ok', 'uwagi', 'odrzucono'], true)) $can_accept_all++;
        }
    }
    ?>
    <?php if ($can_accept_all > 1): ?>
    <div class="mb-3">
      <button type="button" class="btn btn-sm btn-success kdok-open-ikaks"
        data-form="form_accept_all" data-label="Zatwierdź wszystkie kroki naraz (<?= $can_accept_all ?>)">
        <i class="bi bi-check2-all"></i> Zatwierdź wszystkie kroki naraz (<?= $can_accept_all ?>) — jedna autoryzacja
      </button>
      <form method="post" id="form_accept_all" style="display:none">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="accept_all_steps">
        <input type="hidden" name="ikaks" value="" class="kdok-ikaks-value">
        <input type="hidden" name="ikaks_reason" value="" class="kdok-ikaks-reason-value">
        <input type="hidden" name="bypass_ika" value="" class="kdok-bypass-ika-value">
      </form>
    </div>
    <?php endif; ?>
    <?php foreach ($steps_config as $step_key => $cfg): ?>
    <?php
    $step    = $doc['steps'][$step_key] ?? null;
    $decided = $step && in_array($step['status'], ['ok', 'uwagi', 'odrzucono'], true);
    $can_act = !$decided && kdok_has_role($step_key) && !in_array($doc['status'], ['odrzucony', 'zaakceptowany']);
    ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span>
          <i class="bi <?= $cfg['icon'] ?> text-<?= $cfg['color'] ?>"></i>
          <strong><?= h($cfg['label']) ?></strong>
        </span>
        <?php if ($decided): ?>
          <?php if ($step['status'] === 'ok'): ?>
          <span class="badge bg-success"><i class="bi bi-check-lg"></i> Tak</span>
          <?php elseif ($step['status'] === 'odrzucono'): ?>
          <span class="badge bg-danger"><i class="bi bi-x-lg"></i> Odrzucono</span>
          <?php else: ?>
          <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Z uwagami</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="badge bg-secondary">Oczekuje</span>
        <?php endif; ?>
      </div>
      <div class="card-body py-2">
        <?php if ($decided): ?>
        <div class="small">
          <span class="text-muted">Przez:</span> <strong><?= h($step['cert_cn'] ?: ($step['user_name'] ?? '—')) ?></strong>
          &nbsp;|&nbsp; <?= date_pl($step['decided_at']) ?> <?= date('H:i', strtotime($step['decided_at'])) ?>
          <?php if ($step['cert_fingerprint']): ?>
          &nbsp;|&nbsp; <span class="badge bg-secondary"><i class="bi bi-patch-check"></i> X.509</span>
          <div class="text-muted mt-1" style="font-size:.7rem;word-break:break-all">
            <i class="bi bi-fingerprint"></i> <?= h($step['cert_fingerprint']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($step['notes']): ?>
        <div class="mt-1 text-warning-emphasis small"><strong>Uwagi:</strong> <?= nl2br(h($step['notes'])) ?></div>
        <?php endif; ?>
        <?php elseif ($can_act): ?>
        <p class="text-muted small mb-2">Oczekuje na Twoją decyzję.</p>
        <?php else: ?>
        <p class="text-muted small mb-0">Oczekuje — brak uprawnień.</p>
        <?php endif; ?>

        <?php if ($can_act): ?>
        <div class="mt-2 border-top pt-2" id="step_form_<?= $step_key ?>">
          <form method="post"
            id="form_<?= $step_key ?>">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="<?= $step_key ?>">
            <input type="hidden" name="ikaks" value="" class="kdok-ikaks-value">
        <input type="hidden" name="ikaks_reason" value="" class="kdok-ikaks-reason-value">
        <input type="hidden" name="bypass_ika" value="" class="kdok-bypass-ika-value">
            <div class="d-flex gap-3 mb-2 flex-wrap">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="step_status"
                  id="<?= $step_key ?>_ok" value="ok">
                <label class="form-check-label text-success fw-semibold" for="<?= $step_key ?>_ok">
                  <i class="bi bi-check-circle-fill"></i> Tak / OK
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="step_status"
                  id="<?= $step_key ?>_uwagi" value="uwagi">
                <label class="form-check-label text-warning fw-semibold" for="<?= $step_key ?>_uwagi">
                  <i class="bi bi-exclamation-circle-fill"></i> Z uwagami
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="step_status"
                  id="<?= $step_key ?>_odrzucono" value="odrzucono">
                <label class="form-check-label text-danger fw-semibold" for="<?= $step_key ?>_odrzucono">
                  <i class="bi bi-x-circle-fill"></i> Odrzuć
                </label>
              </div>
            </div>
            <div class="mb-2">
              <textarea name="step_notes" class="form-control form-control-sm" rows="2"
                placeholder="Ewentualne uwagi lub powód odrzucenia…"></textarea>
            </div>
            <button type="button" class="btn btn-sm btn-<?= $cfg['color'] ?> kdok-open-ikaks"
              data-form="form_<?= $step_key ?>" data-label="<?= h($cfg['label']) ?>">
              <i class="bi bi-shield-lock"></i> Autoryzuj i zapisz
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Generuj PDF + raport + odrzucenie (admin) -->
    <div class="d-flex gap-2 flex-wrap mb-3">
      <?php if ($doc['generated']): ?>
      <button type="button" class="btn btn-outline-secondary disabled" disabled
              title="Dostępny jest już oficjalny dokument końcowy (poniżej) — raport na żywo jest wyłączony, aby uniknąć dwóch różnych wersji dokumentu.">
        <i class="bi bi-printer"></i> Raport weryfikacji
      </button>
      <?php else: ?>
      <a href="<?= APP_URL ?>/ksiegowosc/raport.php?id=<?= $id ?>" target="_blank"
         class="btn btn-outline-secondary">
        <i class="bi bi-printer"></i> Raport weryfikacji
      </a>
      <?php endif; ?>
      <?php if (kdok_has_role('zatwierdza')): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="generate_pdf">
        <button type="submit" class="btn btn-outline-primary">
          <i class="bi bi-file-earmark-arrow-down"></i> Generuj dokument końcowy
        </button>
      </form>
      <?php endif; ?>
      <?php if (is_admin() && !$kdok_locked): ?>
      <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
        <i class="bi bi-x-circle"></i> Odrzuć dokument (admin)
      </button>
      <?php endif; ?>
    </div>

    <!-- Dokument końcowy -->
    <?php if ($doc['generated']): ?>
    <div class="card shadow-sm mb-3 border-success">
      <div class="card-header bg-success text-white py-2">
        <i class="bi bi-file-earmark-check-fill"></i> <strong>Dokument końcowy</strong>
      </div>
      <div class="card-body py-2 small">
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <a href="<?= APP_URL ?>/ksiegowosc/download.php?id=<?= $id ?>&type=final" class="btn btn-success btn-sm">
            <i class="bi bi-download"></i> Pobierz dokument końcowy
          </a>
          <span class="text-muted">
            Wygenerowano: <?= date_pl($doc['generated']['generated_at']) ?>
            <?= date('H:i', strtotime($doc['generated']['generated_at'])) ?>
            przez <?= h($doc['generated']['gen_name']) ?>
          </span>
        </div>
        <div class="mt-1 text-muted">
          <strong>SHA-256:</strong> <code><?= h($doc['generated']['file_sha256']) ?></code>
          &nbsp;|&nbsp; <?= number_format($doc['generated']['file_size'] / 1024, 1) ?> KB
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Prawa kolumna: historia -->
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header py-2"><i class="bi bi-clock-history"></i> <strong>Historia obiegu</strong></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush small">
          <?php if (!$history): ?>
          <li class="list-group-item text-muted">Brak wpisów.</li>
          <?php endif; ?>
          <?php foreach (array_reverse($history) as $h_row): ?>
          <li class="list-group-item py-2">
            <div class="fw-semibold"><?= h($h_row['user_name']) ?></div>
            <div><?= h($h_row['action']) ?></div>
            <?php if ($h_row['note']): ?>
            <div class="text-muted fst-italic"><?= h($h_row['note']) ?></div>
            <?php endif; ?>
            <div class="text-muted small"><?= date('d.m.Y H:i', strtotime($h_row['created_at'])) ?></div>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- Modal odrzucenia -->
<?php if (is_admin()): ?>
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="reject">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> Odrzuć dokument</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Powód odrzucenia (opcjonalny)</label>
        <textarea name="reject_note" class="form-control" rows="3" placeholder="Opisz powód…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger">Odrzuć</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal IKAKS — wspólny dla wszystkich kroków ─────────────────────────── -->
<?php
$my_cert           = kdok_cert_get((int)$user['id']);
$cert_ok           = $my_cert && kdok_cert_is_valid($my_cert);
$has_webauthn      = webauthn_user_has_keys((int)$user['id']);
$has_ikaks         = kdok_ikaks_has((int)$user['id']);
$has_ms365         = !empty($user['microsoft_id']);
$ms365_session_ok  = kdok_ms365_session_ok((int)$user['id']);
$ms365_expires_at  = $ms365_session_ok ? kdok_ms365_session_expires_at((int)$user['id']) : null;
$bypass_session_ok = $has_webauthn && kdok_bypass_session_ok((int)$user['id']);
$bypass_expires_at = $bypass_session_ok ? kdok_bypass_session_expires_at((int)$user['id']) : null;
$bypass_pending    = $has_webauthn && $ms365_session_ok && !$bypass_session_ok;
$auth_ready        = $cert_ok && ($has_webauthn || $has_ms365 || $has_ikaks);
$ikaks_session_ok  = !$has_webauthn && !$ms365_session_ok && kdok_ikaks_session_ok((int)$user['id']);
$ikaks_expires_at  = $ikaks_session_ok ? kdok_ikaks_session_expires_at((int)$user['id']) : null;
$stepup_url        = APP_URL . '/ksiegowosc/ms365_stepup.php?return_to=' . urlencode(APP_URL . '/ksiegowosc/view.php?id=' . $id);
?>
<div class="modal fade" id="ikaksModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">
          <i class="bi bi-shield-lock-fill"></i>
          Autoryzacja — <span id="ikaksModalLabel">…</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- Status klucza WebAuthn / metody auth -->
        <?php if ($bypass_session_ok): ?>
        <div class="mb-3 p-2 rounded border border-success bg-success bg-opacity-10">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-shield-check-fill fs-4 text-success"></i>
            <div>
              <div class="fw-semibold">Bypass MS365 + IKA aktywny</div>
              <div class="small text-muted">Ważny do <?= date('H:i', $bypass_expires_at) ?> — klucz WebAuthn nie jest teraz wymagany.</div>
            </div>
          </div>
        </div>
        <?php elseif ($bypass_pending): ?>
        <div class="mb-3 p-2 rounded border border-info bg-info bg-opacity-10">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-microsoft fs-4 text-info"></i>
            <div>
              <div class="fw-semibold">Microsoft 365 zweryfikowane</div>
              <div class="small text-muted">Podaj kod IKA poniżej, aby dokończyć autoryzację bez klucza WebAuthn.</div>
            </div>
          </div>
        </div>
        <?php elseif ($has_webauthn): ?>
        <div class="mb-3 p-2 rounded border border-success bg-success bg-opacity-10">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-usb-symbol fs-4 text-success"></i>
            <div>
              <div class="fw-semibold">Klucz WebAuthn zarejestrowany</div>
              <div class="small text-muted">Wymagana świeża weryfikacja kluczem sprzętowym.</div>
            </div>
          </div>
        </div>
        <?php elseif ($has_ms365): ?>
        <div class="mb-3 p-2 rounded border border-primary bg-primary bg-opacity-10">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-microsoft fs-4 text-primary"></i>
            <div>
              <div class="fw-semibold">Microsoft 365 dostępne</div>
              <div class="small text-muted">Możesz potwierdzić tożsamość kontem Microsoft 365 zamiast kluczem WebAuthn.</div>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="mb-3 p-2 rounded border border-warning bg-warning bg-opacity-10">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-usb-symbol fs-4 text-warning"></i>
            <div>
              <div class="fw-semibold text-warning-emphasis">Brak zarejestrowanego klucza WebAuthn</div>
              <div class="small text-muted">
                Możesz awaryjnie użyć kodu IKAKS poniżej. Docelowo zarejestruj klucz w
                <a href="<?= APP_URL ?>/panel/webauthn.php" target="_blank">Mój profil → Klucze bezpieczeństwa</a>.
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Status certyfikatu -->
        <div class="mb-3 p-2 rounded border <?= $cert_ok ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10' ?>">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-patch-<?= $cert_ok ? 'check-fill text-success' : 'x-fill text-danger' ?> fs-4"></i>
            <div>
              <?php if ($cert_ok): ?>
              <div class="fw-semibold"><?= h($my_cert['subject_cn']) ?></div>
              <div class="small text-muted">
                Certyfikat X.509 aktywny · ważny do <?= date('d.m.Y', strtotime($my_cert['valid_to'])) ?>
              </div>
              <div class="text-muted" style="font-size:.65rem;word-break:break-all">
                <?= h($my_cert['fingerprint_sha256']) ?>
              </div>
              <?php elseif ($my_cert): ?>
              <div class="fw-semibold text-danger">Certyfikat wygasł</div>
              <div class="small"><?= h($my_cert['subject_cn']) ?> · wygasł <?= date('d.m.Y', strtotime($my_cert['valid_to'])) ?></div>
              <?php else: ?>
              <div class="fw-semibold text-danger">Brak certyfikatu X.509</div>
              <div class="small text-muted">Skontaktuj się z administratorem.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!$auth_ready): ?>
        <div class=”alert alert-danger mb-0”>
          Autoryzacja niemożliwa. Wymagany ważny certyfikat X.509 oraz zarejestrowany klucz WebAuthn,
          powiązane konto Microsoft 365 albo (awaryjnie) ustawiony kod IKAKS.
          <?php if (!$my_cert): ?>
          Poproś admina o dodanie certyfikatu w
          <a href=”<?= APP_URL ?>/admin/kdok_certs.php”>Certyfikaty X.509 i IKAKS</a>.
          <?php endif; ?>
        </div>

        <?php elseif ($bypass_session_ok): ?>
        <div class=”alert alert-success mb-0”>
          <i class=”bi bi-check-circle-fill”></i> Bypass MS365 + IKA aktywny do
          <strong><?= date('H:i', $bypass_expires_at) ?></strong> — kliknij „Potwierdź autoryzację”.
        </div>

        <?php elseif ($bypass_pending): ?>
        <div>
          <label class=”form-label fw-semibold”>
            <i class=”bi bi-key-fill text-info”></i>
            Kod IKA — ostatni krok autoryzacji bez klucza
          </label>
          <input type=”password” id=”bypassIkaInput” class=”form-control form-control-lg”
            placeholder=”6-cyfrowy kod IKA…” maxlength=”6” inputmode=”numeric” autocomplete=”off”>
          <div class=”form-text mb-1”>
            Twój kod IKA (6 cyfr) nadany przez administratora.
          </div>
          <div id=”bypassIkaError” class=”text-danger small mt-1” style=”display:none”>
            Wpisz 6-cyfrowy kod IKA przed zatwierdzeniem.
          </div>
        </div>

        <?php elseif ($has_webauthn): ?>
        <div id=”webauthnStep”>
          <label class=”form-label fw-semibold”>
            <i class=”bi bi-usb-symbol text-primary”></i>
            Zweryfikuj kluczem WebAuthn
          </label>
          <div class=”form-text mt-0 mb-2”>
            Po dotknięciu klucza decyzja zapisze się automatycznie.
          </div>
          <div class=”d-flex align-items-center gap-2 flex-wrap”>
            <button type=”button” id=”webauthnConfirm” class=”btn btn-primary”>
              <i class=”bi bi-usb-plug”></i> Dotknij klucz WebAuthn i zapisz
            </button>
            <span id=”webauthnSpinner” class=”spinner-border spinner-border-sm text-primary” style=”display:none”></span>
            <span id=”webauthnOk” class=”text-success fw-semibold” style=”display:none”>
              <i class=”bi bi-check-circle-fill”></i> Zweryfikowano
            </span>
          </div>
          <div id=”webauthnError” class=”text-danger small mt-1” style=”display:none”></div>
          <?php if ($has_ms365): ?>
          <hr class=”my-3”>
          <div class=”d-flex align-items-center gap-2 flex-wrap”>
            <a href=”<?= $stepup_url ?>&bypass=1” class=”btn btn-sm btn-outline-secondary”>
              <i class=”bi bi-microsoft me-1”></i>Nie mam klucza przy sobie…
            </a>
            <span class=”text-muted small”>Alternatywa: weryfikacja MS365 + kod IKA (ważna 24h)</span>
          </div>
          <?php endif; ?>
        </div>

        <?php elseif ($ms365_session_ok): ?>
        <div class=”alert alert-success mb-0”>
          <i class=”bi bi-check-circle-fill”></i> Sesja Microsoft 365 aktywna do
          <strong><?= date('H:i', $ms365_expires_at) ?></strong> — kliknij „Potwierdź autoryzację”.
        </div>

        <?php elseif ($has_ms365): ?>
        <div>
          <p class=”mb-2 text-muted small”>
            Zostaniesz przekierowany do Microsoft, aby potwierdzić tożsamość.
            Po powrocie otwórz ponownie okno i kliknij „Potwierdź”.
          </p>
        </div>

        <?php elseif ($ikaks_session_ok): ?>
        <div class=”alert alert-success mb-0”>
          <i class=”bi bi-check-circle-fill”></i> Sesja awaryjna IKAKS aktywna do
          <strong><?= date('H:i', $ikaks_expires_at) ?></strong> — kliknij „Potwierdź autoryzację”.
        </div>

        <?php else: ?>
        <div>
          <label class=”form-label fw-semibold”>
            <i class=”bi bi-key-fill text-warning”></i>
            IKAKS — Indywidualny Kod Autoryzacyjny (awaryjnie, brak klucza WebAuthn)
          </label>
          <input type=”password” id=”ikaksInput” class=”form-control form-control-lg”
            placeholder=”Wpisz swój kod IKAKS…” autocomplete=”off”>
          <div class=”form-text mb-2”>
            Podaj kod IKAKS, który nadał Ci administrator. Możesz go zmienić w
            <a href=”<?= APP_URL ?>/user/kdok_ikaks.php” target=”_blank”>Moim profilu → IKAKS</a>.
          </div>
          <div id=”ikaksError” class=”text-danger small mt-1 mb-2” style=”display:none”>
            Wpisz kod IKAKS przed zatwierdzeniem.
          </div>
          <label class=”form-label fw-semibold”>Powód użycia kodu IKAKS zamiast klucza WebAuthn</label>
          <textarea id=”ikaksReasonInput” class=”form-control” rows=”2”
            placeholder=”Np. klucz zgubiony/w naprawie, jeszcze nie zarejestrowany…”></textarea>
          <div class=”form-text”>
            Kod wystarczy podać raz na 6 godzin — kolejne decyzje w tym oknie czasowym nie wymagają ponownej autoryzacji.
          </div>
          <div id=”ikaksReasonError” class=”text-danger small mt-1” style=”display:none”>
            Podaj powód użycia kodu IKAKS.
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <?php if ($auth_ready): ?>
          <?php if ($has_ms365 && !$has_webauthn && !$ms365_session_ok): ?>
          <a href="<?= $stepup_url ?>" class="btn btn-primary">
            <i class="bi bi-microsoft me-1"></i>Autoryzuj przez Microsoft 365
          </a>
          <?php elseif (!$has_webauthn || $bypass_session_ok || $bypass_pending): ?>
          <button type="button" id="ikaksConfirm" class="btn btn-dark">
            <i class="bi bi-shield-check"></i> Potwierdź autoryzację
          </button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<input type="hidden" id="kdokWebauthnCsrf" value="<?= csrf_token() ?>">
<input type="hidden" id="kdokWebauthnBeginUrl" value="<?= APP_URL ?>/ksiegowosc/webauthn_begin.php">
<input type="hidden" id="kdokWebauthnVerifyUrl" value="<?= APP_URL ?>/ksiegowosc/webauthn_verify.php">

<script>
// Skrypt działa po załadowaniu Bootstrap (który jest w footer.php)
window.addEventListener('load', function () {
  var _targetForm = null;
  var _modalEl    = document.getElementById('ikaksModal');
  if (!_modalEl) return;

  var _labelEl    = document.getElementById('ikaksModalLabel');

  // ── Krok IKAKS (awaryjnie, gdy brak klucza WebAuthn) ────────────────────────
  var _inp          = document.getElementById('ikaksInput');
  var _errorEl      = document.getElementById('ikaksError');
  var _reasonInp    = document.getElementById('ikaksReasonInput');
  var _reasonErrorEl= document.getElementById('ikaksReasonError');
  var _confirmBtn   = document.getElementById('ikaksConfirm');
  var _bypassIkaInp = document.getElementById('bypassIkaInput');
  var _bypassIkaErr = document.getElementById('bypassIkaError');

  // ── Krok WebAuthn ────────────────────────────────────────────────────────
  var _waBtn      = document.getElementById('webauthnConfirm');
  var _waSpinner  = document.getElementById('webauthnSpinner');
  var _waOk       = document.getElementById('webauthnOk');
  var _waError    = document.getElementById('webauthnError');
  var _waVerified = false;

  function b64u_to_ab(str) {
    var s = str.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }

  function ab_to_b64u(buf) {
    var bytes = new Uint8Array(buf);
    var bin   = '';
    for (var i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function setWebauthnVerified(ok) {
    _waVerified = ok;
    if (_waOk) _waOk.style.display = ok ? '' : 'none';
  }

  async function doWebauthn() {
    if (!_waBtn) return;
    var csrf      = document.getElementById('kdokWebauthnCsrf').value;
    var beginUrl  = document.getElementById('kdokWebauthnBeginUrl').value;
    var verifyUrl = document.getElementById('kdokWebauthnVerifyUrl').value;

    _waBtn.disabled = true;
    if (_waSpinner) _waSpinner.style.display = '';
    if (_waError) { _waError.style.display = 'none'; _waError.textContent = ''; }

    try {
      var beginResp = await fetch(beginUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: csrf })
      });
      var beginData = await beginResp.json();
      if (!beginData.ok) throw new Error(beginData.message || 'Błąd inicjalizacji');

      var opts = beginData.options;
      opts.challenge = b64u_to_ab(opts.challenge);
      if (opts.allowCredentials) {
        opts.allowCredentials = opts.allowCredentials.map(function (c) {
          return Object.assign({}, c, { id: b64u_to_ab(c.id) });
        });
      }

      var credential = await navigator.credentials.get({ publicKey: opts });

      var credData = {
        id:                credential.id,
        rawId:             ab_to_b64u(credential.rawId),
        clientDataJSON:    ab_to_b64u(credential.response.clientDataJSON),
        authenticatorData: ab_to_b64u(credential.response.authenticatorData),
        signature:         ab_to_b64u(credential.response.signature),
        userHandle:        credential.response.userHandle ? ab_to_b64u(credential.response.userHandle) : null,
      };

      var verifyResp = await fetch(verifyUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: csrf, response: credData })
      });
      var verifyData = await verifyResp.json();
      if (!verifyData.ok) throw new Error(verifyData.message || 'Błąd weryfikacji klucza');

      setWebauthnVerified(true);
      doConfirm();
    } catch (e) {
      setWebauthnVerified(false);
      if (_waError) {
        _waError.style.display = '';
        _waError.textContent = e.message || 'Nie udało się zweryfikować klucza. Spróbuj ponownie.';
      }
    } finally {
      _waBtn.disabled = false;
      if (_waSpinner) _waSpinner.style.display = 'none';
    }
  }

  if (_waBtn) _waBtn.addEventListener('click', doWebauthn);

  // Lazily get Bootstrap Modal (bootstrap jest gwarantowanie załadowany przy 'load')
  function bsModal() {
    return bootstrap.Modal.getOrCreateInstance(_modalEl);
  }

  // ── Otwieranie modalu ─────────────────────────────────────────────────────
  document.querySelectorAll('.kdok-open-ikaks').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = document.getElementById(this.dataset.form);
      if (!form) return;

      var radios = form.querySelectorAll('input[name="step_status"]');
      if (radios.length) {
        var checked = Array.from(radios).some(function (r) { return r.checked; });
        if (!checked) {
          var wrap = radios[0] && radios[0].closest('.d-flex');
          if (wrap) {
            wrap.classList.add('border', 'border-danger', 'rounded', 'p-1');
            wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          return;
        }
        // Ostrzeżenie przy wyborze "Odrzuć"
        var selected = Array.from(radios).find(function(r){ return r.checked; });
        if (selected && selected.value === 'odrzucono') {
          if (!confirm('Czy na pewno chcesz ODRZUCIĆ dokument? Tej decyzji nie można cofnąć.')) return;
        }
      }

      _targetForm = form;
      if (_labelEl) _labelEl.textContent = this.dataset.label || '';
      setWebauthnVerified(false);
      if (_waError)       _waError.style.display = 'none';
      if (_inp)           _inp.value = '';
      if (_errorEl)       _errorEl.style.display = 'none';
      if (_reasonInp)     _reasonInp.value = '';
      if (_reasonErrorEl) _reasonErrorEl.style.display = 'none';

      bsModal().show();
    });
  });

  // ── Focus na polu IKAKS po otwarciu (tryb awaryjny) ─────────────────────────
  _modalEl.addEventListener('shown.bs.modal', function () {
    if (_inp) _inp.focus();
  });

  // ── Potwierdzenie ─────────────────────────────────────────────────────────
  function doConfirm() {
    // Tryb bypass: MS365 zweryfikowane, brakuje kodu IKA
    if (_bypassIkaInp) {
      var code = _bypassIkaInp.value.replace(/\D/g, '');
      if (!code) {
        if (_bypassIkaErr) { _bypassIkaErr.textContent = 'Wpisz 6-cyfrowy kod IKA.'; _bypassIkaErr.style.display = ''; }
        _bypassIkaInp.focus();
        return;
      }
      if (_bypassIkaErr) _bypassIkaErr.style.display = 'none';
      if (!_targetForm) return;
      var bypassF = _targetForm.querySelector('.kdok-bypass-ika-value');
      if (bypassF) bypassF.value = code;
      var f0 = _targetForm;
      bsModal().hide();
      setTimeout(function () { f0.submit(); }, 150);
      return;
    }

    if (_inp) {
      // Tryb awaryjny: świeży kod IKAKS + powód (brak aktywnej sesji 6h)
      if (!_inp.value.trim()) {
        if (_errorEl) _errorEl.style.display = '';
        _inp.focus();
        return;
      }
      if (_errorEl) _errorEl.style.display = 'none';
      if (_reasonInp && !_reasonInp.value.trim()) {
        if (_reasonErrorEl) _reasonErrorEl.style.display = '';
        _reasonInp.focus();
        return;
      }
      if (_reasonErrorEl) _reasonErrorEl.style.display = 'none';
      if (!_targetForm) return;
      var hidden = _targetForm.querySelector('.kdok-ikaks-value');
      if (hidden) hidden.value = _inp.value;
      var hiddenReason = _targetForm.querySelector('.kdok-ikaks-reason-value');
      if (hiddenReason && _reasonInp) hiddenReason.value = _reasonInp.value;
      var form1 = _targetForm;
      bsModal().hide();
      setTimeout(function () { form1.submit(); }, 150);
      return;
    }

    if (_waBtn) {
      // Tryb WebAuthn
      if (!_waVerified) {
        if (_waError) {
          _waError.style.display = '';
          _waError.textContent = 'Najpierw zweryfikuj się kluczem WebAuthn.';
        }
        return;
      }
      if (!_targetForm) return;
      var form = _targetForm;
      bsModal().hide();
      setTimeout(function () { form.submit(); }, 150);
      return;
    }

    // Tryb: aktywna sesja awaryjna IKAKS (bez ponownego podawania kodu) — po prostu zapisz
    if (!_targetForm) return;
    var form2 = _targetForm;
    bsModal().hide();
    setTimeout(function () { form2.submit(); }, 150);
  }

  if (_confirmBtn) _confirmBtn.addEventListener('click', doConfirm);
  if (_inp) {
    _inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); doConfirm(); }
    });
  }

  // ── Czyszczenie po zamknięciu ─────────────────────────────────────────────
  _modalEl.addEventListener('hidden.bs.modal', function () {
    if (_waError)       _waError.style.display = 'none';
    setWebauthnVerified(false);
    if (_inp)           _inp.value = '';
    if (_errorEl)       _errorEl.style.display = 'none';
    if (_reasonInp)     _reasonInp.value = '';
    if (_reasonErrorEl) _reasonErrorEl.style.display = 'none';
    if (_bypassIkaInp)  _bypassIkaInp.value = '';
    if (_bypassIkaErr)  _bypassIkaErr.style.display = 'none';
  });
});

// ── Wyszukiwanie umowy w formularzu edycji (dla typu "rachunek") ────────────
window.addEventListener('load', function () {
  var search  = document.getElementById('metaContractSearch');
  var typeF   = document.getElementById('metaContractType');
  var idF     = document.getElementById('metaContractId');
  var results = document.getElementById('metaContractResults');
  if (!search) return;

  var timer = null;
  search.addEventListener('input', function () {
    typeF.value = ''; idF.value = '';
    clearTimeout(timer);
    var q = search.value.trim();
    if (q.length < 2) { results.style.display = 'none'; return; }
    timer = setTimeout(function () {
      fetch('<?= APP_URL ?>/ksiegowosc/search_contract.php?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          results.innerHTML = '';
          if (!data.results || !data.results.length) { results.style.display = 'none'; return; }
          data.results.forEach(function (item) {
            var a = document.createElement('button');
            a.type = 'button';
            a.className = 'list-group-item list-group-item-action py-1 small';
            a.textContent = item.label;
            a.addEventListener('click', function () {
              typeF.value = item.type;
              idF.value   = item.id;
              search.value = item.label;
              results.style.display = 'none';
            });
            results.appendChild(a);
          });
          results.style.display = '';
        })
        .catch(function () { results.style.display = 'none'; });
    }, 250);
  });

  document.addEventListener('click', function (e) {
    if (e.target !== search && !results.contains(e.target)) results.style.display = 'none';
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
