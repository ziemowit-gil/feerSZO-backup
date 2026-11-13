<?php
/**
 * modules/it_portal/lib/itPortalMs365OffSeed.php — na stronie usługi „Microsoft 365” (/uslugi-it/uslugi/microsoft-365) wyróżnienie
 * „Wycofujemy się z Microsoft 365” na samej górze wstępu (polecenie 2026-11-14 „dopisz, że wycofujemy się z MS”).
 *
 * DOPISUJE, nie nadpisuje: dotychczasowy wstęp zostaje pod wyróżnieniem (wstęp HTML zamieniamy na blok tekstu). Ponowne uruchomienie
 * podmienia tylko to wyróżnienie (rozpoznaje je po ITP_MS365_OFF_MARK). Data wyłączenia z ustawienia sso_ms_off_date (/ssoadmin),
 * domyślnie 20 listopada 2026 r. — ta sama co na ekranie logowania (modules/sso_logto/view/loginInfo.php).
 * Uruchamia migracja '2026-11_it_portal_ms365_off'.
 */

const ITP_MS365_OFF_MARK = 'Wycofujemy się z Microsoft 365';

/** @return string co zrobiono (do dziennika migracji) */
function itPortalMs365OffApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';
    itPortalMigrate();
    $cat = db_one("SELECT id, intro_html, intro_blocks FROM itp_categories WHERE slug = 'microsoft-365'");
    if (!$cat) return 'brak usługi microsoft-365';

    $blocks = itPortalBlocksClean((string)$cat['intro_blocks']);
    if (!$blocks && trim((string)$cat['intro_html']) !== '') $blocks = [['type' => 'text', 'html' => itPortalSanitizeHtml((string)$cat['intro_html'])]];
    $blocks = array_values(array_filter($blocks, fn($b) => !($b['type'] === 'callout' && str_contains((string)$b['html'], ITP_MS365_OFF_MARK))));
    array_unshift($blocks, ['type' => 'callout', 'kind' => 'warn', 'html' => itPortalMs365OffHtml(rtrim($app, '/'))]);

    $json = json_encode($blocks, JSON_UNESCAPED_UNICODE);
    db_exec("UPDATE itp_categories SET intro_blocks = ?, intro_plain = ?, updated_at = datetime('now') WHERE id = ?",
        [$json, itPortalBlocksPlain($json, ''), (int)$cat['id']]);
    return 'dopisano wyróżnienie (' . count($blocks) . ' bloków we wstępie)';
}

function itPortalMs365OffHtml(string $app): string {
    $off = SsoConfig::msOffDate() ?? strtotime('2026-11-20');
    $months = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    $date = date('j', $off) . ' ' . $months[(int)date('n', $off) - 1] . ' ' . date('Y', $off) . ' r.';
    return '<p><strong>' . ITP_MS365_OFF_MARK . '.</strong> ' . $date . ' wyłączymy Microsoft 365 w Fundacji. '
         . 'Instrukcje na tej stronie przydadzą się tylko do tego dnia.</p>'
         . '<p>Co zamiast Microsoft 365:</p><ul>'
         . '<li>Poczta działa już na nowym serwerze. Adres się nie zmienia. Zobacz <a href="' . $app . '/uslugi-it/uslugi/moja-poczta">Moja poczta</a>.</li>'
         . '<li>Pliki trzymamy w chmurze plików Fundacji. Przenieś tam potrzebne pliki z OneDrive przed ' . $date . '</li>'
         . '<li>Do SZO, chmury i innych aplikacji logujesz się jednym kontem: logowaniem jednokrotnym. '
         . 'Załóż je w <a href="' . $app . '/tozsamosc/index.php#sso">Systemie Tożsamości</a>.</li>'
         . '</ul>'
         . '<p>Dlaczego to robimy, opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/migracja/dlaczego-migrujemy">Dlaczego odchodzimy od Microsoft 365</a>.</p>';
}
