<?php
/**
 * modules/sso_logto/view/loginInfo.php — lewa kolumna ekranu logowania SZO (auth/login.php): „Odchodzimy od Microsoft 365”.
 *
 * Polecenie 2026-11-13: ekran logowania dwukolumnowy; opis, że powoli odchodzimy od Microsoft (awarie, zmiany warunków),
 * prośba o zalogowanie się do IAM (System Tożsamości + logowanie jednokrotne Logto) i informacja, że za 7 dni wyłączymy
 * Microsoft 365. Data: ustawienie sso_ms_off_date (domyślnie 2026-11-20, migracja 2026-11_sso_ms_off_date; /ssoadmin).
 * Fakty o awariach jak w modules/dhosting_migrator/view/_why.php (22.01, 31.08, 12–13.11.2026) — bez nazwy dostawcy poczty.
 *
 * Zwraca ['panel' => HTML kolumny, 'mini' => krótka informacja do karty logowania na wąskim ekranie] przez $LOGIN_INFO;
 * puste, gdy nie ma czego pokazać (IAM nieuruchomione i brak daty). Zmienne wejściowe: $logto_available (bool).
 */
require_once dirname(__DIR__) . '/lib/SsoConfig.php';

$LOGIN_INFO = ['panel' => '', 'mini' => ''];
$__iamReady = !empty($logto_available) || SsoConfig::logtoConfigured();
$__off      = SsoConfig::msOffDate();          // ?int (północ dnia wyłączenia)
if (!$__iamReady && $__off === null) return;

$__months = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
$__date   = $__off !== null ? date('j', $__off) . ' ' . $__months[(int)date('n', $__off) - 1] . ' ' . date('Y', $__off) . ' r.' : '';
$__days   = $__off !== null ? (int)round(($__off - strtotime(date('Y-m-d'))) / 86400) : null;
$__when   = match (true) {
    $__days === null => '',
    $__days > 1      => 'Za ' . $__days . ' dni, ' . $__date . ',',
    $__days === 1    => 'Jutro, ' . $__date . ',',
    $__days === 0    => 'Dziś, ' . $__date . ',',
    default          => '',
};
$__past   = $__days !== null && $__days < 0;
$__tz     = rtrim(APP_URL, '/') . '/tozsamosc/index.php#sso';
$__hd     = rtrim(APP_URL, '/') . '/helpdesk/index.php';

ob_start(); ?>
<aside class="lg-info" id="lg-info" aria-labelledby="lg-info-h">
  <p class="lg-info-kicker">Ważna zmiana</p>
  <h2 id="lg-info-h" class="lg-info-h">Odchodzimy od Microsoft 365</h2>
  <p class="lg-info-lead">Krok po kroku przenosimy pracę Fundacji z Microsoft 365 do narzędzi, nad którymi mamy kontrolę: nowej poczty (adres się nie zmienia), chmury plików i jednego konta IAM.</p>

  <?php if ($__off !== null): ?>
  <div class="lg-info-box lg-info-box--warn" role="note">
    <i class="bi bi-calendar-x" aria-hidden="true"></i>
    <div><?php if ($__past): ?>
      <strong>Od <?= h($__date) ?> Microsoft 365 jest wyłączony.</strong> Logujesz się przez IAM albo e-mailem i hasłem.
    <?php else: ?>
      <strong><?= h($__when) ?> wyłączymy Microsoft 365.</strong> <?= $__days === 0 ? 'Zaloguj się do IAM jeszcze dziś' : 'Do tego dnia zaloguj się do IAM' ?> i przenieś potrzebne pliki do chmury.
    <?php endif; ?></div>
  </div>
  <?php endif; ?>

  <h3 class="lg-info-h3">Dlaczego?</h3>
  <ul class="lg-info-list">
    <li><strong>Awarie.</strong> W 2026 r. poczta Microsoft 365 przestawała działać kilka razy, także na całym świecie: 22 stycznia, 31 sierpnia i 12–13 listopada (jedna z największych awarii — 74 kraje). Gdy poczta, pliki i spotkania są u jednego dostawcy, jedna awaria zatrzymuje całą Fundację.</li>
    <li><strong>Zmiany warunków.</strong> Microsoft zakończył bezpłatne licencje dla organizacji pozarządowych i podnosi ceny. Każda zmiana stawia nas przed wyborem: płacić więcej albo stracić dostęp.</li>
    <li><strong>Niezależność.</strong> Nowe narzędzia działają na otwartych standardach, więc w razie potrzeby przeniesiemy je dalej bez zmiany Twoich adresów.</li>
  </ul>

  <?php if ($__iamReady): ?>
  <div class="lg-info-box" role="note">
    <i class="bi bi-person-check" aria-hidden="true"></i>
    <div><strong>Prosimy: zaloguj się do IAM.</strong> IAM to system tożsamości Fundacji — jedno konto i jedno hasło do SZO, chmury plików i kolejnych aplikacji.
      <ol class="lg-info-steps">
        <li>Zaloguj się na tej stronie e-mailem i hasłem (nie masz hasła? „Nie pamiętasz hasła?” — ustawisz je kodem SMS).</li>
        <li>Po zalogowaniu zobaczysz ekran zakładania konta — wybierz hasło. Zajmie to minutę.</li>
        <?php if (!empty($logto_available)): ?><li>Następnym razem użyj przycisku „Zaloguj przez logowanie jednokrotne”.</li><?php endif; ?>
      </ol>
      <span class="lg-info-small">Konto założysz też w Systemie Tożsamości, w sekcji <a href="<?= h($__tz) ?>">Logowanie jednokrotne</a>. Hasło do poczty się nie zmienia.</span>
    </div>
  </div>
  <?php endif; ?>

  <p class="lg-info-help">Pytania lub problem z logowaniem? <a href="<?= h($__hd) ?>">Napisz do Helpdesku IT</a>. Nikt z Fundacji nie poprosi Cię o hasło.</p>
</aside>
<?php
$LOGIN_INFO['panel'] = (string)ob_get_clean();
$LOGIN_INFO['mini'] = '<div class="lg-alert warn lg-info-mini" role="note"><i class="bi bi-info-circle" aria-hidden="true"></i><div>'
    . '<strong>Odchodzimy od Microsoft 365.</strong> '
    . ($__off !== null && !$__past ? h($__when) . ' wyłączymy go. ' : '')
    . ($__iamReady ? 'Prosimy, zaloguj się do IAM. ' : '')
    . '<a href="#lg-info">Przeczytaj, co zrobić</a></div></div>';
