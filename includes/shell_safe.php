<?php
/**
 * includes/shell_safe.php — bezpieczne wywołania powłoki.
 *
 * Na hostingu z disable_functions (MyDevil: shell_exec, exec, proc_open…)
 * wywołanie wyłączonej funkcji to w PHP 8 błąd krytyczny („has been disabled
 * for security reasons”) — operator @ go NIE tłumi, więc jedna linijka w
 * stopce kładła cały system. Zawsze wołaj szo_shell() zamiast shell_exec().
 */

/** Czy funkcja PHP jest dostępna (istnieje i nie jest na disable_functions). */
function szo_fn_enabled(string $fn): bool {
    static $cache = [];
    if (!array_key_exists($fn, $cache)) {
        $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
        $cache[$fn] = function_exists($fn) && !in_array(strtolower($fn), $disabled, true);
    }
    return $cache[$fn];
}

/** shell_exec() albo '' gdy wyłączone / błąd. Nigdy nie rzuca. */
function szo_shell(string $cmd): string {
    if (!szo_fn_enabled('shell_exec')) return '';
    try {
        $out = @shell_exec($cmd);
    } catch (\Throwable $e) {
        return '';
    }
    return is_string($out) ? $out : '';
}
