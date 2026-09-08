<?php
/**
 * karty30/ti/zapisy.php — Publiczny formularz kontaktowy „Zapisy na zajęcia".
 *
 * Bez logowania. Zgłoszenie trafia do CRM organizacji jako nowy kontakt
 * (status 'prospect'), skąd zespół dalej prowadzi kontakt — tak jak ustalono,
 * leady z tego formularza mają iść do CRM ogólnego, nie do osobnej tabeli
 * ani do modułu Nabór. Kod polecający (opcjonalny) trafia do notatki kontaktu —
 * jego faktyczne wykorzystanie (rabat) następuje dopiero przy zakładaniu
 * konta kursanta (karty30/ti/dydaktyk/konta.php), patrz includes/ti_referrals.php.
 *
 * Publiczna strona = brak sesji, więc żadnego tokenu CSRF (tak jak pozostałe
 * publiczne formularze w aplikacji, np. crm/form/index.php). Zamiast tego:
 * pole-pułapka (honeypot) + minimalny czas wypełniania — podstawowa ochrona
 * przed prostymi botami.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
require_once dirname(dirname(__DIR__)) . '/includes/org_case.php';

$crm_on = module_enabled('crm_enabled');

$errors    = [];
$submitted = ($_GET['ok'] ?? '') === '1';

if ($crm_on && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
    crm_migrate();

    // Honeypot: pole ukryte przed ludźmi przez CSS, boty je wypełniają.
    $honeypot   = trim((string)($_POST['website'] ?? ''));
    $opened_at  = (int)($_POST['t'] ?? 0);
    $too_fast   = $opened_at > 0 && (time() - $opened_at) < 3;

    $name    = trim((string)($_POST['name'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $refcode = strtoupper(trim((string)($_POST['referral_code'] ?? '')));

    if ($honeypot === '' && !$too_fast) {
        if ($name === '') $errors[] = 'Podaj imię i nazwisko.';
        if ($phone === '' && $email === '') $errors[] = 'Podaj telefon albo e-mail — inaczej nie będziemy mieli jak się odezwać.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Adres e-mail wygląda na nieprawidłowy.';

        if (!$errors) {
            $phone_norm = $phone !== '' ? sms_normalize_phone($phone) : '';

            // Zgłoszenie tej samej osoby w ciągu ostatniej doby — nie zakładaj
            // drugiej kartoteki, tylko dopisz się do notatki istniejącej.
            $dup = null;
            if ($email !== '') {
                $dup = db_one("SELECT id, notatka FROM crm_contacts WHERE email=? AND crm_active=1 ORDER BY id DESC LIMIT 1", [$email]);
            }
            if (!$dup && $phone_norm !== '') {
                $dup = db_one("SELECT id, notatka FROM crm_contacts WHERE telefon=? AND crm_active=1 ORDER BY id DESC LIMIT 1", [$phone_norm]);
            }

            $note = 'Zgłoszenie z formularza „Zapisy na zajęcia" (' . date('d.m.Y H:i') . ').';
            if ($message !== '') $note .= ' Wiadomość: ' . $message;
            if ($refcode !== '') $note .= ' Kod polecający: ' . $refcode . '.';

            if ($dup) {
                db()->prepare("UPDATE crm_contacts SET notatka = notatka || ?, updated_at = datetime('now') WHERE id=?")
                    ->execute(["\n\n" . $note, (int)$dup['id']]);
            } else {
                CrmManager::createContact([
                    'type'          => 'osoba',
                    'status'        => 'prospect',
                    'imie_nazwisko' => $name,
                    'telefon'       => $phone_norm,
                    'email'         => $email,
                    'notatka'       => $note,
                    'source'        => 'formularz_www_zajecia_ti',
                ]);
            }

            header('Location: zapisy.php?ok=1'); exit;
        }
    } else {
        // Bot najpewniej — nie zdradzamy dlaczego, po prostu nic się nie dzieje.
        header('Location: zapisy.php?ok=1'); exit;
    }
}

$KP_ORG = defined('ORG_NAME') ? ORG_NAME : '';
$KP_TITLE      = 'Zapisy na zajęcia';
$KP_BODY_CLASS = 'kp-zapisy-page';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_referrals.php';
$ref_on = $crm_on && ti_referral_settings()['enabled'];
include __DIR__ . '/kursant/_layout_head.php';
?>
<style>
*, *::before, *::after { box-sizing: border-box; }
:root {
  --bg:#ffffff; --text:#111111; --muted:#5f5f5f; --rule:#d4d4d4;
  --accent:#c2410c; --accent-h:#9a3409; --hover-bg:#f5f5f5;
}
@media (prefers-color-scheme: dark) {
  :root { --bg:#0c0c0c; --text:#ededed; --muted:#888888; --rule:#2a2a2a; --accent:#e05a1e; --accent-h:#f97316; --hover-bg:#161616; }
}
html, body.kp-zapisy-page { min-height:100%; margin:0; padding:0 !important; }
body.kp-zapisy-page { background:var(--bg); color:var(--text); font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; }
.zp-wrap { min-height:100vh; width:100%; display:flex; align-items:center; justify-content:center; padding:3rem 1.5rem; }
.zp-card { width:100%; max-width:520px; }
.zp-org { font-size:.68rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); margin:0 0 .5rem; }
.zp-h1 { font-size:1.9rem; font-weight:800; letter-spacing:-.03em; line-height:1.15; margin:0 0 .75rem; }
.zp-sub { color:var(--muted); font-size:.92rem; line-height:1.6; margin:0 0 1.75rem; }
.zp-card .form-label { font-weight:600; font-size:.88rem; }
.zp-card .form-control { border:1px solid var(--rule); border-radius:4px; background:var(--bg); color:var(--text); }
.zp-card .form-control:focus { border-color:var(--accent); box-shadow:0 0 0 1px var(--accent); }
.zp-btn { background:var(--accent); border:1px solid var(--accent); color:#fff; border-radius:4px; font-weight:600; width:100%; padding:.65rem 1rem; }
.zp-btn:hover { background:var(--accent-h); border-color:var(--accent-h); color:#fff; }
.zp-honeypot { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
.zp-ok { border:1px solid var(--rule); border-radius:4px; padding:1.25rem; text-align:center; }
.zp-ok i { font-size:2rem; color:var(--accent); }
</style>

<main id="main" class="zp-wrap">
  <div class="zp-card">
    <p class="zp-org"><?= h($KP_ORG) ?></p>
    <h1 class="zp-h1">Zapisy na zajęcia</h1>

    <?php if (!$crm_on): ?>
    <p class="zp-sub">Formularz jest obecnie niedostępny. Zadzwoń lub napisz do nas bezpośrednio — dane kontaktowe znajdziesz na stronie <?= h($KP_ORG) ?>.</p>

    <?php elseif ($submitted): ?>
    <div class="zp-ok">
      <i class="bi bi-check-circle" aria-hidden="true"></i>
      <p class="fw-semibold mt-2 mb-1">Dziękujemy za zgłoszenie!</p>
      <p class="text-body-secondary small mb-0">Skontaktujemy się wkrótce na podany numer lub adres e-mail.</p>
    </div>

    <?php else: ?>
    <p class="zp-sub">Zostaw kontakt, a odezwiemy się z informacją o wolnych terminach i zapisach.</p>

    <?php if ($errors): ?>
    <div class="alert alert-danger py-2 mb-3" role="alert">
      <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="t" value="<?= time() ?>">
      <div class="zp-honeypot" aria-hidden="true">
        <label for="website">Nie wypełniaj tego pola</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <div class="mb-3">
        <label class="form-label" for="name">Imię i nazwisko</label>
        <input type="text" class="form-control" id="name" name="name" required
               value="<?= h((string)($_POST['name'] ?? '')) ?>">
      </div>
      <div class="mb-3">
        <label class="form-label" for="phone">Telefon</label>
        <input type="tel" class="form-control" id="phone" name="phone"
               value="<?= h((string)($_POST['phone'] ?? '')) ?>" placeholder="np. 600 123 456">
      </div>
      <div class="mb-3">
        <label class="form-label" for="email">E-mail</label>
        <input type="email" class="form-control" id="email" name="email"
               value="<?= h((string)($_POST['email'] ?? '')) ?>">
      </div>
      <div class="mb-3">
        <label class="form-label" for="message">Czym jesteś zainteresowany/a? (opcjonalnie)</label>
        <textarea class="form-control" id="message" name="message" rows="3"><?= h((string)($_POST['message'] ?? '')) ?></textarea>
      </div>
      <?php if ($ref_on): ?>
      <div class="mb-3">
        <label class="form-label" for="referral_code">Kod polecający (opcjonalnie)</label>
        <input type="text" class="form-control text-uppercase" id="referral_code" name="referral_code" maxlength="6"
               style="letter-spacing:.15em" value="<?= h((string)($_POST['referral_code'] ?? '')) ?>">
      </div>
      <?php endif; ?>

      <button type="submit" class="zp-btn">
        <i class="bi bi-send me-2" aria-hidden="true"></i>Wyślij zgłoszenie
      </button>
    </form>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/kursant/_layout_foot.php'; ?>
