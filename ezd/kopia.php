<?php
/**
 * EZD — wydruk kopii dokumentu elektronicznego (odwzorowanie cyfrowe).
 *
 * Treść dokumentu ze znakiem wodnym + końcowa strona poświadczenia zgodności
 * kopii z dokumentem elektronicznym (metryka: identyfikator, skrót SHA-256,
 * wersja, akceptacja, data i autor wydruku).
 *
 * GET: type   = pismo | dokument | umowa | zalacznik | zaswiadczenie
 *      id     = identyfikator dokumentu
 *      reczny = 1 → kopia BEZ autoryzacji elektronicznej: zamiast wiersza
 *               „Autor wydruku" wydruk dostaje miejsce na miejscowość, datę,
 *               dane i podpis osoby potwierdzającej zgodność, a strona
 *               poświadczenia jest oznaczona jako kopia nieuwierzytelniona
 *      wm     = 0 wyłącza znak wodny
 *      dl     = 1 wymusza pobranie zamiast podglądu
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/includes/ezd_kopia.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$type   = (string)($_GET['type'] ?? '');
$id     = (int)($_GET['id'] ?? 0);
$manual = ($_GET['reczny'] ?? '') === '1';

$meta = ezd_kopia_resolve($type, $id);
if (!$meta) { http_response_code(404); exit('Nie znaleziono dokumentu.'); }

$user_id = (int)current_user()['id'];
if (!ezd_kopia_access($meta, $user_id)) { http_response_code(403); exit('Brak dostępu do koszulki tego dokumentu.'); }

try {
    ezd_log(
        null, ((int)$meta['sprawa_id']) ?: null,
        $type === 'pismo' ? $id : null,
        $type === 'umowa' ? $id : null,
        $user_id, 'kopia_wydruk',
        'Wydruk kopii dokumentu elektronicznego (' . ($manual ? 'do podpisu odręcznego, bez autoryzacji' : 'autoryzacja elektroniczna') . '): '
        . EZD_KOPIA_TYPES[$type] . ' „' . $meta['nazwa'] . '"'
    );
} catch (\Throwable $e) { /* audyt nie może blokować wydruku */ }

try {
    ezd_kopia_stream($meta, $user_id, [
        'watermark' => ($_GET['wm'] ?? '1') !== '0',
        'download'  => ($_GET['dl'] ?? '') === '1',
        'manual'    => $manual,
    ]);
} catch (\Throwable $e) {
    error_log('[ezd_kopia] ' . $e->getMessage());
    http_response_code(500);
    exit('Błąd generowania kopii: ' . htmlspecialchars($e->getMessage()));
}
