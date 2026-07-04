<?php
/**
 * admin/whatsapp_group.php — Konfiguracja podstrony "WhatsApp — grupa".
 * Prosta treść informacyjna (link do dołączenia + opis) wyświetlana
 * wolontariuszom w panelu (panel/whatsapp_group.php).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'WhatsApp — grupa';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $link = trim($_POST['whatsapp_group_link'] ?? '');
    if ($link !== '' && !preg_match('~^https?://~i', $link)) {
        $link = 'https://' . $link;
    }
    org_setting_set('whatsapp_group_enabled', isset($_POST['whatsapp_group_enabled']) ? '1' : '0');
    org_setting_set('whatsapp_group_link',    $link);
    org_setting_set('whatsapp_group_info',    trim($_POST['whatsapp_group_info'] ?? ''));
    flash_set('success', 'Ustawienia grupy WhatsApp zostały zapisane.');
    header('Location: ' . APP_URL . '/admin/whatsapp_group.php');
    exit;
}

$enabled = org_setting('whatsapp_group_enabled') !== '0';
$link    = org_setting('whatsapp_group_link');
$info    = org_setting('whatsapp_group_info');

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <i class="bi bi-whatsapp text-success" style="font-size:1.5rem"></i>
  <h4 class="mb-0">WhatsApp — grupa</h4>
</div>
<?= flash_html() ?>

<div class="row g-3">
<div class="col-xl-7">

<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-toggles"></i> Aktywacja</div>
    <div class="card-body">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch"
               name="whatsapp_group_enabled" id="whatsapp_group_enabled" value="1"
               <?= $enabled ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="whatsapp_group_enabled">Podstrona „WhatsApp — grupa" widoczna w panelu wolontariusza</label>
      </div>
      <div class="form-text">Gdy wyłączona, link w menu panelu nie jest pokazywany.</div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-link-45deg"></i> Link zaproszenia do grupy</div>
    <div class="card-body">
      <label class="form-label fw-semibold small" for="whatsapp_group_link">Link (np. z WhatsApp: „Zaproś przez link")</label>
      <input type="url" name="whatsapp_group_link" id="whatsapp_group_link" class="form-control form-control-sm"
             value="<?= h($link) ?>" placeholder="https://chat.whatsapp.com/XXXXXXXXXXXXXXXXXXXXXX">
      <div class="form-text">W WhatsApp: otwórz grupę → Info o grupie → Zaproś przez link → Kopiuj link.</div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-card-text"></i> Opis / informacje</div>
    <div class="card-body">
      <label class="form-label fw-semibold small" for="whatsapp_group_info">Treść widoczna dla wolontariuszy</label>
      <textarea name="whatsapp_group_info" id="whatsapp_group_info" class="form-control form-control-sm" rows="8"
                placeholder="Do czego służy grupa, jakie są zasady, kto administruje itp."><?= h($info) ?></textarea>
      <div class="form-text">Zwykły tekst — złamania linii zostaną zachowane.</div>
    </div>
  </div>

  <button type="submit" class="btn btn-success">
    <i class="bi bi-check-lg"></i> Zapisz
  </button>
</form>

</div><!-- /col-xl-7 -->

<div class="col-xl-5">
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-eye"></i> Podgląd</div>
    <div class="card-body">
      <?php if (!$enabled): ?>
      <div class="text-muted small"><i class="bi bi-slash-circle me-1"></i>Podstrona jest obecnie wyłączona.</div>
      <?php endif; ?>
      <?php if ($info !== ''): ?>
      <div class="mb-3" style="white-space:pre-wrap;line-height:1.6"><?= nl2br(h($info)) ?></div>
      <?php else: ?>
      <p class="text-muted small mb-3">Brak opisu — dodaj treść powyżej.</p>
      <?php endif; ?>
      <?php if ($link): ?>
      <a href="<?= h($link) ?>" target="_blank" class="btn btn-success btn-sm">
        <i class="bi bi-whatsapp me-1"></i>Dołącz do grupy
      </a>
      <?php else: ?>
      <div class="text-muted small"><i class="bi bi-exclamation-circle me-1"></i>Brak linku zaproszenia — podaj go powyżej.</div>
      <?php endif; ?>
    </div>
  </div>
  <a href="<?= APP_URL ?>/panel/whatsapp_group.php" target="_blank" class="btn btn-outline-secondary btn-sm w-100">
    <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz podstronę w panelu wolontariusza
  </a>
</div>

</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
