<?php
/**
 * modules/it_portal/lib/itPortalStartoweSeed.php — artykuł „Po migracji: zmień hasła startowe” w usłudze „Migracja”
 * (/uslugi-it/uslugi/migracja/zmien-haslo-startowe). Polecenie 2026-11-14: „dodaj opis do usług IT: ustawienie nowego hasła
 * po migracji — zmień startowe”.
 *
 * Opisuje: hasła startowe z SMS-a (poczta oraz logowanie jednokrotne i chmura), termin NoweHasloInitial::GRACE_DAYS dni,
 * potem blokada panelu SZO; zmiana WYŁĄCZNIE w SZO na /nowehaslo (kreator po zalogowaniu bez kodu, bez logowania — dane + kod);
 * dwa różne hasła; nowe hasło w telefonie i programie pocztowym. Przypięty, na górze usługi „Migracja”.
 * Uruchamia migracja '2026-11_it_portal_startowe_article'.
 */

const ITP_STARTOWE_RELATED = [['konto-i-hasla', 'zmiana-hasel'], ['moja-poczta', 'zmiana-hasla-poczty'], ['moja-poczta', 'poczta-w-telefonie'], ['migracja', 'migracja']];

/** @return array{category:string, saved:string, linked:list<string>} */
function itPortalStartoweApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'saved' => '', 'linked' => []];

    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'migracja'") ?: db_one("SELECT id, name FROM itp_categories WHERE slug = 'konto-i-hasla'");
    if (!$cat) { $rep['category'] = 'brak usługi „Migracja” i „Konto i hasła”'; return $rep; }
    $catId = (int)$cat['id'];
    $rep['category'] = (string)$cat['name'];

    $rel = [];
    foreach (ITP_STARTOWE_RELATED as [$cs, $as]) {
        $r = db_one("SELECT a.id, a.title, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cs, $as]);
        if ($r) $rel[(int)$r['id']] = $r;
    }

    $slug = 'zmien-haslo-startowe';
    [$title, $keywords, $blocks] = itPortalStartoweArticle($app);
    $old = db_one("SELECT id, sort_order, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $rep['saved'] = ($old ? 'nadpisany' : 'nowy') . ": {$slug} — {$title}";
    if ($dryRun) return $rep;

    $sort = $old ? (int)$old['sort_order'] : 1;   // pierwszy w usłudze
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
function itPortalStartoweArticle(string $app): array {
    $nh = $app . '/nowehaslo';
    $hd = $app . '/helpdesk/new.php';
    $li = $app . '/auth/report_login_issue.php';
    $tel = $app . '/uslugi-it/uslugi/moja-poczta/poczta-w-telefonie';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return ['Po migracji: zmień hasła startowe',
        'hasło startowe, hasła startowe, zmiana hasła, nowe hasło, nowehaslo, po migracji, migracja, sms z hasłem, blokada panelu, '
        . 'panel zablokowany, termin, poczta, dpoczta, logowanie jednokrotne, iam, chmura, nextcloud, logto',
        [
            ['type' => 'text', 'html' => '<p>Po przeniesieniu poczty dostałeś SMS-em hasła startowe: do poczty oraz do logowania jednokrotnego i chmury plików. '
                . 'To hasła tymczasowe. Zmień oba na własne.</p>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p><strong>Ważne: masz na to ' . itPortalStartoweDays() . ' dni.</strong> '
                . 'Potem panel SZO zablokuje się i otworzy dopiero, gdy zmienisz hasło. Ile zostało czasu, zobaczysz na stronie Nowe hasło.</p>'
                . '<p><strong>Hasła zmieniaj wyłącznie w SZO, na stronie <a href="' . $nh . '">Nowe hasło</a>.</strong> '
                . 'Nie zmieniaj ich w ustawieniach dpoczta.pl ani w chmurze. Hasło zmienione poza SZO nie zsynchronizuje się i usługi przestaną działać.</p>'],

            ['type' => 'wizard', 'title' => 'Jak zmienić hasło startowe', 'intro' => '<p>Zmieniasz hasła po kolei: najpierw jedno, potem drugie.</p>', 'steps' => [
                $step('Otwórz stronę Nowe hasło', '<p>Zaloguj się do SZO i wejdź na <a href="' . $nh . '">Nowe hasło</a>. W panelu współpracownika prowadzi do niej kafel „Zmiana haseł”.</p>'
                    . '<p>Nie możesz się zalogować? Na tej samej stronie podaj prywatny e-mail albo PESEL z umowy oraz adres służbowy. Wtedy potwierdzisz zmianę kodem.</p>'),
                $step('Wybierz hasło', '<p>Kliknij kafel z hasłem, które zmieniasz. Hasło, które nadal jest startowe, ma oznaczenie „hasło startowe”. Kliknij „Dalej”.</p>'),
                $step('Wpisz nowe hasło', '<p>Wpisz nowe hasło dwa razy. Pod polami odhaczą się wymagania: co najmniej 12 znaków, mała i wielka litera, cyfra. '
                    . 'Kliknij „Zmień hasło”. Po zalogowaniu kod nie jest potrzebny.</p>'
                    . '<p>Nie chcesz wymyślać? Kliknij „Wyślij mi losowe hasło SMS-em”.</p>'),
                $step('Zmień drugie hasło', '<p>Na ekranie „Gotowe” kliknij przycisk z drugim hasłem i powtórz kroki. '
                    . 'Hasło do poczty musi być inne niż hasło logowania jednokrotnego i chmury.</p>'),
                $step('Wpisz nowe hasła w urządzeniach', '<p>Jeśli masz pocztę w telefonie albo w Outlooku, wpisz tam nowe hasło do poczty. '
                    . 'W aplikacji chmury wpisz nowe hasło logowania jednokrotnego. Opis: <a href="' . $tel . '">Poczta w telefonie, Outlooku i Thunderbirdzie</a>.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Które hasło do czego</h2><ul>'
                . '<li><strong>Poczta</strong> — skrzynka <code>imie.nazwisko@feer.org.pl</code> w przeglądarce (dpoczta.pl), w telefonie i w programie pocztowym.</li>'
                . '<li><strong>Logowanie jednokrotne i chmura plików</strong> — jedno hasło do SZO (przycisk logowania jednokrotnego), do chmury plików i do kolejnych aplikacji Fundacji.</li>'
                . '</ul>'],

            ['type' => 'text', 'html' => '<h2>Coś nie działa</h2>'
                . '<p>Nie masz SMS-a z hasłem startowym albo zmiana się nie udaje? Napisz do <a href="' . $hd . '">Helpdesku IT</a>. '
                . 'Nie możesz się zalogować do SZO? Skorzystaj z formularza <a href="' . $li . '">Nie mogę się zalogować</a>.</p>'],

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nikt z Fundacji nie poprosi Cię o hasło ani o kod. Hasła nigdy nie wysyłamy e-mailem.</p>'],
        ]];
}

/** Liczba dni na zmianę hasła startowego (z NoweHasloInitial, gdy moduł jest; inaczej 7). */
function itPortalStartoweDays(): int {
    $f = dirname(__DIR__, 2) . '/nowe_haslo/lib/NoweHasloInitial.php';
    if (is_file($f)) { require_once $f; if (class_exists('NoweHasloInitial')) return NoweHasloInitial::GRACE_DAYS; }
    return 7;
}
