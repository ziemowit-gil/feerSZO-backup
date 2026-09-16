<?php
/**
 * cli/exam_sandbox_worker.php — robotnik piaskownicy Equi Exams.
 *
 * Uruchamiany WYŁĄCZNIE przez modules/equi_exams/logic/sandbox.php
 * (ex_sandbox_dispatch_worker()) jako:
 *   sudo -n -u examrun -- /usr/local/bin/php /var/www/html/cli/exam_sandbox_worker.php
 *
 * Celowo nie przyjmuje ŻADNYCH argumentów CLI — całe zadanie (język, kod,
 * stdin, limity) przychodzi jako JSON na standardowym wejściu, żeby reguła
 * sudoers mogła dopuścić jedno stałe, bez-argumentowe polecenie bez
 * wildcardów. Nie dołącza configu aplikacji ani db.php — kod ucznia nie
 * powinien mieć żadnej okazji dotrzeć do sekretów/bazy przez ten proces.
 *
 * Wypisuje na stdout dokładnie jeden obiekt JSON (kształt RunResult — patrz
 * ex_run_result_failure()/ex_sandbox_worker_run() w sandbox.php) i kończy
 * zawsze kodem 0 — błędy są przenoszone w polu "engineError", nie przez kod
 * wyjścia, żeby strona www-data mogła je jednoznacznie odróżnić od awarii
 * samego sudo/PHP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../modules/equi_exams/logic/sandbox.php';

$raw = stream_get_contents(STDIN);
$job = json_decode((string)$raw, true);

if (!is_array($job)) {
    fwrite(STDOUT, json_encode(
        ex_run_result_failure('Nieprawidłowe zadanie piaskownicy (zły JSON na wejściu).'),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    exit(0);
}

$result = ex_sandbox_worker_run($job);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(0);
