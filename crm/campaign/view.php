<?php
/**
 * crm/campaign/view.php — Statystyki kampanii + tabela odbiorców.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_campaign.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$id = (int)($_GET['id'] ?? 0);
$campaign = $id ? db_one("SELECT * FROM crm_campaigns WHERE id=?", [$id]) : null;
if (!$campaign) { http_response_code(404); exit('Nie znaleziono kampanii.'); }

if ($campaign['status'] === 'sending') crm_campaign_refresh_stats($id);
$campaign = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$id]);

$template = db_one("SELECT name FROM crm_templates WHERE id=?", [(int)$campaign['template_id']]);

$recipients = db_all(
    "SELECT cr.*, ct.imie_nazwisko, ct.email FROM crm_campaign_recipients cr
     JOIN crm_contacts ct ON ct.id=cr.contact_id
     WHERE cr.campaign_id=? ORDER BY cr.id DESC LIMIT 200", [$id]
);

$failed = db_all(
    "SELECT cr.*, ct.imie_nazwisko, ct.email FROM crm_campaign_recipients cr
     JOIN crm_contacts ct ON ct.id=cr.contact_id
     WHERE cr.campaign_id=? AND cr.status='failed'
     ORDER BY ct.imie_nazwisko ASC", [$id]
);

$STATUS_LABELS = [
    'draft' => 'Szkic', 'scheduled' => 'Zaplanowana', 'sending' => 'Wysyłanie', 'sent' => 'Wysłana',
];

$PAGE_TITLE = 'CRM — ' . $campaign['name'];
include dirname(__DIR__) . '/includes/header_crm.php';
?>

<nav aria-label="breadcrumb" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Kampanie</a></li>
    <li class="breadcrumb-item active"><?= h($campaign['name']) ?></li>
  </ol>
</nav>

<div class="crm-page-header mb-4">
  <div>
    <div class="crm-page-title"><i class="bi bi-megaphone-fill" style="color:var(--crm-primary)"></i> <?= h($campaign['name']) ?></div>
    <div class="crm-page-subtitle">
      Szablon: <?= h($template['name'] ?? '—') ?> ·
      Status: <?= h($STATUS_LABELS[$campaign['status']] ?? $campaign['status']) ?>
      <?php if ($campaign['scheduled_at']): ?> · Zaplanowano na <?= h(date('d.m.Y H:i', strtotime($campaign['scheduled_at']))) ?><?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
<?php
$stats = [
    ['Odbiorcy', (int)$campaign['recipients_count'], 'bi-people-fill', '#0176D3'],
    ['Wysłane', (int)$campaign['sent_count'], 'bi-send-check-fill', '#16A34A'],
    ['Nie dotarło', (int)$campaign['failed_count'], 'bi-exclamation-circle-fill', '#DC2626'],
    ['Otwarcia', (int)$campaign['opened_count'], 'bi-envelope-open-fill', '#D97706'],
    ['Kliknięcia', (int)$campaign['clicked_count'], 'bi-cursor-fill', '#7C3AED'],
    ['Wypisania', (int)$campaign['unsubscribed_count'], 'bi-person-x-fill', '#6B7280'],
];
foreach ($stats as [$label, $val, $icon, $color]):
?>
<div class="col-6 col-md">
  <div class="cv-panel h-100">
    <div class="cv-panel__body text-center py-3">
      <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:1.3rem"></i>
      <div class="h4 mt-1 mb-0"><?= $val ?></div>
      <div class="text-muted small"><?= $label ?></div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<div class="table-responsive">
<table class="table align-middle table-sm">
<thead><tr><th>Kontakt</th><th>Status</th><th>Wysłano</th><th>Otwarto</th><th class="text-end">Kliknięcia</th><th>Wypisano</th></tr></thead>
<tbody>
<?php foreach ($recipients as $r): ?>
<tr>
  <td><?= h($r['imie_nazwisko']) ?> <span class="text-muted small"><?= h($r['email']) ?></span></td>
  <td><span class="badge bg-secondary bg-opacity-25 text-secondary"><?= h($r['status']) ?></span></td>
  <td><?= $r['sent_at'] ? h(date('d.m.Y H:i', strtotime($r['sent_at']))) : '—' ?></td>
  <td><?= $r['opened_at'] ? h(date('d.m.Y H:i', strtotime($r['opened_at']))) : '—' ?></td>
  <td class="text-end"><?= (int)$r['click_count'] ?></td>
  <td><?= $r['unsubscribed_at'] ? '<i class="bi bi-check-circle-fill text-danger"></i>' : '—' ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$recipients): ?><tr><td colspan="6" class="text-muted text-center py-4">Brak odbiorców.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php if ($failed): ?>
<div class="mt-4">
  <h6 class="fw-bold mb-2 d-flex align-items-center gap-2" style="color:#DC2626">
    <i class="bi bi-exclamation-circle-fill"></i>
    Nie otrzymali mailingu (<?= count($failed) ?>)
  </h6>
  <div class="alert alert-danger py-2 mb-2" style="font-size:.84rem">
    Wysyłka do poniższych kontaktów zakończyła się błędem — mailing do nich <strong>nie dotarł</strong>.
  </div>
  <div class="table-responsive">
  <table class="table align-middle table-sm table-hover">
    <thead class="table-danger">
      <tr>
        <th>#</th>
        <th>Imię i nazwisko</th>
        <th>E-mail</th>
        <th>Dodano do listy</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($failed as $i => $r): ?>
    <tr>
      <td class="text-muted"><?= $i + 1 ?></td>
      <td>
        <a href="<?= APP_URL ?>/crm/contact.php?id=<?= (int)$r['contact_id'] ?>" class="text-decoration-none">
          <?= h($r['imie_nazwisko']) ?>
        </a>
      </td>
      <td class="text-muted small"><?= h($r['email']) ?></td>
      <td class="text-muted small"><?= $r['created_at'] ? date('d.m.Y H:i', strtotime($r['created_at'])) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
