<?php
/**
 * modules/selfrepairDB/logic/migrations.php — rejestr migracji JEDNORAZOWYCH.
 *
 * Każda pozycja wykona się raz na zawsze (settings 'selfrepair:<id>'), na
 * końcu żądania, gdy schemat już istnieje — patrz selfRepairDB.php.
 * ZASADY:
 *   - id nigdy się nie zmienia (zmiana = ponowne wykonanie);
 *   - migracja jest idempotentna (drugie wykonanie nic nie psuje) i rzuca
 *     wyjątek przy porażce, żeby nie dostała znacznika;
 *   - potrzebne biblioteki dołącza sama (require_once), bo może ruszyć
 *     w dowolnym module;
 *   - NIE usuwamy starych pozycji — to historia zmian danych.
 * Kolejność = kolejność wykonania.
 *
 * @return array<string, array{desc: string, run: callable}>
 */
$ROOT = dirname(__DIR__, 3);

return [

    // Przeniesione z karty30_migrate() (dawniej przy KAŻDYM żądaniu)
    '2026-06_k30_client_status_keys' => [
        'desc' => 'Beneficjenci: stare statusy ready/to_settle/other → learning/graduated/enrolled',
        'run'  => static function (): void {
            db()->exec("UPDATE k30_clients SET status='learning'  WHERE status='ready'");
            db()->exec("UPDATE k30_clients SET status='graduated' WHERE status='to_settle'");
            db()->exec("UPDATE k30_clients SET status='enrolled'  WHERE status='other'");
        },
    ],

    // Przeniesione z ti_protocols_migrate() — dawna flaga ti_protocols_next_month_backfill
    '2026-09_ti_protocols_next_month_backfill' => [
        'desc' => 'Protokoły TI: zatwierdzone miesięczne bez otwartego następnego miesiąca → otwórz go',
        'run'  => static function () use ($ROOT): void {
            if (org_setting('ti_protocols_next_month_backfill') === '1') return;   // już zrobione starym mechanizmem
            require_once $ROOT . '/includes/karty30.php';
            require_once $ROOT . '/includes/ti_protocols.php';
            ti_protocols_backfill_next_months();
            org_setting_set('ti_protocols_next_month_backfill', '1');
        },
    ],

    // Przeniesione z ti_notices_migrate() — dawne flagi ti_notice_2026_09_szo141_v2/_v3
    '2026-09_ti_notice_141a_naglowek' => [
        'desc' => 'Komunikat 14.1: dopisek o nagłówku, selektorze grupy i menu (już zasiany wpis)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();   // tabela komunikatów istnieje
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Nagłówek panelu:%'"
            )->execute(["\n\n(Uzupełnienie 14.1a)\n• " . TI_NOTICE_141A_NAGLOWEK]);
        },
    ],
    '2026-09_ti_notice_141b_pulpit' => [
        'desc' => 'Komunikat 14.1: dopisek o nowym Pulpicie (już zasiany wpis)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Pulpit:%'"
            )->execute(["\n\n(Uzupełnienie 14.1b)\n• " . TI_NOTICE_141B_PULPIT]);
        },
    ],

    '2026-09_ti_notice_141f' => [
        'desc' => 'Komunikat 14.1: dopisek o zmianach 14.1f (Wydruki, dziennik, widoki Zajęć, PDF, szybkość)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Wersja 14.1f:%'"
            )->execute(["\n\n(Uzupełnienie 14.1f)\n• " . TI_NOTICE_141F]);
        },
    ],

    '2026-09_ti_notice_141g' => [
        'desc' => 'Komunikat 14.1: dopisek o zmianach 14.1g (rozliczenia: tryb FVAT/zestawienie, wycofywanie, przeniesienia, podglądy, Przelicz ceny)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Wersja 14.1g%'"
            )->execute(["\n\n(Uzupełnienie 14.1g)\n• " . TI_NOTICE_141G]);
        },
    ],

    '2026-09_ti_notice_141h' => [
        'desc' => 'Komunikat 14.1: dopisek o zmianach 14.1h (stawka online, cenniki i rabaty, nadpłaty, korekty, panel kursanta)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Wersja 14.1h%'"
            )->execute(["\n\n(Uzupełnienie 14.1h)\n• " . TI_NOTICE_141H]);
        },
    ],

    '2026-09_ti_notice_141i' => [
        'desc' => 'Komunikat 14.1: dopisek o zmianach 14.1i (portal płatności /platnosci, dopasowanie wpłat z wyciągów EODoK)',
        'run'  => static function () use ($ROOT): void {
            require_once $ROOT . '/includes/ti_notices.php';
            ti_notices_migrate();
            db()->prepare(
                "UPDATE k30_ti_notices SET body = body || ?, updated_at = datetime('now')
                  WHERE title LIKE 'Aktualizacja SZO 14.1%' AND body NOT LIKE '%Wersja 14.1i%'"
            )->execute(["\n\n(Uzupełnienie 14.1i)\n• " . TI_NOTICE_141I]);
        },
    ],

];
