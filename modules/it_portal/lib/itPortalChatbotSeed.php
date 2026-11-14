<?php
/**
 * modules/it_portal/lib/itPortalChatbotSeed.php — artykuł „Czat Pomocy IT: asystent AI” w Portalu Usług IT
 * (/uslugi-it/uslugi/helpdesk/czat-pomocy-it). Polecenie 2026-11-14: „przebuduj widok chatbota, dodaj mu pomoc IT i artykuły,
 * kafelek w panelu współpracownika i zrób artykuł o nim na pomocy”.
 *
 * Link w artykule to stały adres /chatbot/ — chatbot/index.php przekierowuje go na bieżący adres z tokenem,
 * więc artykuł nie psuje się po zmianie tokenu w Admin → Ustawienia AI.
 * Powiązania „Zobacz też” w obie strony z artykułami o poczcie i logowaniu (tylko istniejące; dopisuje, nie nadpisuje).
 * Uruchamia migracja '2026-11_it_portal_chatbot_article'.
 */

const ITP_CHATBOT_RELATED = [['moja-poczta', 'zmiana-hasla-poczty'], ['moja-poczta', 'logowanie-dpoczta'], ['moj-login', 'pierwsze-logowanie']];

/** @return array{category:string, saved:string, linked:list<string>} */
function itPortalChatbotApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'saved' => '', 'linked' => []];

    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'helpdesk'") ?: db_one("SELECT id, name FROM itp_categories WHERE is_active = 1 ORDER BY sort_order LIMIT 1");
    if (!$cat) { $rep['category'] = 'brak usług w Portalu Usług IT'; return $rep; }
    $catId = (int)$cat['id'];
    $rep['category'] = (string)$cat['name'];

    $rel = [];
    foreach (ITP_CHATBOT_RELATED as [$cs, $as]) {
        $r = db_one("SELECT a.id, a.title, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cs, $as]);
        if ($r) $rel[(int)$r['id']] = $r;
    }

    $slug = 'czat-pomocy-it';
    [$title, $keywords, $blocks] = itPortalChatbotArticle($app);
    $old = db_one("SELECT id, sort_order, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $rep['saved'] = ($old ? 'nadpisany' : 'nowy') . ": {$slug} — {$title}";
    if ($dryRun) return $rep;

    $sort = $old ? (int)$old['sort_order'] : 5;   // na górze listy w usłudze
    $related = array_values(array_unique(array_merge(itPortalIds($old['related'] ?? '[]'), array_keys($rel))));
    $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1,
        'sort_order' => $sort, 'related' => $related], $old ? (int)$old['id'] : 0);

    foreach ($rel as $rid => $r) {
        $ids = itPortalIds($r['related']);
        if (in_array($id, $ids, true)) continue;
        $ids[] = $id;
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode(array_values(array_unique($ids))), $rid]);
        $rep['linked'][] = (string)$r['title'];
    }
    return $rep;
}

/** @return array{0:string,1:string,2:array} tytuł, słowa kluczowe, bloki */
function itPortalChatbotArticle(string $app): array {
    $bot = $app . '/chatbot/';
    $hd  = $app . '/helpdesk/new.php';
    $li  = $app . '/auth/report_login_issue.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return ['Czat Pomocy IT: asystent AI',
        'czat, chat, chatbot, asystent, asystent ai, ai, sztuczna inteligencja, pomoc, pomoc it, pytanie, zapytaj, procedury, '
        . 'hasło, poczta, logowanie, problem, nie działa, helpdesk, bot',
        [
            ['type' => 'text', 'html' => '<p>Czat Pomocy IT to asystent, któremu możesz zadać pytanie zwykłymi słowami. '
                . 'Szuka odpowiedzi w artykułach Pomocy IT, w procedurach i dokumentach Fundacji oraz w opisie systemu SZO. '
                . 'Odpowiada krótko, krok po kroku, i zawsze podaje, skąd wziął odpowiedź.</p>'
                . '<p>Otwórz go tutaj: <a href="' . $bot . '">Czat Pomocy IT</a>. Działa bez logowania, na komputerze i w telefonie. '
                . 'Znajdziesz go też w panelu współpracownika, na kafelku „Czat Pomocy IT”.</p>'],

            ['type' => 'text', 'html' => '<h2>O co możesz zapytać</h2><ul>'
                . '<li><strong>Sprawy IT</strong> — poczta, hasła, logowanie, chmura, VPN. Na przykład: „Jak zmienić hasło do poczty?”</li>'
                . '<li><strong>Procedury i dokumenty</strong> — na przykład: „Jak rozliczyć zwrot kosztów wolontariusza?”</li>'
                . '<li><strong>Obsługa systemu SZO</strong> — gdzie co kliknąć. Na przykład: „Gdzie wpisuję godziny?”</li>'
                . '</ul><p>Gdy trwa awaria, czat pokaże ją z boku strony.</p>'],

            ['type' => 'wizard', 'title' => 'Jak zadać pytanie', 'intro' => '<p>To zajmie chwilę.</p>', 'steps' => [
                $step('Otwórz czat', '<p>Wejdź na <a href="' . $bot . '">Czat Pomocy IT</a> albo kliknij kafelek w panelu.</p>'),
                $step('Napisz pytanie', '<p>Wpisz pytanie w polu na dole i naciśnij Enter. Możesz też kliknąć jedną z gotowych podpowiedzi.</p>'),
                $step('Przeczytaj odpowiedź', '<p>Pod odpowiedzią są linki do źródeł, na przykład do artykułu w Pomocy IT. '
                    . 'Otwórz je, jeśli chcesz zobaczyć całą instrukcję.</p>'),
                $step('Dopytaj', '<p>Czat pamięta rozmowę, więc możesz dopytać, np. „a w telefonie?” '
                    . 'Przycisk „Nowa rozmowa” zaczyna od początku.</p>'),
            ]],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p><strong>Nie wpisuj w czacie haseł, numeru PESEL ani innych danych osobowych.</strong> '
                . 'Czat ich nie potrzebuje i nie widzi Twojego konta. Nie sprawdzi więc statusu Twojej sprawy — '
                . 'o to zapytaj po zalogowaniu, w panelu, w Asystencie AI.</p>'],

            ['type' => 'text', 'html' => '<h2>Czat się pomylił albo nie pomógł</h2>'
                . '<p>Odpowiedzi tworzy sztuczna inteligencja (Claude). Zwykle są trafne, ale mogą zawierać błąd. '
                . 'Przy sprawach formalnych sprawdź źródło, które podał czat.</p>'
                . '<p>Gdy czat nie pomógł, zgłoś sprawę w <a href="' . $hd . '">Helpdesku IT</a> (po zalogowaniu). '
                . 'Jeśli nie możesz się zalogować, skorzystaj z formularza <a href="' . $li . '">Nie mogę się zalogować</a>.</p>'],
        ]];
}
