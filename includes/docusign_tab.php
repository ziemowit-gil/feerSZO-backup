<?php
/**
 * includes/docusign_tab.php — Treść sekcji DocuSign w widoku umowy.
 *
 * Wymagane zmienne kontekstowe:
 *   $TYPE       — typ umowy (np. 'uslugi')
 *   $id         — ID umowy (int)
 *   $row        — wiersz umowy z bazy
 *   $_ds_mode   — (opcjonalnie) 'tab' (domyślnie) lub 'card' (dla widoków bez zakładek)
 */
$_ds_mode = $_ds_mode ?? 'tab';

$_ds_enabled     = docusign_is_enabled();
$_ds_status      = $row['docusign_status']       ?? null;
$_ds_signer_email= $row['docusign_signer_email']  ?? ($row['email'] ?? '');
$_ds_signer_name = $row['docusign_signer_name']   ?? ($row['imie_nazwisko'] ?? ($row['nazwa_wykonawcy'] ?? ''));
$_ds_envelope_id = $row['id_dokumentu_el']         ?? '';
$_ds_badge       = DOCUSIGN_STATUS_BADGES[$_ds_status ?? ''] ?? 'bg-secondary';
$_ds_label       = DOCUSIGN_STATUS_LABELS[$_ds_status ?? ''] ?? '—';
$_ds_can_send    = can_edit() && $_ds_enabled && !in_array($_ds_status, ['sent','delivered','completed']);
$_ds_can_void    = can_edit() && $_ds_enabled && in_array($_ds_status, ['sent','delivered']);
$_ds_can_resend  = can_edit() && $_ds_enabled && in_array($_ds_status, ['declined','voided',null,'']);
?>
<?php if ($_ds_mode === 'tab'): ?>
<div class="tab-pane fade" id="tab-docusign" role="tabpanel">
<?php endif; ?>

<?php if (!$_ds_enabled): ?>
<div class="alert alert-warning mb-0">
  <i class="bi bi-exclamation-triangle me-1"></i>
  Integracja DocuSign nie jest aktywna.
  <?php if (current_user()['role'] === 'admin'): ?>
  <a href="<?= APP_URL ?>/admin/docusign_settings.php" class="alert-link">Skonfiguruj w ustawieniach.</a>
  <?php endif; ?>
</div>

<?php else: ?>

<!-- Status koperty -->
<?php if ($_ds_status): ?>
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-pen-fill text-primary"></i> Status podpisu DocuSign
  <span class="badge <?= $_ds_badge ?> ms-auto"><?= h($_ds_label) ?></span>
</div>
<div class="card-body">
  <div class="row g-3">
    <div class="col-md-4">
      <div class="detail-label">ID koperty</div>
      <div class="detail-value font-monospace" style="font-size:.8rem"><?= h($_ds_envelope_id) ?: '—' ?></div>
    </div>
    <div class="col-md-4">
      <div class="detail-label">Podpisujący</div>
      <div class="detail-value"><?= h($_ds_signer_name) ?: '—' ?></div>
    </div>
    <div class="col-md-4">
      <div class="detail-label">E-mail podpisującego</div>
      <div class="detail-value">
        <?= $_ds_signer_email
            ? '<a href="mailto:' . h($_ds_signer_email) . '">' . h($_ds_signer_email) . '</a>'
            : '—' ?>
      </div>
    </div>
    <?php if ($row['plik_potwierdzenia'] && $_ds_status === 'completed'): ?>
    <div class="col-md-4">
      <div class="detail-label">Podpisany dokument</div>
      <div class="detail-value"><?= upload_link($row['plik_potwierdzenia']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($_ds_status === 'completed'): ?>
  <div class="alert alert-success mt-3 mb-0 py-2">
    <i class="bi bi-check-circle-fill me-1"></i>
    Dokument został podpisany przez <?= h($_ds_signer_name) ?>.
  </div>
  <?php elseif ($_ds_status === 'declined'): ?>
  <div class="alert alert-danger mt-3 mb-0 py-2">
    <i class="bi bi-x-circle-fill me-1"></i>
    Podpisujący odrzucił dokument.
  </div>
  <?php elseif ($_ds_status === 'voided'): ?>
  <div class="alert alert-secondary mt-3 mb-0 py-2">
    <i class="bi bi-slash-circle me-1"></i> Koperta unieważniona.
  </div>
  <?php elseif (in_array($_ds_status, ['sent','delivered'])): ?>
  <div class="alert alert-warning mt-3 mb-0 py-2">
    <i class="bi bi-hourglass-split me-1"></i>
    Oczekuje na podpis — wysłano na <strong><?= h($_ds_signer_email) ?></strong>.
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
    Brak pliku umowy (PDF). Wgraj plik przed wysłaniem do DocuSign.
  </div>
  <?php endif; ?>

  <form id="docusignSendForm" class="row g-3">
    <div class="col-md-5">
      <label class="form-label fw-semibold small">Imię i nazwisko podpisującego</label>
      <input type="text" id="ds_signer_name" class="form-control form-control-sm"
             value="<?= h($_ds_signer_name) ?>" required>
    </div>
    <div class="col-md-5">
      <label class="form-label fw-semibold small">E-mail podpisującego <span class="text-danger">*</span></label>
      <input type="email" id="ds_signer_email" class="form-control form-control-sm"
             value="<?= h($_ds_signer_email) ?>" required>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button type="submit" class="btn btn-primary btn-sm w-100"
              id="dsSendBtn" <?= (!$_ds_can_send && !$_ds_can_resend) || !$row['plik_umowy'] ? 'disabled' : '' ?>>
        <i class="bi bi-send"></i>
        <?= $_ds_status === 'completed' ? 'Wyślij ponownie' : 'Wyślij' ?>
      </button>
    </div>
  </form>

  <?php if ($_ds_envelope_id): ?>
  <div class="d-flex gap-2 mt-3">
    <button class="btn btn-outline-secondary btn-sm" id="dsRefreshBtn">
      <i class="bi bi-arrow-clockwise"></i> Odśwież status
    </button>
    <?php if ($_ds_can_void): ?>
    <button class="btn btn-outline-danger btn-sm" id="dsVoidBtn">
      <i class="bi bi-slash-circle"></i> Unieważnij kopertę
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div id="dsAlert" class="mt-3" style="display:none"></div>
</div>
</div>

<script>
(function () {
    const TYPE = <?= json_encode($TYPE) ?>;
    const CID  = <?= (int)$id ?>;
    const BASE = <?= json_encode(APP_URL) ?>;

    async function dsPost(action, extra = {}) {
        const body = new URLSearchParams({
            _csrf: <?= json_encode(csrf_token()) ?>,
            action,
            contract_type: TYPE,
            contract_id:   CID,
            ...extra,
        });
        const r = await fetch(BASE + '/contracts/docusign_action.php', {
            method: 'POST', body, headers: {'X-Requested-With': 'XMLHttpRequest'},
        });
        return r.json();
    }

    function showAlert(msg, ok) {
        const el = document.getElementById('dsAlert');
        el.style.display = '';
        el.innerHTML = `<div class="alert alert-${ok ? 'success' : 'danger'} py-2 mb-0">
            <i class="bi bi-${ok ? 'check-circle' : 'exclamation-triangle'} me-1"></i>${msg}</div>`;
    }

    const sendForm = document.getElementById('docusignSendForm');
    if (sendForm) {
        sendForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('dsSendBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Wysyłanie…';
            const data = await dsPost('send', {
                signer_name:  document.getElementById('ds_signer_name').value,
                signer_email: document.getElementById('ds_signer_email').value,
            });
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send"></i> Wyślij';
            showAlert(data.msg, data.ok);
            if (data.ok) setTimeout(() => location.reload(), 1500);
        });
    }

    const refreshBtn = document.getElementById('dsRefreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', async function () {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            const data = await dsPost('status');
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Odśwież status';
            showAlert(data.ok ? `Status: <strong>${data.label}</strong>` : data.msg, data.ok);
            if (data.ok) setTimeout(() => location.reload(), 1000);
        });
    }

    const voidBtn = document.getElementById('dsVoidBtn');
    if (voidBtn) {
        voidBtn.addEventListener('click', async function () {
            const reason = prompt('Powód unieważnienia:', 'Anulowane przez administratora');
            if (reason === null) return;
            this.disabled = true;
            const data = await dsPost('void', { void_reason: reason });
            this.disabled = false;
            showAlert(data.msg, data.ok);
            if (data.ok) setTimeout(() => location.reload(), 1500);
        });
    }
})();
</script>

<?php endif; // docusign_enabled ?>
<?php if ($_ds_mode === 'tab'): ?>
</div><!-- /tab-docusign -->
<?php endif; ?>
