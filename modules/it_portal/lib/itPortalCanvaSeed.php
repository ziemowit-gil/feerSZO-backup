<?php
/**
 * modules/it_portal/lib/itPortalCanvaSeed.php — artykuł „Canva: logowanie kontem Microsoft i starym hasłem” w Portalu Usług IT
 * (/uslugi-it/uslugi/microsoft-365/canva-logowanie). Polecenie 2026-11-14: „dodaj do pomocy IT artykuł, że logowanie do Canva
 * jeszcze z Microsoft i starym hasłem”.
 *
 * Canva nie obsługuje SAML (zob. modules canva / admin/canva.php), więc do Canvy wchodzi się przyciskiem „Kontynuuj z Microsoft”
 * — dopóki konta Microsoft 365 działają, liczy się stare hasło Microsoft, a nie hasło do poczty dPoczta ani hasło z /nowehaslo.
 * Gdy logowanie do Canvy się zmieni, wystarczy poprawić treść tutaj i dopisać migrację.
 * Uruchamia migracja '2026-11_it_portal_canva_article'.
 */

const ITP_CANVA_RELATED = [['konto-i-hasla', 'zmiana-hasel'], ['moja-poczta', 'zmiana-hasla-poczty'], ['migracja', 'migracja']];

/** @return array{category:string, saved:string, linked:list<string>} */
function itPortalCanvaApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'saved' => '', 'linked' => []];

    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'microsoft-365'") ?: db_one("SELECT id, name FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) { $rep['category'] = 'brak usługi „Microsoft 365” i „Mój login”'; return $rep; }
    $catId = (int)$cat['id'];
    $rep['category'] = (string)$cat['name'];

    $rel = [];
    foreach (ITP_CANVA_RELATED as [$cs, $as]) {
        $r = db_one("SELECT a.id, a.title, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cs, $as]);
        if ($r) $rel[(int)$r['id']] = $r;
    }

    $slug = 'canva-logowanie';
    [$title, $keywords, $blocks] = itPortalCanvaArticle($app);
    $old = db_one("SELECT id, sort_order, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $rep['saved'] = ($old ? 'nadpisany' : 'nowy') . ": {$slug} — {$title}";
    if ($dryRun) return $rep;

    $sort = $old ? (int)$old['sort_order'] : 5;
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
function itPortalCanvaArticle(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return ['Canva: logowanie kontem Microsoft i starym hasłem',
        'canva, canva pro, grafika, logowanie do canva, nie mogę się zalogować do canva, microsoft, konto microsoft, kontynuuj z microsoft, '
        . 'stare hasło, hasło microsoft, office 365, microsoft 365, outlook',
        [
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p><strong>Ważne: do Canvy logujesz się na razie przez Microsoft i starym hasłem do Microsoft 365.</strong> '
                . 'To hasło, którego używałeś do Outlooka i Teams przed przeniesieniem poczty. '
                . 'Nowe hasło do poczty (dpoczta.pl) ani hasło logowania jednokrotnego tu nie zadziała.</p>'],

            ['type' => 'wizard', 'title' => 'Jak zalogować się do Canvy', 'intro' => '', 'steps' => [
                $step('Otwórz Canvę', '<p>Wejdź na <a href="https://www.canva.com/login">canva.com</a> i kliknij „Zaloguj się”.</p>'),
                $step('Wybierz Microsoft', '<p>Kliknij „Kontynuuj z Microsoft”. Nie wpisuj adresu w polu „Kontynuuj z adresem e-mail”.</p>'),
                $step('Wpisz adres i stare hasło', '<p>Wpisz adres służbowy <code>imie.nazwisko@feer.org.pl</code>, a potem stare hasło do Microsoft 365.</p>'),
                $step('Gotowe', '<p>Zobaczysz zespół Fundacji i wasze projekty. Jeśli Canva pyta o założenie nowego konta, '
                    . 'wróć i sprawdź, czy wybrałeś „Kontynuuj z Microsoft”.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Nie pamiętasz starego hasła</h2>'
                . '<p>Napisz do <a href="' . $hd . '">Helpdesku IT</a> i wybierz sprawę o koncie. Nadamy nowe hasło do konta Microsoft. '
                . 'Nie zmieniaj go na stronie Nowe hasło w SZO, bo ta strona zmienia hasło do poczty i logowania jednokrotnego, a nie do Microsoft.</p>'],

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Gdy zmienimy sposób logowania do Canvy, poprawimy tę instrukcję i damy znać w komunikacie. '
                . 'Twoje projekty w Canvie zostaną na miejscu.</p>'],

            ['type' => 'text', 'html' => '<h2>Nie masz jeszcze dostępu do Canvy</h2>'
                . '<p>Poproś o dostęp w panelu współpracownika, przyciskiem „Poproś o dostęp do Canva”. Po zatwierdzeniu wyślemy zaproszenie na Twój adres.</p>'],
        ]];
}
