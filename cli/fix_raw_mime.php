<?php
/**
 * cli/fix_raw_mime.php — jednorazowa naprawa wiadomości zapisanych jako surowy MIME.
 *
 * Część nadawców (autorespondery, bramki helpdeskowe) dostarczała treść tak, że
 * w bazie lądował cały multipart: granice `--boundary`, nagłówki części
 * i quoted-printable. Od teraz rozpakowuje to już warstwa pobierania poczty
 * (includes/poczta.php), ale wiersze zapisane WCZEŚNIEJ trzeba naprawić ręcznie.
 *
 * Użycie:
 *   php cli/fix_raw_mime.php            # podgląd (nic nie zapisuje)
 *   php cli/fix_raw_mime.php --apply    # zapisuje poprawione treści
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mime_text.php';

$apply = in_array('--apply', $argv, true);

echo '[' . date('Y-m-d H:i:s') . '] Start: fix_raw_mime' . ($apply ? ' (ZAPIS)' : ' (podgląd)') . "\n";

// Zawężamy kandydatów po stronie SQL — pełne wykrywanie robi crm_mail_is_raw_mime()
$rows = db_all(
    "SELECT id, subject, body, body_html FROM crm_communications
     WHERE (body LIKE '%Content-Transfer-Encoding:%' OR body_html LIKE '%Content-Transfer-Encoding:%'
            OR body LIKE '%Content-Type: text/%'     OR body_html LIKE '%Content-Type: text/%')
     ORDER BY id DESC
     LIMIT 5000"
);

$stmt = db()->prepare("UPDATE crm_communications SET body=?, body_html=? WHERE id=?");
$fixed = $skipped = 0;

foreach ($rows as $r) {
    $norm = crm_mail_normalize((string)($r['body'] ?? ''), (string)($r['body_html'] ?? ''));
    if ($norm === null) { $skipped++; continue; }

    $preview = mb_strimwidth(preg_replace('/\s+/u', ' ', $norm['body']), 0, 70, '…');
    echo '  ' . ($apply ? '✓' : '·') . ' #' . (int)$r['id'] . ' '
       . mb_strimwidth((string)($r['subject'] ?: '(bez tematu)'), 0, 40, '…') . ' → ' . $preview . "\n";

    if ($apply) {
        try {
            $stmt->execute([$norm['body'], $norm['body_html'] ?: null, (int)$r['id']]);
        } catch (\Throwable $e) {
            echo '    ✗ ' . $e->getMessage() . "\n";
            continue;
        }
    }
    $fixed++;
}

echo "  Podsumowanie: " . ($apply ? 'naprawiono' : 'do naprawy') . " {$fixed}, "
   . "pominięto (treść w porządku) {$skipped}\n";
if (!$apply && $fixed) echo "  Uruchom ponownie z --apply, żeby zapisać.\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: fix_raw_mime\n";
