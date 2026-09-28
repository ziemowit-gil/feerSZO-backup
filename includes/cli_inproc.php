<?php
/**
 * includes/cli_inproc.php — uruchamianie skryptów cli/*.php W PROCESIE strony
 * (zakładka „Testy” kierownika), bez proc_open i bez binarki PHP CLI.
 *
 * Po co: na hostingu z open_basedir (i często wyłączonym proc_open) strona nie
 * może nawet sprawdzić, czy /usr/local/bin/php istnieje. Skrypty CLI obsługujące
 * ten tryb:
 *   - wpuszczają wywołanie, gdy PHP_SAPI !== 'cli', ale zdefiniowano SZO_CLI_INPROC,
 *   - piszą przez echo (w CLI to i tak stdout; tu przechwytuje ob_start),
 *   - kończą przez cli_exit($code) zamiast exit() — tu to wyjątek z kodem.
 * Otwarta transakcja po błędzie jest wycofywana, żeby nie zablokować strony.
 */

class SzoCliExit extends \Exception {}

/** exit($code) w CLI; w trybie in-process wyjątek przechwytywany przez szo_cli_run(). */
function cli_exit(int $code = 0): never {
    if (defined('SZO_CLI_INPROC')) throw new SzoCliExit('', $code);
    exit($code);
}

/**
 * Uruchamia skrypt CLI w bieżącym procesie. $args jak w terminalu (bez nazwy skryptu).
 * @return array{code:int, out:string, ms:int}
 */
function szo_cli_run(string $script, array $args): array {
    if (!defined('SZO_CLI_INPROC')) define('SZO_CLI_INPROC', true);
    $t0 = microtime(true);
    ob_start();
    $code = 0;
    try {
        (static function (string $__script, array $__args): void {
            $argv = array_merge([$__script], $__args);
            $argc = count($argv);
            require $__script;
        })($script, $args);
    } catch (SzoCliExit $e) {
        $code = (int)$e->getCode();
    } catch (\Throwable $e) {
        echo "\n[wyjątek] " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        $code = 1;
    } finally {
        try { if (function_exists('db') && db()->inTransaction()) db()->rollBack(); } catch (\Throwable $e) {}
    }
    $out = (string)ob_get_clean();
    return ['code' => $code, 'out' => $out, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}
