#!/usr/bin/env php
<?php
/**
 * cron/outlook_calendar_sync.php — Synchronizacja kalendarzy Outlook per-user CRM
 *
 * Uruchamiany przez cron/dispatcher.php co godzinę.
 * Iteruje wszystkich użytkowników CRM z włączoną synchronizacją kalendarza
 * i aktywnym kontem Office 365 (microsoft_id), synchronizuje ich zdarzenia
 * z Outlooka → crm_events.
 *
 * Wymagania aplikacji Azure AD: Calendars.Read (Application)
 */

define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/outlook_sync.php';

// ── Sprawdź czy M365 jest skonfigurowany ──────────────────────────────────────
if (!crm_setting('m365_tenant_id') || !crm_setting('m365_graph_client_id') || !crm_setting('m365_graph_client_secret')) {
    echo "[SKIP] " . date('Y-m-d H:i:s') . " Brak konfiguracji M365 — pomiń synchronizację kalendarzy.\n";
    exit(0);
}

// ── Uruchom migracje ──────────────────────────────────────────────────────────
crm_migrate();

// ── Synchronizuj ─────────────────────────────────────────────────────────────
$start = microtime(true);
echo "[START] " . date('Y-m-d H:i:s') . " Synchronizacja kalendarzy Outlook per-user\n";

try {
    $sync   = new OutlookSync();
    $result = $sync->sync_all_users();

    $duration = round(microtime(true) - $start, 2);

    echo sprintf(
        "[DONE]  %s Użytkownicy: %d | +%d nowych, ~%d aktualizacji, -%d usuniętych | %.2fs\n",
        date('Y-m-d H:i:s'),
        $result['users'],
        $result['created'],
        $result['updated'],
        $result['removed'],
        $duration
    );

    foreach ($result['errors'] as $err) {
        echo "[ERROR] " . date('Y-m-d H:i:s') . " {$err}\n";
    }

    exit(empty($result['errors']) ? 0 : 1);

} catch (\Throwable $e) {
    echo "[FATAL] " . date('Y-m-d H:i:s') . " " . $e->getMessage() . "\n";
    exit(1);
}
