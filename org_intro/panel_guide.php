<?php
/**
 * org_intro/panel_guide.php — Przewodnik po panelu wolontariusza/pracownika.
 *
 * Strona dynamicznie renderowana — pokazuje tylko sekcje
 * odpowiadające włączonym modułom i roli użytkownika.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

$PAGE_TITLE = 'Przewodnik po panelu';
$user       = current_user();
$base       = APP_URL;

// Kontekst użytkownika
$is_vol    = is_viewer();
$has_m365  = (bool)(db_one("SELECT m365_konto FROM umowy_wolontariat WHERE email=? AND m365_konto=1 LIMIT 1", [$user['email']??''])['m365_konto'] ?? 0);

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.pg-hero { background:linear-gradient(135deg,#1E3A5F 0%,#2563EB 100%); border-radius:16px; padding:2rem; color:#fff; margin-bottom:2rem; position:relative; overflow:hidden }
.pg-hero::after { content:''; position:absolute; width:260px; height:260px; border-radius:50%; background:rgba(255,255,255,.05); top:-60px; right:-60px }
.pg-hero-icon { font-size:3rem; margin-bottom:.5rem }
.pg-hero-title { font-size:1.6rem; font-weight:800; margin-bottom:.3rem }
.pg-hero-sub { font-size:.95rem; opacity:.85; max-width:520px }

/* Sekcje */
.pg-section { margin-bottom:2.5rem }
.pg-section-head {
  display:flex; align-items:center; gap:.75rem;
  padding:1rem 1.25rem; border-radius:12px 12px 0 0;
  font-size:1rem; font-weight:700; color:#fff;
}
.pg-section-body { background:#fff; border:1px solid #E5E7EB; border-top:none; border-radius:0 0 12px 12px; overflow:hidden }

/* Kroki */
.pg-steps { list-style:none; padding:0; margin:0 }
.pg-step {
  display:flex; align-items:flex-start; gap:1rem;
  padding:1rem 1.25rem; border-bottom:1px solid #F3F4F6;
}
.pg-step:last-child { border-bottom:none }
.pg-step-num {
  width:32px; height:32px; border-radius:50%;
  background:var(--step-color,#2563EB); color:#fff;
  font-size:.82rem; font-weight:800; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
}
.pg-step-title { font-weight:700; font-size:.92rem; color:#111827; margin-bottom:.2rem }
.pg-step-desc  { font-size:.84rem; color:#4B5563; line-height:1.55 }
.pg-step-action { margin-top:.5rem }

/* Feature cards */
.pg-features { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:.75rem; padding:1.1rem }
.pg-feat {
  background:#F9FAFB; border:1px solid #E5E7EB; border-radius:10px;
  padding:.85rem 1rem; text-decoration:none; color:inherit;
  transition:box-shadow .15s, border-color .15s;
  display:block;
}
.pg-feat:hover { box-shadow:0 4px 12px rgba(0,0,0,.1); border-color:#9CA3AF; color:inherit }
.pg-feat-icon { font-size:1.5rem; margin-bottom:.4rem; display:block }
.pg-feat-title { font-weight:700; font-size:.88rem; color:#111827 }
.pg-feat-desc  { font-size:.76rem; color:#6B7280; margin-top:.15rem }

/* FAQ */
.pg-faq { padding:0 }
.pg-faq-item { border-bottom:1px solid #F3F4F6; padding:1rem 1.25rem }
.pg-faq-item:last-child { border-bottom:none }
.pg-faq-q { font-weight:700; font-size:.9rem; color:#111827; margin-bottom:.35rem; display:flex; align-items:flex-start; gap:.5rem }
.pg-faq-q::before { content:'Q'; min-width:22px; height:22px; border-radius:50%; background:#EFF6FF; color:#2563EB; font-size:.72rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:.05rem }
.pg-faq-a { font-size:.85rem; color:#4B5563; line-height:1.6; padding-left:1.7rem }

/* ToC */
.pg-toc { background:#F8FAFC; border:1px solid #E5E7EB; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.5rem }
.pg-toc-title { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#9CA3AF; margin-bottom:.6rem }
.pg-toc a { display:flex; align-items:center; gap:.5rem; padding:.25rem 0; text-decoration:none; font-size:.84rem; color:#374151; transition:color .1s }
.pg-toc a:hover { color:#2563EB }
.pg-toc a i { font-size:.85rem; width:18px; text-align:center; flex-shrink:0 }
</style>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="index.php">Wprowadzenie do organizacji</a></li>
    <li class="breadcrumb-item active" aria-current="page">Przewodnik po panelu</li>
  </ol>
</nav>

<!-- Hero -->
<div class="pg-hero" role="banner">
  <div style="position:relative;z-index:1">
    <div class="pg-hero-icon" aria-hidden="true">📖</div>
    <div class="pg-hero-title">Przewodnik po Twoim panelu</div>
    <div class="pg-hero-sub">
      Witaj w <?= h(ORG_NAME) ?>! Ten przewodnik pokaże Ci jak korzystać
      z panelu — od sprawdzenia umowy po składanie wniosków.
      Zajmie to około <strong>3 minuty</strong>.
    </div>
  </div>
</div>

<div class="row g-4">
<div class="col-lg-3 order-lg-2">

  <!-- Spis treści -->
  <div class="pg-toc sticky-top" style="top:1rem">
    <div class="pg-toc-title">Spis treści</div>
    <a href="#s-panel"><i class="bi bi-person-circle"></i>Mój panel</a>
    <a href="#s-umowa"><i class="bi bi-file-earmark-text"></i>Twoja umowa</a>
    <?php if (module_enabled('messages_enabled')): ?>
    <a href="#s-wiad"><i class="bi bi-chat-left-text"></i>Wiadomości</a>
    <?php endif; ?>
    <a href="#s-wnioski"><i class="bi bi-send"></i>Wnioski i pisma</a>
    <?php if (module_enabled('certificates_enabled')): ?>
    <a href="#s-zas"><i class="bi bi-award"></i>Zaświadczenia</a>
    <?php endif; ?>
    <?php if (module_enabled('timesheets_enabled')): ?>
    <a href="#s-ew"><i class="bi bi-clock-history"></i>Ewidencja godzin</a>
    <?php endif; ?>
    <a href="#s-konto"><i class="bi bi-gear"></i>Konto i bezpieczeństwo</a>
    <?php if ($has_m365): ?>
    <a href="#s-m365"><i class="bi bi-microsoft"></i>Microsoft 365</a>
    <?php endif; ?>
    <a href="#s-faq"><i class="bi bi-question-circle"></i>FAQ</a>
  </div>

</div>
<div class="col-lg-9 order-lg-1">

<!-- ═══ 1. MÓJ PANEL ═══════════════════════════════════════════════════════ -->
<section class="pg-section" id="s-panel" aria-labelledby="h-panel">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#1E3A5F,#2563EB)">
    <i class="bi bi-person-circle" aria-hidden="true"></i>
    <span id="h-panel">1. Mój panel — strona główna</span>
  </div>
  <div class="pg-section-body">
    <div class="pg-features">
      <a href="<?= $base ?>/panel/index.php" class="pg-feat">
        <span class="pg-feat-icon" aria-hidden="true">📋</span>
        <div class="pg-feat-title">Karta umowy</div>
        <div class="pg-feat-desc">Podgląd numeru, statusu i dat Twojej umowy</div>
      </a>
      <div class="pg-feat">
        <span class="pg-feat-icon" aria-hidden="true">📊</span>
        <div class="pg-feat-title">Postęp umowy</div>
        <div class="pg-feat-desc">Pasek pokazuje ile czasu zostało do zakończenia</div>
      </div>
      <div class="pg-feat">
        <span class="pg-feat-icon" aria-hidden="true">🔔</span>
        <div class="pg-feat-title">Kafelki akcji</div>
        <div class="pg-feat-desc">Szybki dostęp do wiadomości, wniosków, zaświadczeń</div>
      </div>
      <?php if (module_enabled('certificates_enabled')): ?>
      <a href="<?= $base ?>/panel/certificates.php" class="pg-feat">
        <span class="pg-feat-icon" aria-hidden="true">🏆</span>
        <div class="pg-feat-title">Zaświadczenia</div>
        <div class="pg-feat-desc">Złóż wniosek o zaświadczenie wolontariackie</div>
      </a>
      <?php endif; ?>
    </div>
    <div style="padding:.75rem 1.25rem;background:#F8FAFC;border-top:1px solid #F3F4F6;font-size:.82rem;color:#6B7280">
      <i class="bi bi-lightbulb text-warning me-1" aria-hidden="true"></i>
      <strong>Wskazówka:</strong> Jeśli masz więcej niż jedną umowę, użyj przycisku „Zmień umowę" w prawym górnym rogu panelu.
    </div>
  </div>
</section>

<!-- ═══ 2. UMOWA ══════════════════════════════════════════════════════════ -->
<section class="pg-section" id="s-umowa" aria-labelledby="h-umowa">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#14532D,#16A34A)">
    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
    <span id="h-umowa">2. Twoja umowa</span>
  </div>
  <div class="pg-section-body">
    <ul class="pg-steps" style="--step-color:#16A34A">
      <li class="pg-step">
        <div class="pg-step-num">1</div>
        <div>
          <div class="pg-step-title">Sprawdź dane umowy</div>
          <div class="pg-step-desc">
            Na stronie głównej panelu widzisz kartę umowy z numerem, datami zawarcia i zakończenia,
            statusem oraz Twoimi danymi. Możesz tu też sprawdzić ubezpieczenia NNW/OC i szkolenie BHP.
          </div>
          <div class="pg-step-action">
            <a href="<?= $base ?>/panel/index.php" class="btn btn-sm btn-outline-success py-0">
              <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Przejdź do panelu
            </a>
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">2</div>
        <div>
          <div class="pg-step-title">Pełne dane umowy</div>
          <div class="pg-step-desc">
            Kliknij przycisk „Pełne dane" na karcie umowy, aby zobaczyć szczegóły:
            przedmiot porozumienia, miejsce wolontariatu, opiekuna i dokumenty.
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">3</div>
        <div>
          <div class="pg-step-title">Status umowy</div>
          <div class="pg-step-desc">
            Umowa może mieć statusy: <strong>Projekt</strong> (w trakcie akceptacji),
            <strong>Podpisana</strong> (aktywna), <strong>W realizacji</strong>,
            <strong>Zakończona</strong>. Skontaktuj się z opiekunem jeśli status jest nieaktualny.
          </div>
        </div>
      </li>
    </ul>
  </div>
</section>

<!-- ═══ 3. WIADOMOŚCI ════════════════════════════════════════════════════ -->
<?php if (module_enabled('messages_enabled')): ?>
<section class="pg-section" id="s-wiad" aria-labelledby="h-wiad">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#0369A1,#0EA5E9)">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
    <span id="h-wiad">3. Wiadomości</span>
  </div>
  <div class="pg-section-body">
    <ul class="pg-steps" style="--step-color:#0EA5E9">
      <li class="pg-step">
        <div class="pg-step-num">1</div>
        <div>
          <div class="pg-step-title">Jak wysłać wiadomość do organizacji</div>
          <div class="pg-step-desc">
            Przejdź do zakładki <strong>Wiadomości</strong> w menu bocznym.
            Kliknij „Nowa wiadomość", wpisz treść i wyślij.
            Opiekun Twojej umowy odpowie w ciągu kilku dni roboczych.
          </div>
          <div class="pg-step-action">
            <a href="<?= $base ?>/panel/messages.php" class="btn btn-sm btn-outline-primary py-0">
              <i class="bi bi-chat me-1" aria-hidden="true"></i>Wiadomości
            </a>
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">2</div>
        <div>
          <div class="pg-step-title">Powiadomienia o odpowiedzi</div>
          <div class="pg-step-desc">
            Gdy opiekun odpowie, zobaczysz czerwony znacznik (liczbę nieprzeczytanych)
            na kafelku Wiadomości w panelu. Odwiedź stronę by przeczytać odpowiedź.
          </div>
        </div>
      </li>
    </ul>
  </div>
</section>
<?php endif; ?>

<!-- ═══ 4. WNIOSKI I PISMA ════════════════════════════════════════════════ -->
<section class="pg-section" id="s-wnioski" aria-labelledby="h-wnioski">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#6D28D9,#8B5CF6)">
    <i class="bi bi-send" aria-hidden="true"></i>
    <span id="h-wnioski">4. Wnioski i pisma</span>
  </div>
  <div class="pg-section-body">
    <ul class="pg-steps" style="--step-color:#8B5CF6">
      <li class="pg-step">
        <div class="pg-step-num">1</div>
        <div>
          <div class="pg-step-title">Co można złożyć?</div>
          <div class="pg-step-desc">
            Przez panel możesz składać <strong>wnioski</strong> do organizacji:
            prośbę o zaświadczenie, zmianę danych, urlop, rezygnację
            lub dowolne pismo do organizacji.
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">2</div>
        <div>
          <div class="pg-step-title">Jak złożyć wniosek</div>
          <div class="pg-step-desc">
            Kliknij <strong>Wyślij wniosek</strong> w menu. Wybierz typ wniosku z listy,
            wpisz treść i dołącz pliki jeśli wymagane. Kliknij „Wyślij".
          </div>
          <div class="pg-step-action">
            <a href="<?= $base ?>/panel/apply.php" class="btn btn-sm btn-outline-secondary py-0">
              <i class="bi bi-send me-1" aria-hidden="true"></i>Złóż wniosek
            </a>
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">3</div>
        <div>
          <div class="pg-step-title">Śledzenie statusu wniosku</div>
          <div class="pg-step-desc">
            Na liście wniosków zobaczysz status: <strong>Nowy</strong> (oczekuje),
            <strong>W trakcie</strong> (rozpatrywany), <strong>Rozpatrzony</strong>
            (odpowiedź udzielona), <strong>Odrzucony</strong>.
            Gdy wniosek zostanie rozpatrzony, zobaczysz odpowiedź organizacji.
          </div>
        </div>
      </li>
    </ul>
  </div>
</section>

<!-- ═══ 5. ZAŚWIADCZENIA ══════════════════════════════════════════════════ -->
<?php if (module_enabled('certificates_enabled')): ?>
<section class="pg-section" id="s-zas" aria-labelledby="h-zas">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#92400E,#D97706)">
    <i class="bi bi-award" aria-hidden="true"></i>
    <span id="h-zas">5. Zaświadczenia</span>
  </div>
  <div class="pg-section-body">
    <ul class="pg-steps" style="--step-color:#D97706">
      <li class="pg-step">
        <div class="pg-step-num">1</div>
        <div>
          <div class="pg-step-title">Zaświadczenie o wolontariacie</div>
          <div class="pg-step-desc">
            Możesz poprosić o oficjalne zaświadczenie potwierdzające Twoją pracę wolontariacką.
            Przydaje się do CV, stypendium, szkoły lub urzędu.
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">2</div>
        <div>
          <div class="pg-step-title">Jak złożyć wniosek o zaświadczenie</div>
          <div class="pg-step-desc">
            Przejdź do <strong>Zaświadczenia</strong> → kliknij „Złóż wniosek" →
            podaj cel zaświadczenia (np. uczelnia, pracodawca) → wyślij.
            Zaświadczenie jest wydawane w ciągu 7 dni roboczych.
          </div>
          <div class="pg-step-action">
            <a href="<?= $base ?>/panel/certificates.php" class="btn btn-sm btn-outline-warning py-0">
              <i class="bi bi-award me-1" aria-hidden="true"></i>Moje zaświadczenia
            </a>
          </div>
        </div>
      </li>
    </ul>
  </div>
</section>
<?php endif; ?>

<!-- ═══ 6. EWIDENCJA GODZIN ═══════════════════════════════════════════════ -->
<?php if (module_enabled('timesheets_enabled')): ?>
<section class="pg-section" id="s-ew" aria-labelledby="h-ew">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#0F766E,#14B8A6)">
    <i class="bi bi-clock-history" aria-hidden="true"></i>
    <span id="h-ew">6. Ewidencja godzin</span>
  </div>
  <div class="pg-section-body">
    <ul class="pg-steps" style="--step-color:#14B8A6">
      <li class="pg-step">
        <div class="pg-step-num">1</div>
        <div>
          <div class="pg-step-title">Zgłoś przepracowane godziny</div>
          <div class="pg-step-desc">
            W sekcji <strong>Ewidencja godzin</strong> możesz zgłaszać daty i liczby
            godzin przepracowanych jako wolontariusz. Pomaga to organizacji w raportowaniu.
          </div>
          <div class="pg-step-action">
            <a href="<?= $base ?>/panel/timesheets.php" class="btn btn-sm btn-outline-info py-0">
              <i class="bi bi-clock me-1" aria-hidden="true"></i>Ewidencja
            </a>
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">2</div>
        <div>
          <div class="pg-step-title">Kiedy zgłaszać godziny?</div>
          <div class="pg-step-desc">
            Zgłaszaj godziny na bieżąco — po każdym dniu wolontariatu lub raz w tygodniu.
            Opiekun zatwierdza wpisy. Zatwierdzone godziny są wliczane do łącznego czasu Twojej umowy.
          </div>
        </div>
      </li>
    </ul>
  </div>
</section>
<?php endif; ?>

<!-- ═══ 7. KONTO I BEZPIECZEŃSTWO ════════════════════════════════════════ -->
<section class="pg-section" id="s-konto" aria-labelledby="h-konto">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#374151,#6B7280)">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    <span id="h-konto">7. Konto i bezpieczeństwo</span>
  </div>
  <div class="pg-section-body">
    <div class="pg-features">
      <a href="<?= $base ?>/panel/password.php" class="pg-feat">
        <span class="pg-feat-icon" aria-hidden="true">🔑</span>
        <div class="pg-feat-title">Zmiana hasła</div>
        <div class="pg-feat-desc">Regularnie zmieniaj hasło — min. 8 znaków, małe i duże litery</div>
      </a>
      <a href="<?= $base ?>/panel/sessions.php" class="pg-feat">
        <span class="pg-feat-icon" aria-hidden="true">🔒</span>
        <div class="pg-feat-title">Aktywne sesje</div>
        <div class="pg-feat-desc">Sprawdź gdzie jesteś zalogowany(-a) i wyloguj obce urządzenia</div>
      </a>
      <div class="pg-feat">
        <span class="pg-feat-icon" aria-hidden="true">⚠️</span>
        <div class="pg-feat-title">Zgłoś problem</div>
        <div class="pg-feat-desc">Skontaktuj się z opiekunem przez zakładkę Wiadomości</div>
      </div>
      <a href="<?= $base ?>/auth/logout.php" class="pg-feat" style="border-color:#FECACA">
        <span class="pg-feat-icon" aria-hidden="true">🚪</span>
        <div class="pg-feat-title" style="color:#DC2626">Wyloguj się</div>
        <div class="pg-feat-desc">Zawsze wylogowuj się na urządzeniach wspólnych</div>
      </a>
    </div>
  </div>
</section>

<!-- ═══ 8. MICROSOFT 365 ═════════════════════════════════════════════════ -->
<section class="pg-section" id="s-m365" aria-labelledby="h-m365">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#0F172A,#0078D4)">
    <i class="bi bi-microsoft" aria-hidden="true"></i>
    <span id="h-m365">8. Microsoft 365</span>
  </div>
  <div class="pg-section-body">
    <ul class="pg-steps" style="--step-color:#0078D4">
      <li class="pg-step">
        <div class="pg-step-num">1</div>
        <div>
          <div class="pg-step-title">Twoje konto Microsoft 365</div>
          <div class="pg-step-desc">
            Jako wolontariusz możesz otrzymać konto Microsoft 365 (Outlook, Teams, OneDrive, Word).
            Dane logowania (e-mail i hasło) powinny zostać wysłane na Twój adres przy podpisaniu umowy.
          </div>
          <div class="pg-step-action">
            <?php require_once dirname(__DIR__) . '/includes/webmail_clients.php'; ?>
            <a href="<?= h(webmail_chooser_url()) ?>" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-primary py-0"
               aria-label="Otwórz pocztę organizacji w nowej karcie">
              <i class="bi bi-envelope-fill me-1" aria-hidden="true"></i><?= h(webmail_chooser_label()) ?>
            </a>
            <a href="https://portal.office.com" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-secondary py-0 ms-1"
               aria-label="Otwórz portal Microsoft 365 w nowej karcie — hasło i aplikacje">
              <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Hasło / aplikacje
            </a>
            <a href="<?= $base ?>/panel/m365.php" class="btn btn-sm btn-outline-secondary py-0 ms-1">
              <i class="bi bi-microsoft me-1" aria-hidden="true"></i>Dane konta M365
            </a>
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">2</div>
        <div>
          <div class="pg-step-title">Co możesz robić z kontem M365?</div>
          <div class="pg-step-desc">
            <strong>Outlook</strong> — służbowy e-mail <br>
            <strong>Teams</strong> — komunikacja z zespołem, spotkania online <br>
            <strong>OneDrive</strong> — przechowywanie dokumentów w chmurze (1 TB) <br>
            <strong>Word / Excel / PowerPoint</strong> — pakiet biurowy online i offline
          </div>
        </div>
      </li>
      <li class="pg-step">
        <div class="pg-step-num">3</div>
        <div>
          <div class="pg-step-title">Problem z kontem M365?</div>
          <div class="pg-step-desc">
            Skontaktuj się z opiekunem przez zakładkę Wiadomości.
            Możesz też zmienić hasło przez zakładkę
            <a href="<?= $base ?>/panel/m365_password.php">Hasło Microsoft 365</a> w panelu.
          </div>
        </div>
      </li>
    </ul>
  </div>
</section>

<!-- ═══ FAQ ══════════════════════════════════════════════════════════════ -->
<section class="pg-section" id="s-faq" aria-labelledby="h-faq">
  <div class="pg-section-head" style="background:linear-gradient(90deg,#1E3A5F,#2563EB)">
    <i class="bi bi-question-circle" aria-hidden="true"></i>
    <span id="h-faq">Często zadawane pytania</span>
  </div>
  <div class="pg-section-body">
    <div class="pg-faq">
      <div class="pg-faq-item">
        <div class="pg-faq-q">Nie widzę swojej umowy w panelu. Co robić?</div>
        <div class="pg-faq-a">
          Upewnij się, że logujesz się e-mailem podanym przy umowie.
          Skontaktuj się z opiekunem — musi powiązać Twój e-mail z umową w systemie.
        </div>
      </div>
      <div class="pg-faq-item">
        <div class="pg-faq-q">Jak zmienić swoje dane (adres, telefon)?</div>
        <div class="pg-faq-a">
          Wyślij wniosek przez zakładkę <a href="<?= $base ?>/panel/apply.php">Wyślij wniosek</a>
          z opisem jakie dane wymagają aktualizacji. Administrator zmieni je w systemie.
        </div>
      </div>
      <div class="pg-faq-item">
        <div class="pg-faq-q">Kiedy dostanę odpowiedź na wniosek?</div>
        <div class="pg-faq-a">
          Wnioski są rozpatrywane w ciągu 7 dni roboczych.
          Odpowiedź pojawi się w zakładce Wnioski i pisma — zobaczysz powiadomienie.
        </div>
      </div>
      <div class="pg-faq-item">
        <div class="pg-faq-q">Jak rozwiązać umowę wolontariacką?</div>
        <div class="pg-faq-a">
          <?php if (module_enabled('terminations_enabled')): ?>
          Przejdź do zakładki <a href="<?= $base ?>/panel/terminations.php">Rozwiązanie umowy</a>
          i złóż wniosek o rozwiązanie. Podaj powód i preferowaną datę zakończenia.
          <?php else: ?>
          Skontaktuj się bezpośrednio z opiekunem przez zakładkę Wiadomości.
          <?php endif; ?>
        </div>
      </div>
      <div class="pg-faq-item">
        <div class="pg-faq-q">Nie pamiętam hasła do panelu.</div>
        <div class="pg-faq-a">
          Na stronie logowania kliknij <a href="<?= $base ?>/user/verify_reset.php">Zapomniałem hasła</a>.
          Link resetujący zostanie wysłany na Twój e-mail. Sprawdź też folder Spam.
        </div>
      </div>
      <div class="pg-faq-item">
        <div class="pg-faq-q">Co to są „zasady organizacji"?</div>
        <div class="pg-faq-a">
          To zbiór dokumentów wprowadzających: regulamin, zasady BHP, polityka RODO itp.
          Niektóre z nich są obowiązkowe do przeczytania i potwierdzenia.
          Znajdziesz je w sekcji <a href="<?= $base ?>/org_intro/index.php">Zasady organizacji</a>.
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CTA końcowe -->
<div class="text-center py-3">
  <div style="font-size:1.5rem;margin-bottom:.5rem" aria-hidden="true">🎉</div>
  <div class="fw-bold" style="font-size:1rem;color:#111827;margin-bottom:.25rem">To wszystko!</div>
  <div class="text-muted small mb-3">Jeśli masz pytania — Twój opiekun jest do dyspozycji przez zakładkę Wiadomości.</div>
  <div class="d-flex gap-2 justify-content-center flex-wrap">
    <a href="<?= $base ?>/panel/index.php" class="btn btn-primary btn-sm">
      <i class="bi bi-person-circle me-1" aria-hidden="true"></i>Przejdź do panelu
    </a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-book me-1" aria-hidden="true"></i>Wróć do zasad organizacji
    </a>
    <?php if (module_enabled('messages_enabled')): ?>
    <a href="<?= $base ?>/panel/messages.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-chat me-1" aria-hidden="true"></i>Wyślij wiadomość
    </a>
    <?php endif; ?>
  </div>
</div>

</div><!-- /col -->
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
