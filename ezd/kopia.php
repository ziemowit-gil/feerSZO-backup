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
 *      tryb   = el     → kopia z poświadczeniem autoryzowanym elektronicznie
 *                         (metryka kończy się wierszem „Autor wydruku"),
 *               reczny → kopia BEZ autoryzacji: zamiast autora wydruku miejsce na
 *                         miejscowość, datę, dane i podpis osoby potwierdzającej
 *                         zgodność; strona poświadczenia oznaczona jako kopia
 *                         nieuwierzytelniona,
 *               czysty → samo odwzorowanie treści: bez znaku wodnego, bez strony
 *                         poświadczenia i bez nagłówków wydruku
 *      reczny = 1 — dawna postać `tryb=reczny` (wsteczna zgodność linków)
 *      wm     = 0 wyłącza znak wodny (tryby el/reczny; w czystym i tak go nie ma)
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

$type = (string)($_GET['type'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$tryb = ezd_kopia_tryb((string)($_GET['tryb'] ?? (($_GET['reczny'] ?? '') === '1' ? 'reczny' : 'el')));

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
        'Wydruk kopii dokumentu elektronicznego (' . EZD_KOPIA_TRYBY[$tryb]['label'] . '): '
        . EZD_KOPIA_TYPES[$type] . ' „' . $meta['nazwa'] . '"'
    );
} catch (\Throwable $e) { /* audyt nie może blokować wydruku */ }

try {
    ezd_kopia_stream($meta, $user_id, [
        'tryb'      => $tryb,
        'watermark' => ($_GET['wm'] ?? '1') !== '0',
        'download'  => ($_GET['dl'] ?? '') === '1',
    ]);
} catch (EzdKopiaTrybException $e) {
    // Wybór trybu niemożliwy dla tego dokumentu (np. czysty wydruk pliku DOCX)
    // — to decyzja użytkownika, nie awaria, więc 422 z czytelnym komunikatem.
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><div style="font:14px/1.5 system-ui,sans-serif;padding:1.5rem;color:#1e293b">'
        . '<strong>Nie można sporządzić wydruku w tym trybie.</strong><p>' . htmlspecialchars($e->getMessage()) . '</p></div>');
} catch (\Throwable $e) {
    error_log('[ezd_kopia] ' . $e->getMessage());
    http_response_code(500);
    exit('Błąd generowania kopii: ' . htmlspecialchars($e->getMessage()));
}
