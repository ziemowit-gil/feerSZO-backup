<?php
/**
 * TYMCZASOWA DIAGNOSTYKA 500 (umowy zlecenie).
 * Pokazuje adminowi/edytorowi dokładny wyjątek/fatal zamiast pustego 500.
 * USUNĄĆ po zdiagnozowaniu: skasować ten plik + require_once w add.php/edit.php.
 */
if (function_exists('current_user') && in_array(current_user()['role'] ?? '', ['admin', 'editor'], true)) {
    @ini_set('display_errors', '1');
    error_reporting(E_ALL);

    set_exception_handler(function (\Throwable $e) {
        if (!headers_sent()) http_response_code(500);
        echo '<pre style="white-space:pre-wrap;margin:1rem;padding:1rem;background:#fff3cd;border:2px solid #ffc107;border-radius:8px;font-size:13px;font-family:monospace">';
        echo "DIAG 500: " . htmlspecialchars($e->getMessage()) . "\n";
        echo htmlspecialchars($e->getFile()) . ':' . $e->getLine() . "\n\n";
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
        error_log('[zlecenie DIAG] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    });

    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (!headers_sent()) http_response_code(500);
            echo '<pre style="white-space:pre-wrap;margin:1rem;padding:1rem;background:#f8d7da;border:2px solid #dc3545;border-radius:8px;font-size:13px;font-family:monospace">';
            echo "DIAG FATAL: " . htmlspecialchars($e['message']) . "\n" . htmlspecialchars($e['file']) . ':' . $e['line'];
            echo '</pre>';
        }
    });
}
