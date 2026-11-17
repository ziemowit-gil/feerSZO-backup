<?php
/**
 * inc/publicLinks/publicLinks.php — linki do systemu w SMS-ach i powiadomieniach zawsze na adres publiczny, nigdy na localhost
 * (polecenia 2026-11-17: „linki w SMS-ach — gdzie localhost, podmień na inne”, potem to samo w powiadomieniach w dzwonku).
 *
 * Z CLI/CRON-a APP_URL to http://localhost, na serwerze http://localhost/feerSZO (katalog aplikacji liczony względem
 * katalogu bieżącego), więc linki budowane w cronie (kopie zapasowe, migracja poczty, przypomnienia TI…) nigdzie nie
 * prowadzą. publicLinks() zamienia host localhost / 127.0.0.1 — z portem i z doklejonym katalogiem aplikacji — na adres
 * publiczny wg ścieżki:
 *   • panele TI (/karty30/ti/…, /plan…, /dyzury) → https://ti.feer.org.pl, od razu w krótkiej postaci jak w .htaccess
 *     (/karty30/ti/kursant/x → /x, /karty30/ti/dydaktyk/x → /dydaktyk/x, /karty30/ti/x → /x),
 *   • panel licencji (/licencje…) → https://kursant.feer.org.pl (jak LicencjePanelAuth::publicUrl()),
 *   • reszta → https://szo.feer.org.pl.
 * Linki do innych domen i tekst bez linków zostają bez zmian. Działa na zwykłym tekście i na HTML-u (adres kończy się
 * na spacji, cudzysłowie albo < >).
 *
 * Katalog aplikacji wycinany jest niezależnie od bieżącego APP_URL (migracja zapisanych danych może iść w żądaniu na
 * szo.feer.org.pl, gdzie APP_URL go nie ma): ścieżka z APP_URL na localhost, „/<nazwa katalogu repo>” (/feerSZO)
 * i pełna ścieżka katalogu repo.
 *
 * Używają: includes/sms.php (_sms_dispatch → sms_public_links), includes/notifications.php (notif_create),
 * migracja '2026-11_notifications_public_links'.
 */

const PUBLIC_LINKS_SZO = 'https://szo.feer.org.pl';
const PUBLIC_LINKS_TI  = 'https://ti.feer.org.pl';
const PUBLIC_LINKS_LIC = 'https://kursant.feer.org.pl';

/** Prefiksy katalogu aplikacji, które CLI dokleja do http://localhost (najdłuższe pierwsze). */
function publicLinksAppPaths(): array {
    static $paths = null;
    if ($paths !== null) return $paths;
    $paths = [];
    if (defined('APP_URL') && preg_match('~^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(/[^?#\s]*)?$~i', rtrim((string)APP_URL, '/'), $m)) {
        $paths[] = rtrim($m[1] ?? '', '/');
    }
    $root = dirname(__DIR__, 2);
    $paths[] = '/' . basename($root);
    $real = realpath($root);
    if ($real !== false) $paths[] = rtrim(str_replace('\\', '/', $real), '/');
    $paths = array_values(array_unique(array_filter($paths, fn($p) => $p !== '' && $p !== '/')));
    usort($paths, fn($a, $b) => strlen($b) <=> strlen($a));
    return $paths;
}

/** Tekst (albo sam adres) z linkami localhost / 127.0.0.1 przepiętymi na adres publiczny. */
function publicLinks(string $text, string $szo = PUBLIC_LINKS_SZO, string $ti = PUBLIC_LINKS_TI): string {
    if (stripos($text, 'localhost') === false && strpos($text, '127.0.0.1') === false) return $text;
    $szo = rtrim($szo, '/');
    $ti  = rtrim($ti, '/');
    $app = implode('|', array_map(fn($p) => preg_quote($p, '~'), publicLinksAppPaths()));
    $re  = '~https?://(?:localhost|127\.0\.0\.1)(?::\d+)?'
         . ($app !== '' ? '(?:(?:' . $app . ')(?=[/?#\s"\'<>]|$))?' : '')
         . '([/?#][^\s"\'<>]*)?~i';
    return (string)preg_replace_callback($re, function (array $m) use ($szo, $ti): string {
        $rest = $m[1] ?? '';
        if (preg_match('~^/karty30/ti/kursant/(.*)$~is', $rest, $x))  return $ti . '/' . $x[1];
        if (preg_match('~^/karty30/ti/dydaktyk/(.*)$~is', $rest, $x)) return $ti . '/dydaktyk/' . $x[1];
        if (preg_match('~^/karty30/ti/(.*)$~is', $rest, $x))          return $ti . '/' . $x[1];
        if (preg_match('~^/(plan|dyzury)(?=[/?#]|$)~i', $rest))       return $ti . $rest;
        if (preg_match('~^/licencje(?=[/?#]|$)~i', $rest))            return PUBLIC_LINKS_LIC . $rest;
        return $szo . ($rest === '' ? '/' : $rest);
    }, $text);
}

/**
 * Poprawia linki w zapisanych powiadomieniach (notifications.url, notifications.body). Bezpieczne do ponownego
 * uruchomienia (drugi raz nic nie zmienia). Zwraca liczbę poprawionych wierszy.
 */
function publicLinksFixNotifications(bool $dryRun = false): int {
    $rows = db_all("SELECT id, url, body FROM notifications
                    WHERE url LIKE '%localhost%' OR url LIKE '%127.0.0.1%' OR body LIKE '%localhost%' OR body LIKE '%127.0.0.1%'");
    $n = 0;
    foreach ($rows as $r) {
        $url  = publicLinks((string)$r['url']);
        $body = publicLinks((string)$r['body']);
        if ($url === (string)$r['url'] && $body === (string)$r['body']) continue;
        $n++;
        if (!$dryRun) db()->prepare("UPDATE notifications SET url = ?, body = ? WHERE id = ?")->execute([$url, $body, (int)$r['id']]);
    }
    return $n;
}
