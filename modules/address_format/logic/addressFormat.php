<?php
/**
 * Normalizacja wielkości liter w polskich adresach i nazwach miejscowości
 * wg zasad ortografii (np. „UL. JANA PAWŁA II 12A" / „ul. jana pawła ii 12a"
 * → „ul. Jana Pawła II 12a", „NOWE MIASTO NAD PILICĄ" → „Nowe Miasto nad Pilicą").
 *
 * Reguły:
 *  - skróty typu ulicy i tytułów zawsze małą literą (ul., al., pl., os., gen., ks., św., im. …),
 *  - pozostałe wyrazy wielką literą, także człony z dywizem (Bielsko-Biała),
 *  - przyimki/spójniki (nad, pod, przy, i, w …) małą literą — chyba że otwierają nazwę (ul. Nad Stawem),
 *  - liczebniki rzymskie wielkimi (Jana III Sobieskiego), litera przy numerze domu małą (12a),
 *  - gdy wejście ma mieszaną wielkość liter, zapisane WIELKIMI skróty (≥2 litery, np. AK) zostają.
 * Funkcja jest idempotentna — poprawny zapis nie zmienia się.
 */

const ADDR_LOWER_ABBR = [
    'ul.', 'al.', 'pl.', 'os.', 'skr.', 'bulw.', 'wyb.', 'rondo.',
    'gen.', 'ks.', 'św.', 'im.', 'prof.', 'dr.', 'dr', 'bp.', 'abp.', 'kard.', 'marsz.',
    'płk.', 'ppłk.', 'mjr.', 'kpt.', 'por.', 'ppor.', 'hm.', 'bł.', 'o.', 's.', 'inż.', 'kmdr.', 'adm.',
    'nr', 'nr.', 'lok.', 'm.', 'k.', 'woj.', 'pow.', 'gm.',
];

/** Wyrazy po których zaczyna się właściwa nazwa (następny wyraz = początek nazwy). */
const ADDR_NAME_PREFIX = ['ul.', 'al.', 'pl.', 'os.', 'skr.', 'bulw.', 'wyb.', 'rondo.', 'woj.', 'pow.', 'gm.'];

const ADDR_LOWER_WORDS = [
    'i', 'w', 'we', 'z', 'ze', 'u', 'o', 'na', 'nad', 'pod', 'przy', 'przed', 'za',
    'od', 'do', 'koło', 'obok', 'pomiędzy', 'między', 'ku', 'po',
];

function normalizePlAddress(string $text): string {
    // Wielowierszowe pola (textarea) — każdy wiersz osobno, podział zostaje
    if (preg_match('/\R/u', $text)) {
        $lines = array_map('normalizePlAddress', preg_split('/\R/u', trim($text)));
        return implode("\n", array_filter($lines, fn($l) => $l !== ''));
    }
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') return '';
    // Przecinki: bez spacji przed, jedna spacja po
    $text = preg_replace('/\s*,\s*/u', ', ', $text);

    $mixedCase = mb_strtoupper($text) !== $text && mb_strtolower($text) !== $text;
    $atNameStart = true;

    return preg_replace_callback(
        '/[\p{L}\p{N}]+\.?|,/u',
        function (array $m) use ($mixedCase, &$atNameStart): string {
            $orig = $m[0];
            if ($orig === ',') { $atNameStart = true; return $orig; }

            $lower = mb_strtolower($orig);
            $wasNameStart = $atNameStart;
            $atNameStart = false;

            if (in_array($lower, ADDR_LOWER_ABBR, true)) {
                if (in_array($lower, ADDR_NAME_PREFIX, true)) $atNameStart = true;
                return $lower;
            }
            // Numery (domu, lokalu, kod pocztowy, „3" w „ul. 3 Maja") — litery małe
            if (preg_match('/^\p{N}/u', $orig)) {
                $atNameStart = $wasNameStart; // „ul. 3 Maja" — liczba nie zamyka początku nazwy
                return $lower;
            }
            // Skrótowce z mieszanego wejścia zostają (ul. AK, ZHP)
            if ($mixedCase && mb_strlen($orig) >= 2 && preg_match('/^\p{Lu}+\.?$/u', $orig)) {
                return $orig;
            }
            $bare = rtrim($lower, '.');
            if ($bare !== 'i' && $bare !== ''
                && preg_match('/^m{0,3}(cm|cd|d?c{0,3})(xc|xl|l?x{0,3})(ix|iv|v?i{0,3})$/', $bare)) {
                return mb_strtoupper($orig);
            }
            if (!$wasNameStart && in_array($lower, ADDR_LOWER_WORDS, true)) {
                return $lower;
            }
            return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
        },
        $text
    );
}

/** Nazwa miejscowości — te same reguły co adres. */
function normalizePlPlace(string $text): string {
    return normalizePlAddress($text);
}

/**
 * Kolumny adresowe w CRM (kontakty/kontrahenci), osobach i umowach — normalizowane
 * przy każdym db_insert()/db_update() oraz jednorazowo migracją istniejących danych.
 * Celowo POMINIĘTE: kody pocztowe, kraj, adresy e-Doręczeń (AE:PL-…), nazwy odbiorców.
 */
const ADDR_COLUMNS = [
    'crm_contacts'       => ['adres', 'addr_street', 'addr_house', 'addr_flat', 'addr_city'],
    'persons'            => ['adres', 'adres_korespondencyjny', 'addr_street', 'addr_house', 'addr_flat', 'addr_city'],
    'umowy_dzielo'       => ['adres', 'addr_street', 'addr_house', 'addr_flat', 'addr_city'],
    'umowy_zlecenie'     => ['adres', 'addr_street', 'addr_house', 'addr_flat', 'addr_city'],
    'umowy_praca'        => ['adres', 'addr_street', 'addr_house', 'addr_flat', 'addr_city'],
    'umowy_wolontariat'  => ['adres', 'addr_street', 'addr_house', 'addr_flat', 'addr_city',
                             'adres_linia1', 'adres_linia2', 'adres_miasto', 'zgoda_przedstawiciela_adres'],
    'umowy_inne'         => ['adres', 'addr_street', 'addr_house', 'addr_flat', 'addr_city'],
    'umowy_uslugi'       => ['adres', 'addr_street', 'addr_house', 'addr_flat', 'addr_city'],
    'umowy_powierzenie'  => ['organ_adres'],
    'contract_letters'   => ['postivo_adres', 'postivo_miasto'],
];

/** Normalizuje kolumny adresowe w danych zapisu do tabeli (reszta bez zmian). */
function normalizeAddressColumns(string $table, array $data): array {
    $cols = ADDR_COLUMNS[trim($table, '`')] ?? null;
    if (!$cols) return $data;
    foreach ($cols as $c) {
        if (isset($data[$c]) && is_string($data[$c]) && $data[$c] !== '') {
            $data[$c] = normalizePlAddress($data[$c]);
        }
    }
    return $data;
}

/**
 * Przechodzi po istniejących rekordach i poprawia pisownię adresów.
 * @return array<string,int> liczba zmienionych rekordów per tabela
 */
function normalizeAddressesInDb(PDO $pdo, bool $apply = true, ?callable $onChange = null): array {
    $stats = [];
    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    foreach (ADDR_COLUMNS as $table => $cols) {
        try {
            $existing = $isSqlite
                ? $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1)
                : $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) { continue; }
        $cols = array_values(array_intersect($cols, $existing));
        if (!$cols) continue;

        $changed = 0;
        $rows = $pdo->query("SELECT id, " . implode(', ', $cols) . " FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $diff = [];
            foreach ($cols as $c) {
                $v = $row[$c];
                if (!is_string($v) || $v === '') continue;
                $n = normalizePlAddress($v);
                if ($n !== $v) $diff[$c] = $n;
            }
            if (!$diff) continue;
            $changed++;
            if ($onChange) $onChange($table, (int)$row['id'], array_intersect_key($row, $diff), $diff);
            if ($apply) {
                $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($diff)));
                $pdo->prepare("UPDATE {$table} SET {$set} WHERE id = ?")
                    ->execute([...array_values($diff), $row['id']]);
            }
        }
        if ($changed) $stats[$table] = $changed;
    }
    return $stats;
}
