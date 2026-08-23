<?php
/**
 * crm/campaign/index.php — Lista kampanii mailowych CRM.
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

$can_write = can_write('crm') || is_admin();

// Odśwież liczniki kampanii w trakcie wysyłki przy każdym wejściu na listę.
// Kampanie już domknięte pomijamy — otwarcia i kliknięcia i tak podnoszą
// liczniki na bieżąco w crm/track/*.php, a pełne przeliczenie robi widok kampanii.
foreach (db_all("SELECT id FROM crm_campaigns WHERE status='sending'") as $c) {
    crm_campaign_refresh_stats((int)$c['id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $cid    = (int)($_POST['campaign_id'] ?? 0);
    $action = $_POST['_action'] ?? '';
    if ($action === 'send_now') {
        $res = crm_campaign_queue_send($cid);
        if (!empty($res['error'])) {
            flash_set('error', $res['error']);
        } else {
            $msg = 'Kampania wysyłana — w kolejce: ' . (int)$res['queued'] . '.';
            if (!empty($res['skipped'])) $msg .= ' Pominięto ' . (int)$res['skipped'] . ' kontaktów (szczegóły w podglądzie kampanii).';
            flash_set('success', $msg);
        }
    } elseif ($action === 'cancel') {
        db()->prepare("UPDATE crm_campaigns SET status='draft', scheduled_at=NULL WHERE id=? AND status='scheduled'")->execute([$cid]);
        flash_set('success', 'Anulowano harmonogram.');
    } elseif ($action === 'delete') {
        db()->prepare("DELETE FROM crm_campaigns WHERE id=? AND status='draft'")->execute([$cid]);
        flash_set('success', 'Usunięto kampanię.');
    }
    header('Location: ' . APP_URL . '/crm/campaign/index.php');
    exit;
}

$campaigns = db_all("SELECT * FROM crm_campaigns ORDER BY created_at DESC");

$STATUS_LABELS = [
    'draft'     => ['Szkic', '#6B7280', 'bi-pencil'],
    'scheduled' => ['Zaplanowana', '#D97706', 'bi-clock-fill'],
    'sending'   => ['Wysyłanie', '#2563EB', 'bi-send-fill'],
    'sent'      => ['Wysłana', '#16A34A', 'bi-check-circle-fill'],
];

$PAGE_TITLE = 'CRM — Kampanie mailowe';
include dirname(__DIR__) . '/includes/header_crm.php';
?>

<div class="crm-page-header mb-4">
  <div>
    <div class="crm-page-title"><i class="bi bi-megaphone-fill" style="color:var(--crm-primary)"></i> Kampanie mailowe</div>
    <div class="crm-page-subtitle"><?= count($campaigns) ?> kampanii</div>
  </div>
  <div class="crm-page-actions">
    <?php if ($can_write): ?>
    <a href="editor.php" class="btn btn-crm-primary btn-sm"><i class="bi bi-grid-1x2-fill me-1"></i>Nowy newsletter</a>
    <a href="add.php" class="btn btn-light border btn-sm" title="Kampania na bazie gotowego szablonu tekstowego">
      <i class="bi bi-file-earmark-text me-1"></i>Z szablonu
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$campaigns): ?>
<div class="cv-empty py-5">
  <i class="bi bi-megaphone" aria-hidden="true"></i>
  <h2 class="h6 text-muted">Brak kampanii</h2>
  <p class="mb-3" style="font-size:.85rem">Kampanie pozwalają wysłać zaprojektowany e-mail do segmentu kontaktów z trackingiem otwarć i kliknięć.</p>
  <?php if ($can_write): ?>
  <a href="editor.php" class="btn btn-crm-primary btn-sm"><i class="bi bi-grid-1x2-fill me-1"></i>Zaprojektuj pierwszy newsletter</a>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="table-responsive">
<table class="table align-middle">
<thead>
  <tr>
    <th>Nazwa</th><th>Status</th><th class="text-end">Odbiorcy</th><th class="text-end">Wysłane</th>
    <th class="text-end">Otwarcia</th><th class="text-end">Kliknięcia</th><th class="text-end">Wypisania</th><th></th>
  </tr>
</thead>
<tbody>
<?php foreach ($campaigns as $c):
  [$label, $color, $icon] = $STATUS_LABELS[$c['status']] ?? ['?', '#6B7280', 'bi-question'];
  // Bez wysłanych wiadomości współczynnik nie istnieje — pokazujemy „—",
  // a nie 0%, bo 0% sugeruje „nikt nie otworzył", co jest nieprawdą.
  $sent = (int)$c['sent_count'];
  $rate = fn(int $n): string => $sent > 0 ? ' <span class="text-muted small">(' . round($n / $sent * 100) . '%)</span>' : '';
?>
  <tr>
    <td><a href="view.php?id=<?= (int)$c['id'] ?>" class="fw-semibold text-decoration-none"><?= h($c['name']) ?></a></td>
    <td><span class="badge" style="background:<?= $color ?>1a;color:<?= $color ?>"><i class="bi <?= $icon ?> me-1"></i><?= $label ?></span></td>
    <td class="text-end"><?= (int)$c['recipients_count'] ?></td>
    <td class="text-end"><?= (int)$c['sent_count'] ?></td>
    <td class="text-end"><?= (int)$c['opened_count'] ?><?= $rate((int)$c['opened_count']) ?></td>
    <td class="text-end"><?= (int)$c['clicked_count'] ?><?= $rate((int)$c['clicked_count']) ?></td>
    <td class="text-end"><?= (int)$c['unsubscribed_count'] ?></td>
    <td class="text-end">
      <?php if ($can_write && in_array($c['status'], ['draft', 'scheduled'], true)): ?>
      <a href="editor.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj treść"><i class="bi bi-pencil"></i></a>
      <?php endif; ?>
      <?php if ($can_write && $c['status'] === 'draft'): ?>
      <form method="post" class="d-inline" onsubmit="return confirm('Wysłać kampanię teraz?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="send_now">
        <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
        <button class="btn btn-sm btn-outline-primary py-0 px-2" title="Wyślij teraz"><i class="bi bi-send"></i></button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten szkic kampanii?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
        <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
      </form>
      <?php elseif ($can_write && $c['status'] === 'scheduled'): ?>
      <form method="post" class="d-inline" onsubmit="return confirm('Anulować harmonogram?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="cancel">
        <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
        <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Anuluj harmonogram"><i class="bi bi-x-circle"></i></button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
