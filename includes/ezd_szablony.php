<?php
/**
 * includes/ezd_szablony.php — szablony pism + korespondencja seryjna (mail merge).
 *
 * Szablon zawiera wzorzec tytułu i treści pisma z tokenami {{token}}, które przy
 * użyciu są podstawiane danymi sprawy, organizacji i (w trybie seryjnym) adresata.
 * Renderowany szablon prefilluje formularz nowego pisma lub, w trybie seryjnym,
 * generuje wiele pism wychodzących naraz (po jednym na adresata).
 *
 * Tabela ezd_szablony tworzona jest w centralnej auto-migracji includes/ezd.php.
 */

const EZD_SZABLON_KATEGORIE = [
    'pismo'    => 'Pismo',
    'dokument' => 'Dokument wewnętrzny',
];

/**
 * Dostępne tokeny — grupa => [token => opis]. Sterują też pomocą w edytorze.
 */
const EZD_SZABLON_TOKENY = [
    'Sprawa' => [
        'znak_sprawy'   => 'Znak sprawy',
        'nasz_znak'     => 'Nasz znak (= znak sprawy)',
        'sprawa_tytul'  => 'Tytuł sprawy',
        'teczka'        => 'Segregator (symbol + tytuł)',
        'jrwa'          => 'Symbol JRWA',
        'referent'      => 'Referent sprawy',
    ],
    'Adresat' => [
        'odbiorca'      => 'Odbiorca (w trybie seryjnym — z listy)',
        'znak_obcy'     => 'Znak pisma adresata',
    ],
    'Data' => [
        'data'          => 'Data dzisiejsza (RRRR-MM-DD)',
        'data_dl'       => 'Data słownie (np. 11 lipca 2026 r.)',
        'rok'           => 'Bieżący rok',
        'miejscowosc'   => 'Miejscowość organizacji',
    ],
    'Organizacja' => [
        'org_nazwa'     => 'Nazwa organizacji',
        'org_adres'     => 'Adres',
        'org_email'     => 'E-mail',
        'org_tel'       => 'Telefon',
        'org_www'       => 'Strona WWW',
        'org_nip'       => 'NIP',
        'org_krs'       => 'KRS',
        'org_regon'     => 'REGON',
    ],
];

/** Data słownie po polsku, np. „11 lipca 2026 r.". */
function ezd_data_slownie(?string $ymd = null): string {
    $ts = $ymd ? strtotime($ymd) : time();
    if ($ts === false) $ts = time();
    $miesiace = [1=>'stycznia','lutego','marca','kwietnia','maja','czerwca',
                 'lipca','sierpnia','września','października','listopada','grudnia'];
    return (int)date('j', $ts) . ' ' . $miesiace[(int)date('n', $ts)] . ' ' . date('Y', $ts) . ' r.';
}

/**
 * Buduje mapę token => wartość dla danej sprawy (+ opcjonalne nadpisania, np.
 * {{odbiorca}} w trybie seryjnym).
 */
function ezd_szablon_context(?array $sprawa, array $extra = []): array {
    $jrwaSym = '';
    if ($sprawa && !empty($sprawa['jrwa_id'])) {
        $j = ezd_jrwa_get((int)$sprawa['jrwa_id']);
        if ($j) $jrwaSym = $j['symbol'];
    }
    $teczka = '';
    if ($sprawa && !empty($sprawa['teczka_symbol'])) {
        $teczka = trim($sprawa['teczka_symbol'] . ' — ' . ($sprawa['teczka_title'] ?? ''), ' —');
    }
    $miejsc = (string)(org_setting('org_miejscowosc') ?: org_setting('org_miasto') ?: '');

    $ctx = [
        'znak_sprawy'  => $sprawa['znak_sprawy'] ?? '',
        'nasz_znak'    => $sprawa['znak_sprawy'] ?? '',
        'sprawa_tytul' => $sprawa['title'] ?? '',
        'teczka'       => $teczka,
        'jrwa'         => $jrwaSym,
        'referent'     => $sprawa['owner_name'] ?? '',
        'odbiorca'     => '',
        'znak_obcy'    => '',
        'data'         => date('Y-m-d'),
        'data_dl'      => ezd_data_slownie(),
        'rok'          => date('Y'),
        'miejscowosc'  => $miejsc,
        'org_nazwa'    => (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '')),
        'org_adres'    => (string)org_setting('org_adres'),
        'org_email'    => (string)org_setting('org_email'),
        'org_tel'      => (string)(org_setting('org_tel') ?: org_setting('org_telefon')),
        'org_www'      => (string)org_setting('org_www'),
        'org_nip'      => (string)org_setting('org_nip'),
        'org_krs'      => (string)org_setting('org_krs'),
        'org_regon'    => (string)org_setting('org_regon'),
    ];
    foreach ($extra as $k => $v) $ctx[$k] = (string)$v;
    return $ctx;
}

/**
 * Podstawia tokeny {{key}} (dozwolone spacje: {{ key }}). Nieznane tokeny
 * pozostają nietknięte, aby autor je zauważył.
 */
function ezd_szablon_render(string $tpl, array $ctx): string {
    return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($ctx) {
        $k = strtolower($m[1]);
        return array_key_exists($k, $ctx) ? $ctx[$k] : $m[0];
    }, $tpl);
}

// ── CRUD ─────────────────────────────────────────────────────────────────────

function ezd_szablony_all(bool $active_only = false, string $kategoria = ''): array {
    $w = []; $p = [];
    if ($active_only)  $w[] = 'aktywny=1';
    if ($kategoria)   { $w[] = 'kategoria=?'; $p[] = $kategoria; }
    $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    return db_all(
        "SELECT s.*, u.name AS created_name FROM ezd_szablony s
         LEFT JOIN users u ON u.id=s.created_by
         $where ORDER BY s.kategoria, s.nazwa", $p
    );
}

function ezd_szablon_get(int $id): ?array {
    return db_one("SELECT * FROM ezd_szablony WHERE id=?", [$id]);
}

function ezd_szablon_create(array $d, int $user_id): int {
    db()->prepare(
        "INSERT INTO ezd_szablony (nazwa,kategoria,kierunek,rodzaj_medium,tytul_wzor,tresc_wzor,opis,aktywny,created_by)
         VALUES (:nazwa,:kat,:kier,:med,:tyt,:tresc,:opis,:akt,:by)"
    )->execute([
        ':nazwa' => trim($d['nazwa'] ?? ''),
        ':kat'   => array_key_exists($d['kategoria'] ?? '', EZD_SZABLON_KATEGORIE) ? $d['kategoria'] : 'pismo',
        ':kier'  => array_key_exists($d['kierunek'] ?? '', EZD_KIERUNKI) ? $d['kierunek'] : 'wychodzace',
        ':med'   => array_key_exists($d['rodzaj_medium'] ?? '', EZD_MEDIA) ? $d['rodzaj_medium'] : 'papier',
        ':tyt'   => trim($d['tytul_wzor'] ?? ''),
        ':tresc' => (string)($d['tresc_wzor'] ?? ''),
        ':opis'  => trim($d['opis'] ?? ''),
        ':akt'   => !empty($d['aktywny']) ? 1 : 0,
        ':by'    => $user_id,
    ]);
    $id = (int)db()->lastInsertId();
    ezd_log(null, null, null, null, $user_id, 'szablon_create', 'Szablon: ' . trim($d['nazwa'] ?? ''));
    return $id;
}

function ezd_szablon_update(int $id, array $d, int $user_id): void {
    db()->prepare(
        "UPDATE ezd_szablony SET nazwa=:nazwa,kategoria=:kat,kierunek=:kier,rodzaj_medium=:med,
                tytul_wzor=:tyt,tresc_wzor=:tresc,opis=:opis,aktywny=:akt,updated_at=CURRENT_TIMESTAMP
         WHERE id=:id"
    )->execute([
        ':nazwa' => trim($d['nazwa'] ?? ''),
        ':kat'   => array_key_exists($d['kategoria'] ?? '', EZD_SZABLON_KATEGORIE) ? $d['kategoria'] : 'pismo',
        ':kier'  => array_key_exists($d['kierunek'] ?? '', EZD_KIERUNKI) ? $d['kierunek'] : 'wychodzace',
        ':med'   => array_key_exists($d['rodzaj_medium'] ?? '', EZD_MEDIA) ? $d['rodzaj_medium'] : 'papier',
        ':tyt'   => trim($d['tytul_wzor'] ?? ''),
        ':tresc' => (string)($d['tresc_wzor'] ?? ''),
        ':opis'  => trim($d['opis'] ?? ''),
        ':akt'   => !empty($d['aktywny']) ? 1 : 0,
        ':id'    => $id,
    ]);
    ezd_log(null, null, null, null, $user_id, 'szablon_update', 'Szablon #' . $id);
}

function ezd_szablon_delete(int $id, int $user_id): void {
    $s = ezd_szablon_get($id);
    db()->prepare("DELETE FROM ezd_szablony WHERE id=?")->execute([$id]);
    ezd_log(null, null, null, null, $user_id, 'szablon_delete', 'Szablon: ' . ($s['nazwa'] ?? $id));
}
