<?php
/**
 * EZD Client — aplikacja desktopowa (macOS / Windows): skanowanie do EZD, szybka lista kontaktów CRM.
 * Instalatory (.dmg/.pkg/.exe/.msi) leżą w UPLOAD_DIR/ezd-client/ — wgrywa je administrator (lub CI).
 * Pobieranie wyłącznie przez ezd/klient/pobierz.php (po zalogowaniu i z dostępem do EZD).
 */

const EZD_CLIENT_DIR_SUB = 'ezd-client/';
const EZD_CLIENT_TYPES = [   // rozszerzenie => [system, etykieta]
    'dmg' => ['mac', 'macOS (.dmg)'],
    'pkg' => ['mac', 'macOS (.pkg — wdrożenia MDM)'],
    'exe' => ['win', 'Windows (.exe)'],
    'msi' => ['win', 'Windows (.msi — wdrożenia GPO/Intune)'],
];

function ezd_client_dir(): string {
    return rtrim(UPLOAD_DIR, '/') . '/' . EZD_CLIENT_DIR_SUB;
}

/**
 * Dostępne instalatory: najnowsza wersja każdego typu pliku.
 * @return array<string,array{file:string,os:string,label:string,version:string,size:int,mtime:int}> klucz = rozszerzenie
 */
function ezd_client_files(): array {
    $dir = ezd_client_dir();
    if (!is_dir($dir)) return [];
    $best = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f[0] === '.' || !is_file($dir . $f)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!isset(EZD_CLIENT_TYPES[$ext])) continue;
        $ver = preg_match('/(\d+\.\d+\.\d+)/', $f, $m) ? $m[1] : '0.0.0';
        $cur = $best[$ext] ?? null;
        $mtime = (int)filemtime($dir . $f);
        if ($cur && (version_compare($ver, $cur['version']) < 0
            || (version_compare($ver, $cur['version']) === 0 && $mtime <= $cur['mtime']))) continue;
        $best[$ext] = [
            'file' => $f, 'os' => EZD_CLIENT_TYPES[$ext][0], 'label' => EZD_CLIENT_TYPES[$ext][1],
            'version' => $ver, 'size' => (int)filesize($dir . $f), 'mtime' => $mtime,
        ];
    }
    return $best;
}

function ezd_client_size_label(int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '') . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}
