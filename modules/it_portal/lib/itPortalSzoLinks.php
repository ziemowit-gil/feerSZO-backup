<?php
/**
 * modules/it_portal/lib/itPortalSzoLinks.php — linki do stron SZO w Portalu Usług IT zawsze na szo.feer.org.pl
 * (polecenie 2026-11-13: „podmień w artykułach adres na szo.feer.org.pl”).
 *
 * Seedery artykułów (itPortalDpocztaSeed, itPortalMigracjaSeed) budowały linki z APP_URL, a ten zależy od miejsca uruchomienia:
 * z CLI/CRON to http://localhost, z żądania na innej domenie np. https://ti.feer.org.pl. Teraz seedery dostają ITP_SZO_URL,
 * a itPortalSzoLinksApply() poprawia to, co już zapisano: w treści artykułów (body_html, bloki JSON) i we wstępach usług
 * (intro_html, intro_blocks, external_url) zamienia hosta w linkach do stron SZO (helpdesk, migracja poczty, nowe hasło,
 * portal usług IT, Tożsamość…). Linków zewnętrznych (np. dpoczta.pl, nextcloud.com) i innych stron domen Fundacji nie rusza.
 * Używa migracja '2026-11_it_portal_szo_links' i cli/it_portal_szo_links.php.
 */
const ITP_SZO_URL = 'https://szo.feer.org.pl';

/** Host (localhost, 127.0.0.1, dowolna domena *.feer.org.pl) przed ścieżką strony SZO. */
const ITP_SZO_LINK_RE = '~https?://(?:localhost|127\.0\.0\.1|(?:[a-z0-9-]+\.)*feer\.org\.pl)(?::\d+)?(?=/(?:helpdesk|migracjapoczty|migracjaadmin|nowehaslo|uslugi-it|tozsamosc|sso|ssoadmin|auth|panel)(?:[/?#.]|$))~i';

/** To samo w postaci z ukośnikami zapisanymi jako „\/” (HTML zakodowany w JSON-ie, np. ręcznie wklejone bloki). */
const ITP_SZO_LINK_RE_ESC = '~https?:\\\\/\\\\/(?:localhost|127\.0\.0\.1|(?:[a-z0-9-]+\.)*feer\.org\.pl)(?::\d+)?(?=\\\\/(?:helpdesk|migracjapoczty|migracjaadmin|nowehaslo|uslugi-it|tozsamosc|sso|ssoadmin|auth|panel)(?:\\\\/|[?#.]|$))~i';

/** Tekst z linkami do stron SZO przepiętymi na ITP_SZO_URL (także w postaci z „\/”). */
function itPortalSzoLinksFix(string $s): string {
    $s = (string)preg_replace(ITP_SZO_LINK_RE, ITP_SZO_URL, $s);
    return (string)preg_replace_callback(ITP_SZO_LINK_RE_ESC, fn() => str_replace('/', '\\/', ITP_SZO_URL), $s);
}

/** Bloki JSON (itp_articles.blocks, itp_categories.intro_blocks): poprawia wszystkie teksty w środku, zapis jak itPortalBlocksFromInput. */
function itPortalSzoLinksFixBlocks(string $json): string {
    if (trim($json) === '') return $json;
    $data = json_decode($json, true);
    if (!is_array($data)) return itPortalSzoLinksFix($json);   // nie JSON (stary zapis) — poprawiamy tekst wprost
    array_walk_recursive($data, function (&$v) { if (is_string($v)) $v = itPortalSzoLinksFix($v); });
    $out = json_encode($data, JSON_UNESCAPED_UNICODE);
    return $out === false ? $json : $out;
}

/**
 * Poprawia linki w zapisanych artykułach i usługach. Bezpieczne do ponownego uruchomienia (drugi raz nic nie zmienia).
 * @return array{articles:list<string>, categories:list<string>}
 */
function itPortalSzoLinksApply(bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $rep = ['articles' => [], 'categories' => []];
    foreach (db_all("SELECT a.id, a.slug, a.title, a.body_html, a.blocks, c.slug AS cslug FROM itp_articles a LEFT JOIN itp_categories c ON c.id = a.category_id") as $a) {
        $body = itPortalSzoLinksFix((string)$a['body_html']);
        $blocks = itPortalSzoLinksFixBlocks((string)($a['blocks'] ?? ''));
        if ($body === (string)$a['body_html'] && $blocks === (string)($a['blocks'] ?? '')) continue;
        $rep['articles'][] = "#{$a['id']} {$a['cslug']}/{$a['slug']} — {$a['title']}";
        if (!$dryRun) db_exec("UPDATE itp_articles SET body_html = ?, blocks = ?, updated_at = datetime('now') WHERE id = ?", [$body, $blocks, (int)$a['id']]);
    }
    foreach (db_all("SELECT id, slug, name, intro_html, intro_blocks, external_url FROM itp_categories") as $c) {
        $intro = itPortalSzoLinksFix((string)$c['intro_html']);
        $blocks = itPortalSzoLinksFixBlocks((string)($c['intro_blocks'] ?? ''));
        $ext = itPortalSzoLinksFix((string)($c['external_url'] ?? ''));
        if ($intro === (string)$c['intro_html'] && $blocks === (string)($c['intro_blocks'] ?? '') && $ext === (string)($c['external_url'] ?? '')) continue;
        $rep['categories'][] = "#{$c['id']} {$c['slug']} — {$c['name']}";
        if (!$dryRun) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = ?, external_url = ?, updated_at = datetime('now') WHERE id = ?",
                              [$intro, $blocks, $ext, (int)$c['id']]);
    }
    return $rep;
}
