<?php
/**
 * includes/version.php
 * Informacje o wersji aplikacji — pobiera dane z git.
 *
 * Wszystkie wywołania gita idą przez szo_shell() (includes/shell_safe.php):
 * na hostingu z wyłączonym shell_exec (MyDevil) dostajemy '' zamiast błędu
 * krytycznego, a hash/gałąź czytamy wprost z plików .git (app_git_head()).
 * Wersja główna wtedy z min_version.txt (bump_version.php pisze ją razem z tagiem).
 */

require_once __DIR__ . '/shell_safe.php';

/**
 * HEAD bez uruchamiania gita: ['hash' => pełny sha, 'branch' => nazwa].
 * Obsługuje ref luźny (.git/refs/heads/…) i spakowany (packed-refs).
 */
function app_git_head(string $base): array {
    $git = $base . '/.git';
    $head = @file_get_contents($git . '/HEAD');
    if ($head === false) return ['hash' => '', 'branch' => ''];
    $head = trim($head);
    if (!str_starts_with($head, 'ref:')) return ['hash' => $head, 'branch' => 'HEAD'];
    $ref = trim(substr($head, 4));
    $hash = trim((string)@file_get_contents($git . '/' . $ref));
    if ($hash === '' && is_file($git . '/packed-refs')) {
        foreach (file($git . '/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (str_ends_with($l, ' ' . $ref)) { $hash = substr($l, 0, 40); break; }
        }
    }
    return ['hash' => preg_match('/^[0-9a-f]{40}$/', $hash) ? $hash : '', 'branch' => preg_replace('#^refs/heads/#', '', $ref)];
}

function app_version(): array {
    static $v = null;
    if ($v !== null) return $v;

    $base = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);

    // Główne źródło prawdy: ostatni git tag pasujący do v* (np. v12.1b → 12.1b).
    // Fallback: APP_VERSION z config.php / min_version.txt.
    $git_tag = trim(szo_shell("cd " . escapeshellarg($base) . " && git describe --tags --match 'v*' --abbrev=0 2>/dev/null") ?: '');
    if ($git_tag !== '') {
        $main_ver = ltrim($git_tag, 'v');
    } elseif (defined('APP_VERSION')) {
        $main_ver = APP_VERSION;
    } else {
        $ver_file = $base . '/min_version.txt';
        $main_ver = file_exists($ver_file) ? trim(file_get_contents($ver_file)) : '';
    }
    $main_ver = preg_replace('/[^0-9.a-zA-Z]/', '', $main_ver);

    // Hash commitu
    $hash      = trim(szo_shell("cd " . escapeshellarg($base) . " && git rev-parse --short HEAD 2>/dev/null") ?: '');
    $hash_full = trim(szo_shell("cd " . escapeshellarg($base) . " && git rev-parse HEAD 2>/dev/null") ?: '');

    // Data commitu
    $date = trim(szo_shell("cd " . escapeshellarg($base) . " && git log -1 --format='%ci' 2>/dev/null") ?: '');
    $date = $date ? date('d.m.Y H:i', strtotime($date)) : '';

    // Branch
    $branch = trim(szo_shell("cd " . escapeshellarg($base) . " && git rev-parse --abbrev-ref HEAD 2>/dev/null") ?: '');

    if ($hash === '' || $branch === '') {       // brak powłoki (np. disable_functions)
        $gh = app_git_head($base);
        if ($hash === '' && $gh['hash'] !== '') { $hash_full = $gh['hash']; $hash = substr($gh['hash'], 0, 7); }
        if ($branch === '') $branch = $gh['branch'];
    }

    $v = [
        'main'      => $main_ver  ?: '1.0',           // z min_version.txt
        'hash'      => $hash      ?: 'unknown',        // short git hash
        'hash_full' => $hash_full ?: '',
        'date'      => $date,
        'branch'    => $branch    ?: 'main',
        'label'     => ($main_ver ? 'v' . $main_ver . ' ' : '') . ($hash ?: 'unknown'),
        'full'      => ($main_ver ? 'v' . $main_ver : '') . ($hash ? ' (' . $hash . ')' : ''),
    ];
    return $v;
}

/**
 * Ostatni tag wydania (git tag w formacie vX.Y[.Z][litera]) i jego zgodność z HEAD.
 * Tag jest tworzony automatycznie przez cli/bump_version.php przy każdym bumpie,
 * więc w normalnej sytuacji wskazuje ten sam commit co ostatni bump wersji.
 */
function app_release_tag(): array {
    static $t = null;
    if ($t !== null) return $t;

    $base = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
    $git  = fn(string $args) => trim(szo_shell(
        "cd " . escapeshellarg($base) . " && git -c safe.directory=" . escapeshellarg($base) . " $args 2>/dev/null"
    ) ?: '');

    $name = $git("describe --tags --match 'v*' --abbrev=0");

    if ($name === '') {
        $t = ['name' => '', 'hash' => '', 'date' => '', 'synced' => false, 'commits_since' => 0];
        return $t;
    }

    $hash  = $git('rev-parse --short ' . escapeshellarg($name . '^{commit}'));
    $head  = $git('rev-parse --short HEAD');
    $date  = $git('log -1 --format=%ci ' . escapeshellarg($name));
    $count = $git('rev-list --count ' . escapeshellarg($name . '..HEAD'));

    $t = [
        'name'          => $name,
        'hash'          => $hash,
        'date'          => $date ? date('d.m.Y', strtotime($date)) : '',
        'synced'        => ($hash !== '' && $hash === $head),
        'commits_since' => (int)$count,
    ];
    return $t;
}

/**
 * Litera sekwencyjna licząc od 0: 0=a, 25=z, 26=aa, 27=ab, ... (jak kolumny arkusza).
 * Zwykły a-z (26 liter) nie starcza — bywa >100 commitów między bumpami wersji głównej.
 */
function app_seq_letter(int $n): string {
    $n++; // 1-indeksowane
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(97 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/**
 * Wersja "build" tego konkretnego commita: {wersja_główna}{litera}+{ddmmrrrrGGii}.
 *
 * Wersja główna (np. 1.13) jest ustawiana ręcznie przez admina — cli/bump_version.php.
 * Litera rośnie automatycznie z każdym kolejnym commitem od ostatniego bumpa
 * (a, b, c… z, aa, ab…), znacznik czasu to data commitu HEAD. Nic nie jest zapisywane
 * na dysku — liczone na bieżąco z historii gita, tak jak hash/data/branch w app_version().
 * Przykład: 1.13a+030720262014 (3 lipca 2026, 20:14, pierwszy commit po bumpie do 1.13).
 */
function app_build_version(): array {
    static $b = null;
    if ($b !== null) return $b;

    $base = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
    $git  = fn(string $args) => trim(szo_shell(
        "cd " . escapeshellarg($base) . " && git -c safe.directory=" . escapeshellarg($base) . " $args 2>/dev/null"
    ) ?: '');

    $main = app_version()['main'];

    $last_bump = $git("log --grep='^chore(version)' --format=%H -1");
    $count     = $last_bump !== ''
        ? (int)$git('rev-list --count ' . escapeshellarg($last_bump . '..HEAD'))
        : 0;
    // Bez powłoki (disable_functions) nie policzymy commitów od bumpa — sama wersja główna
    $letter = szo_fn_enabled('shell_exec') ? app_seq_letter($count) : '';

    $commit_epoch = $git('log -1 --format=%ct');
    $ts           = $commit_epoch !== '' ? date('dmYHi', (int)$commit_epoch) : '';

    $b = [
        'letter' => $letter,
        'ts'     => $ts,
        'full'   => $main . $letter . ($ts ? '+' . $ts : ''),
    ];
    return $b;
}

/**
 * Pobiera ostatnie N commitów jako historię zmian.
 */
function app_changelog(int $limit = 20): array {
    $base = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
    $raw  = szo_shell(
        "cd " . escapeshellarg($base) .
        " && git log --no-merges --format='%h|%ci|%s' -" . (int)$limit . " 2>/dev/null"
    );
    if (!$raw) return [];

    $items = [];
    foreach (explode("\n", trim($raw)) as $line) {
        if (!$line) continue;
        [$hash, $date, $msg] = array_pad(explode('|', $line, 3), 3, '');
        $date_fmt = $date ? date('d.m.Y', strtotime($date)) : '';

        // Typ commitu z prefixu (feat/fix/refactor/etc.)
        $type = 'other';
        if (preg_match('/^(feat|fix|refactor|docs|style|test|chore|perf)/', $msg, $m)) {
            $type = $m[1];
            $msg  = ltrim(preg_replace('/^' . $m[1] . '(\([^)]+\))?:\s*/', '', $msg));
        }

        $items[] = ['hash' => $hash, 'date' => $date_fmt, 'msg' => $msg, 'type' => $type];
    }
    return $items;
}
