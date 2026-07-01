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

require_login();
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

    // Aktualizacja metadanych dokumentu
    if ($action === 'update_meta' && (is_admin() || kdok_has_role('upload'))) {
        kdok_exec(
            "UPDATE kdok_documents SET description=?, uwagi=?, kwota=?, grant_name=?, mpk=?, updated_at=datetime('now') WHERE id=?",
            [trim($_POST['description'] ?? ''), trim($_POST['uwagi'] ?? ''), trim($_POST['kwota'] ?? ''),
             trim($_POST['grant_name'] ?? ''), trim($_POST['mpk'] ?? ''), $id]
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
                // Weryfikacja kluczem WebAuthn + certyfikat X.509
                $auth = kdok_auth_verify((int)$user['id']);
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
            $auth = kdok_auth_verify((int)$user['id']);
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
            flash_set('success', 'PDF z historią obiegu został wygenerowany.');
        } catch (Throwable $e) {
            flash_set('danger', 'Błąd generowania PDF: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/ksiegowosc/view.php?id=' . $id);
        exit;
    }

    // Odrzucenie dokumentu
    if ($action === 'reject' && is_admin()) {
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

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0 me-auto"><i class="bi bi-file-earmark-check"></i> <?= h($doc['number']) ?></h4>
  <?= kdok_status_badge($doc['status']) ?>
  <?php if (is_admin()): ?>
  <a href="<?= APP_URL ?>/ksiegowosc/delete.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger"
     onclick="return confirm('Na pewno usunąć dokument?')"><i class="bi bi-trash"></i></a>
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

        <?php if (!$errors && (is_admin() || kdok_has_role('upload'))): ?>
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
        'meryt'      => ['label' => 'Sprawdzono pod kątem merytorycznym', 'icon' => 'bi-patch-check', 'color' => 'primary'],
        'formal'     => ['label' => 'Sprawdzono pod kątem formalnym i rachunkowym', 'icon' => 'bi-calculator', 'color' => 'info'],
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
      <a href="<?= APP_URL ?>/ksiegowosc/raport.php?id=<?= $id ?>" target="_blank"
         class="btn btn-outline-secondary">
        <i class="bi bi-printer"></i> Raport weryfikacji
      </a>
      <?php if (kdok_has_role('zatwierdza')): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="generate_pdf">
        <button type="submit" class="btn btn-outline-primary">
          <i class="bi bi-file-earmark-arrow-down"></i> Generuj PDF z historią obiegu
        </button>
      </form>
      <?php endif; ?>
      <?php if (is_admin() && !in_array($doc['status'], ['odrzucony'])): ?>
      <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
        <i class="bi bi-x-circle"></i> Odrzuć dokument (admin)
      </button>
      <?php endif; ?>
    </div>

    <!-- Wygenerowane PDF -->
    <?php if ($doc['generated']): ?>
    <div class="card shadow-sm mb-3 border-success">
      <div class="card-header bg-success text-white py-2">
        <i class="bi bi-file-earmark-check-fill"></i> <strong>Finalny PDF z historią obiegu</strong>
      </div>
      <div class="card-body py-2 small">
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <a href="<?= APP_URL ?>/ksiegowosc/download.php?id=<?= $id ?>&type=final" class="btn btn-success btn-sm">
            <i class="bi bi-download"></i> Pobierz finalny PDF
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
$my_cert      = kdok_cert_get((int)$user['id']);
$cert_ok      = $my_cert && kdok_cert_is_valid($my_cert);
$has_webauthn = webauthn_user_has_keys((int)$user['id']);
$auth_ready   = $cert_ok && $has_webauthn;
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

        <!-- Status klucza WebAuthn -->
        <div class="mb-3 p-2 rounded border <?= $has_webauthn ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10' ?>">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-usb-symbol fs-4 <?= $has_webauthn ? 'text-success' : 'text-danger' ?>"></i>
            <div>
              <?php if ($has_webauthn): ?>
              <div class="fw-semibold">Klucz WebAuthn zarejestrowany</div>
              <div class="small text-muted">Opisywanie dokumentów wymaga świeżej weryfikacji kluczem sprzętowym.</div>
              <?php else: ?>
              <div class="fw-semibold text-danger">Brak zarejestrowanego klucza WebAuthn</div>
              <div class="small text-muted">
                Opisywanie dokumentów wymaga klucza sprzętowego. Zarejestruj go w
                <a href="<?= APP_URL ?>/panel/webauthn.php" target="_blank">Mój profil → Klucze bezpieczeństwa</a>.
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

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
        <div class="alert alert-danger mb-0">
          Autoryzacja niemożliwa bez zarejestrowanego klucza WebAuthn i ważnego certyfikatu X.509.
          <?php if (!$my_cert): ?>
          Poproś admina o dodanie certyfikatu w
          <a href="<?= APP_URL ?>/admin/kdok_certs.php">Certyfikaty X.509 i IKAKS</a>.
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div id="webauthnStep">
          <label class="form-label fw-semibold">
            <i class="bi bi-usb-symbol text-primary"></i>
            Zweryfikuj kluczem WebAuthn
          </label>
          <div class="form-text mt-0 mb-2">
            Klucz WebAuthn zastępuje kod IKAKS — po dotknięciu klucza decyzja zapisze się automatycznie.
          </div>
          <div class="d-flex align-items-center gap-2">
            <button type="button" id="webauthnConfirm" class="btn btn-primary">
              <i class="bi bi-usb-plug"></i> Dotknij klucz WebAuthn i zapisz
            </button>
            <span id="webauthnSpinner" class="spinner-border spinner-border-sm text-primary" style="display:none"></span>
            <span id="webauthnOk" class="text-success fw-semibold" style="display:none">
              <i class="bi bi-check-circle-fill"></i> Zweryfikowano
            </span>
          </div>
          <div id="webauthnError" class="text-danger small mt-1" style="display:none"></div>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
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
      if (_waError) _waError.style.display = 'none';

      bsModal().show();
    });
  });

  // ── Potwierdzenie ─────────────────────────────────────────────────────────
  function doConfirm() {
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
  }

  // ── Czyszczenie po zamknięciu ─────────────────────────────────────────────
  _modalEl.addEventListener('hidden.bs.modal', function () {
    if (_waError) _waError.style.display = 'none';
    setWebauthnVerified(false);
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
