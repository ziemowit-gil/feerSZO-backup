<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/rekrutacja.php';

require_role('admin', 'editor');

$rm    = new VolunteerModuleManager();
$id    = (int)($_GET['id'] ?? 0);
$offer = $rm->getOfferOrFail($id);
$PAGE_TITLE = 'Rekrutacja: ' . $offer['title'];

// ── Filtr statusu zgłoszeń ────────────────────────────────────────────────
$app_status_filter = $_GET['app_status'] ?? '';

// ── POST handler (akcje workflow) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $user   = current_user();
    $uid    = (int)$user['id'];

    try {
        switch ($action) {
            // Status ogłoszenia
            case 'set_offer_status':
                $new_status = $_POST['new_status'] ?? '';
                if (!array_key_exists($new_status, VolunteerModuleManager::OFFER_STATUSES)) {
                    throw new \InvalidArgumentException('Nieprawidłowy status ogłoszenia.');
                }
                $rm->updateOffer($id, ['status' => $new_status]);
                flash_set('success', 'Status ogłoszenia zaktualizowany.');
                break;

            // Status zgłoszenia
            case 'set_app_status':
                $app_id     = (int)($_POST['app_id']     ?? 0);
                $new_status = $_POST['new_status']        ?? '';
                $extra = [];
                if (isset($_POST['rejection_reason'])) $extra['rejection_reason'] = trim($_POST['rejection_reason']);
                if (isset($_POST['admin_notes']))       $extra['admin_notes']      = trim($_POST['admin_notes']);
                if (!$rm->updateApplicationStatus($app_id, $new_status, $uid, $extra)) {
                    throw new \RuntimeException('Nie udało się zaktualizować statusu zgłoszenia.');
                }
                flash_set('success', 'Status zgłoszenia zaktualizowany.');
                break;

            // Notatka admina
            case 'save_app_notes':
                $app_id = (int)($_POST['app_id'] ?? 0);
                db()->prepare("UPDATE volunteer_applications SET admin_notes = ?, updated_at = ? WHERE id = ?")
                     ->execute([trim($_POST['admin_notes'] ?? ''), date('Y-m-d H:i:s'), $app_id]);
                flash_set('success', 'Notatka zapisana.');
                break;

            // Utwórz wpis onboardingowy dla zaakceptowanego kandydata
            case 'create_onboarding':
                $app_id = (int)($_POST['app_id'] ?? 0);
                if (!$app_id) throw new \RuntimeException('Brak ID zgłoszenia.');
                $app_row = $rm->getApplication($app_id);
                if (!$app_row) throw new \RuntimeException('Zgłoszenie nie znalezione.');
                if ($app_row['status'] !== 'accepted') {
                    throw new \RuntimeException('Kandydat musi być zaakceptowany, aby utworzyć wpis onboardingowy.');
                }
                // Sprawdź czy wpis już istnieje
                $existing_ob = db_one("SELECT onboarding_id FROM volunteer_applications WHERE id = ?", [$app_id]);
                if (!empty($existing_ob['onboarding_id'])) {
                    flash_set('info', 'Wpis onboardingowy już istnieje dla tego kandydata.');
                    header('Location: ' . APP_URL . '/onboarding/view.php?id=' . (int)$existing_ob['onboarding_id']);
                    exit;
                }
                // Utwórz wpis w onboarding_volunteers
                $now_ob = date('Y-m-d H:i:s');
                $new_ob_id = db_insert('onboarding_volunteers', [
                    'session_token'          => bin2hex(random_bytes(16)),
                    'status'                 => 'pending',
                    'imie_nazwisko'          => $app_row['candidate_name'],
                    'email'                  => $app_row['candidate_email'],
                    'telefon'                => $app_row['candidate_phone'] ?? '',
                    'adres'                  => '',
                    'phone_verified'         => 0,
                    'email_verified'         => 0,
                    'klauzula_accepted'      => 0,
                    'ip_address'             => '',
                    'user_agent'             => '',
                    'source_application_id'  => $app_id,
                    'created_at'             => $now_ob,
                    'updated_at'             => $now_ob,
                ]);
                // Zapisz link w drugą stronę
                db()->prepare("UPDATE volunteer_applications SET onboarding_id = ?, updated_at = ? WHERE id = ?")
                     ->execute([$new_ob_id, $now_ob, $app_id]);
                flash_set('success', 'Wpis onboardingowy utworzony. Uzupełnij brakujące dane kandydata.');
                header('Location: ' . APP_URL . '/onboarding/view.php?id=' . $new_ob_id);
                exit;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    header('Location: view.php?id=' . $id . ($app_status_filter ? '&app_status=' . urlencode($app_status_filter) : ''));
    exit;
}

// ── Dane ─────────────────────────────────────────────────────────────────
$offer        = $rm->getOfferOrFail($id);  // odśwież po ewentualnym POST
$applications = $rm->listApplications($id, $app_status_filter);

// Liczniki statusów zgłoszeń
$app_counts = [];
foreach (array_keys(VolunteerModuleManager::APP_STATUSES) as $s) {
    $r = db_one(
        "SELECT COUNT(*) AS c FROM volunteer_applications WHERE volunteer_offer_id = ? AND status = ?",
        [$id, $s]
    );
    $app_counts[$s] = (int)($r['c'] ?? 0);
}
$app_counts_total = (int)($offer['app_count'] ?? 0);

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="index.php">Rekrutacja</a></li>
    <li class="breadcrumb-item active"><?= h($offer['title']) ?></li>
  </ol>
</nav>

<?= flash_html() ?>

<!-- ── Nagłówek ogłoszenia ────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4 border-0"
     style="background:linear-gradient(135deg,#0f2044,#1d4ed8);color:#fff;border-radius:1rem">
<div class="card-body py-3 px-4">
  <div class="row align-items-start g-2">
    <div class="col">
      <div class="d-flex align-items-center gap-3">
        <div class="bg-white bg-opacity-10 rounded-3 p-2">
          <i class="bi bi-megaphone-fill fs-3"></i>
        </div>
        <div>
          <div style="font-size:.7rem;opacity:.6;font-weight:700;letter-spacing:.1em;text-transform:uppercase">
            Ogłoszenie rekrutacyjne
          </div>
          <div class="fw-bold" style="font-size:1.25rem"><?= h($offer['title']) ?></div>
          <div class="d-flex gap-2 mt-1 align-items-center flex-wrap">
            <?= rekrutacja_offer_badge($offer['status']) ?>
            <span style="opacity:.7;font-size:.8rem">
              <i class="bi bi-people-fill me-1"></i><?= $app_counts_total ?> zgłoszeń
              <?php if ($offer['max_candidates']): ?>
              / limit <?= h($offer['max_candidates']) ?>
              <?php endif; ?>
            </span>
            <?php if ($offer['published_at']): ?>
            <span style="opacity:.6;font-size:.78rem">
              <i class="bi bi-calendar3 me-1"></i>od <?= date_pl($offer['published_at']) ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-auto d-flex gap-2 align-items-start flex-wrap">
      <a href="add.php?id=<?= $id ?>" class="btn btn-sm btn-light">
        <i class="bi bi-pencil me-1"></i>Edytuj
      </a>
      <?php if ($offer['status'] !== 'active'): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"     value="set_offer_status">
        <input type="hidden" name="new_status"  value="active">
        <button type="submit" class="btn btn-sm btn-success">
          <i class="bi bi-megaphone me-1"></i>Aktywuj
        </button>
      </form>
      <?php endif; ?>
      <?php if ($offer['status'] === 'active'): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"     value="set_offer_status">
        <input type="hidden" name="new_status"  value="closed">
        <button type="submit" class="btn btn-sm btn-outline-light"
                onclick="return confirm('Zamknąć ogłoszenie? Nowe zgłoszenia nie będą przyjmowane.')">
          <i class="bi bi-archive me-1"></i>Zamknij
        </button>
      </form>
      <?php endif; ?>
      <?php if ($offer['status'] === 'active'): ?>
      <a href="apply.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-light">
        <i class="bi bi-box-arrow-up-right me-1"></i>Link do formularza
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<div class="row g-4">
<div class="col-xl-8">

  <!-- ── Opis ogłoszenia ──────────────────────────────────────────────── -->
  <?php if ($offer['content']): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-file-text text-primary me-1"></i>Opis stanowiska
    </div>
    <div class="card-body">
      <div style="white-space:pre-wrap;line-height:1.6"><?= h($offer['content']) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Zgłoszenia ──────────────────────────────────────────────────── -->
  <div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-people text-primary me-1"></i>Zgłoszenia kandydatów
      <span class="badge bg-primary ms-1"><?= $app_counts_total ?></span>
      <div class="ms-auto d-flex gap-1 flex-wrap">
        <a href="?id=<?= $id ?>"
           class="btn btn-xs btn-<?= !$app_status_filter ? 'primary' : 'outline-secondary' ?> btn-sm py-0 px-2">
          Wszystkie
        </a>
        <?php foreach (VolunteerModuleManager::APP_STATUSES as $s => $cfg): ?>
        <?php if (!$app_counts[$s]) continue; ?>
        <a href="?id=<?= $id ?>&app_status=<?= $s ?>"
           class="btn btn-sm py-0 px-2 btn-<?= $app_status_filter === $s ? $cfg['color'] : 'outline-secondary' ?>">
          <?= h($cfg['label']) ?>
          <span class="badge bg-<?= $cfg['color'] ?> ms-1"><?= $app_counts[$s] ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-body p-0">
    <?php if (!$applications): ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-inbox fs-1 d-block mb-2"></i>
      <?= $app_status_filter ? 'Brak zgłoszeń ze statusem „' . h(VolunteerModuleManager::APP_STATUSES[$app_status_filter]['label'] ?? $app_status_filter) . '".' : 'Brak zgłoszeń.' ?>
      <?php if ($offer['status'] === 'active'): ?>
      <div class="mt-2 small">
        <a href="apply.php?id=<?= $id ?>" target="_blank">
          <i class="bi bi-link-45deg me-1"></i>Link do formularza zgłoszeniowego
        </a>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="accordion" id="appsAccordion">
    <?php foreach ($applications as $idx => $app): ?>
    <?php
      $app_cfg    = VolunteerModuleManager::APP_STATUSES[$app['status']] ?? ['label' => $app['status'], 'color' => 'secondary'];
      $collapse_id = 'app-' . $app['id'];
    ?>
    <div class="accordion-item border-0 border-bottom">
      <div class="accordion-header">
        <button class="accordion-button collapsed py-2 px-3 bg-white" type="button"
                data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>">
          <div class="d-flex align-items-center gap-2 w-100">
            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                 style="width:32px;height:32px;font-size:.75rem;flex-shrink:0;
                        background:<?= $app['is_known_person'] ? '#0d6efd' : '#6c757d' ?>">
              <?= mb_strtoupper(mb_substr($app['candidate_name'], 0, 2)) ?>
            </div>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate"><?= h($app['candidate_name']) ?></div>
              <div class="text-muted small text-truncate"><?= h($app['candidate_email']) ?></div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
              <?php if ($app['is_known_person']): ?>
              <span class="badge bg-info-subtle text-info border border-info-subtle" title="Zarejestrowana osoba w systemie">
                <i class="bi bi-person-check me-1"></i>w systemie
              </span>
              <?php endif; ?>
              <span class="badge bg-<?= $app_cfg['color'] ?>"><?= h($app_cfg['label']) ?></span>
              <span class="text-muted small d-none d-md-inline"><?= date_pl($app['created_at']) ?></span>
            </div>
          </div>
        </button>
      </div>
      <div id="<?= $collapse_id ?>" class="accordion-collapse collapse" data-bs-parent="">
        <div class="accordion-body bg-light">
          <div class="row g-3">

            <!-- Dane kandydata -->
            <div class="col-md-6">
              <div class="fw-semibold mb-2 small text-uppercase text-muted">Dane kandydata</div>
              <table class="table table-sm mb-0 bg-white rounded">
                <tr><th class="fw-normal text-muted w-40">Imię i nazwisko</th>
                    <td class="fw-semibold"><?= h($app['candidate_name']) ?></td></tr>
                <tr><th class="fw-normal text-muted">E-mail</th>
                    <td><a href="mailto:<?= h($app['candidate_email']) ?>"><?= h($app['candidate_email']) ?></a></td></tr>
                <tr><th class="fw-normal text-muted">Telefon</th>
                    <td><?= $app['candidate_phone'] ? h($app['candidate_phone']) : '<span class="text-muted">—</span>' ?></td></tr>
                <tr><th class="fw-normal text-muted">Zgłoszono</th>
                    <td><?= date_pl($app['created_at']) ?></td></tr>
                <tr><th class="fw-normal text-muted">Tryb uproszczony</th>
                    <td><?= $app['is_simplified_communication_required'] ? yn(1) : yn(0) ?></td></tr>
              </table>
            </div>

            <!-- Odpowiedzi na dodatkowe pola -->
            <?php if ($offer['custom_fields'] && $app['form_data']): ?>
            <div class="col-md-6">
              <div class="fw-semibold mb-2 small text-uppercase text-muted">Odpowiedzi</div>
              <table class="table table-sm mb-0 bg-white rounded">
                <?php foreach ($offer['custom_fields'] as $cf): ?>
                <tr>
                  <th class="fw-normal text-muted" style="width:40%"><?= h($cf['label']) ?></th>
                  <td><?= h($app['form_data'][$cf['label']] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
              </table>
            </div>
            <?php endif; ?>

            <!-- Akcje workflow -->
            <div class="col-12">
              <div class="d-flex gap-2 flex-wrap align-items-start">
                <?php
                $transitions = [
                    'new'       => ['reviewing' => ['label' => 'Przyjmij do rozpatrzenia', 'color' => 'info',      'icon' => 'bi-search']],
                    'reviewing' => [
                        'interview' => ['label' => 'Zaproś na rozmowę',   'color' => 'warning',   'icon' => 'bi-camera-video'],
                        'accepted'  => ['label' => 'Zaakceptuj',          'color' => 'success',   'icon' => 'bi-check-circle'],
                        'rejected'  => ['label' => 'Odrzuć',              'color' => 'danger',    'icon' => 'bi-x-circle'],
                    ],
                    'interview' => [
                        'accepted'  => ['label' => 'Zaakceptuj',          'color' => 'success',   'icon' => 'bi-check-circle'],
                        'rejected'  => ['label' => 'Odrzuć',              'color' => 'danger',    'icon' => 'bi-x-circle'],
                    ],
                ];
                $available = $transitions[$app['status']] ?? [];
                foreach ($available as $new_st => $t):
                ?>
                <form method="post" class="d-inline"
                      <?= $new_st === 'rejected' ? 'id="rejectForm-' . $app['id'] . '"' : '' ?>>
                  <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action"     value="set_app_status">
                  <input type="hidden" name="app_id"      value="<?= $app['id'] ?>">
                  <input type="hidden" name="new_status"  value="<?= $new_st ?>">
                  <?php if ($new_st === 'rejected'): ?>
                  <input type="hidden" name="rejection_reason" id="rejReason-<?= $app['id'] ?>" value="">
                  <button type="button" class="btn btn-sm btn-outline-<?= $t['color'] ?>"
                          onclick="showRejectModal(<?= $app['id'] ?>)">
                    <i class="bi <?= $t['icon'] ?> me-1"></i><?= h($t['label']) ?>
                  </button>
                  <?php else: ?>
                  <button type="submit" class="btn btn-sm btn-outline-<?= $t['color'] ?>">
                    <i class="bi <?= $t['icon'] ?> me-1"></i><?= h($t['label']) ?>
                  </button>
                  <?php endif; ?>
                </form>
                <?php endforeach; ?>

                <?php if ($app['status'] === 'accepted'): ?>
                <!-- Onboarding — link lub przycisk tworzenia -->
                <?php if (!empty($app['onboarding_id'])): ?>
                <a href="<?= APP_URL ?>/onboarding/view.php?id=<?= (int)$app['onboarding_id'] ?>"
                   class="btn btn-sm btn-outline-primary ms-auto">
                  <i class="bi bi-person-check me-1"></i>Onboarding #<?= (int)$app['onboarding_id'] ?>
                </a>
                <?php else: ?>
                <form method="post" class="d-inline ms-auto">
                  <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action"  value="create_onboarding">
                  <input type="hidden" name="app_id"   value="<?= $app['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-success"
                          title="Utwórz wpis onboardingowy z danych kandydata">
                    <i class="bi bi-person-plus me-1"></i>Utwórz onboarding
                  </button>
                </form>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (in_array($app['status'], ['new','reviewing','interview'], true)): ?>
                <!-- Notatka admina — inline -->
                <form method="post" class="d-inline ms-auto">
                  <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action"  value="save_app_notes">
                  <input type="hidden" name="app_id"   value="<?= $app['id'] ?>">
                  <div class="input-group input-group-sm">
                    <input type="text" name="admin_notes" class="form-control"
                           placeholder="Notatka dla administratora…"
                           value="<?= h($app['admin_notes'] ?? '') ?>" style="min-width:220px">
                    <button type="submit" class="btn btn-outline-secondary">
                      <i class="bi bi-floppy"></i>
                    </button>
                  </div>
                </form>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($app['admin_notes']): ?>
            <div class="col-12">
              <div class="alert alert-secondary py-1 px-2 small mb-0">
                <i class="bi bi-sticky me-1"></i><strong>Notatka:</strong> <?= h($app['admin_notes']) ?>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($app['rejection_reason']): ?>
            <div class="col-12">
              <div class="alert alert-danger py-1 px-2 small mb-0">
                <i class="bi bi-x-circle me-1"></i><strong>Powód odrzucenia:</strong> <?= h($app['rejection_reason']) ?>
              </div>
            </div>
            <?php endif; ?>

          </div><!-- /row -->
        </div><!-- /accordion-body -->
      </div><!-- /collapse -->
    </div><!-- /accordion-item -->
    <?php endforeach; ?>
    </div><!-- /accordion -->
    <?php endif; ?>
    </div><!-- /card-body -->
  </div><!-- /card -->

</div><!-- /col -->

<!-- ── Prawa kolumna ─────────────────────────────────────────────────────── -->
<div class="col-xl-4">

  <!-- Statystyki zgłoszeń -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-bar-chart text-secondary me-1"></i>Statystyki zgłoszeń
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <?php foreach (VolunteerModuleManager::APP_STATUSES as $s => $cfg): ?>
        <?php if (!$app_counts[$s]) continue; ?>
        <tr>
          <td><span class="badge bg-<?= $cfg['color'] ?>"><?= h($cfg['label']) ?></span></td>
          <td class="text-end fw-bold"><?= $app_counts[$s] ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="table-active">
          <td class="fw-semibold">Łącznie</td>
          <td class="text-end fw-bold"><?= $app_counts_total ?></td>
        </tr>
      </table>
    </div>
  </div>

  <!-- Pola dodatkowe -->
  <?php if ($offer['custom_fields']): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
      <i class="bi bi-ui-checks text-secondary me-1"></i>Pola formularza
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <?php foreach ($offer['custom_fields'] as $cf): ?>
        <tr>
          <td class="text-muted small"><?= h($cf['label']) ?></td>
          <td class="text-muted small"><?= h($cf['type']) ?></td>
          <td class="text-end"><?= $cf['required'] ? '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">wymagane</span>' : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Link do formularza -->
  <?php if ($offer['status'] === 'active'): ?>
  <div class="card shadow-sm border-success-subtle">
    <div class="card-header fw-semibold text-success">
      <i class="bi bi-link-45deg me-1"></i>Formularz zgłoszeniowy
    </div>
    <div class="card-body">
      <p class="small text-muted mb-2">Udostępnij ten link kandydatom:</p>
      <div class="input-group input-group-sm">
        <input type="text" class="form-control font-monospace"
               id="applyLink"
               value="<?= h(APP_URL . '/contracts/rekrutacja/apply.php?id=' . $id) ?>"
               readonly>
        <button class="btn btn-outline-secondary" type="button" id="copyLinkBtn"
                onclick="copyApplyLink()">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
      <div class="mt-2 d-grid">
        <a href="apply.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-success">
          <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz formularz
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col -->
</div><!-- /row -->

<!-- ── Modal odrzucenia ──────────────────────────────────────────────────── -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Odrzuć zgłoszenie</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label fw-semibold">Powód odrzucenia <span class="text-danger">*</span></label>
        <textarea class="form-control" id="rejectReasonInput" rows="3"
                  placeholder="Opisz powód odrzucenia kandydatura…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-danger" id="rejectConfirmBtn">
          <i class="bi bi-x-circle me-1"></i>Odrzuć
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var _rejectAppId = null;
var _rejectModal = null;

document.addEventListener('DOMContentLoaded', function () {
    _rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
    document.getElementById('rejectConfirmBtn').addEventListener('click', function () {
        var reason = document.getElementById('rejectReasonInput').value.trim();
        if (!reason) {
            document.getElementById('rejectReasonInput').classList.add('is-invalid');
            return;
        }
        document.getElementById('rejectReasonInput').classList.remove('is-invalid');
        if (_rejectAppId !== null) {
            document.getElementById('rejReason-' + _rejectAppId).value = reason;
            document.getElementById('rejectForm-' + _rejectAppId).submit();
            _rejectModal.hide();
        }
    });
});

function showRejectModal(appId) {
    _rejectAppId = appId;
    document.getElementById('rejectReasonInput').value = '';
    document.getElementById('rejectReasonInput').classList.remove('is-invalid');
    _rejectModal.show();
}

function copyApplyLink() {
    var inp = document.getElementById('applyLink');
    inp.select();
    navigator.clipboard.writeText(inp.value).catch(function() {
        document.execCommand('copy');
    });
    var btn = document.getElementById('copyLinkBtn');
    btn.innerHTML = '<i class="bi bi-check2"></i>';
    setTimeout(function() { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
