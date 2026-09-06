<?php
/**
 * modules/srs/podrecznik.php — Podręcznik SRS dla nowych użytkowników.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/srs/logic/srs.php';

require_login();

$PAGE_TITLE = 'Podręcznik SRS';
$user       = current_user();

$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include dirname(__DIR__, 2) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__, 2) . '/includes/header.php';
}
require_once dirname(__DIR__, 2) . '/panel/includes/pv_ui.php';

pv_page_header('Podręcznik Systemu Rezerwacji Sal', [
    'icon'    => 'bi-book',
    'sub'     => 'Krótki przewodnik dla nowych użytkowników SRS',
    'back'    => ['url' => APP_URL . '/modules/srs/', 'label' => 'System Rezerwacji Sal'],
]);
?>

<div class="pv-wrap" style="max-width:820px">

<div class="tz-card mb-3">
  <div class="tz-card__hd">
    <span class="tz-card__icon" aria-hidden="true"><i class="bi bi-grid"></i></span>
    <div class="tz-card__title">1. Przeglądanie zasobów</div>
  </div>
  <div class="tz-card__bd">
    <p class="mb-2">Na stronie głównej SRS widzisz wszystkie sale i pozostałe zasoby dostępne do rezerwacji,
    pogrupowane kategoriami (Sala, Komputer, Router, Inne). Kliknij pigułkę kategorii u góry listy, żeby
    zawęzić widok do interesującej Cię grupy.</p>
    <p class="mb-0">Każda karta zasobu pokazuje lokalizację, pojemność, osobę dysponującą oraz to, czy
    rezerwacja wymaga zgody, czy jest przyznawana od razu.</p>
  </div>
</div>

<div class="tz-card mb-3">
  <div class="tz-card__hd">
    <span class="tz-card__icon" aria-hidden="true"><i class="bi bi-calendar3"></i></span>
    <div class="tz-card__title">2. Interaktywny kalendarz i filtry</div>
  </div>
  <div class="tz-card__bd">
    <p class="mb-2">Zakładka <a href="<?= APP_URL ?>/modules/srs/kalendarz.php">Kalendarz</a> pokazuje
    wszystkie zdarzenia (rezerwacje) w wybranym dniu lub tygodniu — przełączysz widok przyciskami
    „Dzień" / „Tydzień" nad kalendarzem.</p>
    <p class="mb-0">System filtrów nad kalendarzem pozwala ograniczyć wyświetlane zdarzenia do wybranej
    kategorii, konkretnego zasobu oraz interesujących Cię statusów rezerwacji (np. tylko potwierdzone,
    albo tylko oczekujące na decyzję).</p>
  </div>
</div>

<div class="tz-card mb-3">
  <div class="tz-card__hd">
    <span class="tz-card__icon" aria-hidden="true"><i class="bi bi-calendar-plus"></i></span>
    <div class="tz-card__title">3. Składanie prośby o rezerwację</div>
  </div>
  <div class="tz-card__bd">
    <p class="mb-2">Przycisk „Zarezerwuj" na karcie zasobu otwiera formularz: podajesz zakres dat, godziny
    (jeśli rezerwacja jest na konkretne godziny) oraz cel rezerwacji. Prośbę może złożyć tylko uprawniony
    użytkownik — jeśli nie widzisz przycisku rezerwacji, skontaktuj się z administratorem systemu.</p>
    <p class="mb-0">Zasoby oznaczone „Wymaga zgody" trafiają do akceptacji administratora i/lub osoby
    dysponującej danym zasobem, zanim staną się rezerwacją potwierdzoną. Zasoby „Bezpośrednio" rezerwują
    się od razu, bez dodatkowej akceptacji.</p>
  </div>
</div>

<div class="tz-card mb-0">
  <div class="tz-card__hd">
    <span class="tz-card__icon" aria-hidden="true"><i class="bi bi-list-check"></i></span>
    <div class="tz-card__title">4. Śledzenie swoich rezerwacji</div>
  </div>
  <div class="tz-card__bd">
    <p class="mb-0">Zakładka <a href="<?= APP_URL ?>/modules/srs/my.php">Moje rezerwacje</a> pokazuje status
    każdej złożonej przez Ciebie prośby — od złożenia, przez oczekiwanie na decyzję, aż po potwierdzenie
    lub odmowę. O zmianie statusu dostaniesz też powiadomienie w systemie oraz e-mailem.</p>
  </div>
</div>

</div><!-- /pv-wrap -->

<?php if ($_is_volunteer_only): ?>
<?php include dirname(__DIR__, 2) . '/panel/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
<?php endif; ?>
