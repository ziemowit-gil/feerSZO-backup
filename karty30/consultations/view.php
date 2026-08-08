<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$id = (int)($_GET['id'] ?? 0);
$cons = db_one(
    "SELECT co.*, c.name AS client_name, c.phone AS client_phone,
            u.name AS consultant_name,
            cr.name AS created_by_name
     FROM k30_consultations co
     LEFT JOIN k30_clients c ON c.id=co.client_id
     LEFT JOIN users u ON u.id=co.consultant_id
     LEFT JOIN users cr ON cr.id=co.created_by
     WHERE co.id=?",
    [$id]
);
if (!$cons) {
    flash_set('danger', 'Konsultacja nie istnieje.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Konsultacja — Dydaktyka 3';
$can_write  = can_write('karty30') || is_admin();

// RODO: rejestr dostępu do danych wrażliwych konsultacji
if ($_SERVER['REQUEST_METHOD'] === 'GET') k30_log_access('consultation', $id, 'view', $cons['client_name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'approve' && $can_write && $cons['status'] !== 'completed') {
        require_once dirname(dirname(__DIR__)) . '/includes/cpc.php';
        cpc_migrate();

        $user    = current_user();
        $uid     = (int)($user['id'] ?? 0);
        $approve_errors = [];

        // ── Weryfikacja 1: Kod IKA ────────────────────────────────────────────
        $ika_code = trim($_POST['ika_code'] ?? '');
        if (!$ika_code) {
            $approve_errors[] = 'Kod IKA jest wymagany do zatwierdzenia.';
        } else {
            $cpc_result = cpc_verify($uid, $ika_code);
            if ($cpc_result['blocked']) {
                $blocked_until = $cpc_result['blocked_until']
                    ? ' do ' . date('H:i d.m.Y', strtotime($cpc_result['blocked_until']))
                    : '';
                $approve_errors[] = 'Kod IKA zablokowany' . $blocked_until . '. Za dużo błędnych prób.';
            } elseif (!$cpc_result['ok']) {
                $rem = max(0, 3 - (int)($cpc_result['fails'] ?? 0));
                $approve_errors[] = 'Nieprawidłowy kod IKA. Pozostało prób: ' . $rem . '.';
            }
        }

        // ── Weryfikacja 2: Certyfikat x509 ───────────────────────────────────
        $cert_check = k30_verify_consultant_cert($uid);
        if (!$cert_check['ok']) {
            $approve_errors[] = $cert_check['error'];
        }

        if ($approve_errors) {
            foreach ($approve_errors as $e) {
                flash_set('danger', $e);
            }
            header('Location: view.php?id=' . $id . '#approve-section');
            exit;
        }

        // ── Oba czynniki OK — zatwierdź konsultację ───────────────────────────
        $approved_by = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
            ?: ($user['name'] ?? 'Nieznany');
        $cert        = $cert_check['cert'];
        $now_str     = date('Y-m-d H:i:s');

        // SHA1 obejmuje dane konsultacji + fingerprint certyfikatu + czas IKA
        $sha_data = json_encode([
            'id'              => $cons['id'],
            'client_id'       => $cons['client_id'],
            'dt'              => $cons['consultation_datetime'],
            'duration'        => $cons['duration_minutes'],
            'description'     => $cons['description'],
            'approved_by'     => $approved_by,
            'approved_at'     => $now_str,
            'cert_fingerprint'=> $cert['cert_fingerprint'],
            'cert_subject'    => $cert['cert_subject'],
            'ika_verified_at' => $now_str,
        ], JSON_UNESCAPED_UNICODE);
        $sha1 = sha1($sha_data);

        db()->prepare(
            "UPDATE k30_consultations
             SET status='completed', confirmed=1,
                 approved_by_name=?, sha1sum=?,
                 cert_fingerprint=?, cert_subject=?,
                 ika_verified_at=?, updated_at=datetime('now')
             WHERE id=?"
        )->execute([
            $approved_by, $sha1,
            $cert['cert_fingerprint'], $cert['cert_subject'],
            $now_str,
            $id,
        ]);

        // Zaktualizuj użyte godziny klienta
        if ($cons['duration_minutes']) {
            $hours = round($cons['duration_minutes'] / 60, 2);
            db()->prepare("UPDATE k30_clients SET used=used+?, updated_at=datetime('now') WHERE id=?")
                ->execute([$hours, $cons['client_id']]);
        }

        // Zaloguj w CRM
        $client_row = db_one("SELECT * FROM k30_clients WHERE id=?", [(int)$cons['client_id']]);
        if ($client_row) {
            $crm_cid = k30_get_crm_contact($client_row);
            if (!$crm_cid) $crm_cid = k30_sync_to_crm($client_row, $uid);
            if ($crm_cid) {
                k30_log_crm_activity(
                    $crm_cid, 'meeting',
                    'Zatwierdzona konsultacja TyfloK. #' . $cons['id'],
                    $cons['description'] ?: '',
                    $cons['consultation_datetime'], 'done',
                    'Zatwierdził: ' . $approved_by
                    . ' · SHA1: ' . substr($sha1, 0, 12) . '…'
                    . ' · Cert: ' . substr($cert['cert_fingerprint'], 0, 20) . '…',
                    $uid
                );
            }
        }

        flash_set('success', 'Konsultacja zatwierdzona (IKA + x509). SHA1: ' . $sha1);
        header('Location: view.php?id=' . $id);
        exit;
    }

    if ($action === 'cancel' && $can_write) {
        db()->prepare("UPDATE k30_consultations SET status='cancelled', updated_at=datetime('now') WHERE id=?")
            ->execute([$id]);

        // Zaloguj anulowanie w CRM
        $client_row = db_one("SELECT * FROM k30_clients WHERE id=?", [(int)$cons['client_id']]);
        if ($client_row) {
            $crm_cid = k30_get_crm_contact($client_row);
            if ($crm_cid) {
                k30_log_crm_activity(
                    $crm_cid, 'task',
                    'Anulowana konsultacja TyfloK. #' . $cons['id'],
                    '', $cons['consultation_datetime'], 'done',
                    'Anulowana przez ' . ($current_user_data['name'] ?? ''),
                    (int)(current_user()['id'] ?? 0)
                );
            }
        }

        flash_set('info', 'Konsultacja anulowana.');
        header('Location: view.php?id=' . $id);
        exit;
    }
}

// Reload after POST
$cons = db_one(
    "SELECT co.*, c.name AS client_name, c.phone AS client_phone,
            u.name AS consultant_name, cr.name AS created_by_name
     FROM k30_consultations co
     LEFT JOIN k30_clients c ON c.id=co.client_id
     LEFT JOIN users u ON u.id=co.consultant_id
     LEFT JOIN users cr ON cr.id=co.created_by
     WHERE co.id=?",
    [$id]
);

$schedule = $cons['schedule_id']
    ? db_one("SELECT * FROM k30_schedules WHERE id=?", [$cons['schedule_id']])
    : null;

// Sprawdź stan certyfikatu i IKA bieżącego użytkownika (do info w widoku)
$current_uid      = (int)(current_user()['id'] ?? 0);
$my_cert_check    = k30_verify_consultant_cert($current_uid);
$my_has_ika       = !empty(db_one("SELECT cpc_code FROM users WHERE id=? AND cpc_code IS NOT NULL AND cpc_code != ''", [$current_uid]));
$can_approve      = $can_write && $cons['status'] === 'draft' && $my_cert_check['ok'] && $my_has_ika;
$approve_blocked  = $can_write && $cons['status'] === 'draft' && (!$my_cert_check['ok'] || !$my_has_ika);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item"><a href="index.php">Konsultacje</a></li>
    <li class="breadcrumb-item active">Konsultacja #<?= $id ?></li>
  </ol>
</nav>

<div class="d-flex align-items-start gap-3 mb-4 flex-wrap">
  <div>
    <h4 class="mb-0 fw-bold"><?= h($cons['client_name']) ?></h4>
    <div class="text-muted small"><?= date('d.m.Y H:i', strtotime($cons['consultation_datetime'])) ?>
      <?= $cons['duration_minutes'] ? ' · ' . (int)$cons['duration_minutes'] . ' min' : '' ?>
    </div>
    <div class="mt-1"><?= k30_status_badge($cons['status'], 'consultation') ?></div>
  </div>
  <?php if ($can_write && $cons['status'] === 'draft'): ?>
  <div class="ms-auto d-flex gap-2 flex-wrap" id="approve-section">
    <a href="edit.php?id=<?= $id ?>"
       class="btn btn-outline-secondary btn-sm"
       aria-label="Edytuj tę konsultację">
      <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj
    </a>

    <?php if ($can_approve): ?>
    <!-- Przycisk otwiera modal weryfikacji IKA + certyfikatu -->
    <button type="button"
            class="btn btn-success btn-sm"
            data-bs-toggle="modal"
            data-bs-target="#approveModal"
            aria-label="Zatwierdź konsultację — wymagane kod IKA i certyfikat x509"
            aria-haspopup="dialog">
      <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Zatwierdź
    </button>
    <?php elseif ($approve_blocked): ?>
    <!-- Brak certyfikatu lub IKA — informacja dla czytnika ekranu -->
    <button type="button"
            class="btn btn-warning btn-sm"
            aria-label="Nie możesz zatwierdzić — brak certyfikatu x509 lub kodu IKA"
            aria-disabled="true"
            data-bs-toggle="modal"
            data-bs-target="#approveBlockedModal">
      <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Zatwierdzenie wymaga certyfikatu
    </button>
    <?php endif; ?>

    <form method="post" class="d-inline"
          onsubmit="return confirm('Anulować tę konsultację? Tej operacji nie można cofnąć.')">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="cancel">
      <button type="submit"
              class="btn btn-outline-danger btn-sm"
              aria-label="Anuluj konsultację — wymagane potwierdzenie">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Anuluj
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-md-5">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-info-circle me-1"></i>Szczegóły</div>
      <div class="card-body" style="font-size:.875rem">
        <table class="table table-sm mb-0">
          <tbody>
            <tr><th class="text-muted fw-normal" style="width:140px">Beneficjent</th>
                <td><a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$cons['client_id'] ?>"><?= h($cons['client_name']) ?></a></td></tr>
            <tr><th class="text-muted fw-normal">Data</th><td><?= date('d.m.Y H:i', strtotime($cons['consultation_datetime'])) ?></td></tr>
            <tr><th class="text-muted fw-normal">Czas trwania</th><td><?= $cons['duration_minutes'] ? (int)$cons['duration_minutes'] . ' min' : '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Konsultant</th><td><?= $cons['consultant_name'] ? h($cons['consultant_name']) : '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Status</th><td><?= k30_status_badge($cons['status'], 'consultation') ?></td></tr>
            <?php if ($cons['approved_by_name']): ?>
            <tr><th class="text-muted fw-normal">Zatwierdził</th><td><?= h($cons['approved_by_name']) ?></td></tr>
            <?php endif; ?>
            <?php if ($cons['sha1sum']): ?>
            <tr><th class="text-muted fw-normal">SHA1</th>
                <td><code style="font-size:.7rem;word-break:break-all"><?= h($cons['sha1sum']) ?></code></td></tr>
            <?php endif; ?>
            <?php if ($cons['cert_fingerprint'] ?? ''): ?>
            <tr><th class="text-muted fw-normal">Certyfikat x509</th>
                <td>
                  <code style="font-size:.7rem;word-break:break-all"><?= h(substr($cons['cert_fingerprint'], 0, 30)) ?>…</code>
                  <?php if ($cons['cert_subject'] ?? ''): ?>
                  <div style="font-size:.75rem;color:#6B7280;margin-top:2px"><?= h($cons['cert_subject']) ?></div>
                  <?php endif; ?>
                </td></tr>
            <?php endif; ?>
            <?php if ($cons['ika_verified_at'] ?? ''): ?>
            <tr><th class="text-muted fw-normal">Weryfikacja IKA</th>
                <td style="font-size:.82rem"><?= date('d.m.Y H:i:s', strtotime($cons['ika_verified_at'])) ?></td></tr>
            <?php endif; ?>
            <?php if ($cons['created_by_name']): ?>
            <tr><th class="text-muted fw-normal">Dodał</th><td><?= h($cons['created_by_name']) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($schedule): ?>
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-calendar3 me-1"></i>Powiązany termin</div>
      <div class="card-body" style="font-size:.875rem">
        <div class="mb-1"><?= date('d.m.Y H:i', strtotime($schedule['start_time'])) ?> (<?= (int)$schedule['duration_minutes'] ?> min)</div>
        <?= k30_status_badge($schedule['status']) ?>
        <div class="mt-2">
          <a href="<?= APP_URL ?>/karty30/schedules/view.php?id=<?= (int)$schedule['id'] ?>" class="btn btn-outline-secondary btn-sm">Zobacz termin</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-md-7">
    <?php if ($cons['description']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-text-paragraph me-1"></i>Opis konsultacji</div>
      <div class="card-body" style="font-size:.875rem"><?= nl2br(h($cons['description'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($cons['next_action']): ?>
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-arrow-right-circle me-1"></i>Następne działania</div>
      <div class="card-body" style="font-size:.875rem"><?= nl2br(h($cons['next_action'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($cons['status'] === 'completed' && $cons['sha1sum']): ?>
    <div class="alert alert-success d-flex align-items-start gap-2 mt-3" role="status" aria-label="Konsultacja zatwierdzona">
      <i class="bi bi-shield-check-fill" aria-hidden="true" style="font-size:1.3rem;flex-shrink:0"></i>
      <div>
        <strong>Konsultacja zatwierdzona</strong>
        <?php if ($cons['approved_by_name']): ?>przez <strong><?= h($cons['approved_by_name']) ?></strong><?php endif; ?>
        <?php if ($cons['ika_verified_at'] ?? ''): ?>
        w dniu <?= date('d.m.Y o H:i', strtotime($cons['ika_verified_at'])) ?>
        <?php endif; ?>
        <div class="mt-1" style="font-size:.82rem">
          <i class="bi bi-key-fill me-1" aria-hidden="true"></i>Dwuczynnikowa weryfikacja: kod IKA + certyfikat x509
        </div>
        <?php if ($cons['cert_fingerprint'] ?? ''): ?>
        <details style="margin-top:.5rem">
          <summary style="font-size:.78rem;cursor:pointer;color:#065F46">
            Szczegóły certyfikatu (rozwiń)
          </summary>
          <div style="font-size:.78rem;margin-top:.4rem;font-family:monospace;word-break:break-all">
            <div>Podmiot: <?= h($cons['cert_subject'] ?? '—') ?></div>
            <div>Fingerprint: <?= h($cons['cert_fingerprint']) ?></div>
          </div>
        </details>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($cons['status'] === 'draft' && $approve_blocked): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mt-3" role="note" aria-label="Wymagania do zatwierdzenia">
      <i class="bi bi-exclamation-triangle-fill" aria-hidden="true" style="font-size:1.2rem;flex-shrink:0"></i>
      <div>
        <strong>Aby zatwierdzić konsultację, potrzebujesz:</strong>
        <ul class="mb-0 mt-1">
          <?php if (!$my_has_ika): ?>
          <li>Kod IKA — skontaktuj się z administratorem (<a href="<?= APP_URL ?>/admin/manage_cpc.php">Panel IKA</a>)</li>
          <?php endif; ?>
          <?php if (!$my_cert_check['ok']): ?>
          <li>Certyfikat x509 — <?= h($my_cert_check['error'] ?? 'brak certyfikatu') ?></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ MODAL: Zatwierdzenie konsultacji (IKA + x509) ════════════════════ -->
<?php if ($can_approve): ?>
<div class="modal fade" id="approveModal" tabindex="-1"
     aria-labelledby="approveModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
    <div class="modal-content">
      <form method="post" novalidate aria-label="Formularz zatwierdzenia konsultacji">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="approve">

        <div class="modal-header" style="border-bottom:2px solid #E5E7EB">
          <h2 class="modal-title fw-bold" id="approveModalLabel" style="font-size:1.05rem">
            <i class="bi bi-shield-lock-fill me-2" aria-hidden="true" style="color:#5B21B6"></i>
            Zatwierdź konsultację — weryfikacja dwuczynnikowa
          </h2>
          <button type="button" class="btn-close"
                  data-bs-dismiss="modal"
                  aria-label="Zamknij okno zatwierdzania"></button>
        </div>

        <div class="modal-body">

          <!-- Streszczenie konsultacji -->
          <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:.85rem 1rem;margin-bottom:1.25rem;font-size:.88rem">
            <div class="fw-bold mb-1">Zatwierdzasz konsultację:</div>
            <div>Beneficjent: <strong><?= h($cons['client_name']) ?></strong></div>
            <div>Data: <?= date('d.m.Y H:i', strtotime($cons['consultation_datetime'])) ?></div>
            <?php if ($cons['duration_minutes']): ?>
            <div>Czas trwania: <?= (int)$cons['duration_minutes'] ?> min</div>
            <?php endif; ?>
          </div>

          <!-- Status certyfikatu x509 -->
          <div class="mb-4" role="status" aria-label="Status certyfikatu x509">
            <div class="fw-bold mb-2" style="font-size:.9rem">
              <i class="bi bi-patch-check-fill me-1" aria-hidden="true" style="color:#059669"></i>
              Certyfikat x509
            </div>
            <?php if ($my_cert_check['ok']): ?>
            <?php $my_cert = $my_cert_check['cert']; ?>
            <div style="background:#ECFDF5;border:1.5px solid #059669;border-radius:6px;padding:.65rem .85rem;font-size:.82rem">
              <div class="fw-semibold text-success">
                <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                Certyfikat aktywny i ważny
              </div>
              <div style="margin-top:.3rem;color:#065F46">
                Podmiot: <?= h($my_cert['cert_subject']) ?><br>
                Ważny do: <?= date('d.m.Y', (int)$my_cert['cert_valid_to']) ?><br>
                <span style="font-family:monospace;font-size:.72rem;word-break:break-all">
                  Fingerprint: <?= h(substr($my_cert['cert_fingerprint'], 0, 40)) ?>…
                </span>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <!-- Kod IKA -->
          <div class="mb-3">
            <label class="form-label fw-bold" for="ika_code_input">
              <i class="bi bi-key-fill me-1" aria-hidden="true" style="color:#5B21B6"></i>
              Kod IKA (Indywidualny Kod Autoryzacyjny)
              <span aria-hidden="true" style="color:#DC2626"> *</span>
              <span class="visually-hidden">(wymagany, 6 cyfr)</span>
            </label>
            <input type="text"
                   name="ika_code"
                   id="ika_code_input"
                   class="form-control"
                   inputmode="numeric"
                   pattern="\d{6}"
                   maxlength="6"
                   required
                   aria-required="true"
                   aria-describedby="ika-hint"
                   autocomplete="one-time-code"
                   style="font-size:1.5rem;font-family:monospace;letter-spacing:.4em;text-align:center"
                   placeholder="000000">
            <div id="ika-hint" class="form-text">
              Wpisz swój 6-cyfrowy kod IKA. Kod weryfikowany w czasie rzeczywistym — 3 błędne próby blokują dostęp na 15 minut.
            </div>
          </div>

          <div class="alert alert-warning d-flex align-items-start gap-2" role="note" style="padding:.65rem .85rem">
            <i class="bi bi-info-circle-fill" aria-hidden="true" style="flex-shrink:0"></i>
            <span style="font-size:.82rem">
              Zatwierdzenie jest <strong>nieodwracalne</strong>. Fingerprint Twojego certyfikatu x509 oraz czas weryfikacji IKA zostaną zapisane w podpisanych danych konsultacji.
            </span>
          </div>
        </div>

        <div class="modal-footer" style="border-top:2px solid #E5E7EB">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"
                  aria-label="Anuluj zatwierdzanie — wróć do karty konsultacji">
            Anuluj
          </button>
          <button type="submit" class="btn btn-success fw-semibold" id="approveSubmitBtn"
                  aria-label="Potwierdź zatwierdzenie konsultacji kodem IKA i certyfikatem x509">
            <i class="bi bi-shield-lock-fill me-2" aria-hidden="true"></i>
            Zatwierdź z IKA i certyfikatem
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal: brak certyfikatu lub IKA -->
<?php if ($approve_blocked): ?>
<div class="modal fade" id="approveBlockedModal" tabindex="-1"
     aria-labelledby="blockedModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="blockedModalLabel" style="font-size:1rem;font-weight:700">
          <i class="bi bi-exclamation-triangle-fill me-2 text-warning" aria-hidden="true"></i>
          Nie można zatwierdzić
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p>Zatwierdzenie konsultacji wymaga <strong>kodu IKA</strong> i <strong>certyfikatu x509</strong>.</p>
        <ul>
          <?php if (!$my_has_ika): ?><li>Brak kodu IKA — skontaktuj się z administratorem</li><?php endif; ?>
          <?php if (!$my_cert_check['ok']): ?><li><?= h($my_cert_check['error'] ?? 'Brak certyfikatu x509') ?></li><?php endif; ?>
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
        <?php if (is_admin()): ?>
        <a href="<?= APP_URL ?>/karty30/admin/cert_upload.php" class="btn btn-primary">
          Wgraj certyfikat x509
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// Focus na polu IKA po otwarciu modala
document.getElementById('approveModal')?.addEventListener('shown.bs.modal', function() {
  var inp = document.getElementById('ika_code_input');
  if (inp) {
    inp.value = '';
    inp.focus();
    // Ogłoś dla czytnika
    var live = document.getElementById('k30-live-urgent');
    if (live) live.textContent = 'Okno zatwierdzania otwarte. Wpisz 6-cyfrowy kod IKA.';
  }
});

// Auto-submit po wpisaniu 6 cyfr (z 150ms opóźnieniem dla potwierdzenia)
document.getElementById('ika_code_input')?.addEventListener('input', function() {
  this.value = this.value.replace(/\D/g, '').slice(0, 6);
  var btn = document.getElementById('approveSubmitBtn');
  if (btn) btn.disabled = (this.value.length !== 6);
});

// Inicjalnie wyłącz przycisk submit
(function() {
  var btn = document.getElementById('approveSubmitBtn');
  if (btn) btn.disabled = true;
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
