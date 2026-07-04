<?php
/**
 * panel/whatsapp_group.php — Podstrona "WhatsApp — grupa" w panelu wolontariusza.
 * Treść (opis + link zaproszenia) zarządzana przez administratora
 * w admin/whatsapp_group.php (settings: whatsapp_group_*).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
require_module_enabled('whatsapp_group_enabled', 'Podstrona WhatsApp — grupa');

$link = org_setting('whatsapp_group_link');
$info = org_setting('whatsapp_group_info');

$PAGE_TITLE = 'WhatsApp — grupa';
include __DIR__ . '/includes/header_panel.php';
?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-whatsapp me-2" aria-hidden="true"></i>WhatsApp — grupa</h1>
  <p class="pv-page-sub">Dołącz do grupy organizacji na WhatsApp</p>
</div>

<div class="row g-3">
<div class="<?= $link ? 'col-lg-7' : 'col-12' ?>">
  <div class="vol-detail-card h-100">
    <div class="vol-detail-body">
      <?php if (trim($info) !== ''): ?>
      <div class="mb-3" style="line-height:1.6;white-space:pre-wrap;word-break:break-word"><?= nl2br(h($info)) ?></div>
      <?php else: ?>
      <p class="text-muted mb-3">Brak dodatkowych informacji o grupie.</p>
      <?php endif; ?>

      <?php if ($link): ?>
      <a href="<?= h($link) ?>" target="_blank" rel="noopener" class="btn btn-success">
        <i class="bi bi-whatsapp me-1" aria-hidden="true"></i>Dołącz do grupy
      </a>
      <?php else: ?>
      <div class="alert alert-light border mb-0 small py-2">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Link do grupy nie został jeszcze udostępniony.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($link): ?>
<div class="col-lg-5">
  <div class="vol-detail-card h-100">
    <div class="vol-detail-header"><i class="bi bi-qr-code me-2" aria-hidden="true"></i>Zeskanuj telefonem</div>
    <div class="vol-detail-body text-center">
      <div id="waGroupQr" class="mb-2 d-flex justify-content-center" role="img" aria-label="Kod QR do dołączenia do grupy WhatsApp"></div>
      <p class="text-muted small mb-0">Zeskanuj kod aparatem telefonu, aby otworzyć zaproszenie bezpośrednio w aplikacji WhatsApp.</p>
    </div>
  </div>
</div>
<?php endif; ?>
</div>

<?php if ($link): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('waGroupQr');
    if (!container) return;
    var url = <?= json_encode($link) ?>;
    try {
        new QRCode(container, { text: url, width: 180, height: 180, colorDark: '#128C7E', colorLight: '#ffffff' });
    } catch (e) {
        container.innerHTML = '<a href="' + url + '" target="_blank" class="btn btn-outline-success btn-sm">' + url + '</a>';
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
