<?php
/**
 * includes/crm_sender_trust.php — czy nadawca wiadomości jest „nasz".
 *
 * W Skrzynce CRM większość pracy to ocena, czy wiadomość w ogóle warto brać.
 * Dwie rzeczy rozstrzygają to od razu:
 *   • adres w domenie organizacji (@feer.org.pl) — pisze ktoś z fundacji,
 *   • adres występujący w którejś z umów — pisze zleceniobiorca, wolontariusz,
 *     pracownik albo kontrahent, z którym mamy podpisany dokument.
 *
 * Takiego nadawcę oznaczamy jako ADRES AUTORYZOWANY. To podpowiedź dla człowieka,
 * nie mechanizm bezpieczeństwa — nagłówek From da się podrobić, więc nie wolno
 * na tej podstawie niczego automatycznie dopuszczać.
 *
 * Domeny: `m365_domain` z ustawień M365 + opcjonalna lista `crm_trusted_domains`
 * (po przecinku) w settings.
 */

/** Domeny uznawane za wewnętrzne (małymi literami, bez @). */
function crm_trusted_domains(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $out = [];
    try {
        require_once __DIR__ . '/m365.php';
        $d = strtolower(trim((string)m365_setting('m365_domain')));
        if ($d !== '') $out[] = ltrim($d, '@');
    } catch (\Throwable $e) {}

    try {
        $extra = (string)(db_one("SELECT value FROM settings WHERE key_='crm_trusted_domains'")['value'] ?? '');
        foreach (preg_split('/[\s,;]+/', strtolower($extra)) as $d) {
            $d = ltrim(trim($d), '@');
            if ($d !== '') $out[] = $d;
        }
    } catch (\Throwable $e) {}

    if (!$out) $out[] = 'feer.org.pl';   // środowisko bez skonfigurowanego M365
    return $cache = array_values(array_unique($out));
}

/** Domena adresu, małymi literami ('' gdy adres bez sensu). */
function _crm_email_domain(string $email): string {
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    return $at === false ? '' : substr($email, $at + 1);
}

/**
 * Sprawdza wiele adresów naraz — jedno zapytanie na tabelę umów zamiast jednego
 * na wiadomość. Lista w skrzynce ma 20 wierszy, więc to realna różnica.
 *
 * @param  array $emails Adresy (dowolna wielkość liter)
 * @return array [adres małymi literami => ['level','label','title','contract'=>?array]]
 */
function crm_sender_trust_bulk(array $emails): array {
    static $cache = [];

    $emails = array_values(array_unique(array_filter(array_map(
        static fn($e) => strtolower(trim((string)$e)),
        $emails
    ), static fn($e) => $e !== '' && str_contains($e, '@'))));
    if (!$emails) return [];

    $out  = [];
    $todo = [];
    foreach ($emails as $e) {
        if (isset($cache[$e])) { $out[$e] = $cache[$e]; continue; }
        $todo[] = $e;
    }
    if (!$todo) return $out;

    $domains = crm_trusted_domains();

    // 1) Domena organizacji — bez pytania bazy
    $rest = [];
    foreach ($todo as $e) {
        if (in_array(_crm_email_domain($e), $domains, true)) {
            $out[$e] = $cache[$e] = [
                'level'    => 'internal',
                'label'    => 'adres wewnętrzny',
                'title'    => 'Adres w domenie organizacji (' . _crm_email_domain($e) . ') — pisze ktoś z fundacji',
                'contract' => null,
            ];
        } else {
            $rest[] = $e;
        }
    }
    if (!$rest) return $out;

    // 2) Adresy z umów — po jednym zapytaniu na typ umowy
    $ph   = implode(',', array_fill(0, count($rest), '?'));
    $hits = [];

    foreach (CONTRACT_TYPES as $slug => $type_label) {
        $table = 'umowy_' . $slug;
        try {
            $cols = array_column(db_all("PRAGMA table_info({$table})"), 'name');
        } catch (\Throwable $e) { continue; }
        if (!$cols || !in_array('email', $cols, true)) continue;

        // Nazwy kolumn różnią się między typami umów — bierzemy to, co istnieje.
        $name_col = null;
        foreach (['nazwa_wykonawcy', 'imie_nazwisko'] as $c) {
            if (in_array($c, $cols, true)) { $name_col = $c; break; }
        }
        $sel = 'email'
             . (in_array('numer_umowy', $cols, true) ? ', numer_umowy' : ", '' AS numer_umowy")
             . (in_array('status', $cols, true)      ? ', status'      : ", '' AS status")
             . ($name_col                            ? ", {$name_col} AS strona" : ", '' AS strona");

        try {
            $rows = db_all(
                "SELECT {$sel} FROM {$table}
                 WHERE email IS NOT NULL AND email <> '' AND lower(email) IN ({$ph})", $rest
            );
        } catch (\Throwable $e) { continue; }

        foreach ($rows as $r) {
            $e = strtolower(trim((string)$r['email']));
            // Pierwsza znaleziona umowa wystarcza; nie licytujemy się o „lepszą".
            if (isset($hits[$e])) continue;
            $hits[$e] = [
                'type'   => $type_label,
                'numer'  => (string)($r['numer_umowy'] ?? ''),
                'strona' => (string)($r['strona'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
            ];
        }
    }

    foreach ($rest as $e) {
        if (isset($hits[$e])) {
            $c = $hits[$e];
            $title = 'Adres z umowy: ' . $c['type']
                   . ($c['numer']  !== '' ? ' nr ' . $c['numer'] : '')
                   . ($c['strona'] !== '' ? ' — ' . $c['strona'] : '')
                   . ($c['status'] !== '' ? ' (' . $c['status'] . ')' : '');
            $out[$e] = $cache[$e] = [
                'level' => 'contract', 'label' => 'adres z umowy', 'title' => $title, 'contract' => $c,
            ];
        } else {
            $out[$e] = $cache[$e] = ['level' => '', 'label' => '', 'title' => '', 'contract' => null];
        }
    }

    return $out;
}

/** Wersja dla jednego adresu. */
function crm_sender_trust(?string $email): array {
    $e = strtolower(trim((string)$email));
    if ($e === '' || !str_contains($e, '@')) {
        return ['level' => '', 'label' => '', 'title' => '', 'contract' => null];
    }
    $r = crm_sender_trust_bulk([$e]);
    return $r[$e] ?? ['level' => '', 'label' => '', 'title' => '', 'contract' => null];
}

/**
 * Gotowa plakietka do listy i nagłówka wiadomości.
 *
 * @param string $size 'sm' = sama ikonka (lista), 'md' = ikonka z opisem (podgląd)
 */
function crm_sender_trust_badge(array $trust, string $size = 'sm'): string {
    if (empty($trust['level'])) return '';

    $internal = $trust['level'] === 'internal';
    $icon     = $internal ? 'bi-shield-check' : 'bi-file-earmark-check';
    $cls      = 'ib-trust ib-trust--' . ($internal ? 'int' : 'doc');
    $title    = h((string)$trust['title']);

    if ($size === 'sm') {
        return '<span class="' . $cls . '" title="Adres autoryzowany — ' . $title . '">'
             . '<i class="bi ' . $icon . '" aria-hidden="true"></i>'
             . '<span class="visually-hidden">Adres autoryzowany</span></span>';
    }
    return '<span class="' . $cls . ' ib-trust--lg" title="' . $title . '">'
         . '<i class="bi ' . $icon . '" aria-hidden="true"></i>'
         . 'adres autoryzowany · ' . h((string)$trust['label'])
         . '</span>';
}
