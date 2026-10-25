<?php
/**
 * modules/field_crypto/lib/fieldCrypto.php — szyfrowanie pól z danymi osobowymi w bazie (funkcjonalność współdzielona).
 *
 * Format zaszyfrowanej wartości:  enc1:<kid>:<base64url(nonce12 | tag16 | szyfrogram)>
 *   • AES-256-GCM (uwierzytelnione) — każda zmiana szyfrogramu jest wykrywana przy odczycie;
 *   • AAD = kontekst „tabela.kolumna” — szyfrogram skopiowany do innej kolumny nie da się odczytać;
 *   • kid = 8 znaków skrótu klucza — wskazuje, którym kluczem zaszyfrowano (rotacja bez przerwy w działaniu).
 *
 * Klucze (config.local.php):
 *   DATA_KEY           — 64 znaki hex (php -r "echo bin2hex(random_bytes(32));"). Bez niego używany jest APP_KEY,
 *                        co działa, ale wymiana APP_KEY unieważniłaby dane — dlatego DATA_KEY ma być osobny.
 *   DATA_KEY_PREVIOUS  — poprzednie klucze (po przecinku), tylko do odczytu podczas rotacji (cli/fieldCrypto.php rotate).
 * Z kluczem głównym wyprowadzane są HKDF-em osobne klucze: szyfrujący i do indeksów ślepych.
 *
 * Indeks ślepy (fcBlind) — HMAC-SHA256 znormalizowanej wartości: pozwala szukać „czy taki PESEL/e-mail już jest”
 * bez odszyfrowywania kolumny. Nie nadaje się do wyszukiwania częściowego (LIKE) ani sortowania.
 *
 * Wartość bez prefiksu enc1: jest traktowana jako dawny tekst jawny (fcDecrypt zwraca ją bez zmian), więc kolumny
 * można szyfrować stopniowo (cli/fieldCrypto.php encrypt) bez przerwy w działaniu systemu.
 */

const FC_PREFIX = 'enc1:';

/** Wszystkie dostępne klucze główne: [kid => surowy klucz 32 B]; pierwszy = bieżący. */
function fcMasterKeys(): array {
    static $keys = null;
    if ($keys !== null) return $keys;
    $keys = [];
    $add = function (string $secret) use (&$keys): void {
        $raw = (strlen($secret) === 64 && ctype_xdigit($secret)) ? (string)hex2bin($secret) : hash('sha256', $secret, true);
        $keys[substr(hash('sha256', $raw), 0, 8)] = $raw;
    };
    $cur = defined('DATA_KEY') && DATA_KEY !== '' ? (string)DATA_KEY : (defined('APP_KEY') ? (string)APP_KEY : '');
    if ($cur === '') throw new RuntimeException('Brak DATA_KEY (ani APP_KEY) w konfiguracji — nie można szyfrować danych.');
    $add($cur);
    if (defined('DATA_KEY_PREVIOUS')) {
        foreach (explode(',', (string)DATA_KEY_PREVIOUS) as $p) if (trim($p) !== '') $add(trim($p));
    }
    return $keys;
}

function fcCurrentKid(): string { return (string)array_key_first(fcMasterKeys()); }

/** Czy używany jest osobny DATA_KEY (a nie awaryjnie APP_KEY). */
function fcHasDedicatedKey(): bool { return defined('DATA_KEY') && DATA_KEY !== ''; }

function fcSubKey(string $raw, string $purpose): string {
    return hash_hkdf('sha256', $raw, 32, 'szo-field-crypto/' . $purpose);
}

function fcIsEncrypted(?string $v): bool { return $v !== null && str_starts_with($v, FC_PREFIX); }

function fcB64(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function fcUnB64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/'), true); }

/** Szyfruje wartość; null i pusty tekst zostają bez zmian, już zaszyfrowana nie jest szyfrowana drugi raz. */
function fcEncrypt(?string $plain, string $context): ?string {
    if ($plain === null || $plain === '' || fcIsEncrypted($plain)) return $plain;
    $keys = fcMasterKeys();
    $kid = (string)array_key_first($keys);
    $key = fcSubKey($keys[$kid], 'enc');
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
    if ($ct === false) throw new RuntimeException('Szyfrowanie nie powiodło się.');
    return FC_PREFIX . $kid . ':' . fcB64($nonce . $tag . $ct);
}

/**
 * Odszyfrowuje wartość. Tekst jawny (bez prefiksu) wraca bez zmian. Zwraca null, gdy brak klucza o danym kid
 * albo szyfrogram jest uszkodzony / z innej kolumny (zły kontekst).
 */
function fcDecrypt(?string $stored, string $context): ?string {
    if ($stored === null || $stored === '' || !fcIsEncrypted($stored)) return $stored;
    $parts = explode(':', $stored, 3);
    if (count($parts) !== 3) return null;
    $keys = fcMasterKeys();
    if (!isset($keys[$parts[1]])) return null;
    $raw = fcUnB64($parts[2]);
    if (strlen($raw) < 28) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', fcSubKey($keys[$parts[1]], 'enc'), OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, 12, 16), $context);
    return $plain === false ? null : $plain;
}

/** Szyfrogram wymaga przeszyfrowania, gdy użyto starego klucza. */
function fcNeedsRotation(?string $stored): bool {
    if (!fcIsEncrypted($stored)) return false;
    return (explode(':', (string)$stored, 3)[1] ?? '') !== fcCurrentKid();
}

/** Normalizacja przed indeksem: cyfry (PESEL, NIP, telefon), małe litery (e-mail), reszta — bez spacji na brzegach. */
function fcNormalize(string $v, string $kind): string {
    $v = trim($v);
    return match ($kind) {
        'digits' => (string)preg_replace('/\D+/', '', $v),
        'email'  => mb_strtolower($v),
        default  => mb_strtolower((string)preg_replace('/\s+/', ' ', $v)),
    };
}

/** Indeks ślepy (hex) do wyszukiwania równościowego po zaszyfrowanej kolumnie; '' dla pustej wartości. */
function fcBlind(?string $value, string $context, string $kind = 'text'): string {
    if ($value === null || trim($value) === '') return '';
    $keys = fcMasterKeys();
    return hash_hmac('sha256', $context . "\0" . fcNormalize($value, $kind), fcSubKey(reset($keys), 'blind'));
}

/** Indeksy ślepy wyliczone wszystkimi kluczami (bieżącym i poprzednimi) — do wyszukiwania w czasie rotacji. */
function fcBlindAll(?string $value, string $context, string $kind = 'text'): array {
    if ($value === null || trim($value) === '') return [];
    $out = [];
    foreach (fcMasterKeys() as $raw) {
        $out[] = hash_hmac('sha256', $context . "\0" . fcNormalize($value, $kind), fcSubKey($raw, 'blind'));
    }
    return array_values(array_unique($out));
}

/** Maskowanie do wyświetlania (PESEL: ******12345 → ostatnie 3 cyfry; e-mail: j***@d***.pl). */
function fcMask(?string $v, string $kind = 'text'): string {
    $v = (string)$v;
    if ($v === '') return '';
    if ($kind === 'digits') return str_repeat('•', max(0, strlen($v) - 3)) . substr($v, -3);
    if ($kind === 'email' && str_contains($v, '@')) {
        [$l, $d] = explode('@', $v, 2);
        return mb_substr($l, 0, 1) . '•••@' . mb_substr($d, 0, 1) . '•••' . (str_contains($d, '.') ? substr($d, (int)strrpos($d, '.')) : '');
    }
    return mb_substr($v, 0, 1) . str_repeat('•', max(2, mb_strlen($v) - 1));
}
