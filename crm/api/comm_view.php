<?php
/**
 * crm/api/comm_view.php — treść jednej wiadomości do okna podglądu w kartotece.
 *
 * Osobny endpoint, a nie 50 modali w stronie: historia kontaktu ma do 50 pozycji,
 * a treści maili bywają duże — osadzanie wszystkiego rozdmuchałoby stronę.
 *
 * Zwraca gotowy fragment HTML. Treść wiadomości pochodzi od dowolnego nadawcy,
 * więc HTML jest CZYSZCZONY z elementów wykonywalnych, a nie wstawiany surowo.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_read('crm') && !is_admin()) { http_response_code(403); exit('Brak uprawnień.'); }

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$id = (int)($_GET['id'] ?? 0);
$m  = $id ? db_one(
    "SELECT cc.*, u.name AS sender_name, mb.mailbox AS mailbox_name,
            c.imie_nazwisko AS contact_name, p.imie_nazwisko AS person_name
       FROM crm_communications cc
       LEFT JOIN users            u  ON u.id  = cc.sent_by
       LEFT JOIN poczta_mailboxes mb ON mb.id = cc.mailbox_id
       LEFT JOIN crm_contacts     c  ON c.id  = cc.contact_id
       LEFT JOIN crm_contact_persons p ON p.id = cc.person_id
      WHERE cc.id = ?", [$id]
) : null;

if (!$m) { http_response_code(404); exit('<div class="p-3 text-danger">Nie znaleziono wiadomości.</div>'); }

$is_out = in_array((string)($m['direction'] ?? ''), ['out', 'outgoing'], true);
$from   = trim((string)($m['from_name'] ?? '')) ?: (string)($m['from_email'] ?? '');

// Załączniki — jeśli wiadomość pochodzi ze skanowanej skrzynki.
$att = [];
try {
    $att = db_all("SELECT original_name, size_bytes FROM poczta_attachments WHERE communication_id=? ORDER BY id", [$id]);
} catch (\Throwable $e) {}

$fmt = static function (int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024) . ' kB';
    return round($b / 1048576, 1) . ' MB';
};
?>
<dl class="row mb-3" style="font-size:.85rem;row-gap:.25rem">
  <dt class="col-sm-3 text-muted fw-normal">Kierunek</dt>
  <dd class="col-sm-9 mb-0">
    <span class="badge bg-<?= $is_out ? 'primary' : 'success' ?>"><?= $is_out ? 'wychodząca' : 'przychodząca' ?></span>
    <?php if (!empty($m['mailbox_name'])): ?>
    <span class="font-monospace text-muted ms-1" style="font-size:.78rem"><?= h((string)$m['mailbox_name']) ?></span>
    <?php endif; ?>
  </dd>

  <?php if ($from !== ''): ?>
  <dt class="col-sm-3 text-muted fw-normal">Od</dt>
  <dd class="col-sm-9 mb-0">
    <?= h($from) ?>
    <?php if (!empty($m['from_name']) && !empty($m['from_email'])): ?>
    <span class="text-muted">&lt;<?= h((string)$m['from_email']) ?>&gt;</span>
    <?php endif; ?>
  </dd>
  <?php endif; ?>

  <?php if (!empty($m['sender_name'])): ?>
  <dt class="col-sm-3 text-muted fw-normal">Wysłał</dt>
  <dd class="col-sm-9 mb-0"><?= h((string)$m['sender_name']) ?></dd>
  <?php endif; ?>

  <?php if (!empty($m['person_name'])): ?>
  <dt class="col-sm-3 text-muted fw-normal">Osoba kontaktowa</dt>
  <dd class="col-sm-9 mb-0"><?= h((string)$m['person_name']) ?></dd>
  <?php endif; ?>

  <dt class="col-sm-3 text-muted fw-normal">Data</dt>
  <dd class="col-sm-9 mb-0"><?= h(!empty($m['sent_at']) ? date('d.m.Y H:i', strtotime((string)$m['sent_at'])) : '—') ?></dd>

  <?php if (!empty($m['subject'])): ?>
  <dt class="col-sm-3 text-muted fw-normal">Temat</dt>
  <dd class="col-sm-9 mb-0 fw-semibold"><?= h((string)$m['subject']) ?></dd>
  <?php endif; ?>
</dl>

<?php
  // Treść z zewnątrz — zostawiamy formatowanie, wycinamy wykonywalne elementy.
  $html = trim((string)($m['body_html'] ?? ''));
  if ($html !== '') {
      $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html);
      $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta)\b[^>]*/?>#is', '', $html);
      $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
      $html = preg_replace('#(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2#i', '$1="#"', $html);
  }
?>
<div class="border rounded p-3 bg-light" style="font-size:.88rem;line-height:1.6;overflow-wrap:anywhere;max-height:55vh;overflow-y:auto">
  <?php if ($html !== ''): ?>
    <?= $html ?>
  <?php elseif (trim((string)($m['body'] ?? '')) !== ''): ?>
    <?= nl2br(h((string)$m['body'])) ?>
  <?php else: ?>
    <span class="text-muted">Wiadomość bez treści.</span>
  <?php endif; ?>
</div>

<?php if ($att): ?>
<div class="mt-2">
  <div class="text-muted mb-1" style="font-size:.76rem">Załączniki (<?= count($att) ?>)</div>
  <?php foreach ($att as $a): ?>
  <div class="d-flex align-items-center gap-2" style="font-size:.82rem">
    <i class="bi bi-paperclip text-muted" aria-hidden="true"></i>
    <span class="text-truncate flex-grow-1"><?= h((string)$a['original_name']) ?></span>
    <?php if (!empty($a['size_bytes'])): ?>
    <span class="text-muted" style="font-size:.74rem"><?= h($fmt((int)$a['size_bytes'])) ?></span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
