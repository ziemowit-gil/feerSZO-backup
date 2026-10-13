<?php
/**
 * karty30/ti/dydaktyk/_nav.php — nagłówek panelu dydaktyka (jeden pasek).
 *
 * Sekcje panelu (Mój panel · Kurs · Komunikacja · Zasoby · Kierownik) stoją
 * w granatowym pasku obok nazwy panelu, a po prawej: przełącznik grupy,
 * dzwonek i MENU UŻYTKOWNIKA (rola/kontekst, zmiana roli, Wyloguj) zamiast
 * luźnych: nazwiska, plakietki roli i przycisku „Wyloguj”. Styl USOS
 * (ti_skin.css) zostaje — zmienia się tylko układ. Podmenu sekcji i okruszki
 * nadal rysuje _usos_bar.php; osobny rząd sekcji znika, gdy stoją w pasku.
 *
 * Działa automatycznie na wszystkich ekranach panelu: kursant/_layout_head.php
 * woła dyd_topbar_enrich() dla paska z brand = „Panel dydaktyka”, gdy funkcja
 * istnieje (ładuje ją dydaktyk/auth.php) — strony ustawiają $KP_TOPBAR jak
 * dotąd. Sekcje w pasku tylko w skórce USOS (body.ti-skin), bo ich wygląd
 * siedzi w ti_skin.css; menu użytkownika zawsze. Panel kursanta i Equi Exams
 * (inny brand) zostają bez zmian.
 *
 * Mapa zakładek → sekcja musi odpowiadać pozycjom w _usos_bar.php.
 */

/** Zakładki index.php należące do sekcji (reszta zakładek kursu → 'kurs'). */
const DYD_NAV_TABS = [
    'start'       => ['pulpit', 'pomoc', 'frekwencja_grup', 'dostepnosc', 'cykliczne', 'wydruki'],
    'komunikacja' => ['wiadomosci', 'komunikaty'],
    'zasoby'      => ['dysk', 'zoom'],
    'kierownik'   => ['grupy', 'kursy', 'wypłaty', 'praca_wlasna', 'komunikacja', 'rozliczenia'],
];

/** Strony samodzielne → sekcja (ekrany kierownika z _kierownik_bar.php i „moje”). */
const DYD_NAV_PAGES = [
    'start' => ['protokoly_moje.php', 'rekrutacja.php', 'urlopy.php', 'mojeZgloszenia.php', 'kartaKursanta.php', 'godziny.php', 'umowa.php'],
    'certyfikat' => ['moj_certyfikat.php', 'certyfikaty.php'],
    'komunikacja' => ['konsultacjeKomunikacja.php'],
    'konsultacje' => ['konsultacje.php', 'konsultacja.php', 'konsultacjaTermin.php', 'grupaUproszczona.php',
                      'rezerwacje.php', 'rezerwacja.php', 'rezerwacjeKalendarz.php', 'kolejka.php', 'choose_mode.php',
                      'konsultacjeZestawienia.php'],
    'planowanie' => ['planista.php', 'generatorCiagow.php', 'raportyPlanisty.php', 'kadraLimity.php', 'zgloszenia.php'],
    'kierownik' => ['klienci.php', 'konta.php', 'microsoft365.php', 'licencjeKursantow.php', 'billing.php', 'protokoly.php',
                    'audyt_dziennikow.php', 'polecenia.php', 'log_grup.php', 'wydruki.php', 'zetony.php',
                    'dostepnosci.php', 'sale.php', 'sale_rezerwacje.php', 'okresy.php', 'przedmioty.php',
                    'dni_wolne.php', 'wylaczenia.php', 'zespol.php', 'testy.php', 'overpayments.php', 'przypomnieniaWplat.php', 'pricing.php',
                    'szablonyEmail.php', 'szablonySms.php', 'analityka.php', 'analitykaRaport.php', 'planLinki.php', 'regulaminy.php', 'kontaTestowe.php'],
];

/** Sekcje pierwszego poziomu: klucz => [etykieta, adres]. */
function dyd_nav_sections(): array {
    $c = (int)($GLOBALS['cur_course'] ?? 0);
    // Tryb Konsultacje (choose_mode.php): menu tylko do konsultacji — karty, komunikacja, certyfikat (+ Kierownik)
    if (function_exists('dyd_mode') && dyd_mode() === 'konsultacje') {
        $s = [
            'konsultacje' => ['Konsultacje', 'rezerwacje.php'],
            'komunikacja' => ['Komunikacja', 'konsultacjeKomunikacja.php'],   // e-mail / SMS do beneficjentów konsultacji
            'certyfikat'  => ['Certyfikat',  'moj_certyfikat.php'],
        ];
        if (function_exists('dyd_is_staff') && dyd_is_staff()) $s['kierownik'] = ['Kierownik', 'index.php?tab=grupy'];
        return $s;
    }
    $s = [
        'start'       => ['Mój panel',   'index.php?tab=pulpit'],
        'kurs'        => ['Kurs',        $c ? 'index.php?course=' . $c . '&tab=lekcje' : 'index.php?tab=lekcje'],
        'komunikacja' => ['Komunikacja', 'index.php?tab=wiadomosci'],
        'zasoby'      => ['Zasoby',      'index.php?tab=dysk'],
        'certyfikat'  => ['Certyfikat',  'moj_certyfikat.php'],
    ];
    if (function_exists('dyd_is_staff') && dyd_is_staff()) $s['planowanie'] = ['Planowanie', 'planista.php'];
    if (function_exists('dyd_is_staff') && dyd_is_staff()) $s['kierownik'] = ['Kierownik', 'index.php?tab=grupy'];
    return $s;
}

/** Czy zalogowany prowadzący nie ma aktywnego certyfikatu do podpisu protokołów (wtedy czerwona odznaka w nawigacji). */
function dyd_cert_missing(): bool {
    $s = function_exists('dyd_current') ? dyd_current() : null;
    if (!$s || empty($s['user_id'])) return false;
    require_once dirname(__DIR__, 3) . '/includes/x509_login.php';
    return !x509_user_has_active_cert((int)$s['user_id']);
}

/** Sekcja bieżącego ekranu ('' = nieznana — żadna nie jest podświetlona). */
function dyd_nav_current(): string {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'index.php') {
        $tab = (string)($GLOBALS['tab'] ?? 'pulpit');
        $staff = function_exists('dyd_is_staff') && dyd_is_staff();
        foreach (DYD_NAV_TABS as $k => $tabs) {
            if ($k === 'kierownik' && !$staff) continue;       // u prowadzącego „rozliczenia” są w Kursie
            if (in_array($tab, $tabs, true)) return $k;
        }
        return 'kurs';
    }
    foreach (DYD_NAV_PAGES as $k => $pages) if (in_array($script, $pages, true)) return $k;
    return '';
}

/**
 * Dokłada do $KP_TOPBAR: 'nav' (sekcje w pasku) i 'user_menu' (rozwijane menu
 * użytkownika). Ustawia $GLOBALS['DYD_HEADER_NAV'], żeby _usos_bar.php nie
 * rysował drugi raz rzędu sekcji. Zostawia bez zmian, gdy strona już podała
 * własne 'nav'/'user_menu' albo 'no_enrich'.
 */
function dyd_topbar_enrich(array $tb, bool $with_nav = true): array {
    if (!empty($tb['no_enrich'])) return $tb;
    $s = function_exists('dyd_current') ? dyd_current() : null;
    if (!$s) return $tb;

    if ($with_nav && !isset($tb['nav'])) {
        $cur = dyd_nav_current();
        $n_msg = (int)($GLOBALS['dyd_msg_unread_total'] ?? 0) + (int)($GLOBALS['dyd_notices_unread'] ?? 0);
        ob_start(); ?>
<nav class="dyd-topnav" aria-label="Sekcje panelu">
  <?php foreach (dyd_nav_sections() as $k => [$label, $href]): ?>
  <a href="<?= h($href) ?>"<?= $cur === $k ? ' aria-current="page"' : '' ?>><?= h($label) ?><?php
    if ($k === 'komunikacja' && $n_msg > 0): ?> <span class="badge text-bg-danger"><?= $n_msg ?><span class="visually-hidden"> nieprzeczytanych</span></span><?php endif;
    if ($k === 'certyfikat' && dyd_cert_missing()): ?> <span class="badge text-bg-danger">brak</span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<?php   $tb['nav'] = (string)ob_get_clean();
        $GLOBALS['DYD_HEADER_NAV'] = true;
    }

    if (!isset($tb['user_menu'])) {
        $acting = !empty($s['acting_as_other']);
        $staff  = function_exists('dyd_is_staff') && dyd_is_staff();
        [$role, $icon] = $acting ? ['W zastępstwie', 'person-video2']
                       : ($staff ? ['Kierownik Instytucji', 'shield-fill-check'] : ['Prowadzący', 'mortarboard-fill']);
        $items = [];
        // Zmiana roli — kierownik, który jest też prowadzącym (choose_context.php
        // sam odsyła, gdy nie ma czego wybierać), albo powrót z zastępstwa.
        if ($acting) {
            $items[] = ['Wróć do swojego konta', 'choose_context.php?role=staff', 'arrow-return-left'];
        } elseif (!empty($s['is_staff']) || (function_exists('dyd_modes_available') && count(dyd_modes_available($s)) > 1)) {
            // Jeden ekran wyboru: rola (kierownik/prowadzący) i tryb pracy (Szkolenia/Konsultacje) — choose_mode.php
            $items[] = ['Zmień rolę / tryb pracy (teraz: ' . (dyd_mode() === 'konsultacje' ? 'Konsultacje' : 'Szkolenia') . ')', 'choose_mode.php', 'signpost-split'];
        }
        // „Pracujesz jako: …” widoczne wprost w pasku (dawniej akapit w treści
        // index.php); link do zmiany roli — gdy kierownik ma własne grupy albo
        // pracuje w zastępstwie (choose_context.php).
        $own = function_exists('dyd_own_courses_cached') ? dyd_own_courses_cached((int)$s['user_id'])
             : (function_exists('k30_ti_instructor_courses') ? k30_ti_instructor_courses((int)$s['user_id'], false) : []);
        $ctx = function_exists('dyd_ctx_role') ? dyd_ctx_role() : '';
        if ($acting) {
            $work_as = ['label' => 'Prowadzący — w zastępstwie: ' . (string)($tb['user'] ?? ''), 'icon' => 'person-video2',
                        'acting' => true, 'change_href' => 'choose_context.php', 'change_label' => 'Zakończ / zmień rolę'];
        } else {
            // „Pracujesz jako: rola · tryb” — link do wspólnego ekranu wyboru (choose_mode.php), gdy jest co wybierać
            $_two = function_exists('dyd_modes_available') && count(dyd_modes_available($s)) > 1;
            $_lbl = ($own && $ctx !== '') ? ($ctx === 'instructor' ? 'Prowadzący' : 'Kierownik Instytucji') : $role;
            if ($_two) $_lbl .= ' · ' . (dyd_mode() === 'konsultacje' ? 'Konsultacje' : 'Szkolenia');
            $work_as = ['label' => $_lbl, 'icon' => ($own && $ctx !== '') ? 'person-gear' : $icon]
                     + (($own && $ctx !== '') || $_two ? ['change_href' => 'choose_mode.php', 'change_label' => 'Zmień'] : []);
        }
        // Planowanie — skróty w menu użytkownika (kierownik: planista i narzędzia; prowadzący: własne zgłoszenia)
        if ($staff && !$acting) {
            $items[] = ['Planista zajęć', 'planista.php', 'calendar3-range'];
            $items[] = ['Zgłoszenia trenerów', 'zgloszenia.php', 'inbox'];
            $items[] = ['Generator ciągów zajęć', 'generatorCiagow.php', 'magic'];
            $items[] = ['Raporty planisty', 'raportyPlanisty.php', 'bar-chart-line'];
        } else {
            $items[] = ['Moje zgłoszenia (niedostępność, zmiana terminu)', 'mojeZgloszenia.php', 'envelope-exclamation'];
        }
        // Konsultacje — w menu użytkownika w każdym trybie (osoby z uprawnieniem do Konsultacji, TiWorkModes)
        if (function_exists('dyd_modes_available') && in_array('konsultacje', dyd_modes_available($s), true)) {
            $items[] = ['Rezerwacje terminów', 'rezerwacje.php', 'calendar-check'];
            $items[] = ['Karty konsultacji', 'konsultacje.php', 'clipboard2-check'];
        }
        if (function_exists('dyd_can_analytics') && dyd_can_analytics($s)) $items[] = ['Analityka TI', 'analityka.php', 'speedometer2'];
        $items[] = ['Mój certyfikat', 'moj_certyfikat.php', 'shield-check'];
        if (function_exists('ti_panel_role') && (is_admin() || ti_panel_role((int)($s['user_id'] ?? 0)) === 'kierownik')) {
            $items[] = ['Certyfikaty prowadzących', 'certyfikaty.php', 'key'];
        }
        $items[] = ['Komunikaty', 'index.php?tab=komunikaty', 'megaphone'];
        $items[] = ['Gdzie co jest', 'index.php?tab=pomoc', 'signpost-split'];
        $tb['user_menu'] = [
            'name'  => (string)($tb['user'] ?? ($s['name'] ?? '')),
            'role'  => $role,
            'icon'  => $icon,
            'note'  => $acting ? 'Pełne wejście na konto prowadzącego' : '',
            'work_as' => $work_as,
            'items' => $items,
        ];
    }
    return $tb;
}
