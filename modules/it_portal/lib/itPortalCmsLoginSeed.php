<?php
/**
 * modules/it_portal/lib/itPortalCmsLoginSeed.php — artykuł „Logowanie do panelu strony feer.org.pl (CMS)” w usłudze „Mój login”
 * (/uslugi-it/uslugi/moj-login/logowanie-do-cms). Polecenie 2026-11-14: „dodaj to też do instrukcji w pomoc IT”, po dodaniu w CMS
 * (repo feer-web, commit 70e5cd4c) logowania przez logowanie jednokrotne Fundacji (Logto, OpenID Connect).
 *
 * Fakty z feer-web: strona logowania /login, przycisk „Zaloguj się przez logowanie jednokrotne” (gdy administrator włączy SSO),
 * wpuszcza tylko konta z „Użytkownicy” po tym samym adresie e-mail, logowanie jednokrotne pomija kod 2FA panelu,
 * hasło i Microsoft dalej działają (chyba że włączono „Tylko SSO”). Uruchamia migracja '2026-11_it_portal_cms_login_article'.
 */

/** @return array{category:string, saved:string} */
function itPortalCmsLoginApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'moj-login'") ?: db_one("SELECT id, name FROM itp_categories WHERE slug = 'konto-i-hasla'");
    if (!$cat) return ['category' => 'brak usługi „Mój login” i „Konto i hasła”', 'saved' => ''];
    $catId = (int)$cat['id'];
    $slug = 'logowanie-do-cms';
    [$title, $keywords, $blocks] = itPortalCmsLoginArticle($app);
    $old = db_one("SELECT id, sort_order, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $rep = ['category' => (string)$cat['name'], 'saved' => ($old ? 'nadpisany' : 'nowy') . ": {$slug} — {$title}"];
    if ($dryRun) return $rep;

    $rel = [];
    foreach ([['moj-login', 'logowanie-do-crm'], ['moj-login', 'pierwsze-logowanie'], ['konto-i-hasla', 'zmiana-hasel']] as [$cs, $as]) {
        $r = db_one("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cs, $as]);
        if ($r) $rel[] = (int)$r['id'];
    }
    $sort = $old ? (int)$old['sort_order'] : (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM itp_articles WHERE category_id = ?", [$catId])['s'] ?? 10);
    $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0, 'sort_order' => $sort,
        'related' => array_values(array_unique(array_merge(itPortalIds($old['related'] ?? '[]'), $rel)))], $old ? (int)$old['id'] : 0);
    // „Zobacz też” w artykule o CRM prowadzi z powrotem do CMS
    $crm = db_one("SELECT a.id, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = 'moj-login' AND a.slug = 'logowanie-do-crm'");
    if ($crm) {
        $ids = itPortalIds($crm['related']);
        if (!in_array($id, $ids, true)) { $ids[] = $id; db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($ids), (int)$crm['id']]); }
    }
    return $rep;
}

/** @return array{0:string,1:string,2:array} tytuł, słowa kluczowe, bloki */
function itPortalCmsLoginArticle(string $app): array {
    $cms = 'https://feer.org.pl/login';
    $nh  = $app . '/nowehaslo';
    $hd  = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return ['Logowanie do panelu strony feer.org.pl (CMS)',
        'cms, panel strony, strona internetowa, feer.org.pl, feer.org.pl/login, admin, panel admina, redakcja, aktualności, logowanie, '
        . 'logowanie jednokrotne, iam, logto, sso, jedno hasło, microsoft, nie mam dostępu',
        [
            ['type' => 'text', 'html' => '<p>Panel strony feer.org.pl (CMS) to miejsce, w którym redakcja dodaje aktualności, strony i działania. '
                . 'Do panelu zalogujesz się teraz tym samym kontem co do SZO i chmury plików, czyli <strong>logowaniem jednokrotnym</strong>. '
                . 'Nie potrzebujesz osobnego hasła do strony.</p>'],

            ['type' => 'wizard', 'title' => 'Jak wejść do panelu strony', 'intro' => '', 'steps' => [
                $step('Otwórz stronę logowania', '<p>Wejdź na <a href="' . $cms . '">feer.org.pl/login</a>.</p>'),
                $step('Wybierz logowanie jednokrotne', '<p>Kliknij przycisk <strong>„Zaloguj się przez logowanie jednokrotne”</strong>.</p>'),
                $step('Zaloguj się kontem Fundacji', '<p>Otworzy się ta sama strona logowania co w SZO. Wpisz adres e-mail i hasło logowania jednokrotnego. '
                    . 'Jeśli przed chwilą logowałeś(-aś) się do SZO albo do chmury, często ten krok się pominie.</p>'),
                $step('Pracuj w panelu', '<p>Wrócisz na feer.org.pl, od razu zalogowany(-a) do panelu. Dodatkowego kodu z aplikacji panel wtedy nie pyta, '
                    . 'bo zabezpiecza to już logowanie jednokrotne.</p>'),
            ]],

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p><strong>Kto ma dostęp.</strong> Logowanie jednokrotne potwierdza tylko, kim jesteś. '
                . 'Do panelu wejdziesz, jeśli administrator strony założył Ci w nim konto na ten sam adres e-mail. '
                . 'Gdy po zalogowaniu widzisz komunikat „To konto nie ma dostępu do panelu”, napisz do <a href="' . $hd . '">Helpdesku IT</a>.</p>'],

            ['type' => 'text', 'html' => '<h2>Coś nie działa</h2><ul>'
                . '<li><strong>Nie widzisz przycisku logowania jednokrotnego</strong>: administrator jeszcze go nie włączył. Zaloguj się dotychczasowym sposobem.</li>'
                . '<li><strong>Nie pamiętasz hasła</strong>: zmień je na stronie <a href="' . $nh . '">Nowe hasło</a>. To samo hasło działa w SZO, chmurze i na stronie.</li>'
                . '<li><strong>Logujesz się innym adresem e-mail niż w panelu</strong>: poproś administratora, żeby poprawił adres w Twoim koncie w panelu.</li>'
                . '</ul>'
                . '<p>Dotychczasowe logowanie e-mailem i hasłem albo przez Microsoft na razie działa dalej.</p>'],
        ]];
}
