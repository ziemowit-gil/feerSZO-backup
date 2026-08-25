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
require_once dirname(__DIR__) . '/includes/org_case.php';   // odmiana nazwy organizacji
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

/* Rozjazd dla kogoś, kto tu zabłądził.
   crm.feer.org.pl łatwo trafić z wyszukiwarki, ze starego odnośnika albo przez
   pomyłkę z adresem strony fundacji. Taka osoba widziała dotąd wyłącznie przycisk
   logowania, którego nie ma jak użyć — czyli ślepy zaułek. Poniżej są miejsca,
   w które najczęściej zmierzała naprawdę; adresy budujemy na APP_URL, bo z aliasu
   crm.* pozostałe moduły nie są dostępne. */
$_public_site = 'https://feer.org.pl';
$_lost_links  = [
    [
        'url'   => $_public_site,
        'icon'  => 'bi-globe2',
        'label' => 'Strona Fundacji FEER',
        'desc'  => 'Informacje o działalności, kontakt, aktualności',
    ],
    [
        'url'   => APP_URL . '/panel/index.php',
        'icon'  => 'bi-person-heart',
        'label' => 'Panel wolontariusza i współpracownika',
        'desc'  => 'Umowy, zadania, godziny, zaświadczenia',
    ],
    [
        'url'   => APP_URL . '/karty30/ti/kursant/index.php',
        'icon'  => 'bi-mortarboard',
        'label' => 'Panel kursanta',
        'desc'  => 'Zajęcia, materiały, oceny i rozliczenia',
    ],
    [
        'url'   => APP_URL . '/karty30/ti/dydaktyk/login.php',
        'icon'  => 'bi-easel2',
        'label' => 'Panel prowadzącego',
        'desc'  => 'Lekcje, obecności, dziennik ocen',
    ],
];
try {
    require_once dirname(__DIR__) . '/includes/webmail_clients.php';
    $_lost_links[] = [
        'url'   => webmail_chooser_url(),
        'icon'  => 'bi-envelope',
        'label' => 'Poczta',
        'desc'  => 'Skrzynka w domenie ' . $org_domain,
    ];
} catch (\Throwable $e) {}

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

    <?php /* Nazwa modułu + organizacja W DOPEŁNIACZU — „CRM Fundacji…", nie
             „CRM Fundacja…". Wspólny wzorzec wszystkich ekranów logowania. */ ?>
    <h1 class="ks-h1"><?= h(org_login_title('CRM')) ?></h1>
    <?php /* Pierwsze zdanie musi odpowiedzieć „gdzie ja jestem", bo część osób
             trafia tu przypadkiem. Dopiero drugie mówi, dla kogo to narzędzie. */ ?>
    <p class="ks-lead">
      Wewnętrzne narzędzie zespołu Fundacji: kontakty, sprawy, oferty i korespondencja.
      <br><span class="crm-lead-note">Dostęp mają wyłącznie osoby z kontem służbowym
      w domenie <strong>@<?= h($org_domain) ?></strong>.</span>
    </p>

    <?php if ($ms_available): ?>
    <a href="<?= h($ms_url) ?>" class="ks-btn ks-btn--primary"
       aria-label="Zaloguj się przez Microsoft 365 — zostaniesz przekierowany do Microsoft">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
        <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
        <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
      </svg>
      Zaloguj przez Microsoft 365
    </a>
    <?php /* Bez akapitu o politykach Entra ID pod przyciskiem: informacja, że wchodzi
             się kontem służbowym, jest już w zdaniu wstępnym, a powtórzona spychała
             rozjazd „to nie tutaj" poniżej krawędzi ekranu. */ ?>

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

    <hr class="ks-sep">

    <?php /* Rozjazd. Świadomie POD przyciskiem logowania i wizualnie spokojniejszy:
             osoba z kontem ma tu nie zgubić swojej ścieżki, a zabłąkana ma znaleźć
             swoją bez pytania kogokolwiek. */ ?>
    <h2 class="crm-lost-h">Szukasz czegoś innego?</h2>
    <p class="crm-lost-sub">
      Ten adres to narzędzie do pracy zespołu. Jeśli trafiłeś tu z wyszukiwarki
      albo starego odnośnika, prawdopodobnie chodziło o jedno z tych miejsc:
    </p>
    <ul class="crm-lost">
      <?php foreach ($_lost_links as $l): ?>
      <li>
        <a href="<?= h($l['url']) ?>">
          <i class="bi <?= h($l['icon']) ?>" aria-hidden="true"></i>
          <span>
            <strong><?= h($l['label']) ?></strong>
            <span class="crm-lost-desc"><?= h($l['desc']) ?></span>
          </span>
          <i class="bi bi-chevron-right crm-lost-arr" aria-hidden="true"></i>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>

    <?php /* Bez adresu e-mail w tym miejscu: biuro@ w tej domenie nie istnieje,
             a odsyłanie pod nieistniejący adres jest gorsze niż nieodesłanie
             nigdzie. Kontakt jest na stronie fundacji, pierwszej na liście. */ ?>

<style>
/* Rozjazd „to nie tutaj" — utrzymany w języku powłoki logowania (ks-*), ale
   celowo cichszy od przycisku logowania: to podpowiedź, nie główna akcja. */
.crm-lead-note{display:inline-block;margin-top:.35rem;font-size:.85rem;color:var(--ks-muted)}
.crm-lost-h{font-size:1rem;font-weight:700;text-align:center;margin:0 0 .35rem;color:var(--ks-ink)}
.crm-lost-sub{text-align:center;font-size:.85rem;color:var(--ks-muted);line-height:1.55;margin:0 0 1.1rem}
.crm-lost{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.4rem}
.crm-lost a{
  display:flex;align-items:center;gap:.7rem;padding:.65rem .8rem;border:1px solid var(--ks-line);
  border-radius:10px;text-decoration:none;color:var(--ks-ink);background:#fff;transition:border-color .12s,background .12s;
}
.crm-lost a:hover,.crm-lost a:focus{border-color:var(--ks);background:#fafafa}
.crm-lost i:first-child{font-size:1.05rem;color:var(--ks);flex-shrink:0}
.crm-lost strong{display:block;font-size:.9rem;font-weight:600;line-height:1.3}
.crm-lost-desc{display:block;font-size:.78rem;color:var(--ks-muted);line-height:1.4}
.crm-lost-arr{margin-left:auto;font-size:.8rem;color:var(--ks-muted)}
html[data-theme="hc"] .crm-lost a{border:2px solid #000}
</style>

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
