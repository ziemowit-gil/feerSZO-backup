<?php
/**
 * modules/it_portal/lib/itPortalGosposiaSeed.php — artykuł „Gosposia: sprzątanie reklam z poczty” w Portalu Usług IT
 * (/uslugi-it/uslugi/moja-poczta/gosposia). Polecenia: 2026-11-13 „przygotuj artykuł o gosposi na /pomoc-it”;
 * 2026-11-14 „dodaj artykuł do pomocy IT”, „proste opisy”, „połącz jako przydatne z innymi artykułami w pomocy”.
 *
 * Opisuje samodzielną stronę /pomocne/gosposia (modules/gosposia): dla administratora i współpracowników z umową, kafel w panelu,
 * hasło do poczty, Kosz zamiast usuwania, zaufani nadawcy, co widzi AI (tylko metadane), co zapisujemy.
 * Powiązane tematy („Zobacz też”) w obie strony: Gosposia ↔ RELATED (tylko artykuły, które istnieją; dopisuje, nie nadpisuje cudzych powiązań).
 * Uwaga: itPortalArticleSave zapisuje 'related' z wejścia, więc przy ponownym zapisie artykułu przekazujemy powiązania jawnie.
 * Uruchamiają: migracje '2026-11_it_portal_gosposia_draft' (szkic) i '2026-11_it_portal_gosposia_publish', cli/it_portal_gosposia_seed.php.
 */

/** Artykuły z „Mojej poczty” łączone z Gosposią (slug w usłudze moja-poczta). */
const ITP_GOSPOSIA_RELATED = ['spam-i-zaufani-nadawcy', 'filtry-i-przekierowanie', 'logowanie-dpoczta', 'zmiana-hasla-poczty', 'duze-zalaczniki'];

/**
 * @return array{category:string, created:list<string>, updated:list<string>, linked:list<string>}
 */
function itPortalGosposiaApply(string $app, bool $dryRun = false, bool $publish = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'created' => [], 'updated' => [], 'linked' => []];

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moja-poczta'");
    if (!$cat) { $rep['category'] = 'brak usługi „Moja poczta” (najpierw php cli/it_portal_dpoczta_seed.php)'; return $rep; }
    $catId = (int)$cat['id'];
    $rep['category'] = 'Moja poczta';

    // istniejące artykuły do „Zobacz też”
    $rel = [];
    foreach (ITP_GOSPOSIA_RELATED as $slug) {
        $r = db_one("SELECT id, title, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        if ($r) $rel[(int)$r['id']] = $r;
    }

    $gid = 0;
    foreach (itPortalGosposiaArticles($app) as $slug => [$title, $keywords, $blocks]) {
        $old = db_one("SELECT id, is_published, sort_order, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $pub = $publish || ($old && (int)$old['is_published'] === 1);   // już opublikowanego nie ukrywamy
        $rep[$old ? 'updated' : 'created'][] = "moja-poczta/{$slug} — {$title} (" . ($pub ? 'opublikowany' : 'szkic') . ')';
        if ($dryRun) { $gid = $old ? (int)$old['id'] : 0; continue; }
        $sort = $old ? (int)$old['sort_order'] : (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM itp_articles WHERE category_id = ?", [$catId])['s'] ?? 10);
        $related = array_values(array_unique(array_merge(itPortalIds($old['related'] ?? '[]'), array_keys($rel))));
        $gid = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => $pub ? 1 : 0,
            'is_pinned' => 0, 'sort_order' => $sort, 'related' => $related], $old ? (int)$old['id'] : 0);
    }

    // w drugą stronę: Gosposia w „Zobacz też” powiązanych artykułów (dopisanie do ich listy)
    foreach ($rel as $id => $r) {
        $ids = itPortalIds($r['related']);
        if ($gid && in_array($gid, $ids, true)) continue;
        $rep['linked'][] = (string)$r['title'];
        if ($dryRun || !$gid) continue;
        $ids[] = $gid;
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode(array_values(array_unique($ids))), $id]);
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
    $nh = $app . '/uslugi-it/uslugi/moja-poczta/zmiana-hasla-poczty';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'gosposia' => ['Gosposia: sprzątanie reklam z poczty',
            'gosposia, sprzątanie poczty, porządek w poczcie, reklamy, spam, newsletter, newslettery, oferty, promocje, marketing, wypisz się, kosz, pełna skrzynka, miejsce w poczcie, dpoczta, ai, sztuczna inteligencja, zaufani nadawcy, biała lista', [
            ['type' => 'text', 'html' => '<p>Gosposia sprząta z poczty reklamy, newslettery i oferty handlowe. '
                . 'Pokazuje, od kogo są, a Ty wybierasz, co wyrzucić. Wiadomości trafiają do Kosza, więc możesz je odzyskać.</p>'
                . '<p>Otwórz ją tutaj: <a href="' . $g . '">Gosposia</a>. Znajdziesz ją też w panelu współpracownika, na kafelku „Gosposia”. '
                . 'Mogą z niej korzystać współpracownicy z umową i administrator.</p>'],

            ['type' => 'wizard', 'title' => 'Jak posprzątać pocztę', 'intro' => '<p>Zajmie to kilka minut. Przygotuj hasło do poczty.</p>', 'steps' => [
                $step('Połącz pocztę', '<p>Otwórz <a href="' . $g . '">Gosposię</a>. Twój adres jest już wpisany. Wpisz hasło do poczty i kliknij „Połącz”.</p>'
                    . '<p>To hasło, którym logujesz się do poczty, a nie hasło do SZO. Zaznacz „Zapamiętaj”, jeśli nie chcesz go wpisywać następnym razem.</p>'),
                $step('Sprawdź pocztę', '<p>Kliknij „Sprawdź pocztę” i poczekaj chwilę.</p>'),
                $step('Przejrzyj listę', '<p>Zobaczysz nadawców reklam i newsletterów. Przy każdym jest krótki opis i liczba wiadomości.</p>'
                    . '<p>Kliknij „Pokaż wiadomości”, żeby zobaczyć tematy. Odznacz to, co chcesz zostawić.</p>'),
                $step('Zostaw zaufanych', '<p>Jeśli ktoś z listy pisze do Ciebie ważne rzeczy, kliknij „Zostaw tego nadawcę”. '
                    . 'Gosposia nie będzie już ruszać jego poczty.</p>'),
                $step('Przenieś do Kosza', '<p>Kliknij czerwony przycisk na dole. Potwierdź w okienku. Gotowe.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Jak odzyskać wiadomość</h2>'
                . '<p>Otwórz Kosz w poczcie i przenieś wiadomość z powrotem do Odebranych. Gosposia niczego nie kasuje na stałe.</p>'
                . '<p>Pamiętaj, że Kosz może się sam opróżniać po pewnym czasie.</p>'],

            ['type' => 'text', 'html' => '<h2>Twoje dane</h2>'
                . '<p>Gosposia ocenia nadawców przy pomocy sztucznej inteligencji (Claude). Wysyła tylko nadawcę, temat i datę. '
                . 'Nie wysyła treści wiadomości ani załączników.</p>'
                . '<p>Poczta od zaufanych nadawców nie trafia do oceny wcale. Zaufani są zawsze urzędy, Fundacja, dostawca poczty i banki, '
                . 'a także wszyscy, których sam dodasz.</p>'
                . '<p>Zapisujemy tylko, kto i kiedy coś przeniósł, ile wiadomości i od jakich adresów. Tematów i treści nie zapisujemy.</p>'],

            ['type' => 'text', 'html' => '<h2>Coś nie działa</h2>'
                . '<p>Gosposia nie łączy się z pocztą? Sprawdź hasło, logując się do poczty w przeglądarce. '
                . 'Nie pamiętasz hasła? Zobacz <a href="' . $nh . '">Jak zmienić hasło do poczty</a>. '
                . 'Gdy dalej nie działa, napisz do <a href="' . $hd . '">Helpdesku IT</a>.</p>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Gosposia może się pomylić. Zanim potwierdzisz, rzuć okiem na listę, '
                . 'zwłaszcza na pozycje oznaczone „niepewne, sprawdź”.</p>'],
        ]],
    ];
}
