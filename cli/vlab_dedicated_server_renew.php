<?php
/**
 * cli/vlab_dedicated_server_renew.php — nalicza odnowienia dedykowanych serwerów VLab.
 *
 * Dla każdego aktywnego zamówienia (k30_ti_vlab_dedicated_server, status='active'), którego
 * next_renewal_at minął, dopisuje opłatę za kolejny okres do rozliczenia kursanta (k30_ti_billing,
 * pole adjustment) i przesuwa termin odnowienia o kolejny okres (miesiąc/rok). Idempotentny —
 * bezpieczny do wielokrotnego uruchamiania tego samego dnia.
 *
 * Uruchom:  php cli/vlab_dedicated_server_renew.php
 * Cron:     15 2 * * *  php /sciezka/cli/vlab_dedicated_server_renew.php >> /var/log/vlab_dedsrv_renew.log 2>&1
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/vlab.php';
require_once dirname(__DIR__) . '/includes/ti_payments.php';

karty30_migrate();

$res = vlab_dedicated_server_bill_renewals();

echo "[" . date('Y-m-d H:i') . "] {$res['date']}: sprawdzono {$res['checked']}, naliczono {$res['billed']}.\n";
exit(0);
