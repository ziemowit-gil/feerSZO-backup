<?php
/**
 * modules/it_portal/lib/itPortalCrmLoginSeed.php — artykuł „Logowanie do CRM” w usłudze „Mój login”
 * (/uslugi-it/uslugi/moj-login/logowanie-do-crm). Polecenie 2026-11-14: „dodaj artykuł o logowaniu do CRM na pomocy IT”.
 *
 * Od 14.11.2026 (commit 73a0c933d) CRM nie ma własnego logowania Microsoft 365: wejście idzie przez logowanie SZO
 * (e-mail i hasło albo logowanie jednokrotne), zalogowany w SZO wchodzi prosto do CRM; adresy /logowanieCRM i crm.feer.org.pl
 * prowadzą na ten sam ekran. Uprawnienia do CRM bez zmian (nadaje administrator).
 * Uruchamia migracja '2026-11_it_portal_crm_login_article'.
 */

const ITP_CRMLOGIN_RELATED = [['moj-login', 'pierwsze-logowanie'], ['konto-i-hasla', 'zmiana-hasel'], ['migracja', 'zmien-haslo-startowe']];

/** @return array{category:string, saved:string, linked:list<string>} */
function itPortalCrmLoginApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'saved' => '', 'linked' => []];

    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'moj-login'") ?: db_one("SELECT id, name FROM itp_categories WHERE slug = 'konto-i-hasla'");
    if (!$cat) { $rep['category'] = 'brak usługi „Mój login” i „Konto i hasła”'; return $rep; }
    $catId = (int)$cat['id'];
    $rep['category'] = (string)$cat['name'];

    $rel = [];
    foreach (ITP_CRMLOGIN_RELATED as [$cs, $as]) {
        $r = db_one("SELECT a.id, a.title, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cs, $as]);
        if ($r) $rel[(int)$r['id']] = $r;
    }

    $slug = 'logowanie-do-crm';
    [$title, $keywords, $blocks] = itPortalCrmLoginArticle($app);
    $old = db_one("SELECT id, sort_order, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $rep['saved'] = ($old ? 'nadpisany' : 'nowy') . ": {$slug} — {$title}";
    if ($dryRun) return $rep;

    $sort = $old ? (int)$old['sort_order'] : (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM itp_articles WHERE category_id = ?", [$catId])['s'] ?? 10);
    $related = array_values(array_unique(array_merge(itPortalIds($old['related'] ?? '[]'), array_keys($rel))));
    $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0,
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
function itPortalCrmLoginArticle(string $app): array {
    $crm = $app . '/crm/login.php';
    $nh  = $app . '/nowehaslo';
    $hd  = $app . '/helpdesk/new.php';
    $li  = $app . '/auth/report_login_issue.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return ['Logowanie do CRM',
        'crm, logowanie do crm, logowanieCRM, crm.feer.org.pl, kontakty, kampanie, newsletter, dialer, mobilna, microsoft, microsoft 365, '
        . 'office, logto, logowanie jednokrotne, nie mogę wejść do crm, brak dostępu',
        [
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p><strong>Od 14 listopada 2026 do CRM logujesz się przez SZO, a nie przez Microsoft 365.</strong> '
                . 'CRM nie ma już osobnego ekranu logowania. Używasz tego samego loginu i hasła co w SZO.</p>'],

            ['type' => 'wizard', 'title' => 'Jak wejść do CRM', 'intro' => '', 'steps' => [
                $step('Otwórz CRM', '<p>Wejdź na <a href="' . $crm . '">CRM</a>. Działają też adresy <code>' . htmlspecialchars($app, ENT_QUOTES) . '/logowanieCRM</code> i <code>crm.feer.org.pl</code>.</p>'),
                $step('Zaloguj się do SZO', '<p>Jeśli nie jesteś zalogowany, zobaczysz zwykły ekran logowania SZO. '
                    . 'Zaloguj się adresem e-mail i hasłem albo przyciskiem logowania jednokrotnego.</p>'
                    . '<p>Jeśli jesteś już zalogowany do SZO, ten krok pominiesz.</p>'),
                $step('Pracuj w CRM', '<p>Po zalogowaniu wrócisz prosto do CRM, na stronę, którą otwierałeś.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Dialer w telefonie</h2>'
                . '<p>W aplikacji CRM w telefonie (strona „mobilna”) kliknij „Zaloguj się do SZO” i zaloguj się tak samo jak na komputerze.</p>'],

            ['type' => 'text', 'html' => '<h2>Coś nie działa</h2><ul>'
                . '<li><strong>Nie pamiętasz hasła</strong> — zmień je na stronie <a href="' . $nh . '">Nowe hasło</a>. Hasła zmieniaj tylko tam.</li>'
                . '<li><strong>Logujesz się, ale CRM pisze, że nie masz dostępu</strong> — dostęp do CRM nadaje administrator. Napisz do <a href="' . $hd . '">Helpdesku IT</a>.</li>'
                . '<li><strong>Nie możesz się zalogować do SZO</strong> — skorzystaj z formularza <a href="' . $li . '">Nie mogę się zalogować</a>.</li>'
                . '</ul>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Przycisk „Zaloguj przez Microsoft” na ekranie SZO działa już tylko dla administratorów. '
                . 'Jeśli masz go w zakładkach albo w menedżerze haseł, zamień zakładkę na adres CRM.</p>'],
        ]];
}
