<?php
/**
 * crm/login.php — logowanie do CRM (crm.feer.org.pl).
 *
 * WYŁĄCZNIE MICROSOFT 365. Do CRM nie da się wejść e-mailem i hasłem — ani kontem
 * służbowym, ani prywatnym. Powód: CRM trzyma dane osobowe kontaktów, zgody
 * i korespondencję, więc dostęp ma być pod kontrolą Entra ID (MFA, polityki
 * warunkowe, natychmiastowe odcięcie po odejściu współpracownika). Hasło lokalne
 * omijałoby to wszystko.
 *
 * Skutek uboczny, świadomy: konto bez powiązania z Microsoft 365 (współpracownik
 * z prywatnym adresem) nie wejdzie do CRM. Dostęp nadaje się przez konto służbowe.
 *
 * Uwierzytelnia /auth/ms365.php — ten sam tor co logowanie systemowe, wraz
 * z bramką WebAuthn i wymuszoną zmianą hasła.
 *
 * Warstwa CRM-owa zostaje: sprawdzenie uprawnień do modułu, bramka IKA dla kont
 * „tylko CRM" i powrót na host aliasu (crm.feer.org.pl), a nie na szo.feer.org.pl.
 *
 * WYGLĄD: ta sama powłoka co logowanie do systemu (includes/auth_screen.php) —
 * pasek dostępności (rozmiar tekstu, wysoki kontrast), tło marki z geometrią,
 * biała karta i te same kontrolki. Wcześniej ekran miał własny arkusz stylów
 * i wyglądał jak inna aplikacja, choć uwierzytelnia dokładnie tak samo; każda
 * poprawka wyglądu albo dostępności trzeba było robić dwa razy.
 *
 * WCAG: etykiety powiązane z polami, komunikat błędu jako role="alert",
 * widoczny fokus, obsługa klawiaturą, kontrast tekstu na tle marki.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/crm_sender_trust.php';   // domena organizacji

auth_start();

// Alias crm.feer.org.pl → zostajemy na tym hoście; APP_URL wskazuje szo.feer.org.pl,
// więc bez tego użytkownik po zalogowaniu wylądowałby na innej domenie.
$crm_base = crm_alias_base_url() ?? APP_URL;
$redirect = $crm_base . '/crm/dashboard.php';

if (current_user()) { header('Location: ' . $redirect); exit; }

$_b       = branding_load();
$org_name = $_b['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'System');

$org_domain   = crm_trusted_domains()[0] ?? 'feer.org.pl';
$ms_available = ms_login_available();
$ms_url       = $ms_available ? ms_auth_url($redirect) : '';

$error = (string)($_GET['e'] ?? '');   // komunikat z powrotu z /auth/ms365.php

/* Logowanie hasłem zostało z tego ekranu USUNIĘTE (nie schowane): formularz, który
   tylko nie jest wyświetlany, nadal przyjmuje POST i bywa znajdowany. Cała ścieżka
   `password_verify`, bramka IKA i 2FA żyją w torze systemowym — tu nie ma czego
   dublować, bo tu nie ma już czym się logować poza Microsoft 365. */

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'     => 'Logowanie do CRM',
    'tab'       => '',            // CRM nie ma rejestracji — zakładki byłyby ślepą uliczką
    'bootstrap' => true,
    'main_id'   => 'crm-login-main',
]);
?>

    <?php if ($error): ?>
    <div class="l-alert l-alert-danger" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <h1 class="ks-h1">CRM <?= h($org_name) ?></h1>
    <p class="ks-lead">Kontakty, sprawy, oferty i korespondencja organizacji.</p>

    <?php if ($ms_available): ?>
    <a href="<?= h($ms_url) ?>" class="ks-btn ks-btn--primary"
       aria-label="Zaloguj się przez Microsoft 365 — zostaniesz przekierowany do Microsoft">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
        <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
        <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
      </svg>
      Zaloguj przez Microsoft 365
    </a>
    <p class="ks-hint">
      To jedyna droga do CRM — modułu nie da się otworzyć e-mailem i hasłem.
      Dostęp, zmiana hasła i drugi składnik są po stronie konta
      <strong>@<?= h($org_domain) ?></strong>.
    </p>

    <hr class="ks-sep">

    <p class="ks-hint">
      <?php /* Dlaczego akurat tu bez hasła: CRM trzyma dane osobowe kontaktów, zgody
               i korespondencję. Konto lokalne omijałoby polityki Entra ID, w tym
               odcięcie dostępu w dniu zakończenia współpracy. */ ?>
      CRM trzyma dane osobowe, zgody i korespondencję, dlatego dostęp jest pod kontrolą
      Microsoft 365: wieloskładnikowe logowanie, polityki organizacji i natychmiastowe
      odcięcie konta po zakończeniu współpracy.
    </p>

    <?php else: ?>
    <?php /* Bez skonfigurowanego Microsoft 365 nikt się tu nie zaloguje — i nie ma
             obejścia hasłem, bo byłoby to dokładnie to wejście, które właśnie
             zamknęliśmy. Wyjściem jest logowanie systemowe: stamtąd administrator
             dojdzie do ustawień i włączy integrację. */ ?>
    <div class="l-alert l-alert-info" role="status">
      <i class="bi bi-info-circle-fill flex-shrink-0" aria-hidden="true"></i>
      <span>
        Logowanie do CRM odbywa się wyłącznie przez Microsoft 365, a integracja
        nie jest w tej chwili skonfigurowana.
      </span>
    </div>
    <p class="ks-hint">
      Administrator włącza ją w <strong>Ustawienia → Microsoft 365</strong> po zalogowaniu
      do systemu głównego.
    </p>
    <a href="<?= APP_URL ?>/auth/login.php" class="ks-btn ks-btn--ghost">
      <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Logowanie systemowe
    </a>
    <?php endif; ?>

<?php
// Odnośniki pod kartą — te same, które ekran miał w stopce, w powłoce systemowej
/* Bez „Odzyskaj dostęp": to odzyskiwanie HASŁA LOKALNEGO, którym do CRM i tak
   nie da się wejść. Hasło do konta służbowego zmienia się po stronie Microsoftu. */
$_crm_links = [
    ['url' => APP_URL . '/tozsamosc/index.php', 'label' => 'Moja tożsamość', 'icon' => 'bi-person-vcard'],
];
if (!CRM_STANDALONE) {
    $_crm_links[] = ['url' => APP_URL . '/auth/login.php', 'label' => 'Logowanie systemowe', 'icon' => 'bi-box-arrow-in-right'];
}
auth_screen_foot(['links' => $_crm_links]);
