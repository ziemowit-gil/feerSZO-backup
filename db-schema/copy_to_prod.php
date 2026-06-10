<?php
/**
 * Kopiuje plik bazy danych z db-schema/ do głównego folderu aplikacji.
 * Uruchom z linii poleceń: php db-schema/copy_to_prod.php
 *
 * Opcje:
 *   --force   Nadpisz bez pytania
 *   --dry-run Pokaż co zostanie skopiowane, nie kopiuj
 */

$root = dirname(__DIR__);
$schemaDir = __DIR__;

$force  = in_array('--force',   $argv ?? []);
$dryRun = in_array('--dry-run', $argv ?? []);

$files = glob($schemaDir . '/*.{db,sqlite}', GLOB_BRACE);

if (empty($files)) {
    echo "Brak plików .db / .sqlite w db-schema/\n";
    exit(1);
}

foreach ($files as $src) {
    $filename = basename($src);
    $dst = $root . '/' . $filename;

    if (file_exists($dst) && !$force && !$dryRun) {
        echo "UWAGA: $filename już istnieje w głównym folderze.\n";
        echo "Użyj --force żeby nadpisać, lub --dry-run żeby podejrzeć.\n";
        exit(1);
    }

    if ($dryRun) {
        echo "[dry-run] $src -> $dst\n";
        continue;
    }

    if (!copy($src, $dst)) {
        echo "BŁĄD: nie można skopiować $filename\n";
        exit(1);
    }

    echo "Skopiowano: $filename -> " . basename($root) . "/$filename\n";
}

echo "Gotowe.\n";
