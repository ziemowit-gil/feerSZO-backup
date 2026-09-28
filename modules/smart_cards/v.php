<?php
/**
 * modules/smart_cards/v.php — publiczna weryfikacja karty po tokenie z rekordu NDEF
 * (telefon przyłożony do karty otwiera ten adres). Pokazuje tylko ważność karty —
 * bez danych osobowych. Pełne dane widzi obsługa w Programatorze NFC (tryb „Sprawdź”).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/smart_cards.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$token = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['t'] ?? ''));
$row = null;
if (module_enabled('smart_cards_enabled')) {
    try { $row = (new SmartCardService(null))->verifyToken($token); } catch (\Throwable $e) { error_log('[smart_cards/v] ' . $e->getMessage()); }
}
$valid = $row && $row['status'] === 'active';
[$title, $msg, $col, $ico] = !$row
    ? ['Karta nierozpoznana', 'Ta karta nie figuruje w ewidencji albo jej zapis został zmieniony.', '#b91c1c', '✕']
    : ($valid
        ? ['Karta ważna', 'Karta jest aktywna do ' . date('d.m.Y', strtotime($row['expires_at'])) . '.', '#047857', '✓']
        : ['Karta nieważna', 'Status: ' . mb_strtolower(SCARD_STATUSES[$row['status']]['label'] ?? $row['status']) . '. Nie honoruj tej karty.', '#b45309', '!']);
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f1f5f9; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; }
  main { background: #fff; border-radius: 20px; padding: 32px 28px; max-width: 360px; margin: 16px; text-align: center; box-shadow: 0 10px 30px -12px rgba(15,23,42,.3); }
  .ico { width: 72px; height: 72px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 16px; color: #fff; font-size: 36px; font-weight: 800; background: <?= $col ?>; }
  h1 { margin: 0 0 8px; font-size: 1.4rem; } p { margin: 0; color: #475569; }
  small { display: block; margin-top: 20px; color: #64748b; }
</style></head>
<body><main role="main" aria-live="polite">
  <div class="ico" aria-hidden="true"><?= $ico ?></div>
  <h1><?= h($title) ?></h1>
  <p><?= h($msg) ?></p>
  <small><?= h(defined('APP_NAME') ? APP_NAME : 'SZO') ?> · weryfikacja karty dostępu</small>
</main></body></html>
