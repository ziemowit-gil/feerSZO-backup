<?php
/**
 * admin/oswiadczenia_settings.php — Ustawienia modułu Oświadczeń.
 * Adres nadawcy powiadomień (kod 2FA, nowe oświadczenie, potwierdzenie podpisu)
 * konfigurowalny osobno od domyślnego nadawcy systemowego — patrz
 * includes/oswiadczenia.php:_osw_from_email().
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/oswiadczenia.php';

require_login();
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $from_email = trim($_POST['oswiadczenia_from_email'] ?? '');
    if ($from_email !== '' && !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Podany adres nadawcy nie jest prawidłowym adresem e-mail.');
        header('Location: oswiadczenia_settings.php'); exit;
    }

    org_setting_set('oswiadczenia_from_email', $from_email);
    org_setting_set('oswiadczenia_enabled', isset($_POST['oswiadczenia_enabled']) ? '1' : '0');

    flash_set('success', 'Ustawienia modułu Oświadczeń zapisane.');
    header('Location: oswiadczenia_settings.php'); exit;
}

$PAGE_TITLE = 'Ustawienia — Oświadczenia';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/oswiadczenia/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-check text-primary me-2"></i>Ustawienia — Oświadczenia</h4>
</div>

<?= flash_html() ?>

<div class="row">
<div class="col-md-7">

<div class="card border-0 shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
    <i class="bi bi-envelope me-1 text-primary"></i>Nadawca powiadomień
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Adres e-mail nadawcy</label>
        <input type="email" name="oswiadczenia_from_email" class="form-control"
               placeholder="powiadomienia@szo.feer.org.pl"
               value="<?= h(org_setting('oswiadczenia_from_email')) ?>">
        <div class="form-text">
          Puste pole = domyślny nadawca systemowy. Z tego adresu wysyłane są: kod
          autoryzacyjny 2FA, powiadomienie o nowym oświadczeniu i potwierdzenie
          podpisu (z załącznikiem PDF).
        </div>
      </div>

      <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" id="oswEnabled" name="oswiadczenia_enabled" value="1"
               <?= module_enabled('oswiadczenia_enabled') ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="oswEnabled">Moduł Oświadczeń włączony</label>
      </div>

      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>Zapisz ustawienia
      </button>
    </form>
  </div>
</div>

</div><!-- /col-7 -->
<div class="col-md-5">

  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-info-circle me-1"></i>O module
    </div>
    <div class="card-body small" style="line-height:1.7">
      <p class="text-muted mb-2">
        Oświadczenia w formie dokumentowej (art. 77² k.c.) — treść ustalona przez
        organizację, podpis potwierdzony jednorazowym kodem wysłanym SMS-em lub
        e-mailem.
      </p>
      <p class="text-muted mb-0">
        Dedykowany adres nadawcy ułatwia filtrowanie tych powiadomień po stronie
        odbiorcy i oddziela je od pozostałej korespondencji z domyślnej skrzynki
        organizacji.
      </p>
    </div>
  </div>

</div><!-- /col-5 -->
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
