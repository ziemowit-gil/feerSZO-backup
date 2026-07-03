<?php
/**
 * includes/version.php
 * Informacje o wersji aplikacji — pobiera dane z git.
 */

function app_version(): array {
    static $v = null;
    if ($v !== null) return $v;

    $base = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);

    // Główna wersja aplikacji — APP_VERSION z config.php
    // (fallback do min_version.txt, jeśli stała niezdefiniowana)
    if (defined('APP_VERSION')) {
        $main_ver = APP_VERSION;
    } else {
        $ver_file = $base . '/min_version.txt';
        $main_ver = file_exists($ver_file) ? trim(file_get_contents($ver_file)) : '';
    }
    $main_ver = preg_replace('/[^0-9.a-zA-Z]/', '', $main_ver); // cyfry, kropki, sufiks literowy (np. 1.12e)

    // Hash commitu
    $hash      = trim(@shell_exec("cd " . escapeshellarg($base) . " && git rev-parse --short HEAD 2>/dev/null") ?: '');
    $hash_full = trim(@shell_exec("cd " . escapeshellarg($base) . " && git rev-parse HEAD 2>/dev/null") ?: '');

    // Data commitu
    $date = trim(@shell_exec("cd " . escapeshellarg($base) . " && git log -1 --format='%ci' 2>/dev/null") ?: '');
    $date = $date ? date('d.m.Y H:i', strtotime($date)) : '';

    // Branch
    $branch = trim(@shell_exec("cd " . escapeshellarg($base) . " && git rev-parse --abbrev-ref HEAD 2>/dev/null") ?: '');

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
    $git  = fn(string $args) => trim(@shell_exec(
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
 * Pobiera ostatnie N commitów jako historię zmian.
 */
function app_changelog(int $limit = 20): array {
    $base = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
    $raw  = @shell_exec(
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
