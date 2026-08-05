<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
$PAGE_TITLE = 'Pomoc — Panel wolontariusza';
$user = current_user();
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<div class="pv-wrap">

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-question-circle" aria-hidden="true"></i>Pomoc</h1>
    <p class="pv-page-sub">Często zadawane pytania i wsparcie</p>
  </div>
</div>

<div class="tz-card mb-4">
  <div class="tz-card__hd" id="sec-faq"><i class="bi bi-list-columns me-2" aria-hidden="true"></i>Jak to działa — FAQ</div>
  <div class="tz-card__bd p-0">
    <div class="accordion accordion-flush" id="faqAccordion">

      <div class="accordion-item">
        <h2 class="accordion-header" id="faqh1">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1" aria-expanded="false" aria-controls="faq1">
            <i class="bi bi-house me-2 text-muted" aria-hidden="true"></i>Co znajdę na stronie głównej panelu?
          </button>
        </h2>
        <div id="faq1" class="accordion-collapse collapse" aria-labelledby="faqh1" data-bs-parent="#faqAccordion">
          <div class="accordion-body small text-muted">
            Skrót najważniejszych informacji: dane z umowy, liczba pism, wniosków i nieprzeczytanych wiadomości. Jeśli masz kilka umów, użyj przycisku <strong>Zmień umowę</strong>.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="faqh2">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2" aria-expanded="false" aria-controls="faq2">
            <i class="bi bi-award me-2 text-muted" aria-hidden="true"></i>Jak złożyć wniosek o zaświadczenie?
          </button>
        </h2>
        <div id="faq2" class="accordion-collapse collapse" aria-labelledby="faqh2" data-bs-parent="#faqAccordion">
          <div class="accordion-body small text-muted">
            Przejdź do <strong>Zaświadczenia</strong>, wybierz umowę, podaj cel (np. dla pracodawcy, uczelni) i wyślij. Fundacja przygotuje zaświadczenie i wyśle je na Twój adres e-mail w ciągu kilku dni roboczych.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="faqh3">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3" aria-expanded="false" aria-controls="faq3">
            <i class="bi bi-send me-2 text-muted" aria-hidden="true"></i>Jak wysłać wniosek lub pismo do organizacji?
          </button>
        </h2>
        <div id="faq3" class="accordion-collapse collapse" aria-labelledby="faqh3" data-bs-parent="#faqAccordion">
          <div class="accordion-body small text-muted">
            Przejdź do <strong>Wyślij wniosek</strong>, wybierz typ pisma, wypełnij formularz i kliknij Wyślij. Administrator otrzyma powiadomienie i odpowie przez panel.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="faqh4">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4" aria-expanded="false" aria-controls="faq4">
            <i class="bi bi-chat-left-text me-2 text-muted" aria-hidden="true"></i>Jak skontaktować się z koordynatorem?
          </button>
        </h2>
        <div id="faq4" class="accordion-collapse collapse" aria-labelledby="faqh4" data-bs-parent="#faqAccordion">
          <div class="accordion-body small text-muted">
            Użyj sekcji <strong>Wiadomości</strong> — wiadomości są prywatne i przypisane do konkretnej umowy. Możesz pisać w dowolnej sprawie. Nowe odpowiedzi pojawią się jako powiadomienia.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="faqh5">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5" aria-expanded="false" aria-controls="faq5">
            <i class="bi bi-file-earmark-x me-2 text-muted" aria-hidden="true"></i>Jak rozwiązać umowę?
          </button>
        </h2>
        <div id="faq5" class="accordion-collapse collapse" aria-labelledby="faqh5" data-bs-parent="#faqAccordion">
          <div class="accordion-body small text-muted">
            Przejdź do <strong>Rozwiązanie umowy</strong> i złóż wniosek z podaniem powodu. Wniosek wymaga akceptacji administratora — umowa nie zostanie rozwiązana automatycznie.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="faqh6">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6" aria-expanded="false" aria-controls="faq6">
            <i class="bi bi-shield-lock me-2 text-muted" aria-hidden="true"></i>Jak zwiększyć bezpieczeństwo konta?
          </button>
        </h2>
        <div id="faq6" class="accordion-collapse collapse" aria-labelledby="faqh6" data-bs-parent="#faqAccordion">
          <div class="accordion-body small text-muted">
            Włącz <strong>weryfikację dwuetapową (2FA)</strong> w ustawieniach konta — użyj aplikacji Google/Microsoft Authenticator lub kodu SMS. Regularnie sprawdzaj aktywne sesje w zakładce <strong>Bezpieczeństwo</strong>.
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<div class="tz-card">
  <div class="tz-card__hd" id="sec-kontakt"><i class="bi bi-envelope me-2" aria-hidden="true"></i>Kontakt z organizacją</div>
  <div class="tz-card__bd">
    <?php
    $org_email = org_setting('contact_email') ?: org_setting('email') ?: null;
    $org_phone = org_setting('contact_phone') ?: org_setting('phone') ?: null;
    $org_name  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : null);
    ?>
    <?php if ($org_name): ?>
    <div class="vol-detail-row">
      <span class="vol-detail-row-lbl">Organizacja</span>
      <span class="vol-detail-row-val"><?= h($org_name) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($org_email): ?>
    <div class="vol-detail-row">
      <span class="vol-detail-row-lbl">E-mail</span>
      <span class="vol-detail-row-val"><a href="mailto:<?= h($org_email) ?>"><?= h($org_email) ?></a></span>
    </div>
    <?php endif; ?>
    <?php if ($org_phone): ?>
    <div class="vol-detail-row">
      <span class="vol-detail-row-lbl">Telefon</span>
      <span class="vol-detail-row-val"><a href="tel:<?= h($org_phone) ?>"><?= h($org_phone) ?></a></span>
    </div>
    <?php endif; ?>
    <?php if (!$org_email && !$org_phone): ?>
    <p class="text-muted small mb-0">Dane kontaktowe nie są skonfigurowane. Skorzystaj z modułu <strong>Wiadomości</strong>.</p>
    <?php endif; ?>
  </div>
</div>

<?php else: /* admin/editor */ ?>

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <h1 class="pv-page-title"><i class="bi bi-question-circle" aria-hidden="true"></i>Pomoc</h1>
    <p class="pv-page-sub">Przewodnik po wszystkich funkcjach dostępnych w panelu wolontariusza.</p>
  </div>
</div>

<div class="row g-4">

<!-- Spis treści -->
<div class="col-lg-3 d-none d-lg-block">
  <div class="tz-card sticky-top">
    <div class="tz-card__hd">Spis treści</div>
    <div class="tz-card__bd p-2">
      <nav class="d-flex flex-column gap-1" aria-label="Spis treści">
        <a href="#sec-strona-glowna"  class="sb-sub-link"><i class="bi bi-house me-1" aria-hidden="true"></i> Strona główna</a>
        <a href="#sec-twoje-dane"     class="sb-sub-link"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i> Twoje dane</a>
        <a href="#sec-pisma"          class="sb-sub-link"><i class="bi bi-envelope-paper me-1" aria-hidden="true"></i> Pisma i dokumenty</a>
        <a href="#sec-zaswiadczenia"  class="sb-sub-link"><i class="bi bi-award me-1" aria-hidden="true"></i> Zaświadczenia</a>
        <a href="#sec-rozwiazanie"    class="sb-sub-link"><i class="bi bi-file-earmark-x me-1" aria-hidden="true"></i> Rozwiązanie umowy</a>
        <a href="#sec-wiadomosci"     class="sb-sub-link"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i> Wiadomości</a>
        <a href="#sec-konto"          class="sb-sub-link"><i class="bi bi-gear me-1" aria-hidden="true"></i> Ustawienia konta</a>
        <a href="#sec-logowanie"      class="sb-sub-link"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i> Logowanie</a>
      </nav>
    </div>
  </div>
</div>

<!-- Treść -->
<div class="col-lg-9">

<!-- Strona główna -->
<div class="tz-card mb-4" id="sec-strona-glowna">
  <div class="tz-card__hd"><i class="bi bi-house me-1" aria-hidden="true"></i> Strona główna panelu</div>
  <div class="tz-card__bd">
    <p>Po zalogowaniu trafiasz na stronę <strong>Moja umowa</strong>. Znajdziesz tu skrót najważniejszych informacji:</p>
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-1"><i class="bi bi-envelope-paper text-primary me-1" aria-hidden="true"></i> Pisma i dokumenty</div>
          <div class="small text-muted">Liczba pism wystawionych do Twoich umów (np. potwierdzenia, listy, decyzje).</div>
        </div>
      </div>
      <div class="col-sm-6">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-1"><i class="bi bi-award text-success me-1" aria-hidden="true"></i> Wnioski o zaświadczenia</div>
          <div class="small text-muted">Liczba aktualnie oczekujących wniosków o zaświadczenie wolontariackie.</div>
        </div>
      </div>
      <div class="col-sm-6">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-1"><i class="bi bi-file-earmark-x text-danger me-1" aria-hidden="true"></i> Wnioski o rozwiązanie</div>
          <div class="small text-muted">Liczba wniosków o rozwiązanie umowy, które oczekują na decyzję fundacji.</div>
        </div>
      </div>
      <div class="col-sm-6">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-1"><i class="bi bi-chat-left-text text-secondary me-1" aria-hidden="true"></i> Wiadomości</div>
          <div class="small text-muted">Liczba nieprzeczytanych wiadomości od koordynatora.</div>
        </div>
      </div>
    </div>
    <div class="tz-note tz-note--info">
      <i class="bi bi-lightbulb" aria-hidden="true"></i>
      Jeśli masz kilka umów, użyj przycisku <strong>Zmień umowę</strong>, aby przełączyć kontekst między nimi.
    </div>
  </div>
</div>

<!-- Twoje dane -->
<div class="tz-card mb-4" id="sec-twoje-dane">
  <div class="tz-card__hd"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i> Twoje dane w umowie</div>
  <div class="tz-card__bd">
    <p>Na stronie głównej panelu wyświetla się karta z Twoimi danymi z aktywnej umowy: imię i nazwisko, PESEL (maskowany), adres, telefon i e-mail.</p>
    <p class="mb-2"><strong>PESEL</strong> jest domyślnie ukryty. Kliknij ikonę <i class="bi bi-eye" aria-hidden="true"></i>, aby go wyświetlić.</p>
    <p class="mb-2">Kliknij <strong>Pełne dane</strong>, aby przejść do widoku szczegółowego umowy.</p>
    <div class="tz-note tz-note--warn">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      Dane w umowie może zmieniać wyłącznie koordynator. Jeśli zauważysz błąd — skontaktuj się przez <strong>Wiadomości</strong>.
    </div>
  </div>
</div>

<!-- Pisma -->
<div class="tz-card mb-4" id="sec-pisma">
  <div class="tz-card__hd"><i class="bi bi-envelope-paper me-1" aria-hidden="true"></i> Pisma i dokumenty</div>
  <div class="tz-card__bd">
    <p>W zakładce <strong>Moje pisma</strong> znajdziesz dokumenty wystawione przez fundację do Twoich umów.</p>
    <ul class="mb-2">
      <li>Możesz pobrać każde pismo jako PDF lub DOCX.</li>
      <li>Pisma są przypisane do konkretnej umowy — zmień kontekst, jeśli nie widzisz oczekiwanego dokumentu.</li>
    </ul>
    <p class="mb-0 text-muted small">Listy, potwierdzenia, zaświadczenia wystawione poza standardowym procesem wniosku pojawią się właśnie tutaj.</p>
  </div>
</div>

<!-- Zaświadczenia -->
<div class="tz-card mb-4" id="sec-zaswiadczenia">
  <div class="tz-card__hd"><i class="bi bi-award me-1" aria-hidden="true"></i> Zaświadczenia wolontariackie</div>
  <div class="tz-card__bd">
    <p>W sekcji <strong>Zaświadczenia</strong> możesz złożyć wniosek o zaświadczenie potwierdzające wolontariat.</p>
    <ol class="mb-3">
      <li>Przejdź do <em>Zaświadczenia</em> w menu bocznym.</li>
      <li>Kliknij <strong>Złóż wniosek o zaświadczenie</strong>.</li>
      <li>Podaj powód / cel zaświadczenia (np. praca, studia, organ publiczny).</li>
      <li>Wyślij wniosek — fundacja wyda zaświadczenie w ciągu kilku dni roboczych.</li>
      <li>Gotowe zaświadczenie pojawi się w zakładce zaświadczeń do pobrania w PDF.</li>
    </ol>
    <div class="tz-note tz-note--ok">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      Zaświadczenie potwierdzające wolontariat możesz pobrać i przekazać np. do pracodawcy, uczelni lub urzędu.
    </div>
  </div>
</div>

<!-- Rozwiązanie umowy -->
<div class="tz-card mb-4" id="sec-rozwiazanie">
  <div class="tz-card__hd"><i class="bi bi-file-earmark-x me-1" aria-hidden="true"></i> Rozwiązanie umowy</div>
  <div class="tz-card__bd">
    <p>Jeśli chcesz zakończyć współpracę wolontariacką, możesz złożyć wniosek o rozwiązanie umowy.</p>
    <ol class="mb-3">
      <li>Przejdź do <em>Rozwiązanie umowy</em> w menu.</li>
      <li>Wybierz umowę i podaj datę zakończenia oraz powód.</li>
      <li>Wyślij wniosek — fundacja rozpatrzy go i potwierdzi rozwiązanie.</li>
      <li>Po akceptacji status umowy zmieni się na <em>Rozwiązana</em>.</li>
    </ol>
    <div class="tz-note tz-note--warn">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      Wniosek <strong>nie rozwiązuje umowy automatycznie</strong> — wymaga akceptacji koordynatora lub administratora.
    </div>
  </div>
</div>

<!-- Wiadomości -->
<div class="tz-card mb-4" id="sec-wiadomosci">
  <div class="tz-card__hd"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i> Wiadomości</div>
  <div class="tz-card__bd">
    <p>Moduł wiadomości umożliwia bezpośredni kontakt z koordynatorem w ramach danej umowy.</p>
    <ul class="mb-2">
      <li>Wiadomości są <strong>prywatne</strong> — widzi je tylko Ty i fundacja.</li>
      <li>Nowe wiadomości wyświetlają się jako <span class="tz-badge">liczba</span> w menu bocznym.</li>
      <li>Możesz pisać w dowolnej sprawie: pytania, aktualizacje danych, informacje o nieobecności.</li>
    </ul>
    <p class="mb-0 text-muted small">
      Wątki są przypisane do konkretnej umowy. Jeśli masz kilka umów, przełącz kontekst w górnej belce panelu.
    </p>
  </div>
</div>

<!-- Ustawienia konta -->
<div class="tz-card mb-4" id="sec-konto">
  <div class="tz-card__hd"><i class="bi bi-gear me-1" aria-hidden="true"></i> Ustawienia konta</div>
  <div class="tz-card__bd">
    <p>W <strong>Ustawieniach konta</strong> (ikonka zębatki w menu) możesz:</p>
    <div class="row g-3 mb-2">
      <div class="col-sm-6">
        <div class="border rounded p-3">
          <div class="fw-semibold mb-1"><i class="bi bi-key me-1" aria-hidden="true"></i> Zmiana hasła</div>
          <div class="small text-muted">Ustaw nowe hasło do swojego konta. Wymagane jest podanie aktualnego hasła.</div>
        </div>
      </div>
      <div class="col-sm-6">
        <div class="border rounded p-3">
          <div class="fw-semibold mb-1"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i> Weryfikacja dwuetapowa (2FA)</div>
          <div class="small text-muted">Podwyższony poziom bezpieczeństwa — po zalogowaniu wymagany będzie kod z aplikacji (np. Google Authenticator).</div>
        </div>
      </div>
    </div>
    <div class="tz-note tz-note--info">
      <i class="bi bi-microsoft" aria-hidden="true"></i>
      Jeśli logujesz się przez <strong>Microsoft 365</strong>, hasło jest zarządzane przez Twój konto Microsoft — nie możesz go zmienić tutaj.
    </div>
  </div>
</div>

<!-- Logowanie -->
<div class="tz-card mb-4" id="sec-logowanie">
  <div class="tz-card__hd"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i> Sposoby logowania</div>
  <div class="tz-card__bd">
    <div class="row g-3">
      <div class="col-sm-4">
        <div class="border rounded p-3 text-center">
          <i class="bi bi-envelope-fill fs-3 text-primary mb-2" aria-hidden="true"></i>
          <div class="fw-semibold small">E-mail + hasło</div>
          <div class="small text-muted">Klasyczne logowanie przez adres e-mail i hasło ustawione przez administratora.</div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="border rounded p-3 text-center">
          <i class="bi bi-microsoft fs-3 text-info mb-2" aria-hidden="true"></i>
          <div class="fw-semibold small">Microsoft 365</div>
          <div class="small text-muted">Logowanie przez konto Microsoft/Azure AD — jeśli fundacja korzysta z M365.</div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="border rounded p-3 text-center">
          <i class="bi bi-phone-fill fs-3 text-success mb-2" aria-hidden="true"></i>
          <div class="fw-semibold small">Kod SMS</div>
          <div class="small text-muted">Jednorazowy kod wysłany na numer telefonu z umowy — bez hasła.</div>
        </div>
      </div>
    </div>
    <div class="tz-note mt-3">
      <i class="bi bi-lock" aria-hidden="true"></i>
      W przypadku problemów z logowaniem skontaktuj się z koordynatorem fundacji.
    </div>
  </div>
</div>

</div><!-- /col-lg-9 -->
</div><!-- /row -->

<?php endif; /* $_is_volunteer_only */ ?>

</div><!-- /pv-wrap -->

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
