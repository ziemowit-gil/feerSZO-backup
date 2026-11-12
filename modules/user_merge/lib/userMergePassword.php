<?php
/**
 * modules/user_merge/lib/userMergePassword.php — hasło tymczasowe po scaleniu kont.
 *
 * Zgodne z polityką SZO (tozsamosc/index.php: ≥ 12 znaków, wielka i mała litera, cyfra, znak specjalny, bez imienia,
 * nazwiska i loginu) i domyślną polityką Entra ID (8–256 znaków, 3 z 4 grup). Bez znaków mylących (I/l/1, O/0) i bez
 * znaków rozszerzonych GSM-7 (^{}[]~\|€), które bramki SMS liczą podwójnie albo przekłamują. Losowość: random_int (CSPRNG).
 * Hasło istnieje tylko w pamięci żądania — w bazie wyłącznie hash (password_hash).
 */

const USER_MERGE_PW_UPPER   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const USER_MERGE_PW_LOWER   = 'abcdefghijkmnopqrstuvwxyz';
const USER_MERGE_PW_DIGITS  = '23456789';
const USER_MERGE_PW_SPECIAL = '!#%*+-=?@';

/** Czy hasło spełnia politykę (też dla testów). $avoid — człony imienia, nazwiska i loginu (> 2 znaki). */
function userMergePasswordValid(string $p, array $avoid = []): bool {
    if (strlen($p) < 12) return false;
    if (!preg_match('/[A-Z]/', $p) || !preg_match('/[a-z]/', $p) || !preg_match('/[0-9]/', $p) || !preg_match('/[^A-Za-z0-9]/', $p)) return false;
    foreach ($avoid as $part) {
        if (mb_strlen($part) > 2 && mb_stripos($p, $part) !== false) return false;
    }
    return true;
}

/** Człony do wykluczenia z hasła: imię, nazwisko, nazwa, login (część adresu przed @). */
function userMergePasswordAvoid(array $u): array {
    $src = implode(' ', [(string)($u['first_name'] ?? ''), (string)($u['last_name'] ?? ''), (string)($u['name'] ?? ''), (string)($u['email'] ?? '')]);
    return array_values(array_unique(array_filter(preg_split('/[\s@.\-_]+/u', mb_strtolower($src)) ?: [], fn($p) => mb_strlen($p) > 2)));
}

function userMergeGeneratePassword(int $len = 16, array $avoid = []): string {
    $len  = max(12, $len);
    $sets = [USER_MERGE_PW_UPPER, USER_MERGE_PW_LOWER, USER_MERGE_PW_DIGITS, USER_MERGE_PW_SPECIAL];
    $all  = implode('', $sets);
    for ($try = 0; $try < 50; $try++) {
        $chars = [];
        foreach ($sets as $s) $chars[] = $s[random_int(0, strlen($s) - 1)];   // po jednym z każdej grupy
        while (count($chars) < $len) $chars[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($chars) - 1; $i > 0; $i--) {                           // tasowanie Fisher–Yates na CSPRNG
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        $p = implode('', $chars);
        if (userMergePasswordValid($p, $avoid)) return $p;
    }
    throw new RuntimeException('Nie udało się wygenerować hasła tymczasowego.');
}
