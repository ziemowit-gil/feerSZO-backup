<?php
/**
 * panel/poczta.php — Zarządzanie pocztą: tekst o panelu ms365webpanel i potwierdzenie ustawienia stopki.
 * Wymóg (tryb require) przenosi tu z pulpitów — zob. modules/mail_footer/lib/mailFooter.php.
 * POST: _action=confirm_footer (osoba) albo mail_footer_mode (tylko administrator, karta w admin/m365_settings.php).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/mail_footer/lib/mailFooter.php';

require_login();
$PAGE_TITLE = 'Zarządzanie pocztą';
$user = current_user();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';
    if ($act === 'mail_footer_mode') {
        if (!is_admin()) { http_response_code(403); exit('Brak uprawnień.'); }
        mailFooterSaveMode((string)($_POST['mail_footer_mode'] ?? ''));
        header('Location: ' . APP_URL . '/admin/m365_settings.php#poczta-stopka'); exit;
    }
    if ($act === 'confirm_footer') {
        if (mailFooterHasMailbox($user) && !empty($_POST['footer_set'])) {
            mailFooterConfirm($uid);
            header('Location: ' . APP_URL . (!empty($_POST['wymagane']) ? '/panel/launcher.php' : '/panel/poczta.php?potwierdzono=1')); exit;
        }
        header('Location: ' . APP_URL . '/panel/poczta.php?blad=1' . (!empty($_POST['wymagane']) ? '&wymagane=1' : '')); exit;
    }
    header('Location: ' . APP_URL . '/panel/poczta.php'); exit;
}

$st        = mailFooterStatus($user);
$required  = !empty($_GET['wymagane']) && $st['pending'];
$mfHighlight = $st['pending'];
$_is_volunteer_only = panel_volunteer_shell($user);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
    require_once __DIR__ . '/includes/pv_ui.php'; pv_ui_styles();
}
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0050ef');
?>
<style>
.pvm .mf-confirm{border-left:6px solid <?= $required ? 'var(--m-red)' : 'var(--m-amber, #c25100)' ?>;background:var(--pvm-light-h);padding:1rem 1.1rem;margin-bottom:1.5rem}
.pvm .mf-confirm h2{font-size:1.15rem;font-weight:600;margin:0 0 .35rem}
.pvm .mf-confirm p{margin:0 0 .75rem;max-width:75ch}
.pvm .mf-confirm label{display:flex;gap:.6rem;align-items:flex-start;margin:0 0 .85rem;font-weight:600}
.pvm .mf-confirm input[type=checkbox]{width:1.2rem;height:1.2rem;margin-top:.15rem;flex-shrink:0}
.pvm .mf-confirm .mf-row{display:flex;flex-wrap:wrap;gap:8px}
</style>

<div class="pvm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker"><a href="<?= APP_URL ?>/panel/index.php" style="color:inherit">Panel współpracownika</a> · konta i dostępy</div>
      <h1 class="mt-title">Zarządzanie pocztą</h1>
      <p class="mt-lead">Stopka, aliasy, hasło, 2FA, autoresponder i przekierowanie. Wszystko dotyczy wyłącznie Twojej skrzynki.</p>
    </div>
  </div>

  <?php if (isset($_GET['blad'])): ?>
  <div class="mt-note" role="alert" style="border-left-color:var(--m-red)"><i class="bi bi-x-circle" style="color:var(--m-red)" aria-hidden="true"></i>
    <span>Zaznacz, że stopka jest ustawiona, i potwierdź ponownie.</span></div>
  <?php endif; ?>
  <?php if (isset($_GET['potwierdzono'])): ?>
  <div class="mt-note" role="status" style="border-left-color:var(--m-green)"><i class="bi bi-check-circle" style="color:var(--m-green)" aria-hidden="true"></i>
    <span>Dziękujemy, zapisaliśmy, że Twoja stopka jest ustawiona.</span></div>
  <?php endif; ?>

  <?php if (!$st['applies']): ?>
  <div class="mt-note" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i>
    <span>Nie widzimy przy Twoim koncie skrzynki Microsoft 365, więc nie musisz niczego ustawiać. Jeśli to pomyłka, napisz do działu IT przez <a href="<?= APP_URL ?>/panel/helpdesk.php" style="color:inherit;font-weight:600">Helpdesk</a>.</span></div>
  <?php elseif ($st['pending']): ?>
  <section class="mf-confirm" role="<?= $required ? 'alert' : 'region' ?>" aria-labelledby="mf-c-h">
    <h2 id="mf-c-h"><?= $required ? 'Zanim przejdziesz dalej, ustaw swoją stopkę' : 'Ustaw swoją stopkę' ?></h2>
    <p>Stopka to podpis, który dopisuje się pod każdą wiadomością wysłaną z Twojej skrzynki. Przygotuj ją w panelu poczty, a potem wróć tutaj i potwierdź.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="confirm_footer">
      <?php if ($required): ?><input type="hidden" name="wymagane" value="1"><?php endif; ?>
      <label for="footer_set"><input type="checkbox" id="footer_set" name="footer_set" value="1" required>Moja stopka jest ustawiona</label>
      <div class="mf-row">
        <a class="mt-btn mt-btn--ghost" href="<?= h(mailFooterUrl('stopka')) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Ustaw stopkę<span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>
        <button type="submit" class="mt-btn"><i class="bi bi-check2" aria-hidden="true"></i>Potwierdzam</button>
      </div>
    </form>
  </section>
  <?php elseif ($st['confirmed']): ?>
  <div class="mt-note" role="status" style="border-left-color:var(--m-green)"><i class="bi bi-check-circle" style="color:var(--m-green)" aria-hidden="true"></i>
    <span>Stopka jest ustawiona (potwierdzone <?= h(date('d.m.Y', strtotime((string)$st['confirmedAt']))) ?>). Możesz ją zmienić w każdej chwili w panelu poczty.</span></div>
  <?php endif; ?>

  <?php include dirname(__DIR__) . '/modules/mail_footer/view/manageCard.php'; ?>
</div>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
