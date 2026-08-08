<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$id = (int)($_GET['id'] ?? 0);
$schedule = db_one(
    "SELECT s.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
            u.name AS consultant_name,
            r.name AS resource_name, r.location AS resource_location,
            rc.name AS resource_cat, rc.icon AS resource_icon, rc.color AS resource_color
     FROM k30_schedules s
     LEFT JOIN k30_clients c ON c.id=s.client_id
     LEFT JOIN users u ON u.id=s.assigned_to
     LEFT JOIN resources r ON r.id=s.resource_id
     LEFT JOIN resource_categories rc ON rc.id=r.category_id
     WHERE s.id=?",
    [$id]
);
if (!$schedule) {
    flash_set('danger', 'Termin nie istnieje.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Termin — Dydaktyka 3';
$can_write  = can_write('karty30') || is_admin();

// RODO: rejestr dostępu do danych wrażliwych wizyty
if ($_SERVER['REQUEST_METHOD'] === 'GET') k30_log_access('schedule', $id, 'view', $schedule['client_name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $quick  = ['attended','cancelled_by_feer','cancelled_by_client','no_show','confirmed','cancelled'];
    if ($can_write && in_array($action, $quick)) {
        $cancel_reason = trim($_POST['cancel_reason'] ?? '');
        db()->prepare("UPDATE k30_schedules SET status=?, cancel_reason=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$action, $cancel_reason ?: null, $id]);
        flash_set('success', 'Status zaktualizowany.');
        header('Location: view.php?id=' . $id);
        exit;
    }
    // Anuluj całą serię od tego terminu
    if ($can_write && $action === 'cancel_series') {
        $series_id = $schedule['series_id'] ?? '';
        $reason    = trim($_POST['cancel_reason'] ?? '');
        if ($series_id) {
            db()->prepare(
                "UPDATE k30_schedules SET status='cancelled', cancel_reason=?, updated_at=datetime('now')
                 WHERE series_id=? AND start_time >= ? AND status NOT IN ('attended','cancelled')"
            )->execute([$reason ?: 'Anulowanie serii', $series_id, $schedule['start_time']]);
            flash_set('success', 'Anulowano tę i kolejne wizyty z serii.');
        }
        header('Location: view.php?id=' . $id);
        exit;
    }
}

$dt = new DateTime($schedule['start_time']);
$end_dt = clone $dt;
$end_dt->modify('+' . (int)$schedule['duration_minutes'] . ' minutes');

$consultation = db_one("SELECT * FROM k30_consultations WHERE schedule_id=?", [$id]);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
    <li class="breadcrumb-item"><a href="index.php">Harmonogram</a></li>
    <li class="breadcrumb-item active">Termin #<?= $id ?></li>
  </ol>
</nav>

<?= flash_html() ?>

<?php if (isset($_GET['print_suggest'])): ?>
<div class="alert d-flex align-items-center gap-3 mb-3"
     role="alert" style="border:0;border-left:4px solid #16a34a;background:#f0fdf4;border-radius:8px;padding:14px 18px">
  <i class="bi bi-file-earmark-pdf-fill text-success flex-shrink-0" style="font-size:1.4rem"></i>
  <div class="flex-grow-1">
    <div class="fw-semibold" style="color:#15803d">Zapisz kartę terminu jako PDF</div>
    <div class="text-muted small mt-1">
      Możesz wydrukować kartę terminu lub zapisać ją jako plik PDF do archiwum.
    </div>
  </div>
  <div class="d-flex gap-2 flex-shrink-0">
    <a href="pdf.php?id=<?= $id ?>&auto=1" target="_blank"
       class="btn btn-success btn-sm d-inline-flex align-items-center gap-1">
      <i class="bi bi-printer-fill"></i> Drukuj / PDF
    </a>
    <a href="pdf.php?id=<?= $id ?>" target="_blank"
       class="btn btn-outline-success btn-sm d-inline-flex align-items-center gap-1">
      <i class="bi bi-eye"></i> Podgląd
    </a>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="this.closest('[role=alert]').remove()">✕</button>
  </div>
</div>
<?php endif; ?>

<div class="d-flex align-items-start gap-3 mb-4 flex-wrap">
  <div>
    <h4 class="mb-0 fw-bold"><?= h($schedule['client_name']) ?></h4>
    <div class="text-muted small"><?= $dt->format('d.m.Y, H:i') ?> – <?= $end_dt->format('H:i') ?> (<?= (int)$schedule['duration_minutes'] ?> min)</div>
    <div class="mt-1 d-flex gap-2 flex-wrap align-items-center">
      <?= k30_status_badge($schedule['status']) ?>
      <?php if ($schedule['series_id']): ?>
      <?php
        $series_count = (int)(db_one("SELECT COUNT(*) AS c FROM k30_schedules WHERE series_id=?",[$schedule['series_id']])['c']??0);
        $series_index = (int)($schedule['series_index'] ?? 0);
      ?>
      <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:.72rem">
        <i class="bi bi-arrow-repeat me-1"></i>Cykl <?= $series_index+1 ?>/<?= $series_count ?>
      </span>
      <a href="index.php?series=<?= h($schedule['series_id']) ?>" class="text-muted small text-decoration-none">
        Pokaż całą serię
      </a>
      <?php endif; ?>
      <?php
        $bt_info = K30_BILLING_TYPES[$schedule['billing_type'] ?? 'free'] ?? null;
        if ($bt_info): ?>
      <span class="badge" style="background:<?= h($bt_info['bg']) ?>;color:<?= h($bt_info['color']) ?>;border:1px solid <?= h($bt_info['color']) ?>33;font-size:.72rem">
        <i class="bi <?= h($bt_info['icon']) ?> me-1"></i><?= h($bt_info['label']) ?>
      </span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($can_write): ?>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a href="pdf.php?id=<?= $id ?>" target="_blank"
       class="btn btn-outline-secondary btn-sm" title="Drukuj / Zapisz jako PDF">
      <i class="bi bi-printer me-1"></i>PDF
    </a>
    <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edytuj</a>
    <?php if (!$consultation): ?>
    <a href="<?= APP_URL ?>/karty30/consultations/add.php?schedule_id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-clipboard2-plus me-1"></i>Dodaj konsultację</a>
    <?php endif; ?>
    <?php if ($schedule['series_id'] && !in_array($schedule['status'],['cancelled','attended'])): ?>
    <button type="button" class="btn btn-outline-danger btn-sm"
            onclick="document.getElementById('cancel_series_form').style.display=''">
      <i class="bi bi-x-octagon me-1"></i>Anuluj serię od tej daty
    </button>
    <form id="cancel_series_form" method="post" style="display:none" class="d-inline">
      <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action"  value="cancel_series">
      <input type="text"   name="cancel_reason" class="form-control form-control-sm d-inline-block"
             style="width:180px" placeholder="Powód (opcjonalnie)">
      <button type="submit" class="btn btn-danger btn-sm ms-1">Potwierdź</button>
    </form>
    <?php endif; ?>
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
            <tr><th class="text-muted fw-normal" style="width:130px">Beneficjent</th><td><a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$schedule['client_id'] ?>"><?= h($schedule['client_name']) ?></a></td></tr>
            <tr><th class="text-muted fw-normal">Telefon</th><td><?= $schedule['client_phone'] ? h($schedule['client_phone']) : '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Email</th><td><?= $schedule['client_email'] ? h($schedule['client_email']) : '—' ?></td></tr>
            <tr><th class="text-muted fw-normal">Konsultant</th><td><?= $schedule['consultant_name'] ? h($schedule['consultant_name']) : '—' ?></td></tr>
            <?php if ($schedule['resource_name']): ?>
            <tr>
              <th class="text-muted fw-normal">Zasób</th>
              <td>
                <?php if ($schedule['resource_icon']): ?>
                <i class="bi <?= h($schedule['resource_icon']) ?> me-1" style="color:<?= h($schedule['resource_color']) ?>"></i>
                <?php endif; ?>
                <strong><?= h($schedule['resource_name']) ?></strong>
                <?php if ($schedule['resource_location']): ?>
                <span class="text-muted ms-1">— <?= h($schedule['resource_location']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endif; ?>
            <tr>
              <th class="text-muted fw-normal">Czas trwania</th>
              <td>
                <?= (int)$schedule['duration_minutes'] ?> min
                <?php if ($schedule['time_from'] && $schedule['time_to']): ?>
                <span class="text-muted ms-1">(<?= h($schedule['time_from']) ?> – <?= h($schedule['time_to']) ?>)</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ((float)($schedule['billed_hours'] ?? 0) > 0): ?>
            <tr>
              <th class="text-muted fw-normal">Rozliczenie</th>
              <td>
                <?php
                  $bh = (float)$schedule['billed_hours'];
                  $fh = (float)$schedule['free_hours'];
                  $ch = (float)$schedule['charged_hours'];
                  $am = (float)$schedule['amount_due'];
                ?>
                <?php if ($am == 0): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle">
                  <i class="bi bi-gift me-1"></i>Bezpłatne
                </span>
                <?php else: ?>
                <strong><?= number_format($am, 2, ',', ' ') ?> zł</strong>
                <?php endif; ?>
                <?php if ($schedule['pricing_note']): ?>
                <div class="text-muted small mt-1"><?= h($schedule['pricing_note']) ?></div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endif; ?>
            <tr><th class="text-muted fw-normal">Status</th><td><?= k30_status_badge($schedule['status']) ?></td></tr>
            <?php if ($schedule['cancel_reason']): ?>
            <tr><th class="text-muted fw-normal">Powód odwołania</th><td><?= h($schedule['cancel_reason']) ?></td></tr>
            <?php endif; ?>
            <?php if ($schedule['description']): ?>
            <tr><th class="text-muted fw-normal">Opis</th><td><?= nl2br(h($schedule['description'])) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($schedule['needs_invoice'])): ?>
            <?php $fv_type = $schedule['invoice_type'] ?? 'company'; ?>
            <tr>
              <th colspan="2" style="background:#fffbeb;border-top:2px solid #f59e0b;padding:8px 12px">
                <i class="bi bi-receipt-cutoff text-warning me-1"></i>
                <strong>Prośba o FV</strong>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-2" style="font-size:.72rem">
                  <?= $fv_type === 'personal' ? 'Imienna' : 'Firmowa' ?>
                </span>
              </th>
            </tr>
            <tr><th class="text-muted fw-normal" style="padding-left:1.5rem">Nazwa</th><td><?= h($schedule['invoice_name']) ?></td></tr>
            <?php if ($schedule['invoice_nip']): ?>
            <tr><th class="text-muted fw-normal" style="padding-left:1.5rem">NIP</th><td class="font-monospace"><?= h($schedule['invoice_nip']) ?></td></tr>
            <?php endif; ?>
            <tr><th class="text-muted fw-normal" style="padding-left:1.5rem">Adres</th><td><?= h($schedule['invoice_address']) ?></td></tr>
            <?php if ($schedule['invoice_email']): ?>
            <tr><th class="text-muted fw-normal" style="padding-left:1.5rem">E-mail FV</th><td><a href="mailto:<?= h($schedule['invoice_email']) ?>"><?= h($schedule['invoice_email']) ?></a></td></tr>
            <?php endif; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($consultation): ?>
    <div class="card shadow-sm border-success">
      <div class="card-header fw-semibold text-success" style="font-size:.85rem"><i class="bi bi-clipboard2-check me-1"></i>Powiązana konsultacja</div>
      <div class="card-body">
        <div class="mb-2"><?= k30_status_badge($consultation['status'], 'consultation') ?></div>
        <a href="<?= APP_URL ?>/karty30/consultations/view.php?id=<?= (int)$consultation['id'] ?>" class="btn btn-outline-success btn-sm">Zobacz konsultację</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Zmiana statusu -->
  <?php if ($can_write): ?>
  <div class="col-md-7">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.85rem"><i class="bi bi-arrow-repeat me-1"></i>Zmień status</div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php
          $quick = [
              'attended'           => ['Odbyta', 'btn-primary'],
              'confirmed'          => ['Potwierdzona', 'btn-success'],
              'cancelled_by_feer'  => ['Odwołana przez FEER', 'btn-danger'],
              'cancelled_by_client'=> ['Odwołana przez beneficjenta', 'btn-warning'],
              'no_show'            => ['Nie pojawił się', 'btn-secondary'],
              'cancelled'          => ['Odwołana', 'btn-outline-secondary'],
          ];
          foreach ($quick as $st => [$lbl, $cls]):
              if ($schedule['status'] === $st) continue;
          ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="<?= $st ?>">
            <button type="submit" class="btn btn-sm <?= $cls ?>"><?= $lbl ?></button>
          </form>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted">Aktualny status: <?= k30_status_badge($schedule['status']) ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
