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
