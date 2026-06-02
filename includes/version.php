<?php
/**
 * includes/version.php
 * Informacje o wersji aplikacji — pobiera dane z git.
 */

function app_version(): array {
    static $v = null;
    if ($v !== null) return $v;

    $base = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);

    // Hash commitu
    $hash  = trim(@shell_exec("cd " . escapeshellarg($base) . " && git rev-parse --short HEAD 2>/dev/null") ?: '');
    $hash_full = trim(@shell_exec("cd " . escapeshellarg($base) . " && git rev-parse HEAD 2>/dev/null") ?: '');

    // Data commitu
    $date  = trim(@shell_exec("cd " . escapeshellarg($base) . " && git log -1 --format='%ci' 2>/dev/null") ?: '');
    $date  = $date ? date('d.m.Y H:i', strtotime($date)) : '';

    // Branch
    $branch = trim(@shell_exec("cd " . escapeshellarg($base) . " && git rev-parse --abbrev-ref HEAD 2>/dev/null") ?: '');

    $v = [
        'hash'      => $hash      ?: 'unknown',
        'hash_full' => $hash_full ?: '',
        'date'      => $date,
        'branch'    => $branch    ?: 'main',
        'label'     => $hash ? $hash . ($date ? ' · ' . $date : '') : 'brak git',
    ];
    return $v;
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
