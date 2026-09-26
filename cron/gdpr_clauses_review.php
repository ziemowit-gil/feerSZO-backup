<?php
/**
 * Skrypt cron: przypomnienie o okresowym przeglądzie klauzul RODO.
 * Uruchamiany raz dziennie (cron/dispatcher.php, 'gdpr_clauses_review').
 *
 * Opublikowane klauzule po terminie przeglądu (GdprClauseService::reviewDue():
 * ostatnia zmiana / potwierdzenie + settings.gdpr_review_months) — jedno zbiorcze
 * powiadomienie (dzwonek + e-mail) dla zatwierdzających (settings.gdpr_approvers,
 * pusta lista = admini). Klauzula przypominana raz na termin: klucz w settings
 * gdpr_review_sent_{id}_{termin}; potwierdzenie „Przejrzana” przesuwa termin.
 * Przy okazji: puste wartości zmiennych użytych w opublikowanych klauzulach.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/notifications.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/modules/gdpr_clauses/logic/gdpr_clauses.php';

$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';
echo $ts() . " Start: gdpr_clauses_review\n";

if (GdprClauseService::reviewMonths() === 0) {
    echo $ts() . " Przeglądy wyłączone (gdpr_review_months = 0).\n";
    exit;
}

notif_migrate();
$svc = new GdprClauseService();

$fresh = [];
foreach ($svc->dueForReview() as $c) {
    $key = 'gdpr_review_sent_' . (int)$c['id'] . '_' . GdprClauseService::reviewDue($c);
    if (db_one("SELECT 1 FROM settings WHERE key_ = ?", [$key])) continue;
    $fresh[] = $c + ['_key' => $key];
}

// Puste zmienne w opublikowanych klauzulach — dołączane do wiadomości, bez osobnej wysyłki.
$empty = [];
foreach (db_all("SELECT * FROM gdpr_clauses WHERE is_published = 1") as $c) {
    if ($t = $svc->emptyTags($c)) $empty[] = ['clause' => $c, 'tags' => $t];
}

if (!$fresh) {
    echo $ts() . " Brak nowych klauzul do przeglądu.\n";
    exit;
}

$app   = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
$org   = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$e     = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$items = '';
foreach ($fresh as $c) {
    $items .= '<li><a href="' . $e($app . '/modules/gdpr_clauses/edit.php?id=' . (int)$c['id']) . '">' . $e($c['tytul']) . '</a>'
            . ' <span style="color:#6c757d">(' . $e(strtoupper($c['lang'])) . ', v' . (int)$c['version']
            . ', ostatnia zmiana ' . $e(date('d.m.Y', strtotime(max((string)$c['updated_at'], (string)$c['reviewed_at'])))) . ')</span></li>';
}
$emptyHtml = '';
if ($empty) {
    $emptyHtml = '<p style="margin-top:18px"><strong>Uwaga — puste zmienne w opublikowanych klauzulach:</strong></p><ul>';
    foreach ($empty as $x) {
        $emptyHtml .= '<li>' . $e($x['clause']['tytul']) . ': <code>{{' . implode('}}</code>, <code>{{', array_map($e, $x['tags'])) . '}}</code></li>';
    }
    $emptyHtml .= '</ul>';
}
$months  = GdprClauseService::reviewMonths();
$subject = 'Klauzule RODO do okresowego przeglądu (' . count($fresh) . ')';
$html = <<<HTML
<html><body style="font-family:sans-serif;max-width:620px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#0d6efd;padding:18px 22px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.05rem">Klauzule RODO do przeglądu — {$e($org)}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:22px;border-radius:0 0 10px 10px">
  <p>Te opublikowane klauzule nie były zmieniane ani potwierdzane od {$months} miesięcy. Sprawdź, czy cele,
     podstawy prawne, okresy przechowywania i odbiorcy są nadal aktualne — potem zapisz zmiany albo kliknij
     „Przejrzana — nadal aktualna”.</p>
  <ul>{$items}</ul>
  {$emptyHtml}
  <p style="color:#6c757d;font-size:.82em;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">Moduł Klauzule RODO — {$e($org)}</p>
</div>
</body></html>
HTML;

$sent = 0;
foreach (GdprClauseService::approverIds() as $uid) {
    try {
        notif_create($uid, 'gdpr_clause', $subject, count($fresh) . ' klauzul(e) po terminie przeglądu', $app . '/modules/gdpr_clauses/index.php');
        $u = db_one("SELECT name, email FROM users WHERE id = ?", [$uid]);
        if ($u && filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) {
            mail_queue_add($u['email'], $u['name'] ?? '', $subject, $html);
            $sent++;
        }
    } catch (\Throwable $ex) {
        echo $ts() . " BLAD (user {$uid}): " . $ex->getMessage() . "\n";
    }
}
foreach ($fresh as $c) {
    db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")->execute([$c['_key'], date('Y-m-d')]);
}
echo $ts() . " Klauzul do przeglądu: " . count($fresh) . ", e-maili: {$sent}\n";
