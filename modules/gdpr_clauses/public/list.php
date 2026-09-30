<?php
/**
 * modules/gdpr_clauses/public/list.php — lista opublikowanych klauzul RODO (JSON).
 *
 * Adres: /klauzule.json (reguła w .htaccess). Konsument: strona feer.org.pl
 * (feer-web, polecenie `szo:import-clauses`), która trzyma lokalną kopię, żeby
 * strona /rodo działała także przy niedostępnym SZO.
 *
 * Bez logowania — to dokładnie te dane, które są już publiczne pod
 * /klauzula/{slug}. HTML pochodzi z GdprClauseService::renderClause(), czyli
 * z tego samego, escapującego renderera co strona publiczna (brak surowego HTML
 * z bazy). Wersje robocze (drafty w obiegu akceptacji) nie są ujawniane.
 *
 * Parametry: ?lang=pl — tylko jeden język; ?slug=a,b — tylko wybrane klauzule.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/logic/gdpr_clauses.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: noindex');

$onlyLang = strtolower((string)($_GET['lang'] ?? ''));
$onlyLang = isset(GDPR_LANGS[$onlyLang]) ? $onlyLang : '';
$onlySlugs = array_values(array_filter(
    array_map('trim', explode(',', strtolower((string)($_GET['slug'] ?? '')))),
    fn($s) => $s !== '' && preg_match(GDPR_SLUG_RE, $s)
));

try {
    $svc  = new GdprClauseService();
    $base = rtrim(APP_URL, '/');
    $out  = [];
    foreach ($svc->listClauses() as $row) {
        if (empty($row['is_published'])) continue;
        if ($onlyLang !== '' && $row['lang'] !== $onlyLang) continue;
        if ($onlySlugs && !in_array($row['slug'], $onlySlugs, true)) continue;

        $clause = $svc->getById((int)$row['id']);
        if (!$clause) continue;
        $path = '/klauzula/' . $clause['slug'] . ($clause['lang'] !== GDPR_DEFAULT_LANG ? '/' . $clause['lang'] : '');
        $out[] = [
            'slug'       => $clause['slug'],
            'lang'       => $clause['lang'],
            'title'      => (string)$clause['tytul'],
            'version'    => (int)($clause['version'] ?? 0),
            'updated_at' => $clause['updated_at'] ? date('c', strtotime($clause['updated_at'])) : null,
            'url'        => $base . $path,
            'pdf_url'    => $base . $path . '.pdf',
            'html'       => $svc->renderClause($clause),
        ];
    }
    echo json_encode([
        'ok'           => true,
        'generated_at' => date('c'),
        'count'        => count($out),
        'clauses'      => $out,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('[gdpr_clauses/list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lista klauzul jest chwilowo niedostępna.'], JSON_UNESCAPED_UNICODE);
}
