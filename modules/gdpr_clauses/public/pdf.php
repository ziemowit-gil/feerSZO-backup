<?php
/**
 * modules/gdpr_clauses/public/pdf.php — PDF opublikowanej klauzuli (bez logowania).
 * /klauzula/{slug}[/{lang}].pdf (.htaccess) lub pdf.php?slug=&lang=.
 * Zawsze bieżąca wersja — wersje archiwalne i migawki akceptacji tylko z panelu.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/logic/gdpr_clauses.php';

$slug   = strtolower(trim((string)($_GET['slug'] ?? '')));
$svc    = new GdprClauseService();
$clause = $svc->getBySlug($slug, true, (string)($_GET['lang'] ?? GDPR_DEFAULT_LANG));
if (!$clause) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nie znaleziono klauzuli.';
    exit;
}
$svc->trackView((int)$clause['id'], 'pdf');
$vars = $svc->variables();
$org  = $vars['company_name'] ?? (defined('ORG_NAME') ? ORG_NAME : '');
gdpr_clauses_send_pdf($clause['tytul'], $svc->renderClause($clause), [
    'lang'    => $clause['lang'],
    'org'     => $org,
    'updated' => $clause['updated_at'] ? date('d.m.Y', strtotime($clause['updated_at'])) : '',
    'footer'  => trim($org . ' · ' . gdpr_clauses_public_url($clause['slug'], false, $clause['lang']) . ' · v' . (int)$clause['version']),
], $clause['slug'] . ($clause['lang'] !== GDPR_DEFAULT_LANG ? '_' . $clause['lang'] : ''));
