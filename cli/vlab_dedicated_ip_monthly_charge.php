<?php
/**
 * cli/vlab_dedicated_ip_monthly_charge.php — nalicza abonament za dedykowane IP VLAB.
 *
 * Dla każdej aktywnej usługi dedykowanego IP (k30_ti_vlab_dedicated_ip, status='active'),
 * której bieżący miesiąc nie został jeszcze rozliczony, dopisuje opłatę miesięczną do
 * rozliczenia kursanta (k30_ti_billing, pole adjustment). Miesiąc aktywacji jest już
 * pokryty opłatą aktywacyjną, więc pierwsze naliczenie następuje w kolejnym miesiącu.
 * Idempotentny — bezpieczny do wielokrotnego uruchamiania w tym samym miesiącu.
 *
 * Uruchom:  php cli/vlab_dedicated_ip_monthly_charge.php
 * Cron:     10 2 1 * *  php /sciezka/cli/vlab_dedicated_ip_monthly_charge.php >> /var/log/vlab_dedip_charge.log 2>&1
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/vlab.php';
require_once dirname(__DIR__) . '/includes/ti_payments.php';

karty30_migrate();

$res = vlab_dedicated_ip_bill_monthly();

echo "[" . date('Y-m-d H:i') . "] Okres {$res['period']}: sprawdzono {$res['checked']}, naliczono {$res['billed']}.\n";
exit(0);
