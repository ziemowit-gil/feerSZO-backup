<?php
/**
 * modules/it_portal/lib/itPortalCplIkaSeed.php — dwa artykuły w usłudze „Mój login”: „CPL, czyli Centralny Portal Logowania”
 * (slug 'cpl') i „Kod IKA, czyli Indywidualny Kod Autoryzacyjny” (slug 'ika'), polecenie 2026-11-14: „artykuł o CPL i IKA w SZO”.
 * Źródła: tozsamosc/cpl.php (pola: nazwa użytkownika, hasło, logowanie domenowe tylko dla administratorów, logowanie jednokrotne Logto,
 * Profil / Rola = adres docelowy, nie uprawnienia; profile z wymogiem VPN: TI panel dydaktyka, CRM; „Zapamiętaj profil”),
 * auth_screen_cpl_band() w includes/auth_screen.php i ramka „Logujesz się przez CPL… pełne okno CPL” w auth/login.php;
 * contracts/ika_gate.php (brama IKA: kod 6 cyfr, 3 błędne próby = blokada 15 min, kod e-mail ważny 15 min, 3 cyfry PESEL z kartoteki
 * umów, AdminCode 10 znaków do ustawienia IKA + IKAKS), ika_ok()/ika_require() w includes/functions.php (role admin, editor, crm_user
 * i doradcy Dydaktyki; weryfikacja ważna 30 min, wspólna dla SZO, CRM i Dydaktyki; logowanie kluczem bezpieczeństwa zwalnia
 * administratorów i redaktorów), tozsamosc/index.php#ika (zmiana kodu), panel/set_my_codes.php (setup), tozsamosc/ika_admin.php
 * (nadawanie i reset przez Koordynatorów IT), ksiegowosc/index.php (IKAKS awaryjnie zamiast klucza WebAuthn).
 * Wstęp usługi „Mój login” bez zmian. Używa migracja '2026-11_it_portal_cpl_ika'.
 */

require_once __DIR__ . '/itPortalLogowanieSzoSeed.php';

function itPortalCplIkaApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) return 'brak usługi „Mój login” — pominięto';
    $catId = (int)$cat['id'];
    $defs = [
        'cpl' => ['title' => 'CPL, czyli Centralny Portal Logowania', 'sort' => 6,
            'keywords' => 'cpl, centralny portal logowania, okno logowania, logowanie do szo, profil, rola, profil rola, wybór modułu, logowanie jednokrotne, logto, logowanie domenowe, microsoft 365, administratorzy, zapamiętaj profil, vpn, zapomniałem hasła, pełne okno cpl, panel kursanta, panel dydaktyka, crm, ezd, helpdesk',
            'blocks' => itPortalCplBlocks($app)],
        'ika' => ['title' => 'Kod IKA, czyli Indywidualny Kod Autoryzacyjny', 'sort' => 7,
            'keywords' => 'ika, kod ika, indywidualny kod autoryzacyjny, brama ika, kod autoryzacyjny, 6 cyfr, sześć cyfr, zablokowany kod, nie pamiętam kodu ika, reset kodu ika, zmiana kodu ika, admincode, ikaks, kod e-mail, pesel, dane osobowe, umowy, crm, dydaktyka, karty30, sesja ika wygasła, klucz bezpieczeństwa',
            'blocks' => itPortalIkaBlocks($app)],
    ];
    $ids = [];
    $out = [];
    foreach ($defs as $slug => $d) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
        foreach (['pierwsze-logowanie', 'logowanie-do-crm'] as $rs) {
            $r = db_one("SELECT id FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $rs]);
            if ($r && !in_array((int)$r['id'], $related, true)) $related[] = (int)$r['id'];
        }
        $ids[$slug] = itPortalArticleSave(['title' => $d['title'], 'slug' => $slug, 'category_id' => $catId, 'keywords' => $d['keywords'],
            'blocks' => json_encode($d['blocks'], JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0, 'sort_order' => $d['sort'],
            'related' => $related], $old ? (int)$old['id'] : 0);
        $out[] = "moj-login/{$slug} — {$d['title']} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
    }
    // CPL i IKA wskazują na siebie nawzajem w „Powiązanych”.
    foreach (['cpl' => 'ika', 'ika' => 'cpl'] as $a => $b) {
        $row = db_one("SELECT related FROM itp_articles WHERE id = ?", [$ids[$a]]);
        $rel = itPortalIds($row['related'] ?? '[]');
        if (!in_array($ids[$b], $rel, true)) {
            $rel[] = $ids[$b];
            db()->prepare("UPDATE itp_articles SET related = ? WHERE id = ?")->execute([json_encode(array_values($rel)), $ids[$a]]);
        }
    }
    return implode('; ', $out);
}

function itPortalCplBlocks(string $app): array {
    $cpl = $app . '/tozsamosc/cpl.php';
    $login = $app . '/auth/login.php';
    $vr = $app . '/user/verify_reset.php';
    $vpn = $app . '/vpn/';
    $hd = $app . '/helpdesk/new.php';
    $ika = $app . '/uslugi-it/uslugi/moj-login/ika';
    $first = $app . '/uslugi-it/uslugi/moj-login/pierwsze-logowanie';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>CPL to skrót od „Centralny Portal Logowania”. To jedno okno logowania do wszystkich części SZO. '
            . 'Przez CPL wchodzą pracownicy, wolontariusze, zleceniobiorcy, dydaktycy i kursanci. Każdy używa tego samego konta i tego samego hasła.</p>'
            . '<p>Rozpoznasz je po napisie „CPL Portal” i podpisie „Centralny Portal Logowania” u góry okna. Ten sam pasek widać na zwykłej <a href="' . $login . '">stronie logowania do SZO</a>. '
            . 'Pod nim jest ramka „Logujesz się przez CPL, czyli Centralny Portal Logowania”.</p>'],

        ['type' => 'text', 'html' => '<h2>Zwykłe okno i pełne okno CPL</h2>'
            . '<p>Na zwykłej stronie logowania wpisujesz adres e-mail i hasło. Po zalogowaniu trafiasz na swój ekran startowy. Tak loguje się większość osób.</p>'
            . '<p>W ramce na stronie logowania jest link „pełne okno CPL”. Możesz też wejść prosto na <a href="' . $cpl . '">pełne okno Centralnego Portalu Logowania</a>. '
            . 'W pełnym oknie wybierasz dodatkowo, do którego modułu chcesz trafić od razu po zalogowaniu.</p>'],

        ['type' => 'wizard', 'title' => 'Logowanie w pełnym oknie CPL', 'intro' => '', 'steps' => [
            $step('Wpisz nazwę użytkownika i hasło', '<p>W polu „Nazwa użytkownika” wpisz adres e-mail, którym logujesz się do SZO. W polu „Hasło” wpisz swoje hasło do SZO.</p>'),
            $step('Wybierz profil', '<p>Z listy „Profil / Rola” wybierz miejsce, do którego chcesz trafić. Na przykład „SZO, panel startowy”, „TI, panel kursanta”, „TI, panel dydaktyka”, „EZD”, „Helpdesk IT” albo „Panel współpracownika”.</p>'
                . '<p>Profil wskazuje tylko adres docelowy. Nie daje nowych uprawnień. Jeśli nie masz dostępu do wybranego modułu, system go nie otworzy.</p>'),
            $step('Zaloguj się', '<p>Kliknij „Zaloguj”. Jeśli masz włączoną weryfikację dwuskładnikową, system poprosi Cię jeszcze o drugi składnik.</p>'),
            $step('Zapamiętaj wybór, jeśli chcesz', '<p>Zaznacz na dole „Zapamiętaj profil i sposób logowania”. Przeglądarka zapamięta Twój profil na tym komputerze. Nie zaznaczaj tego na komputerze współdzielonym.</p>'),
        ]],

        ['type' => 'text', 'html' => '<h2>Inne sposoby logowania w CPL</h2><ul>'
            . '<li>„Zaloguj przez logowanie jednokrotne” używa jednego konta Fundacji, tego samego co do chmury plików. Ono też zabierze Cię do wybranego profilu.</li>'
            . '<li>„Logowanie domenowe” to logowanie kontem Microsoft 365. Od 13 listopada 2026 działa tylko dla administratorów. Pozostałe osoby logują się hasłem albo logowaniem jednokrotnym.</li>'
            . '<li>Link „Zapomniałem hasła” prowadzi do <a href="' . $vr . '">formularza odzyskiwania dostępu</a>.</li></ul>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Niektóre profile, na przykład panel dydaktyka i CRM, działają tylko po połączeniu z VPN Fundacji. CPL pokazuje wtedy żółtą ramkę „Niektóre usługi są dostępne wyłącznie z VPN”. '
            . 'Jeśli moduł się nie otwiera, połącz się z VPN i spróbuj ponownie. Więcej przeczytasz na <a href="' . $vpn . '">stronie o VPN Fundacji</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>CPL a kod IKA</h2>'
            . '<p>CPL sprawdza, kim jesteś, przy wejściu do SZO. Niektóre moduły z danymi osobowymi proszą potem dodatkowo o kod IKA. '
            . 'Opisuje to artykuł <a href="' . $ika . '">Kod IKA, czyli Indywidualny Kod Autoryzacyjny</a>. Jeśli logujesz się pierwszy raz, zacznij od instrukcji <a href="' . $first . '">Pierwsze logowanie krok po kroku</a>.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Hasło wpisuj tylko w oknie CPL na stronie SZO Fundacji. Nikomu go nie podawaj. Fundacja nigdy nie prosi o hasło e-mailem ani telefonicznie. '
            . 'Nie możesz się zalogować? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
    ];
}

function itPortalIkaBlocks(string $app): array {
    $tz = $app . '/tozsamosc/index.php#ika';
    $setup = $app . '/panel/set_my_codes.php';
    $hd = $app . '/helpdesk/new.php';
    $cpl = $app . '/uslugi-it/uslugi/moj-login/cpl';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>IKA to skrót od „Indywidualny Kod Autoryzacyjny”. To Twój osobisty kod z 6 cyfr. Potwierdza, że przy komputerze siedzisz Ty, zanim zobaczysz chronione dane osobowe.</p>'
            . '<p>Kod IKA nie zastępuje hasła. Najpierw logujesz się przez <a href="' . $cpl . '">Centralny Portal Logowania</a>, a kod IKA wpisujesz dopiero przy wejściu do wybranych modułów.</p>'],

        ['type' => 'text', 'html' => '<h2>Kto używa kodu IKA</h2>'
            . '<p>Kod IKA dotyczy osób, które pracują na danych osobowych innych ludzi. To administratorzy, redaktorzy, osoby pracujące w CRM i doradcy w Dydaktyce. '
            . 'Większość wolontariuszy, zleceniobiorców i kursantów nie ma kodu IKA i nigdy go nie zobaczy.</p>'
            . '<p>System poprosi o kod na przykład przy otwieraniu umów, w CRM, w Dydaktyce (Karty30), przy dokumentach PFRON, przy wydruku sprawy w EZD i w panelu administratora.</p>'],

        ['type' => 'wizard', 'title' => 'Jak wpisać kod IKA', 'intro' => '<p>Gdy wejdziesz do chronionego miejsca, SZO pokaże ekran „Kod IKA”.</p>', 'steps' => [
            $step('Wpisz 6 cyfr', '<p>Wpisz swój kod IKA w pole „Kod IKA”. Przycisk „Pokaż kod” odsłania wpisane cyfry.</p>'),
            $step('Zatwierdź', '<p>Kliknij „Zweryfikuj i wejdź”. System wróci na stronę, którą chciałeś otworzyć.</p>'),
            $step('Pracuj przez 30 minut', '<p>Po poprawnym kodzie przez 30 minut nie musisz go wpisywać ponownie. Dotyczy to całego SZO, także CRM i Dydaktyki. '
                . 'Po tym czasie albo po wylogowaniu system zapyta o kod jeszcze raz.</p>'),
        ]],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Po trzech błędnych próbach kod IKA blokuje się na 15 minut. Ekran pokaże godzinę odblokowania. Poczekaj albo skorzystaj z jednej z dróg opisanych niżej.</p>'],

        ['type' => 'text', 'html' => '<h2>Nie pamiętasz kodu IKA</h2>'
            . '<p>Pod polem kodu jest sekcja „nie pamiętasz kodu?”. Możesz tam wybrać jedną z dróg:</p><ul>'
            . '<li>„Wyślij jednorazowy kod e-mail”. Kod przyjdzie na adres e-mail Twojego konta i jest ważny 15 minut.</li>'
            . '<li>„Zweryfikuj cyframi PESEL”. Podajesz 3 cyfry PESEL ze wskazanych pozycji. Ta droga działa, gdy Twoja umowa w SZO ma PESEL.</li>'
            . '<li>„Mam AdminCode”. AdminCode to jednorazowy kod z 10 znaków od Koordynatora IT. Po jego wpisaniu ustawiasz nowy kod IKA.</li></ul>'],

        ['type' => 'wizard', 'title' => 'Pierwsze ustawienie kodu IKA', 'intro' => '<p>Jeśli Twoje konto wymaga kodu IKA, a Ty jeszcze go nie masz, poproś Koordynatora IT o AdminCode.</p>', 'steps' => [
            $step('Otwórz ekran ustawiania kodów', '<p>Na ekranie kodu IKA kliknij „Mam AdminCode”. Możesz też otworzyć <a href="' . $setup . '">stronę ustawiania własnych kodów</a>.</p>'),
            $step('Wpisz AdminCode', '<p>Wpisz AdminCode dokładnie tak, jak go dostałeś. Ma 10 znaków: cyfry i litery od A do F.</p>'),
            $step('Wymyśl kod IKA', '<p>W polu „Nowy kod IKA” wpisz 6 cyfr, które zapamiętasz. Unikaj prostych ciągów, takich jak 123456, i swojej daty urodzenia.</p>'),
            $step('Ustaw kod IKAKS', '<p>Formularz prosi też o kod IKAKS, co najmniej 6 znaków, wpisany dwa razy. To kod awaryjny w module Księgowość. Używasz go tylko wtedy, gdy nie masz przy sobie klucza bezpieczeństwa. '
                . 'Kliknij „Zapisz i wejdź”.</p>'),
        ]],

        ['type' => 'text', 'html' => '<h2>Zmiana kodu IKA</h2>'
            . '<p>Kod zmienisz sam w Systemie Tożsamości. Otwórz <a href="' . $tz . '">sekcję „Kod IKA” w Systemie Tożsamości</a>. '
            . 'Wpisz obecny kod, potem dwa razy nowy i kliknij „Zmień kod IKA”. Na tej samej stronie, w kafelku „Kod IKA”, widzisz, czy kod jest aktywny, czy tymczasowo zablokowany.</p>'
            . '<p>Jeśli logujesz się kluczem bezpieczeństwa, na przykład YubiKey albo passkey, a jesteś administratorem lub redaktorem, system nie pyta Cię o kod IKA. Klucz jest silniejszym potwierdzeniem.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Dla Koordynatorów IT: kody IKA nadajesz i resetujesz w Systemie Tożsamości, w sekcji administracyjnej „Kody IKA”. Tam też wygenerujesz AdminCode dla osoby.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Kod IKA, AdminCode i IKAKS są tylko Twoje. Nie zapisuj ich przy komputerze i nikomu ich nie podawaj, także pracownikom Fundacji. Nikt z IT nie poprosi Cię o nie. '
            . 'Jeśli ktoś mógł poznać Twój kod, zmień go od razu i <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
    ];
}
