<?php
/**
 * cli/bump_version.php — podnoszenie wersji aplikacji (semver X.Y[.Z]).
 *
 * Jedyne źródło prawdy: min_version.txt (config.php go odczytuje, patrz APP_VERSION).
 * Skrypt: liczy nową wersję, pokazuje podgląd zmian od ostatniego bumpa (git log),
 * po potwierdzeniu zapisuje plik i tworzy commit `chore(version): ...` (+ opcjonalnie tag).
 *
 * Użycie:
 *   php cli/bump_version.php minor            # 1.11 -> 1.12 (domyślnie)
 *   php cli/bump_version.php major            # 1.11 -> 2.0
 *   php cli/bump_version.php patch            # 1.11.2 -> 1.11.3 (tylko schemat X.Y.Z)
 *   php cli/bump_version.php 1.12             # ustaw wprost
 *   php cli/bump_version.php minor --tag      # + git tag -a vX.Y
 *   php cli/bump_version.php minor --yes      # bez pytania o potwierdzenie
 *   php cli/bump_version.php minor --dry-run  # tylko podgląd, nic nie zapisuje
 *
 * Kody wyjścia: 0 = OK, 1 = błąd użycia/danych, 2 = błąd krytyczny (git/zapis).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);

if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}

define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/updater.php';

function cli_line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function cli_err(string $s): void { fwrite(STDERR, $s . "\n"); }

// ── Argumenty ────────────────────────────────────────────────────────────────
$args    = array_slice($argv, 1);
$flags   = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$posArgs = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

$want_tag   = in_array('--tag', $flags, true);
$auto_yes   = in_array('--yes', $flags, true) || in_array('-y', $flags, true);
$dry_run    = in_array('--dry-run', $flags, true);
$bump_arg   = $posArgs[0] ?? 'minor';

// ── Wersja bieżąca (plik jest jedynym źródłem prawdy) ───────────────────────
$ver_file = $base . '/min_version.txt';
$current  = is_file($ver_file) ? trim((string)file_get_contents($ver_file)) : '1.0';
if ($current === '') $current = '1.0';

if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?$/', $current, $m)) {
    cli_err("Nieczytelna wersja w min_version.txt: \"$current\" (oczekiwano X.Y lub X.Y.Z).");
    exit(1);
}
[$maj, $min] = [(int)$m[1], (int)$m[2]];
$patch = isset($m[3]) ? (int)$m[3] : null;

// ── Oblicz nową wersję ───────────────────────────────────────────────────────
function fmt_ver(int $maj, int $min, ?int $patch): string {
    return $patch === null ? "$maj.$min" : "$maj.$min.$patch";
}

switch ($bump_arg) {
    case 'major':
        $new = fmt_ver($maj + 1, 0, $patch === null ? null : 0);
        break;
    case 'minor':
        $new = fmt_ver($maj, $min + 1, $patch === null ? null : 0);
        break;
    case 'patch':
        if ($patch === null) {
            cli_err("Ten projekt używa schematu X.Y (bieżąca: $current) — 'patch' wymaga X.Y.Z.");
            cli_err("Użyj 'minor' albo podaj wersję wprost, np.: php cli/bump_version.php 1.12");
            exit(1);
        }
        $new = fmt_ver($maj, $min, $patch + 1);
        break;
    default:
        if (!preg_match('/^\d+\.\d+(?:\.\d+)?$/', $bump_arg)) {
            cli_err("Nieznany argument: \"$bump_arg\". Użyj: major | minor | patch | X.Y[.Z]");
            exit(1);
        }
        $new = $bump_arg;
}

if ($new === $current) {
    cli_line("Wersja już wynosi $current — nic do zrobienia.");
    exit(0);
}

// ── Podgląd zmian od ostatniego bumpa ───────────────────────────────────────
cli_line("━━ Podnoszenie wersji aplikacji ━━━━━━━━━━━━━━━━━━━");
cli_line("  Bieżąca wersja: v$current");
cli_line("  Nowa wersja:    v$new");
cli_line('');

if (upd_git_available()) {
    $last_bump = upd_git("log --grep='^chore(version)' --format=%H -1");
    $range     = ($last_bump['code'] === 0 && trim($last_bump['out']) !== '')
        ? trim($last_bump['out']) . '..HEAD'
        : '-20'; // brak poprzedniego bumpa w historii — pokaż ostatnie 20 commitów

    $raw = $range === '-20'
        ? upd_git("log --no-merges --format='%h|%ci|%s' -20")
        : upd_git("log --no-merges --format='%h|%ci|%s' " . escapeshellarg($range));

    $commits = ($raw['code'] === 0) ? upd_parse_commits($raw['out']) : [];

    if ($commits) {
        $by_type = [];
        foreach ($commits as $c) $by_type[$c['type']] = ($by_type[$c['type']] ?? 0) + 1;
        arsort($by_type);
        $summary = [];
        foreach ($by_type as $t => $n) $summary[] = "$t:$n";

        cli_line("  Zmiany od ostatniego bumpa: " . count($commits) . " (" . implode(', ', $summary) . ")");
        $shown = array_slice($commits, 0, 30);
        foreach ($shown as $c) {
            cli_line("    · [{$c['type']}] {$c['msg']} ({$c['hash']}, {$c['date']})");
        }
        if (count($commits) > count($shown)) {
            cli_line("    … i jeszcze " . (count($commits) - count($shown)) . " commitów (pełna lista: /admin/version.php)");
        }
    } else {
        cli_line("  (brak nowych commitów od ostatniego bumpa)");
    }
    cli_line('');

    if (upd_is_dirty()) {
        cli_err("  ⚠ Drzewo robocze ma niezacommitowane zmiany — commit wersji obejmie tylko min_version.txt.");
    }
} else {
    cli_line("  (git niedostępny — pomijam podgląd zmian)");
}

if ($dry_run) {
    cli_line("Tryb --dry-run: nic nie zapisano.");
    exit(0);
}

// ── Potwierdzenie ────────────────────────────────────────────────────────────
if (!$auto_yes) {
    cli_line("Zapisać v$new i utworzyć commit" . ($want_tag ? " + tag v$new" : '') . "? [t/N]");
    $answer = trim((string)fgets(STDIN));
    if (!in_array(strtolower($answer), ['t', 'tak', 'y', 'yes'], true)) {
        cli_line("Przerwano.");
        exit(0);
    }
}

// ── Zapis pliku ──────────────────────────────────────────────────────────────
if (file_put_contents($ver_file, $new . "\n") === false) {
    cli_err("Nie udało się zapisać $ver_file");
    exit(2);
}
cli_line("  ✔ min_version.txt → $new");

// ── Commit + tag ─────────────────────────────────────────────────────────────
if (upd_git_available()) {
    $add = upd_git('add ' . escapeshellarg($ver_file));
    if ($add['code'] !== 0) {
        cli_err("  ⚠ git add nie powiódł się: {$add['out']}");
    } else {
        $msg    = "chore(version): podnieś wersję do $new";
        $commit = upd_git('commit -m ' . escapeshellarg($msg) . ' -- ' . escapeshellarg($ver_file));
        if ($commit['code'] === 0) {
            cli_line("  ✔ commit: $msg");
        } else {
            cli_err("  ⚠ git commit nie powiódł się: {$commit['out']}");
        }

        if ($want_tag) {
            $tag_name = 'v' . $new;
            $tag      = upd_git('tag -a ' . escapeshellarg($tag_name) . ' -m ' . escapeshellarg("Wersja $new"));
            if ($tag['code'] === 0) {
                cli_line("  ✔ tag: $tag_name");
            } else {
                cli_err("  ⚠ git tag nie powiódł się: {$tag['out']}");
            }
        }
    }
} else {
    cli_line("  (git niedostępny — pominięto commit/tag, zapisano tylko plik)");
}

cli_line("━━ Gotowe: v$current → v$new ━━━━━━━━━━━━━━━━━━━━━━");
exit(0);
