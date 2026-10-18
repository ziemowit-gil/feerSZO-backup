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
    // W żądaniu WWW nie ma STDOUT/STDERR — fwrite(STDERR, …) skryptów idzie do przechwytywanego wyjścia.
    if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'w'));
    if (!defined('STDERR')) define('STDERR', fopen('php://output', 'w'));
    $GLOBALS['__szoCliArgs'] = $args;   // dla cli_getopt()
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

/**
 * getopt() działające też w trybie in-process — zwykłe getopt() czyta argv PROCESU,
 * które w żądaniu WWW jest puste. Obsługuje opcje długie: 'flaga', 'wartosc:' (wymagana),
 * 'opcja::' (opcjonalna, tylko w postaci --opcja=…). Wynik jak z getopt(): flaga → false.
 */
function cli_getopt(string $short, array $long = []): array|false {
    if (!defined('SZO_CLI_INPROC')) return getopt($short, $long);
    $spec = [];
    foreach ($long as $l) $spec[rtrim($l, ':')] = substr_count($l, ':');
    $args = array_values($GLOBALS['__szoCliArgs'] ?? []);
    $out  = [];
    for ($i = 0, $n = count($args); $i < $n; $i++) {
        $a = (string)$args[$i];
        if (!str_starts_with($a, '--')) continue;
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, null);
        if (!isset($spec[$k])) continue;
        $mode = $spec[$k];
        if ($mode === 0)          $val = false;
        elseif ($v !== null)      $val = $v;
        elseif ($mode === 1 && isset($args[$i + 1]) && !str_starts_with((string)$args[$i + 1], '-')) $val = (string)$args[++$i];
        elseif ($mode === 1)      continue;
        else                      $val = false;
        if (array_key_exists($k, $out)) { $out[$k] = (array)$out[$k]; $out[$k][] = $val; }
        else $out[$k] = $val;
    }
    return $out;
}
