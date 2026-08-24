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
require_once dirname(dirname(__DIR__)) . '/includes/crm_consent.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('campaigns', 'read');

$id = (int)($_GET['id'] ?? 0);
$campaign = $id ? db_one("SELECT * FROM crm_campaigns WHERE id=?", [$id]) : null;
if (!$campaign) { http_response_code(404); exit('Nie znaleziono kampanii.'); }

// Odświeżamy zawsze, nie tylko przy statusie 'sending': otwarcia i kliknięcia
// przychodzą jeszcze wiele dni po domknięciu wysyłki.
crm_campaign_refresh_stats($id);
$campaign = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$id]);

$st        = crm_campaign_stats($id);
$top_links = crm_campaign_top_links($id);
$skips     = crm_campaign_skip_breakdown($id);
$design    = crm_campaign_design($campaign);

// Mianownik współczynników: liczba realnie wysłanych wiadomości. Liczymy tu,
// a nie w SQL-u, żeby dzielenie przez zero było widoczne, a nie ukryte w NULL-u.
$base  = max(1, (int)$st['sent']);
$pct   = fn(int $n): string => $st['sent'] > 0 ? number_format($n / $base * 100, 1, ',', ' ') . '%' : '—';

$template = db_one("SELECT name FROM crm_templates WHERE id=?", [(int)$campaign['template_id']]);
$purpose  = ((int)($campaign['purpose_id'] ?? 0)) ? crm_consent_purpose((int)$campaign['purpose_id']) : null;

$recipients = db_all(
    "SELECT cr.*, ct.imie_nazwisko, ct.email FROM crm_campaign_recipients cr
     JOIN crm_contacts ct ON ct.id=cr.contact_id
     WHERE cr.campaign_id=? AND cr.status <> 'skipped'
     ORDER BY cr.id DESC LIMIT 200", [$id]
);

$skipped_rows = db_all(
    "SELECT cr.skip_reason, ct.id AS contact_id, ct.imie_nazwisko, ct.email
       FROM crm_campaign_recipients cr
       JOIN crm_contacts ct ON ct.id=cr.contact_id
      WHERE cr.campaign_id=? AND cr.status='skipped'
      ORDER BY cr.skip_reason, ct.imie_nazwisko LIMIT 300", [$id]
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
$can_write = can_write('crm') || is_admin();

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
      <?php if ($purpose): ?>
      · <span title="Wysyłka objęła tylko kontakty ze zgodą na ten cel">
          <i class="bi bi-shield-check" aria-hidden="true"></i> Cel: <?= h($purpose['nazwa']) ?>
        </span>
      <?php endif; ?>
      <?php if ($design): ?> · <span title="Treść pochodzi z edytora blokowego"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Edytor blokowy</span><?php endif; ?>
    </div>
  </div>
  <?php if ($can_write && in_array($campaign['status'], ['draft', 'scheduled'], true)): ?>
  <div class="crm-page-actions">
    <a href="editor.php?id=<?= $id ?>" class="btn btn-crm-primary btn-sm"><i class="bi bi-pencil-square me-1"></i>Edytuj treść</a>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
<?php
$stats = [
    ['Wysłane',     $st['sent'],          '',                        'bi-send-check-fill',          '#16A34A', 'Wiadomości przyjęte do wysyłki bez błędu'],
    ['Nie dotarło', $st['failed'],        $pct($st['failed']),       'bi-exclamation-circle-fill',  '#DC2626', 'Błąd wysyłki lub odbicie'],
    ['Otwarcia',    $st['unique_opens'],  $pct($st['unique_opens']),  'bi-envelope-open-fill',       '#D97706', 'Unikalni odbiorcy; łącznie otwarć: ' . $st['total_opens']],
    ['Kliknięcia',  $st['unique_clicks'], $pct($st['unique_clicks']), 'bi-cursor-fill',              '#7C3AED', 'Unikalni odbiorcy; łącznie klików: ' . $st['total_clicks']],
    ['Wypisania',   $st['unsubscribes'],  $pct($st['unsubscribes']),  'bi-person-x-fill',            '#6B7280', 'Kliknęli link wypisania'],
    ['Pominięci',   $st['skipped'],       '',                        'bi-slash-circle',             '#94A3B8', 'Byli w segmencie, ale nie wolno było do nich napisać'],
];
foreach ($stats as [$label, $val, $rate, $icon, $color, $title]):
?>
<div class="col-6 col-md">
  <div class="cv-panel h-100" title="<?= h($title) ?>">
    <div class="cv-panel__body text-center py-3">
      <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:1.3rem"></i>
      <div class="h4 mt-1 mb-0"><?= (int)$val ?></div>
      <?php if ($rate !== ''): ?><div style="font-size:.72rem;color:<?= $color ?>"><?= h($rate) ?></div><?php endif; ?>
      <div class="text-muted small"><?= $label ?></div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php if ($st['pending'] > 0): ?>
<div class="alert alert-info py-2" style="font-size:.84rem">
  <i class="bi bi-hourglass-split me-1"></i>
  W kolejce zostało <strong><?= (int)$st['pending'] ?></strong> wiadomości. Liczniki uzupełnią się w miarę wysyłki.
</div>
<?php endif; ?>

<?php if ($skips): ?>
<div class="cv-panel mb-4">
  <div class="cv-panel__body">
    <h6 class="fw-bold mb-2" style="font-size:.85rem">
      <i class="bi bi-slash-circle me-1" style="color:#94A3B8"></i>Dlaczego <?= (int)$st['skipped'] ?> kontaktów nie dostało wiadomości
    </h6>
    <ul class="mb-0" style="font-size:.82rem">
      <?php foreach ($skips as $sk): ?>
      <li><?= h($sk['label']) ?> — <strong><?= (int)$sk['n'] ?></strong></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<?php if ($top_links): ?>
<div class="cv-panel mb-4">
  <div class="cv-panel__body">
    <h6 class="fw-bold mb-2" style="font-size:.85rem">
      <i class="bi bi-link-45deg me-1" style="color:#7C3AED"></i>Najczęściej klikane linki
    </h6>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Adres</th><th class="text-end">Kliknięcia</th><th class="text-end">Unikalnych</th><th class="text-end">CTR</th></tr></thead>
      <tbody>
      <?php foreach ($top_links as $l): ?>
        <tr>
          <td class="small"><a href="<?= h($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($l['label'] ?: $l['url']) ?></a></td>
          <td class="text-end"><?= (int)$l['clicks'] ?></td>
          <td class="text-end"><?= (int)$l['unique_clicks'] ?></td>
          <td class="text-end text-muted small"><?= h($pct((int)$l['unique_clicks'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

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

<?php if ($skipped_rows): ?>
<div class="mt-4">
  <h6 class="fw-bold mb-2 d-flex align-items-center gap-2 text-muted" style="font-size:.85rem">
    <i class="bi bi-slash-circle"></i>Pominięci odbiorcy (<?= count($skipped_rows) ?>)
  </h6>
  <div class="table-responsive">
  <table class="table align-middle table-sm">
    <thead><tr><th>Kontakt</th><th>E-mail</th><th>Powód pominięcia</th></tr></thead>
    <tbody>
    <?php foreach ($skipped_rows as $r): ?>
    <tr>
      <td><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$r['contact_id'] ?>" class="text-decoration-none"><?= h($r['imie_nazwisko']) ?></a></td>
      <td class="text-muted small"><?= h($r['email']) ?></td>
      <td class="small"><?= h($r['skip_reason']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

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
        <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$r['contact_id'] ?>" class="text-decoration-none">
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
