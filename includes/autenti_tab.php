<?php
/**
 * includes/autenti_tab.php — Treść sekcji Autenti w widoku umowy.
 *
 * Wymagane zmienne kontekstowe:
 *   $TYPE     — typ umowy (np. 'uslugi')
 *   $id       — ID umowy (int)
 *   $row      — wiersz umowy z bazy
 *   $_at_mode — (opcjonalnie) 'tab' (domyślnie) lub 'card' (bez opakownia tab-pane)
 */
$_at_mode        = $_at_mode ?? 'tab';
$_at_status      = $row['autenti_status']       ?? null;
$_at_signer_email= $row['autenti_signer_email'] ?? ($row['email'] ?? '');
$_at_signer_name = $row['autenti_signer_name']  ?? ($row['imie_nazwisko'] ?? ($row['nazwa_wykonawcy'] ?? ''));
$_at_document_id = $row['autenti_document_id']  ?? '';
$_at_badge       = AUTENTI_STATUS_BADGES[$_at_status ?? ''] ?? 'bg-secondary';
$_at_label       = AUTENTI_STATUS_LABELS[$_at_status ?? ''] ?? '—';
$_at_can_send    = can_edit() && autenti_is_enabled()
    && !in_array($_at_status, ['IN_PROGRESS']);
$_at_can_cancel  = can_edit() && autenti_is_enabled()
    && in_array($_at_status, ['IN_PROGRESS']);
?>
<?php if ($_at_mode === 'tab'): ?>
<div class="tab-pane fade" id="tab-autenti" role="tabpanel">
<?php endif; ?>

<?php if (!autenti_is_enabled()): ?>
<div class="alert alert-warning mb-0">
  <i class="bi bi-exclamation-triangle me-1"></i>
  Integracja Autenti nie jest aktywna.
  <?php if (current_user()['role'] === 'admin'): ?>
  <a href="<?= APP_URL ?>/admin/autenti_settings.php" class="alert-link">Skonfiguruj w ustawieniach.</a>
  <?php endif; ?>
</div>

<?php else: ?>

<!-- Status dokumentu -->
<?php if ($_at_status): ?>
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-pen-fill text-primary"></i> Status podpisu Autenti
  <span class="badge <?= $_at_badge ?> ms-auto"><?= h($_at_label) ?></span>
</div>
<div class="card-body">
  <div class="row g-3">
    <div class="col-md-4">
      <div class="detail-label">ID dokumentu</div>
      <div class="detail-value font-monospace" style="font-size:.8rem"><?= h($_at_document_id) ?: '—' ?></div>
    </div>
    <div class="col-md-4">
      <div class="detail-label">Podpisujący</div>
      <div class="detail-value"><?= h($_at_signer_name) ?: '—' ?></div>
    </div>
    <div class="col-md-4">
      <div class="detail-label">E-mail podpisującego</div>
      <div class="detail-value">
        <?= $_at_signer_email
            ? '<a href="mailto:' . h($_at_signer_email) . '">' . h($_at_signer_email) . '</a>'
            : '—' ?>
      </div>
    </div>
    <?php if ($row['plik_potwierdzenia'] && $_at_status === 'COMPLETED'): ?>
    <div class="col-md-4">
      <div class="detail-label">Podpisany dokument</div>
      <div class="detail-value"><?= upload_link($row['plik_potwierdzenia']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($_at_status === 'COMPLETED'): ?>
  <div class="alert alert-success mt-3 mb-0 py-2">
    <i class="bi bi-check-circle-fill me-1"></i>
    Dokument został podpisany przez <?= h($_at_signer_name) ?>.
  </div>
  <?php elseif ($_at_status === 'DECLINED'): ?>
  <div class="alert alert-danger mt-3 mb-0 py-2">
    <i class="bi bi-x-circle-fill me-1"></i>
    Podpisujący odrzucił dokument.
  </div>
  <?php elseif (in_array($_at_status, ['CANCELLED', 'EXPIRED'])): ?>
  <div class="alert alert-secondary mt-3 mb-0 py-2">
    <i class="bi bi-slash-circle me-1"></i>
    Dokument <?= $_at_status === 'EXPIRED' ? 'wygasł' : 'anulowany' ?>.
  </div>
  <?php elseif ($_at_status === 'IN_PROGRESS'): ?>
  <div class="alert alert-warning mt-3 mb-0 py-2">
    <i class="bi bi-hourglass-split me-1"></i>
    Oczekuje na podpis — wysłano na <strong><?= h($_at_signer_email) ?></strong>.
  </div>
  <?php endif; ?>
</div>
</div>
<?php endif; ?>

<!-- Akcje -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-send me-1"></i>Wyślij do podpisu</div>
<div class="card-body">

  <?php if (!$row['plik_umowy']): ?>
  <div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Brak pliku umowy (PDF). Wgraj plik przed wysłaniem do Autenti.
  </div>
  <?php endif; ?>

  <form id="autentiSendForm" class="row g-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold small">Imię i nazwisko podpisującego</label>
      <input type="text" id="at_signer_name" class="form-control form-control-sm"
             value="<?= h($_at_signer_name) ?>" required>
    </div>
    <div class="col-md-5">
      <label class="form-label fw-semibold small">E-mail podpisującego <span class="text-danger">*</span></label>
      <input type="email" id="at_signer_email" class="form-control form-control-sm"
             value="<?= h($_at_signer_email) ?>" required>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button type="submit" class="btn btn-primary btn-sm w-100"
              id="atSendBtn" <?= !$_at_can_send || !$row['plik_umowy'] ? 'disabled' : '' ?>>
        <i class="bi bi-send"></i>
        <?= $_at_status === 'COMPLETED' ? 'Wyślij ponownie' : 'Wyślij' ?>
      </button>
    </div>
  </form>

  <?php if ($_at_document_id): ?>
  <div class="d-flex gap-2 mt-3">
    <button class="btn btn-outline-secondary btn-sm" id="atRefreshBtn">
      <i class="bi bi-arrow-clockwise"></i> Odśwież status
    </button>
    <?php if ($_at_can_cancel): ?>
    <button class="btn btn-outline-danger btn-sm" id="atCancelBtn">
      <i class="bi bi-slash-circle"></i> Anuluj dokument
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div id="atAlert" class="mt-3" style="display:none"></div>
</div>
</div>

<script>
(function () {
    const TYPE = <?= json_encode($TYPE) ?>;
    const CID  = <?= (int)$id ?>;
    const BASE = <?= json_encode(APP_URL) ?>;

    async function atPost(action, extra = {}) {
        const body = new URLSearchParams({
            _csrf: <?= json_encode(csrf_token()) ?>,
            action,
            contract_type: TYPE,
            contract_id:   CID,
            ...extra,
        });
        const r = await fetch(BASE + '/contracts/autenti_action.php', {
            method: 'POST', body, headers: {'X-Requested-With': 'XMLHttpRequest'},
        });
        return r.json();
    }

    function showAlert(msg, ok) {
        const el = document.getElementById('atAlert');
        el.style.display = '';
        el.innerHTML = `<div class="alert alert-${ok ? 'success' : 'danger'} py-2 mb-0">
            <i class="bi bi-${ok ? 'check-circle' : 'exclamation-triangle'} me-1"></i>${msg}</div>`;
    }

    const sendForm = document.getElementById('autentiSendForm');
    if (sendForm) {
        sendForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('atSendBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Wysyłanie…';
            const data = await atPost('send', {
                signer_name:  document.getElementById('at_signer_name').value,
                signer_email: document.getElementById('at_signer_email').value,
            });
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send"></i> Wyślij';
            showAlert(data.msg, data.ok);
            if (data.ok) setTimeout(() => location.reload(), 1500);
        });
    }

    const refreshBtn = document.getElementById('atRefreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', async function () {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            const data = await atPost('status');
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Odśwież status';
            showAlert(data.ok ? `Status: <strong>${data.label}</strong>` : data.msg, data.ok);
            if (data.ok) setTimeout(() => location.reload(), 1000);
        });
    }

    const cancelBtn = document.getElementById('atCancelBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async function () {
            if (!confirm('Anulować dokument w Autenti? Tej operacji nie można cofnąć.')) return;
            this.disabled = true;
            const data = await atPost('cancel');
            this.disabled = false;
            showAlert(data.msg, data.ok);
            if (data.ok) setTimeout(() => location.reload(), 1500);
        });
    }
})();
</script>

<?php endif; // autenti_enabled ?>
<?php if ($_at_mode === 'tab'): ?>
</div><!-- /tab-autenti -->
<?php endif; ?>
