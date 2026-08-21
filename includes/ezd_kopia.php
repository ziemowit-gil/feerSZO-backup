<?php
/**
 * EZD — „Wydruk kopii dokumentu elektronicznego" (odwzorowanie cyfrowe).
 *
 * Wzorzec EZD RP: treść dokumentu z ukośnym znakiem wodnym na każdej stronie
 * + końcowa strona poświadczenia „Potwierdzam zgodność kopii z dokumentem
 * elektronicznym" z metryką (identyfikator, nazwa, tytuł, skrót SHA-256,
 * wersja, data dokumentu, akceptacja, data i autor wydruku).
 *
 * Obsługiwane typy dokumentów EZD („każdy dokument"):
 *   pismo | dokument | umowa | zalacznik (plik w repozytorium koszulki) |
 *   zaswiadczenie (zaświadczenie własne — tylko wydane)
 *
 * Wejście HTTP: /ezd/kopia.php?type=<typ>&id=<id>
 * Przycisk w listach/widokach: ezd_kopia_btn($type, $id)
 */

require_once __DIR__ . '/ezd.php';

const EZD_KOPIA_TYPES = [
    'pismo'     => 'Pismo',
    'dokument'  => 'Dokument wewnętrzny',
    'umowa'     => 'Umowa',
    'zalacznik' => 'Plik',
    'zaswiadczenie' => 'Zaświadczenie',
];

/** Domyślny tekst znaku wodnego na kopii (nadpisywalny w Ustawieniach EZD). */
const EZD_KOPIA_WATERMARK_DEFAULT = 'KOPIA ELEKTRONICZNA';

/** Rozszerzenia, których treść potrafimy odwzorować bezpośrednio w wydruku. */
const EZD_KOPIA_IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

/* ── Identyfikator dokumentu ─────────────────────────────────────────────── */

/**
 * Sól instancji użyta do wyliczania identyfikatorów kopii. Generowana raz,
 * przechowywana w `settings` — dzięki temu identyfikator tego samego dokumentu
 * jest stały między wydrukami, a nieodgadywalny spoza systemu.
 */
function ezd_kopia_salt(): string {
    $s = org_setting('ezd_kopia_salt');
    if ($s === '') {
        $s = bin2hex(random_bytes(16));
        org_setting_set('ezd_kopia_salt', $s);
    }
    return $s;
}

/** Stały 32-znakowy identyfikator dokumentu (jak GUID dokumentu w EZD RP). */
function ezd_kopia_ident(string $type, int $id): string {
    return md5('ezd-kopia|' . $type . '|' . $id . '|' . ezd_kopia_salt());
}

/* ── Metryka dokumentu ───────────────────────────────────────────────────── */

/**
 * Zbiera dane potrzebne do wydruku kopii dla wskazanego dokumentu.
 * @return array|null null, gdy typ nieznany albo dokument nie istnieje
 */
function ezd_kopia_resolve(string $type, int $id): ?array {
    if (!array_key_exists($type, EZD_KOPIA_TYPES) || $id <= 0) return null;

    switch ($type) {
        case 'pismo':     return _ezd_kopia_pismo($id);
        case 'dokument':  return _ezd_kopia_dokument($id);
        case 'umowa':     return _ezd_kopia_umowa($id);
        case 'zalacznik': return _ezd_kopia_zalacznik($id);
        case 'zaswiadczenie': return _ezd_kopia_zaswiadczenie($id);
    }
    return null;
}

/**
 * Uprawnienie oglądającego: 'read' | 'write' | null.
 * Reguła podstawowa to dostęp do koszulki dokumentu. Zaświadczenia własne mogą
 * nie mieć koszulki (sprawa_id NULL) — tam obowiązuje reguła jak w
 * ezd/zaswiadczenia/pdf.php: kancelaria/edytor albo autor wniosku.
 */
function ezd_kopia_access(array $meta, int $user_id): ?string {
    if (($meta['type'] ?? '') === 'zaswiadczenie') {
        if (ezd_is_manager($user_id) || can_edit())      return 'write';
        if ((int)($meta['_created_by'] ?? 0) === $user_id) return 'read';
        // brak własnej reguły — spróbuj przez koszulkę, jeśli zaświadczenie ją ma
    }
    $sprawa_id = (int)($meta['sprawa_id'] ?? 0);
    if ($sprawa_id <= 0) return null;
    $sprawa = ezd_sprawa_get($sprawa_id);
    return $sprawa ? ezd_sprawa_access($sprawa, $user_id) : null;
}

function _ezd_kopia_base(string $type, int $id, array $row): array {
    return [
        'type'          => $type,
        'id'            => $id,
        'sygnatura'     => (string)($row['sygnatura'] ?? ''),
        'sprawa_id'     => (int)$row['sprawa_id'],
        'znak_sprawy'   => (string)($row['znak_sprawy'] ?? ''),
        'sprawa_title'  => (string)($row['sprawa_title'] ?? ''),
        'identyfikator' => ezd_kopia_ident($type, $id),
        'typ_label'     => EZD_KOPIA_TYPES[$type],
    ];
}

/**
 * Skrót dokumentu bez pliku źródłowego — SHA-256 z kanonicznej serializacji
 * pól merytorycznych rekordu (kolejność pól jest częścią definicji skrótu).
 */
function _ezd_kopia_hash_fields(array $row, array $fields): string {
    $parts = [];
    foreach ($fields as $f) $parts[] = $f . '=' . (string)($row[$f] ?? '');
    return hash('sha256', implode("\n", $parts));
}

function _ezd_kopia_pismo(int $id): ?array {
    $p = ezd_pismo_get($id);
    if (!$p) return null;

    $wer = 1 + (int)(db_one("SELECT COUNT(*) AS c FROM ezd_pisma_wersje WHERE pismo_id=?", [$id])['c'] ?? 0);
    $kier = EZD_KIERUNKI[$p['kierunek']] ?? ['label' => $p['kierunek']];

    $rows = [
        'Kierunek'     => $kier['label'],
        'Nadawca'      => $p['nadawca'] ?? '',
        'Odbiorca'     => $p['odbiorca'] ?? '',
        'Data pisma'   => _ezd_kopia_date($p['data_pisma'] ?? null),
        'Data wpływu'  => _ezd_kopia_date($p['data_wplywu'] ?? null),
        'Data wysyłki' => _ezd_kopia_date($p['data_wysylki'] ?? null),
        'Referent'     => $p['owner_name'] ?? '',
    ];

    return _ezd_kopia_base('pismo', $id, $p) + [
        'nazwa'      => $p['sygnatura'] . ' — ' . $p['title'],
        'tytul'      => $p['title'],
        'skrot'      => _ezd_kopia_hash_fields($p, ['sygnatura','kierunek','title','tresc','nadawca','odbiorca','data_pisma','data_wplywu','data_wysylki']),
        'wersja'     => $wer . '.0',
        'data'       => substr((string)($p['data_pisma'] ?: $p['data_wplywu'] ?: $p['created_at']), 0, 10),
        'akceptacja' => _ezd_kopia_akceptacja_rekord('pismo', $id, $p, ['odpowiedziano' => 'Załatwione', 'archiwum' => 'Zarchiwizowane']),
        'source'     => ['kind' => 'html', 'html' => _ezd_kopia_content_html($p['sygnatura'], $p['title'], $rows, (string)$p['tresc'])],
    ];
}

function _ezd_kopia_dokument(int $id): ?array {
    $d = ezd_dokument_get($id);
    if (!$d) return null;

    $rows = [
        'Rodzaj'          => EZD_DOK_RODZAJE[$d['rodzaj']] ?? $d['rodzaj'],
        'Status'          => EZD_DOK_STATUSY[$d['status']]['label'] ?? $d['status'],
        'Autor/referent'  => $d['owner_name'] ?? '',
        'Utworzył'        => $d['creator_name'] ?? '',
        'Data utworzenia' => _ezd_kopia_date($d['created_at'] ?? null, true),
    ];

    return _ezd_kopia_base('dokument', $id, $d) + [
        'nazwa'      => $d['sygnatura'] . ' — ' . $d['title'],
        'tytul'      => $d['title'],
        'skrot'      => _ezd_kopia_hash_fields($d, ['sygnatura','rodzaj','title','tresc','status']),
        'wersja'     => '1.0',
        'data'       => substr((string)$d['created_at'], 0, 10),
        'akceptacja' => _ezd_kopia_akceptacja_rekord('dokument', $id, $d, ['zatwierdzony' => 'Zatwierdzony']),
        'source'     => ['kind' => 'html', 'html' => _ezd_kopia_content_html($d['sygnatura'], $d['title'], $rows, (string)$d['tresc'])],
    ];
}

function _ezd_kopia_umowa(int $id): ?array {
    $u = ezd_umowa_get($id);
    if (!$u) return null;

    $rows = [
        'Typ'                 => EZD_UMOWA_TYPY[$u['typ']] ?? $u['typ'],
        'Strona'              => $u['strona'] ?? '',
        'Wartość'             => $u['wartosc'] !== null ? number_format((float)$u['wartosc'], 2, ',', ' ') . ' ' . $u['waluta'] : '',
        'Data zawarcia'       => _ezd_kopia_date($u['data_zawarcia'] ?? null),
        'Obowiązuje od'       => _ezd_kopia_date($u['data_od'] ?? null),
        'Obowiązuje do'       => _ezd_kopia_date($u['data_do'] ?? null),
        'Warunki płatności'   => $u['warunki_platnosci'] ?? '',
        'Opiekun umowy'       => $u['owner_name'] ?? '',
    ];

    return _ezd_kopia_base('umowa', $id, $u) + [
        'nazwa'      => $u['sygnatura'] . ' — ' . $u['title'],
        'tytul'      => $u['title'],
        'skrot'      => _ezd_kopia_hash_fields($u, ['sygnatura','typ','title','strona','wartosc','waluta','data_zawarcia','data_od','data_do','warunki_platnosci','status']),
        'wersja'     => '1.0',
        'data'       => substr((string)($u['data_zawarcia'] ?: $u['created_at']), 0, 10),
        'akceptacja' => _ezd_kopia_akceptacja_rekord('umowa', $id, $u, ['aktywna' => 'Aktywna', 'wygasla' => 'Wygasła', 'rozwiazana' => 'Rozwiązana']),
        'source'     => ['kind' => 'meta'],
        'meta_rows'  => $rows,
    ];
}

function _ezd_kopia_zalacznik(int $id): ?array {
    $z = ezd_zal_get($id);
    if (!$z) return null;

    $sprawa = ezd_sprawa_get((int)$z['sprawa_id']);
    if (!$sprawa) return null;

    $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'];
    $ext  = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));

    if (is_file($path) && $ext === 'pdf')                                  $source = ['kind' => 'pdf',   'path' => $path];
    elseif (is_file($path) && in_array($ext, EZD_KOPIA_IMAGE_EXT, true))   $source = ['kind' => 'image', 'path' => $path];
    elseif (!is_file($path))                                              $source = ['kind' => 'none', 'note' => 'Pliku nie znaleziono w repozytorium systemu.'];
    else                                                                  $source = ['kind' => 'none', 'note' => 'Treści pliku w formacie „' . strtoupper($ext) . '" nie można odwzorować w wydruku. Kopię można sporządzić po konwersji pliku na PDF.'];

    // Tytuł dokumentu = nazwa pliku bez rozszerzenia; kontekst = pismo/umowa/dokument nadrzędny
    $ctx = '';
    if (!empty($z['pismo_id']))    { $p = db_one("SELECT sygnatura,title FROM ezd_pisma     WHERE id=?", [(int)$z['pismo_id']]);    if ($p) $ctx = 'pismo '     . $p['sygnatura'] . ' — ' . $p['title']; }
    elseif (!empty($z['umowa_id'])){ $p = db_one("SELECT sygnatura,title FROM ezd_umowy     WHERE id=?", [(int)$z['umowa_id']]);    if ($p) $ctx = 'umowa '     . $p['sygnatura'] . ' — ' . $p['title']; }
    elseif (!empty($z['dokument_id'])){ $p = db_one("SELECT sygnatura,title FROM ezd_dokumenty WHERE id=?", [(int)$z['dokument_id']]); if ($p) $ctx = 'dokument ' . $p['sygnatura'] . ' — ' . $p['title']; }

    return _ezd_kopia_base('zalacznik', $id, [
        'sprawa_id'    => (int)$z['sprawa_id'],
        'znak_sprawy'  => $sprawa['znak_sprawy'],
        'sprawa_title' => $sprawa['title'],
    ]) + [
        'nazwa'      => (string)$z['original_name'],
        'tytul'      => pathinfo((string)$z['original_name'], PATHINFO_FILENAME),
        'skrot'      => is_file($path) ? hash_file('sha256', $path) : '',
        'wersja'     => max(1, (int)$z['wersja']) . '.0',
        'data'       => substr((string)$z['uploaded_at'], 0, 10),
        'akceptacja' => _ezd_kopia_akceptacja_zalacznik($id, $path, (string)$z['original_name']),
        'kontekst'   => $ctx,
        'source'     => $source,
    ];
}

/**
 * Zaświadczenie własne (ezd_zaswiadczenia_wlasne). Kopię można sporządzić tylko
 * z zaświadczenia wydanego. Źródłem treści jest wgrany plik (jeśli wydano jako
 * plik własny) albo wydruk wygenerowany z szablonu — renderowany do pliku
 * tymczasowego, żeby style zaświadczenia nie mieszały się ze stroną poświadczenia.
 */
function _ezd_kopia_zaswiadczenie(int $id): ?array {
    require_once __DIR__ . '/zaswiadczenia_ezd.php';
    $z = ezd_zas_get($id);
    if (!$z || ($z['status'] ?? '') !== 'wydane') return null;

    $plik = trim((string)($z['plik_path'] ?? ''));
    $is_pdf_file = $plik !== '' && is_file($plik)
        && strtolower(pathinfo($plik, PATHINFO_EXTENSION)) === 'pdf';

    if ($is_pdf_file) {
        $source = ['kind' => 'pdf', 'path' => $plik];
        $skrot  = hash_file('sha256', $plik);
    } elseif ($plik !== '' && is_file($plik)) {
        $source = ['kind' => 'none', 'note' => 'Zaświadczenie wydano jako plik w formacie '
            . strtoupper(pathinfo($plik, PATHINFO_EXTENSION)) . ', którego treści nie można odwzorować w wydruku.'];
        $skrot  = hash_file('sha256', $plik);
    } else {
        $source = ['kind' => 'zas_html', 'zas' => $z];
        $skrot  = hash('sha256', (string)($z['tresc_html'] ?? ''));
    }

    $nr  = trim((string)($z['nr_zaswiadczenia'] ?? '')) ?: ('zaswiadczenie-' . $id);
    $typ = trim((string)($z['typ_nazwa'] ?? '')) ?: 'Zaświadczenie';

    $akc = null;
    if (!empty($z['zatwierdzone_at'])) {
        $akc = [
            'przez'    => trim((string)($z['zatw_name'] ?? '')) ?: '—',
            'data'     => _ezd_kopia_date($z['zatwierdzone_at'], true),
            'wersja'   => '',
            'podstawa' => 'Zaświadczenie zatwierdzone i wydane',
        ];
    }

    return [
        'type'          => 'zaswiadczenie',
        'id'            => $id,
        'sprawa_id'     => (int)($z['sprawa_id'] ?? 0),
        'znak_sprawy'   => (string)($z['znak_sprawy'] ?? ''),
        'sprawa_title'  => '',
        'identyfikator' => ezd_kopia_ident('zaswiadczenie', $id),
        'typ_label'     => EZD_KOPIA_TYPES['zaswiadczenie'],
        '_created_by'   => (int)($z['created_by'] ?? 0),
        'sygnatura'     => $nr,
        'nazwa'         => $nr . ' — ' . $typ,
        'tytul'         => $typ,
        'skrot'         => $skrot,
        'wersja'        => '1.0',
        'data'          => substr((string)($z['zatwierdzone_at'] ?: $z['created_at']), 0, 10),
        'kontekst'      => trim((string)($z['wnioskodawca_name'] ?? '')) !== ''
                             ? 'wniosek: ' . $z['wnioskodawca_name'] : '',
        'akceptacja'    => $akc,
        'source'        => $source,
    ];
}

/* ── Akceptacja ──────────────────────────────────────────────────────────── */

/**
 * Akceptacja pliku: kwalifikowany podpis elektroniczny w samym pliku ma
 * pierwszeństwo, następnie zamknięty obieg podpisu (ezd_sign_requests).
 * @return array{przez:string,data:string,wersja:string,podstawa:string}|null
 */
function _ezd_kopia_akceptacja_zalacznik(int $zal_id, string $path, string $name): ?array {
    if (is_file($path)) {
        require_once __DIR__ . '/sigcheck.php';
        $sig = ezd_signature_info($path, $name);
        if (!empty($sig['signed'])) {
            return [
                'przez'    => trim((string)($sig['signer'] ?? '')) ?: 'podpis elektroniczny (nie ustalono podpisującego)',
                'data'     => _ezd_kopia_date($sig['signed_at'] ?? null, true),
                'wersja'   => '',
                'podstawa' => 'Podpisany elektronicznie' . ($sig['type'] ? ' (' . $sig['type'] . ')' : ''),
            ];
        }
    }

    $r = db_one(
        "SELECT r.status, r.signed_at, r.confirmed_at, ut.name AS signer, uc.name AS confirmer
           FROM ezd_sign_requests r
           LEFT JOIN users ut ON ut.id = r.requested_to
           LEFT JOIN users uc ON uc.id = r.confirmed_by
          WHERE (r.zal_id = ? OR r.signed_zal_id = ?)
            AND r.status IN ('podpisane','potwierdzone')
          ORDER BY r.id DESC LIMIT 1",
        [$zal_id, $zal_id]
    );
    if ($r) {
        $potw = $r['status'] === 'potwierdzone';
        return [
            'przez'    => trim((string)($r['signer'] ?? '')) ?: '—',
            'data'     => _ezd_kopia_date(($potw ? $r['confirmed_at'] : $r['signed_at']) ?? null, true),
            'wersja'   => '',
            'podstawa' => $potw ? 'Podpis potwierdzony przez ' . (trim((string)($r['confirmer'] ?? '')) ?: '—') : 'Podpisany w obiegu podpisu EZD',
        ];
    }
    return null;
}

/**
 * Akceptacja pisma/dokumentu/umowy: zamknięta dekretacja „do akceptacji" ma
 * pierwszeństwo, następnie status rekordu uznany za akceptujący.
 * @param array<string,string> $accepting_statuses status => etykieta
 */
function _ezd_kopia_akceptacja_rekord(string $type, int $id, array $row, array $accepting_statuses): ?array {
    if (in_array($type, ['pismo', 'umowa'], true)) {
        $col = $type === 'pismo' ? 'pismo_id' : 'umowa_id';
        $d = db_one(
            "SELECT d.completed_at, u.name AS wykonawca
               FROM ezd_dekretacje d LEFT JOIN users u ON u.id = d.wykonawca_id
              WHERE d.$col = ? AND d.dyspozycja = 'do_akcept' AND d.completed_at IS NOT NULL
              ORDER BY d.completed_at DESC LIMIT 1",
            [$id]
        );
        if ($d) {
            return [
                'przez'    => trim((string)($d['wykonawca'] ?? '')) ?: '—',
                'data'     => _ezd_kopia_date($d['completed_at'], true),
                'wersja'   => '',
                'podstawa' => 'Dekretacja „Do akceptacji" zrealizowana',
            ];
        }
    }

    $st = (string)($row['status'] ?? '');
    if ($st !== '' && isset($accepting_statuses[$st])) {
        return [
            'przez'    => trim((string)($row['owner_name'] ?? $row['creator_name'] ?? '')) ?: '—',
            'data'     => _ezd_kopia_date($row['updated_at'] ?? $row['created_at'] ?? null, true),
            'wersja'   => '',
            'podstawa' => 'Status dokumentu: ' . $accepting_statuses[$st],
        ];
    }
    return null;
}

/* ── Formatowanie ────────────────────────────────────────────────────────── */

function _ezd_kopia_date($v, bool $with_time = false): string {
    $v = trim((string)($v ?? ''));
    if ($v === '') return '';
    $t = strtotime($v);
    if ($t === false) return $v;
    return date($with_time ? 'Y-m-d H:i' : 'Y-m-d', $t);
}

/** Opis autora wydruku: „Imię Nazwisko (Stanowisko) KOD JEDNOSTKI". */
function ezd_kopia_autor(int $user_id): string {
    $u = db_one("SELECT name FROM users WHERE id=?", [$user_id]);
    $name = trim((string)($u['name'] ?? '')) ?: ('user#' . $user_id);

    $pos = ''; $unit = '';
    if (is_file(__DIR__ . '/org.php')) {
        require_once __DIR__ . '/org.php';
        try {
            $units = org_user_units($user_id);
            if ($units) {
                $m    = $units[0];
                $pos  = trim((string)($m['position_name'] ?? '')) ?: trim((string)($m['position_label'] ?? ''));
                $unit = trim((string)($m['unit_code'] ?? '')) ?: trim((string)($m['unit_name'] ?? ''));
            }
        } catch (\Throwable $e) { /* struktura organizacyjna niedostępna */ }
    }

    $out = $name;
    if ($pos !== '')  $out .= ' (' . $pos . ')';
    if ($unit !== '') $out .= ' ' . $unit;
    return $out;
}

/** Treść dokumentu tekstowego (pismo/dokument) jako HTML dla mPDF. */
function _ezd_kopia_content_html(string $sygnatura, string $title, array $rows, string $tresc): string {
    $org = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

    $meta = '';
    foreach ($rows as $k => $v) {
        if (trim((string)$v) === '') continue;
        $meta .= '<tr><td class="k">' . h($k) . '</td><td class="v">' . h((string)$v) . '</td></tr>';
    }

    $body = trim($tresc) !== ''
        ? '<div class="tresc">' . nl2br(h($tresc)) . '</div>'
        : '<div class="brak">Dokument nie zawiera treści tekstowej.</div>';

    return '<div class="doc">'
        . ($org !== '' ? '<div class="org">' . h($org) . '</div>' : '')
        . '<div class="syg">' . h($sygnatura) . '</div>'
        . '<h1>' . h($title) . '</h1>'
        . ($meta !== '' ? '<table class="metatab">' . $meta . '</table>' : '')
        . $body
        . '</div>';
}

/**
 * Strona poświadczenia zgodności kopii — układ jak w EZD RP.
 *
 * Dwa tryby autoryzacji:
 *  - elektroniczny (domyślny): klauzulę zgodności autoryzuje system, podając
 *    w metryce autora wydruku;
 *  - odręczny ($manual = true): system NIE autoryzuje kopii — zamiast wiersza
 *    „Autor wydruku" wstawia miejsce na miejscowość, datę, dane i podpis osoby
 *    potwierdzającej zgodność. Do czasu podpisania taki wydruk jest jawnie
 *    oznaczony jako kopia nieuwierzytelniona.
 */
function ezd_kopia_cert_html(array $meta, int $user_id, bool $manual = false): string {
    $a = $meta['akceptacja'] ?? null;

    $row = function (string $label, string $value, bool $mono = false) {
        return '<tr><td class="lbl">' . h($label) . '</td>'
             . '<td class="val' . ($mono ? ' mono' : '') . '">' . h($value) . '</td></tr>';
    };

    $html = '<div class="cert">';

    if ($manual) {
        $html .= '<div class="cert-nieuw">KOPIA NIEUWIERZYTELNIONA — do potwierdzenia podpisem odręcznym</div>';
    }

    $html .= '<div class="cert-h">Potwierdzam zgodność kopii z dokumentem elektronicznym:</div>'
        . '<table class="cert-t">'
        . $row('Identyfikator dokumentu', $meta['identyfikator'], true)
        . $row('Nazwa dokumentu',  (string)$meta['nazwa'])
        . $row('Tytuł dokumentu',  (string)$meta['tytul'])
        . $row('Skrót dokumentu (SHA-256)', (string)($meta['skrot'] ?: '—'), true)
        . $row('Wersja dokumentu', (string)$meta['wersja'])
        . $row('Data dokumentu',   (string)($meta['data'] ?: '—'));

    $znak = trim((string)($meta['znak_sprawy'] ?? ''));
    if ($znak !== '') {
        $st = trim((string)($meta['sprawa_title'] ?? ''));
        $html .= $row('Znak sprawy (koszulka)', $znak . ($st !== '' ? ' — ' . $st : ''));
    }

    if (!empty($meta['kontekst'])) {
        $html .= $row('Dokument nadrzędny', (string)$meta['kontekst']);
    }

    if ($a) {
        $sub = '<tr><td class="lbl2">Zaakceptowany przez</td><td class="val">' . h($a['przez']) . '</td></tr>';
        if (trim((string)$a['data']) !== '')     $sub .= '<tr><td class="lbl2">Data akceptacji</td><td class="val">' . h($a['data']) . '</td></tr>';
        if (trim((string)$a['wersja']) !== '')   $sub .= '<tr><td class="lbl2">Wersja dokumentu akceptacji</td><td class="val">' . h($a['wersja']) . '</td></tr>';
        if (trim((string)$a['podstawa']) !== '') $sub .= '<tr><td class="lbl2">Podstawa akceptacji</td><td class="val">' . h($a['podstawa']) . '</td></tr>';
        $html .= '<tr><td class="lbl">Akceptacja</td><td class="nest">'
               . '<table class="cert-sub">' . $sub . '</table></td></tr>';
    } else {
        $html .= $row('Akceptacja', 'Dokument nie został zaakceptowany w systemie.');
    }

    $html .= '<tr><td class="lbl"></td><td class="val sys">' . h(_ezd_kopia_system_label()) . '</td></tr>'
        . $row('Data wydruku', date('Y-m-d'));

    // Autoryzacja: elektroniczna (system podaje autora) albo odręczna (miejsce na podpis)
    if (!$manual) {
        $html .= $row('Autor wydruku', ezd_kopia_autor($user_id));
    }

    $html .= '</table>';

    if ($manual) $html .= _ezd_kopia_sig_block();

    return $html . '</div>';
}

/** Blok podpisu odręcznego pod metryką — miejscowość i data, dane osoby, podpis. */
function _ezd_kopia_sig_block(): string {
    return '<table class="sig-t">'
        . '<tr>'
        . '<td class="sig-line">&nbsp;</td><td class="sig-gap"></td><td class="sig-line">&nbsp;</td>'
        . '</tr><tr>'
        . '<td class="sig-cap">miejscowość i data</td><td class="sig-gap"></td>'
        . '<td class="sig-cap">imię, nazwisko i stanowisko osoby<br>potwierdzającej zgodność kopii</td>'
        . '</tr><tr>'
        . '<td class="sig-spacer" colspan="3">&nbsp;</td>'
        . '</tr><tr>'
        . '<td class="sig-gap"></td><td class="sig-gap"></td><td class="sig-line">&nbsp;</td>'
        . '</tr><tr>'
        . '<td class="sig-gap"></td><td class="sig-gap"></td>'
        . '<td class="sig-cap">podpis</td>'
        . '</tr></table>'
        . '<div class="cert-uwaga">Kopia nieuwierzytelniona. Bez podpisu odręcznego osoby '
        . 'potwierdzającej zgodność niniejszy wydruk nie stanowi poświadczonej kopii dokumentu '
        . 'elektronicznego — jest wyłącznie jego odwzorowaniem.</div>';
}

function _ezd_kopia_system_label(): string {
    $org = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    return trim(($org !== '' ? $org . ' · ' : '') . 'EZD ' . (defined('APP_VERSION') ? APP_VERSION : ''));
}

function _ezd_kopia_css(): string {
    return <<<'CSS'
body { font-family: dejavusans, sans-serif; font-size: 10.5pt; color: #000; }
.doc .org  { font-weight: bold; font-size: 11pt; }
.doc .syg  { font-family: monospace; font-size: 8.5pt; color: #444; margin-top: 2pt; }
.doc h1    { font-size: 13pt; margin: 10pt 0 8pt; }
.metatab   { width: 100%; border-collapse: collapse; margin-bottom: 12pt; font-size: 9pt; }
.metatab td{ border: 0.4pt solid #999; padding: 3pt 5pt; vertical-align: top; }
.metatab .k{ width: 32%; background: #f2f2f2; color: #333; }
.doc .tresc{ font-size: 10.5pt; line-height: 1.45; text-align: justify; }
.doc .brak { font-size: 9.5pt; font-style: italic; color: #555; }
.nofile    { border: 0.5pt solid #999; background: #f7f7f7; padding: 10pt; font-size: 9.5pt; }

.cert-h    { font-weight: bold; font-size: 11pt; margin-bottom: 10pt; }
.cert-t    { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
.cert-t > tr > td, .cert-t td { border: 0.5pt solid #808080; padding: 4pt 6pt; vertical-align: middle; }
.cert-t .lbl { width: 30%; background: #f2f2f2; text-align: right; }
.cert-t .val { text-align: left; }
.cert-t .mono{ font-family: monospace; font-size: 8.5pt; word-break: break-all; }
.cert-t .sys { text-align: right; color: #333; }
.cert-t .nest{ padding: 0; }
.cert-sub  { width: 100%; border-collapse: collapse; }
.cert-sub td { border: 0.5pt solid #808080; padding: 4pt 6pt; }
.cert-sub .lbl2 { width: 42%; background: #f2f2f2; text-align: right; }

.cert-nieuw { border: 0.8pt solid #333; background: #f2f2f2; padding: 5pt 8pt; margin-bottom: 10pt;
              text-align: center; font-weight: bold; font-size: 9pt; letter-spacing: 0.05em; }
.cert-uwaga { margin-top: 10pt; font-size: 8pt; font-style: italic; color: #333; text-align: justify; }
.sig-t             { width: 100%; border-collapse: collapse; margin-top: 22pt; font-size: 8.5pt; }
.sig-t td          { border: 0; padding: 0; vertical-align: bottom; }
/* Selektory kwalifikowane klasą td — inaczej „.sig-t td{border:0}" wygrywa specyficznością
   i zjada dolną krawędź, czyli całą linię do podpisu. */
.sig-t td.sig-line { width: 40%; border-bottom: 0.6pt dotted #000; padding-top: 26pt; }
.sig-t td.sig-gap  { width: 20%; }
.sig-t td.sig-cap  { text-align: center; color: #333; font-size: 7.5pt; padding-top: 2pt; vertical-align: top; }
.sig-t td.sig-spacer { padding-top: 22pt; }
CSS;
}

/* ── Generowanie PDF ─────────────────────────────────────────────────────── */

/**
 * Rejestr plików tymczasowych żywotnych do końca generowania PDF-a.
 * FPDI trzyma otwarty uchwyt do zaimportowanego pliku i przepisuje jego obiekty
 * dopiero w Output() — dlatego kasujemy je PO wypisaniu dokumentu, nie wcześniej.
 * @param string|null $add ścieżka do zarejestrowania; null = pobierz i wyczyść listę
 * @return string[]
 */
function _ezd_kopia_tmp_files(?string $add = null): array {
    static $files = [];
    if ($add !== null) { $files[] = $add; return $files; }
    $out = $files; $files = []; return $out;
}


/** Tekst znaku wodnego kopii (ustawienie `ezd_kopia_watermark`). */
function ezd_kopia_watermark(): string {
    $w = trim(org_setting('ezd_kopia_watermark'));
    return $w !== '' ? $w : EZD_KOPIA_WATERMARK_DEFAULT;
}

/**
 * Buduje i streamuje PDF kopii do przeglądarki (inline).
 * @param array $opts ['watermark'=>bool, 'download'=>bool, 'manual'=>bool]
 *                    manual = kopia bez autoryzacji elektronicznej, z miejscem
 *                    na podpis odręczny (patrz ezd_kopia_cert_html()).
 * @throws \Throwable
 */
function ezd_kopia_stream(array $meta, int $user_id, array $opts = []): void {
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $tmp = UPLOAD_DIR . 'mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);

    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 20,
        'margin_right'  => 20,
        'margin_top'    => 18,
        'margin_bottom' => 18,
        'default_font'  => 'dejavusans',
        'tempDir'       => $tmp,
    ]);
    $manual = (bool)($opts['manual'] ?? false);
    $mpdf->SetTitle('Kopia dokumentu elektronicznego'
        . ($manual ? ' (do podpisu odręcznego)' : '') . ' — ' . $meta['nazwa']);
    $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
    $mpdf->SetCreator('EZD ' . (defined('APP_VERSION') ? APP_VERSION : ''));

    if (($opts['watermark'] ?? true)) {
        $mpdf->watermark_font    = 'dejavusans';
        $mpdf->watermarkTextAlpha = 0.10;
        $mpdf->SetWatermarkText(ezd_kopia_watermark(), 0.10);
        $mpdf->showWatermarkText = true;
    }
    $mpdf->WriteHTML(_ezd_kopia_css(), \Mpdf\HTMLParserMode::HEADER_CSS);

    $src = $meta['source'] ?? ['kind' => 'none', 'note' => ''];

    switch ($src['kind']) {
        case 'pdf':
            _ezd_kopia_append_pdf($mpdf, (string)$src['path'], $meta);
            break;

        case 'image':
            _ezd_kopia_append_image($mpdf, (string)$src['path'], $meta);
            break;

        case 'meta':
            $mpdf->AddPage();
            $mpdf->WriteHTML(_ezd_kopia_content_html(
                (string)($meta['sygnatura'] ?? ''),
                (string)$meta['tytul'],
                (array)($meta['meta_rows'] ?? []),
                ''
            ));
            break;

        case 'html':
            $mpdf->AddPage();
            $mpdf->WriteHTML((string)$src['html']);
            break;

        case 'zas_html':
            // Zaświadczenie ma własny, kompletny dokument HTML (z <style>) — renderujemy
            // je osobnym przebiegiem mPDF do pliku tymczasowego i dokładamy jako strony,
            // żeby jego style nie nadpisały arkusza strony poświadczenia.
            $tmp_zas = _ezd_kopia_render_zas_pdf((array)$src['zas']);
            if ($tmp_zas !== null) {
                _ezd_kopia_tmp_files($tmp_zas);
                _ezd_kopia_append_pdf($mpdf, $tmp_zas, $meta);
            } else {
                _ezd_kopia_page_note($mpdf, $meta, 'Nie udało się wygenerować wydruku zaświadczenia z szablonu.');
            }
            break;

        default:
            _ezd_kopia_page_note($mpdf, $meta,
                ((string)($src['note'] ?? 'Treści dokumentu nie można odwzorować.'))
                . ' Poświadczenie na następnej stronie dotyczy metryki dokumentu w systemie EZD.');
    }

    // Strona poświadczenia — zawsze A4 pionowo, niezależnie od formatu treści.
    // Znak wodny obejmuje tylko odwzorowanie treści (jak w EZD RP): AddPage domykając
    // poprzednią stronę rysuje jej stopkę ze znakiem wodnym, więc wyłączenie flagi
    // dopiero teraz zdejmuje znak wodny wyłącznie ze strony poświadczenia.
    $mpdf->AddPageByArray([
        'orientation' => 'P',
        'newformat'   => 'A4',
        'mgl' => 20, 'mgr' => 20, 'mgt' => 18, 'mgb' => 18, 'mgh' => 0, 'mgf' => 0,
    ]);
    $mpdf->showWatermarkText = false;
    $mpdf->WriteHTML(ezd_kopia_cert_html($meta, $user_id, (bool)($opts['manual'] ?? false)));

    $fname = 'kopia' . ($manual ? '_do_podpisu' : '') . '_'
        . preg_replace('/[^a-zA-Z0-9\-_]+/', '_', (string)$meta['nazwa']) . '.pdf';
    try {
        $mpdf->Output($fname, ($opts['download'] ?? false)
            ? \Mpdf\Output\Destination::DOWNLOAD
            : \Mpdf\Output\Destination::INLINE);
    } finally {
        foreach (_ezd_kopia_tmp_files() as $f) @unlink($f);
    }
}

/**
 * Renderuje wydruk zaświadczenia do pliku tymczasowego (te same marginesy i
 * czcionka co ezd/zaswiadczenia/pdf.php). Zwraca ścieżkę albo null.
 */
function _ezd_kopia_render_zas_pdf(array $zas): ?string {
    try {
        require_once __DIR__ . '/zaswiadczenia_ezd.php';
        $tmpdir = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($tmpdir)) @mkdir($tmpdir, 0755, true);

        $inner = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 25,
            'margin_right'  => 25,
            'margin_top'    => 20,
            'margin_bottom' => 20,
            'default_font'  => 'dejavusans',
            'tempDir'       => $tmpdir,
        ]);
        $inner->WriteHTML(ezd_zas_pdf_html($zas, false));

        $out = tempnam(sys_get_temp_dir(), 'ezdzas_') . '.pdf';
        $inner->Output($out, \Mpdf\Output\Destination::FILE);
        return is_file($out) && filesize($out) > 0 ? $out : null;
    } catch (\Throwable $e) {
        error_log('[ezd_kopia] render zas: ' . $e->getMessage());
        return null;
    }
}

/**
 * Dokłada skan/zdjęcie jako pełnowymiarową stronę. Obraz wpisywany jest w obszar
 * A4 (z zachowaniem proporcji), a strona przyjmuje orientację obrazu — jak przy
 * odwzorowaniu papierowego dokumentu wciągniętego do EZD.
 */
function _ezd_kopia_append_image(\Mpdf\Mpdf $mpdf, string $path, array $meta): void {
    $dim = @getimagesize($path);
    $land = $dim && (int)$dim[0] > (int)$dim[1];

    $mpdf->AddPageByArray([
        'orientation' => $land ? 'L' : 'P',
        'newformat'   => 'A4',
        'mgl' => 12, 'mgr' => 12, 'mgt' => 12, 'mgb' => 12, 'mgh' => 0, 'mgf' => 0,
    ]);

    // Konwencja projektu dla mPDF: obrazy osadzane jako data: URI (bez sięgania do FS)
    $mime = @mime_content_type($path) ?: 'application/octet-stream';
    $data = @file_get_contents($path);
    if ($data === false) {
        $mpdf->WriteHTML('<div class="nofile">Nie udało się odczytać pliku obrazu z repozytorium.</div>');
        return;
    }

    $mpdf->WriteHTML(
        '<div class="doc"><div class="syg">' . h((string)$meta['nazwa']) . '</div></div>'
        . '<div style="text-align:center"><img src="data:' . $mime . ';base64,' . base64_encode($data) . '"'
        . ' style="max-width:' . ($land ? '265' : '180') . 'mm"></div>'
    );
}

/**
 * Dokłada strony źródłowego PDF-a jako szablony (zachowuje oryginalny format
 * strony).
 *
 * Kolejność jak w „Spinaczu" (ezd_merge_pdf_files): najpierw qpdf, bo darmowy
 * parser FPDI nie czyta skompresowanego cross-reference (PDF 1.5+ — czyli
 * praktycznie każdego pliku z Worda, Chrome'a czy LibreOffice). Gdy qpdf nie ma
 * na serwerze, próbujemy wprost FPDI (starsze PDF-y ≤1.4 przechodzą).
 *
 * Otwarcie pliku (setSourceFile) jest oddzielone od dokładania stron, żeby
 * ponowna próba nigdy nie zdublowała stron już dołożonych.
 */
function _ezd_kopia_append_pdf(\Mpdf\Mpdf $mpdf, string $path, array $meta): void {
    $norm = _ezd_kopia_qpdf_normalize($path);
    $cnt  = 0;

    foreach (array_filter([$norm, $path]) as $candidate) {
        try { $cnt = (int)$mpdf->setSourceFile($candidate); }
        catch (\Throwable $e) { $cnt = 0; error_log('[ezd_kopia] setSourceFile: ' . $e->getMessage()); }
        if ($cnt > 0) break;
    }

    if ($cnt < 1) {
        if ($norm !== null) @unlink($norm);   // nic nie zaimportowano — można kasować od razu
        _ezd_kopia_page_note($mpdf, $meta,
            'Nie udało się odwzorować treści pliku PDF w wydruku kopii — plik używa kompresji '
            . 'nieobsługiwanej przez wbudowany parser (potrzebny jest qpdf na serwerze) albo jest '
            . 'zaszyfrowany bądź uszkodzony. Poświadczenie na następnej stronie dotyczy metryki '
            . 'dokumentu w systemie EZD; skrót SHA-256 wyliczono z oryginalnego pliku.');
        return;
    }

    for ($i = 1; $i <= $cnt; $i++) {
        try {
            $tpl  = $mpdf->importPage($i);
            $size = $mpdf->getTemplateSize($tpl);
            // Format podany wprost jako [szerokość, wysokość] — orientacja MUSI zostać
            // 'P', inaczej mPDF zamieni wymiary miejscami (_setPageSize) i obróci stronę.
            $mpdf->AddPageByArray([
                'orientation' => 'P',
                'newformat'   => [$size['width'], $size['height']],
                'mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0, 'mgh' => 0, 'mgf' => 0,
            ]);
            $mpdf->useTemplate($tpl);
        } catch (\Throwable $e) {
            error_log('[ezd_kopia] strona ' . $i . ': ' . $e->getMessage());
            _ezd_kopia_page_note($mpdf, $meta,
                'Strony ' . $i . ' z ' . $cnt . ' nie udało się odwzorować w wydruku kopii.');
        }
    }

    // Plik znormalizowany zostaje na dysku do końca Output() — patrz _ezd_kopia_tmp_files().
    if ($norm !== null) _ezd_kopia_tmp_files($norm);
}

/** Normalizuje PDF przez qpdf (odszyfrowanie + rozpakowanie strumieni). */
function _ezd_kopia_qpdf_normalize(string $path): ?string {
    $qpdf = _ezd_qpdf_bin();
    if (!$qpdf) return null;

    $out = tempnam(sys_get_temp_dir(), 'ezdkopia_') . '.pdf';
    $cmd = escapeshellarg($qpdf) . ' --warning-exit-0 --decrypt --object-streams=disable'
         . ' --stream-data=uncompress ' . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>&1';
    $o = []; $rc = 1; @exec($cmd, $o, $rc);

    if ($rc === 0 && is_file($out) && filesize($out) > 0) return $out;
    if (is_file($out)) @unlink($out);
    return null;
}

/** Strona informacyjna zamiast błędu 500, gdy treści nie da się odwzorować. */
function _ezd_kopia_page_note(\Mpdf\Mpdf $mpdf, array $meta, string $note): void {
    $mpdf->AddPageByArray([
        'orientation' => 'P', 'newformat' => 'A4',
        'mgl' => 20, 'mgr' => 20, 'mgt' => 18, 'mgb' => 18, 'mgh' => 0, 'mgf' => 0,
    ]);
    $mpdf->WriteHTML(
        '<div class="doc"><div class="syg">' . h((string)$meta['nazwa']) . '</div>'
        . '<h1>' . h((string)$meta['tytul']) . '</h1>'
        . '<div class="nofile">' . h($note) . '</div></div>'
    );
}

/* ── UI ──────────────────────────────────────────────────────────────────── */

/**
 * Przyciski „Wydruk kopii" — jedno źródło wyglądu dla wszystkich list i widoków.
 * Zawsze udostępnia OBA tryby autoryzacji (grupa dwóch przycisków), bo wybór
 * należy do osoby drukującej:
 *   1. kopia autoryzowana elektronicznie (metryka z autorem wydruku),
 *   2. kopia bez autoryzacji — z miejscem na podpis odręczny (`reczny=1`).
 * Oba otwierają PDF we współdzielonym modalu podglądu (includes/ezd_pdf_modal.php).
 *
 * @param string $style 'icon' (kompaktowy, do wierszy list) | 'label' (z podpisem)
 */
function ezd_kopia_btn(string $type, int $id, string $name = '', string $style = 'icon', string $extra_cls = ''): string {
    return '<span class="btn-group" role="group" aria-label="Wydruk kopii dokumentu elektronicznego">'
        . ezd_kopia_btn_one($type, $id, $name, $style, $extra_cls, false)
        . ezd_kopia_btn_one($type, $id, $name, $style, $extra_cls, true)
        . '</span>';
}

/**
 * Pojedynczy przycisk wydruku kopii w wybranym trybie autoryzacji.
 * @param bool $manual true = kopia bez autoryzacji, do podpisu odręcznego
 */
function ezd_kopia_btn_one(string $type, int $id, string $name = '', string $style = 'icon', string $extra_cls = '', bool $manual = false): string {
    $url = APP_URL . '/ezd/kopia.php?type=' . urlencode($type) . '&id=' . (int)$id
         . ($manual ? '&reczny=1' : '');
    $cls = trim('btn ' . ($manual ? 'btn-outline-secondary' : 'btn-outline-dark') . ' ezd-pdf-btn ' . $extra_cls);

    $tytul = $manual
        ? 'Wydruk kopii BEZ autoryzacji elektronicznej — z miejscem na podpis odręczny'
        : 'Wydruk kopii dokumentu elektronicznego (autoryzacja elektroniczna, z poświadczeniem zgodności)';

    if ($style === 'label') {
        $lbl = $manual
            ? '<i class="bi bi-pen me-1"></i>Kopia do podpisu'
            : '<i class="bi bi-printer me-1"></i>Wydruk kopii';
    } else {
        $lbl = $manual ? '<i class="bi bi-pen"></i>' : '<i class="bi bi-printer"></i>';
    }

    $mname = ($manual ? 'Kopia do podpisu — ' : 'Kopia — ')
           . ($name !== '' ? $name : (EZD_KOPIA_TYPES[$type] ?? $type));

    return '<a href="' . h($url) . '" class="' . h($cls) . '"'
         . ' title="' . h($tytul) . '"'
         . ' data-url="' . h($url) . '"'
         . ' data-name="' . h($mname) . '">'
         . $lbl . '</a>';
}
