<?php
/**
 * karty30/ti/dydaktyk/katalog.php — Publiczny katalog szkoleń TI (bez logowania), część modułu MSUES (modules/ti_msues).
 *
 *   (brak parametrów)  lista szkoleń z katalogu (efekty, czas trwania, forma, najbliższy termin)
 *   ?u=<id grupy>      karta szkolenia: opis, efekty uczenia się, wymagania, program, czas trwania, terminy, kadra, wsparcie, dokument
 * Krótkie adresy na ti.* / szo.* / kurs*.*: /katalog/, /katalog/<id> (.htaccess). Wpis publikuje kierownik w profilu usługi MSUES.
 * Logika: modules/ti_msues/lib/MsuesCatalog.php; widok: modules/ti_msues/view/public/catalog.php (wygląd planu publicznego).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/modules/ti_plan_public/ui/portalLayout.php';
require_once dirname(__DIR__, 3) . '/modules/ti_msues/lib/MsuesCatalog.php';

header('X-Robots-Tag: index, follow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-cache, private');   // publikacja w profilu usługi ma być widoczna od razu

$entries = MsuesCatalog::entries();
$id = (int)($_GET['u'] ?? 0);
$entry = $id ? MsuesCatalog::entry($id) : null;
if ($id && !$entry) http_response_code(404);

require dirname(__DIR__, 3) . '/modules/ti_msues/view/public/catalog.php';
