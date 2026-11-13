<?php
/**
 * modules/it_portal/lib/itPortalGosposiaSeed.php — artykuł „Gosposia: sprzątanie reklam ze skrzynki” w Portalu Usług IT
 * (/uslugi-it/uslugi/moja-poczta/gosposia). Polecenie 2026-11-13: „przygotuj artykul o gosposi” „na /pomoc-it”.
 *
 * Opisuje narzędzie /pomocne/gosposia według specyfikacji (IMAP imap.dpoczta.pl:993, klasyfikacja Claude Haiku tylko na metadanych,
 * biała lista nigdy nie trafia do AI, przeniesienie do Kosza bez trwałego usuwania, potwierdzenie w oknie, ślad audytowy bez treści).
 * Modułu jeszcze NIE MA, dlatego domyślnie artykuł zapisuje się jako SZKIC (is_published=0) — widać go tylko w panelu Portalu.
 * Po wdrożeniu modułu: sprawdź zgodność tekstu i opublikuj: php cli/it_portal_gosposia_seed.php --publish
 * Usługa „Moja poczta” pochodzi z itPortalDpocztaSeed.php; gdy jej brak, artykuł nie powstaje.
 * Tekst prostym językiem (bez myślników i ozdobników), jak w itPortalHaslaSeed.php.
 */

/**
 * @return array{category:string, created:list<string>, updated:list<string>}
 */
function itPortalGosposiaApply(string $app, bool $dryRun = false, bool $publish = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'created' => [], 'updated' => []];

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moja-poczta'");
    if (!$cat) { $rep['category'] = 'brak usługi „Moja poczta” (najpierw php cli/it_portal_dpoczta_seed.php)'; return $rep; }
    $catId = (int)$cat['id'];
    $rep['category'] = 'Moja poczta';

    foreach (itPortalGosposiaArticles($app) as $slug => [$title, $keywords, $blocks]) {
        $old = db_one("SELECT id, is_published, sort_order FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $rep[$old ? 'updated' : 'created'][] = "moja-poczta/{$slug} — {$title} (" . ($publish ? 'opublikowany' : ($old && $old['is_published'] ? 'zostaje opublikowany' : 'szkic')) . ')';
        if ($dryRun) continue;
        $sort = $old ? (int)$old['sort_order'] : (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM itp_articles WHERE category_id = ?", [$catId])['s'] ?? 10);
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
            // nie cofamy publikacji, którą ktoś już włączył w panelu
            'is_published' => ($publish || ($old && (int)$old['is_published'] === 1)) ? 1 : 0,
            'is_pinned' => 0, 'sort_order' => $sort], $old ? (int)$old['id'] : 0);
    }
    return $rep;
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki].
 * @return array<string, array{0:string,1:string,2:array}>
 */
function itPortalGosposiaArticles(string $app): array {
    $g  = $app . '/pomocne/gosposia';
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'gosposia' => ['Gosposia: sprzątanie reklam i newsletterów ze skrzynki',
            'gosposia, sprzątanie poczty, reklamy, spam, newsletter, newslettery, oferty handlowe, marketing, wypisz się, kosz, porządek w skrzynce, dpoczta, ai, claude, biała lista, zaufani nadawcy', [
            ['type' => 'text', 'html' => '<p>Gosposia pomaga posprzątać skrzynkę <code>imie.nazwisko@feer.org.pl</code> z newsletterów, ofert handlowych, '
                . 'reklam i maili sprzedażowych. Przegląda pocztę, układa takie wiadomości według nadawców i pokazuje Ci listę. '
                . 'Ty decydujesz, co przenieść do Kosza. Bez Twojego potwierdzenia nic nie znika.</p>'
                . '<p>Gosposię znajdziesz w SZO pod adresem <a href="' . $g . '">' . htmlspecialchars($g, ENT_QUOTES) . '</a>. Trzeba być zalogowanym.</p>'],

            ['type' => 'wizard', 'title' => 'Jak posprzątać skrzynkę', 'intro' => '<p>Pierwsze sprzątanie zajmuje kilka minut. Przygotuj hasło do poczty.</p>', 'steps' => [
                $step('Połącz skrzynkę', '<p>Otwórz <a href="' . $g . '">Gosposię</a>. Adres e-mail jest już wpisany. Podaj hasło do poczty, '
                    . 'to samo co w przeglądarce i w telefonie. To nie jest hasło logowania jednokrotnego.</p>'
                    . '<p>Hasło jest pamiętane tylko do końca sesji. Jeśli zaznaczysz „Zapamiętaj w SZO”, zapiszemy je zaszyfrowane, '
                    . 'żeby następnym razem nie trzeba było go wpisywać.</p>'),
                $step('Poczekaj na przegląd', '<p>Gosposia czyta nagłówki wiadomości i ocenia nadawców. Przy dużej skrzynce trwa to chwilę.</p>'),
                $step('Przejrzyj listę nadawców', '<p>Przy każdym nadawcy widzisz rodzaj poczty (na przykład newsletter albo oferta handlowa), '
                    . 'krótkie uzasadnienie, liczbę wiadomości i ich rozmiar. Rozwiń nadawcę, żeby zobaczyć tematy i daty. '
                    . 'Odznacz wiadomości, które chcesz zostawić.</p>'),
                $step('Zostaw zaufanych nadawców', '<p>Jeśli na liście jest ktoś, od kogo chcesz dostawać pocztę, kliknij „Dodaj do białej listy”. '
                    . 'Nadawca od razu znika z listy i Gosposia nie pokaże go już nigdy więcej.</p>'),
                $step('Przenieś do Kosza', '<p>Kliknij „Przenieś zaznaczone do Kosza”. W okienku zobaczysz, ile wiadomości i od ilu nadawców przeniesiesz. '
                    . 'Potwierdź, a wiadomości trafią do folderu Kosz na Twojej skrzynce.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Jak odzyskać wiadomość</h2>'
                . '<p>Gosposia niczego nie usuwa na stałe. Wiadomości lądują w Koszu, tak jakbyś usunął je sam w poczcie. '
                . 'Otwórz Kosz w poczcie w przeglądarce albo w telefonie i przenieś wiadomość z powrotem do Odebranych. '
                . 'Pamiętaj, że serwer poczty może po jakimś czasie sam opróżniać Kosz.</p>'],

            ['type' => 'text', 'html' => '<h2>Co widzi sztuczna inteligencja</h2>'
                . '<p>Do oceny nadawców Gosposia używa modelu Claude firmy Anthropic. Wysyła tylko to, co potrzebne: nadawcę, temat, datę, '
                . 'informację, czy mail ma link „wypisz się”, i najwyżej pierwsze 200 znaków tekstu. Nie wysyła załączników ani całych wiadomości.</p>'
                . '<p>Poczta od zaufanych nadawców nie trafia do oceny wcale. Należą do nich urzędy (adresy <code>.gov.pl</code>), Fundacja '
                . '(<code>feer.org.pl</code>), dostawca poczty i banki, a także każdy, kogo sam dodasz do białej listy. Takich wiadomości Gosposia nigdy nie zaproponuje do Kosza.</p>'
                . '<p>Gdy usługa AI jest niedostępna, Gosposia ocenia nadawców prostszymi regułami, na przykład po nagłówku „wypisz się”. '
                . 'Wtedy przejrzyj listę szczególnie uważnie.</p>'],

            ['type' => 'text', 'html' => '<h2>Co zapisujemy</h2>'
                . '<p>SZO zapisuje, kto i kiedy przeniósł wiadomości do Kosza, ile ich było i od jakich adresów. '
                . 'Nie zapisujemy tematów ani treści Twojej korespondencji, nie zapisujemy też hasła w dziennikach.</p>'],

            ['type' => 'text', 'html' => '<h2>Coś nie działa</h2>'
                . '<p>Jeśli Gosposia nie łączy się ze skrzynką, sprawdź hasło do poczty, logując się do poczty w przeglądarce. '
                . 'Gdy hasło działa, a Gosposia dalej zgłasza błąd, napisz do <a href="' . $hd . '">Helpdesku IT</a>.</p>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Gosposia może się pomylić. Zanim potwierdzisz, rzuć okiem na listę, '
                . 'zwłaszcza na nadawców z niską pewnością oceny. Ważną korespondencję zostaw albo dodaj nadawcę do białej listy.</p>'],
        ]],
    ];
}
