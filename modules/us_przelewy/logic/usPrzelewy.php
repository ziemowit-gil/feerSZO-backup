<?php
/**
 * modules/us_przelewy/logic/usPrzelewy.php — Przelewy do Urzędu Skarbowego (EODoK › Płatności).
 *
 * Rejestr zobowiązań podatkowych (PIT-4R, PIT-8AR, CIT, VAT…) z okresem, kwotą i terminem, plik przelewu
 * podatkowego dla banku i statusy do zapłaty → zlecony → zapłacony. Bez obiegu akceptacji (jak składki ZUS —
 * kwota wynika z deklaracji). Plik powstaje przez wspólny eksport EODoK (edok_przelewy_export) w formatach
 * ELIXIR-O, z kodem 71 i tytułem /TI/…/OKR/…/SFP/…/TXT/… (edok_elixir_tytul / edok_elixir_kod).
 *
 * Ustawienia organizacji (org_setting): usp_rachunek — rachunek podatkowy (mikrorachunek NBP), usp_odbiorca —
 * nazwa odbiorcy w przelewie, usp_przypomnienia — adresy e-mail przypomnień (puste = wyłączone),
 * usp_przypomnienia_dni — ile dni przed terminem. NIP = org_nip. Symbole formularzy: Słownik Centralny
 * (słownik us_formularze, atrybuty sfp / okres / termin_dzien / termin_mies).
 */

require_once dirname(__DIR__, 3) . '/includes/edok.php';
require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';

const USP_DICT = 'us_formularze';

/** Zasiew i zapas słownika: kod => [etykieta, symbol w przelewie, okres M/K/R, dzień terminu, miesięcy po końcu okresu]. */
const USP_FORMULARZE_DEFAULT = [
    'pit4r'  => ['PIT-4R — zaliczki na podatek dochodowy (wynagrodzenia, zlecenia)', 'PIT4R',  'M', 20, 1],
    'pit8ar' => ['PIT-8AR — zryczałtowany podatek dochodowy',                       'PIT8AR', 'M', 20, 1],
    'cit'    => ['CIT — zaliczka miesięczna',                                       'CIT8',   'M', 20, 1],
    'cit8'   => ['CIT-8 — podatek roczny (dopłata)',                                'CIT8',   'R', 31, 3],
    'vat7'   => ['VAT — JPK_V7M (miesięczny)',                                      'VAT7',   'M', 25, 1],
    'vat7k'  => ['VAT — JPK_V7K (kwartalny)',                                       'VAT7K',  'K', 25, 1],
    'vat8'   => ['VAT-8 — VAT od nabyć (podatnik zwolniony)',                       'VAT8',   'M', 25, 1],
    'vat9m'  => ['VAT-9M — VAT od importu usług',                                   'VAT9M',  'M', 25, 1],
];

const USP_STATUSY = [
    'do_zaplaty' => ['label' => 'Do zapłaty', 'badge' => 'text-bg-warning'],
    'zlecony'    => ['label' => 'Zlecony',    'badge' => 'text-bg-info'],
    'zaplacony'  => ['label' => 'Zapłacony',  'badge' => 'text-bg-success'],
    'anulowany'  => ['label' => 'Anulowany',  'badge' => 'text-bg-secondary'],
];

/** Formaty pliku dla przelewu podatkowego — tylko ELIXIR-O (kod 71); XML-e nie mają ujednoliconego oznaczenia US. */
const USP_FORMATY = ['auto', 'elixir', 'ipko', 'millenet'];

const USP_ODBIORCA_DOMYSLNY = 'URZĄD SKARBOWY';

function uspMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    edok_migrate();
    if (szo_schema_current('us_przelewy_migrate', __FILE__)) return;
    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS us_zobowiazania (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        formularz       TEXT    NOT NULL,
        sfp             TEXT    NOT NULL,
        okres_typ       TEXT    NOT NULL DEFAULT 'M',
        okres_rok       INTEGER NOT NULL,
        okres_nr        INTEGER NOT NULL DEFAULT 0,
        kwota           TEXT    NOT NULL,
        termin          TEXT    NOT NULL,
        rachunek        TEXT    NOT NULL,
        identyfikator   TEXT    NOT NULL,
        txt             TEXT    NOT NULL DEFAULT '',
        status          TEXT    NOT NULL DEFAULT 'do_zaplaty',
        data_zaplaty    TEXT,
        rachunek_z      TEXT    NOT NULL DEFAULT '',
        plik_format     TEXT    NOT NULL DEFAULT '',
        plik_nazwa      TEXT    NOT NULL DEFAULT '',
        plik_path       TEXT    NOT NULL DEFAULT '',
        zlecono_at      TEXT,
        edok_doc_id     INTEGER,
        zalacznik_path  TEXT    NOT NULL DEFAULT '',
        zalacznik_nazwa TEXT    NOT NULL DEFAULT '',
        uwagi           TEXT    NOT NULL DEFAULT '',
        przypomniano    TEXT    NOT NULL DEFAULT '',
        created_by      INTEGER,
        created_name    TEXT    NOT NULL DEFAULT '',
        created_at      TEXT,
        updated_at      TEXT
    )");
    // Drugi załącznik: wyliczenie kwoty (obok deklaracji w zalacznik_*).
    _edok_add_columns($db, 'us_zobowiazania', [
        'wyliczenie_path'  => "TEXT NOT NULL DEFAULT ''",
        'wyliczenie_nazwa' => "TEXT NOT NULL DEFAULT ''",
    ]);
    $db->exec("CREATE INDEX IF NOT EXISTS idx_us_zob_status ON us_zobowiazania(status, termin)");
    $db->exec("CREATE TABLE IF NOT EXISTS us_zobowiazania_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        zob_id     INTEGER NOT NULL,
        akcja      TEXT    NOT NULL,
        opis       TEXT    NOT NULL DEFAULT '',
        user_id    INTEGER,
        user_name  TEXT    NOT NULL DEFAULT '',
        created_at TEXT
    )");
}

/** Dostęp: administrator i osoby zatwierdzające do zapłaty (EODoK lub archiwum KDOK) — jak Preliminarz. */
function uspCanAccess(): bool {
    if (is_admin()) return true;
    if (edok_has_role('zatwierdza')) return true;
    if (!function_exists('kdok_has_role')) @require_once dirname(__DIR__, 3) . '/includes/ksiegowosc.php';
    return function_exists('kdok_has_role') && kdok_has_role('zatwierdza');
}

// ── Ustawienia ────────────────────────────────────────────────────────────────

function uspNip(): string {
    return preg_replace('/\D/', '', (string) org_setting('org_nip'));
}

/** Rachunek podatkowy z ustawień ('' = nieskonfigurowany). */
function uspRachunek(): string {
    return preg_replace('/\D/', '', (string) org_setting('usp_rachunek'));
}

function uspOdbiorca(): string {
    return trim((string) org_setting('usp_odbiorca')) ?: USP_ODBIORCA_DOMYSLNY;
}

/**
 * Mikrorachunek podatkowy NBP wyliczony z NIP: 1010 0071 + „222” + typ identyfikatora (2 = NIP)
 * + NIP + „00”, z sumą kontrolną IBAN. '' gdy NIP nie ma 10 cyfr.
 */
function uspMikrorachunek(string $nip): string {
    $nip = preg_replace('/\D/', '', $nip);
    if (strlen($nip) !== 10) return '';
    $bban = '10100071' . '222' . '2' . $nip . '00';
    $mod = 0;
    foreach (str_split($bban . '252100', 7) as $chunk) $mod = (int)(($mod . $chunk) % 97);
    return sprintf('%02d', 98 - $mod) . $bban;
}

/** Ostrzeżenia do numeru rachunku podatkowego (nie blokują zapisu — US bywa też innym rachunkiem). */
function uspRachunekUwagi(string $nrb): array {
    $nrb = preg_replace('/\D/', '', $nrb);
    $uw = [];
    if (substr($nrb, 2, 8) !== '10100071') $uw[] = 'Rachunek nie jest w NBP — urzędy skarbowe mają rachunki w NBP (1010 0071).';
    $mikro = uspMikrorachunek(uspNip());
    if ($mikro !== '' && substr($nrb, 2, 11) === '10100071222' && $nrb !== $mikro) {
        $uw[] = 'To mikrorachunek innego podmiotu — dla NIP organizacji mikrorachunek to ' . edok_nrb_format($mikro) . '.';
    }
    return $uw;
}

/** Zapis ustawień z formularza. Zwraca listę błędów (pustą przy sukcesie). */
function uspSaveSettings(array $post): array {
    $nrb = preg_replace('/\D/', '', (string)($post['rachunek'] ?? ''));
    $errors = [];
    if (!edok_nrb_valid($nrb)) $errors[] = 'Numer rachunku US jest nieprawidłowy (26 cyfr z poprawną sumą kontrolną IBAN).';
    $emails = [];
    foreach (preg_split('/[\s,;]+/', (string)($post['przypomnienia'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy adres e-mail przypomnień: ' . $e;
        else $emails[] = strtolower($e);
    }
    $dni = max(0, min(30, (int)($post['przypomnienia_dni'] ?? 3)));
    if ($errors) return $errors;
    $old = uspRachunek();
    org_setting_set('usp_rachunek', $nrb);
    org_setting_set('usp_odbiorca', mb_substr(trim((string)($post['odbiorca'] ?? '')), 0, 70));
    org_setting_set('usp_przypomnienia', implode(', ', array_unique($emails)));
    org_setting_set('usp_przypomnienia_dni', (string)$dni);
    if ($old !== $nrb) uspLog(0, 'ustawienia', 'Rachunek US: ' . ($old !== '' ? edok_nrb_format($old) : '—') . ' → ' . edok_nrb_format($nrb));
    return [];
}

// ── Formularze (Słownik Centralny) ────────────────────────────────────────────

/** @return array<string, array{label:string, sfp:string, okres:string, termin_dzien:int, termin_mies:int}> */
function uspFormularze(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $out = [];
    foreach (dictItems(USP_DICT) as $code => $it) {
        if (!empty($it['has_children'])) continue;
        $a = (array)($it['attributes'] ?? []);
        $def = USP_FORMULARZE_DEFAULT[$code] ?? null;
        $sfp = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($a['sfp'] ?? $def[1] ?? '')));
        if ($sfp === '') continue;
        $okres = in_array($a['okres'] ?? '', ['M', 'K', 'R'], true) ? $a['okres'] : ($def[2] ?? 'M');
        $out[$code] = ['label' => (string)$it['label'], 'sfp' => $sfp, 'okres' => $okres,
                       'termin_dzien' => (int)($a['termin_dzien'] ?? $def[3] ?? 20), 'termin_mies' => (int)($a['termin_mies'] ?? $def[4] ?? 1)];
    }
    if (!$out) foreach (USP_FORMULARZE_DEFAULT as $code => $d) {
        $out[$code] = ['label' => $d[0], 'sfp' => $d[1], 'okres' => $d[2], 'termin_dzien' => $d[3], 'termin_mies' => $d[4]];
    }
    return $cache = $out;
}

/** Wpisy startowe słownika (format importu Słownika Centralnego) — selfrepairDB 2026-10_dictionary_us_formularze. */
function uspFormularzeSeed(): string {
    $entries = [];
    $i = 0;
    foreach (USP_FORMULARZE_DEFAULT as $code => $d) {
        $entries[] = ['code' => $code, 'label' => $d[0], 'parent_code' => null, 'sort_order' => (++$i) * 10, 'is_active' => true, 'description' => null,
                      'attributes' => ['sfp' => $d[1], 'okres' => $d[2], 'termin_dzien' => $d[3], 'termin_mies' => $d[4]]];
    }
    return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => [
        ['code' => USP_DICT, 'name' => 'Księgowość — formularze przelewów do US', 'kind' => 'system', 'parent_code' => null,
         'is_protected' => false, 'sort_order' => 80,
         'description' => 'Zobowiązania podatkowe w module Przelewy do US (EODoK). Atrybuty: sfp — symbol formularza w tytule przelewu (/SFP/, np. PIT4R, VAT7), okres — M (miesiąc), K (kwartał) albo R (rok), termin_dzien i termin_mies — domyślny termin płatności: dzień miesiąca, który przypada termin_mies miesięcy po końcu okresu (dzień większy niż długość miesiąca = ostatni dzień).',
         'entries' => $entries],
    ]], JSON_UNESCAPED_UNICODE);
}

// ── Okres, termin, tytuł ──────────────────────────────────────────────────────

/** Okres w zapisie przelewu podatkowego: 26M09, 26K03, 26R. */
function uspOkresKod(string $typ, int $rok, int $nr): string {
    $rr = sprintf('%02d', $rok % 100);
    return match ($typ) {
        'K'     => $rr . 'K' . sprintf('%02d', $nr),
        'R'     => $rr . 'R',
        default => $rr . 'M' . sprintf('%02d', $nr),
    };
}

/** Okres czytelny: „09/2026”, „III kw. 2026”, „2026”. */
function uspOkresLabel(string $typ, int $rok, int $nr): string {
    return match ($typ) {
        'K'     => (['I', 'II', 'III', 'IV'][$nr - 1] ?? $nr) . ' kw. ' . $rok,
        'R'     => (string)$rok,
        default => sprintf('%02d/%d', $nr, $rok),
    };
}

/** Domyślny okres: poprzedni miesiąc / poprzedni kwartał / poprzedni rok. @return array{0:int,1:int} [rok, nr] */
function uspOkresDomyslny(string $typ): array {
    $t = strtotime(date('Y-m-01') . ' -1 month');
    return match ($typ) {
        'K'     => [(int)date('Y', strtotime(date('Y-m-01') . ' -3 months')), intdiv((int)date('n', strtotime(date('Y-m-01') . ' -3 months')) - 1, 3) + 1],
        'R'     => [(int)date('Y') - 1, 0],
        default => [(int)date('Y', $t), (int)date('n', $t)],
    };
}

/** Święta ustawowo wolne (art. 1 ustawy o dniach wolnych) — termin w taki dzień przesuwa się na następny dzień roboczy. */
function uspSwieta(int $y): array {
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $wielkanoc = mktime(12, 0, 0, intdiv($h + $l - 7 * $m + 114, 31), (($h + $l - 7 * $m + 114) % 31) + 1, $y);
    $rel = fn(int $days) => date('Y-m-d', $wielkanoc + $days * 86400);
    $s = ["$y-01-01", "$y-01-06", $rel(0), $rel(1), "$y-05-01", "$y-05-03", $rel(49), $rel(60), "$y-08-15", "$y-11-01", "$y-11-11", "$y-12-25", "$y-12-26"];
    if ($y >= 2025) $s[] = "$y-12-24";
    return $s;
}

/** Ustawowy termin płatności: wg słownika, przesunięty z soboty / niedzieli / święta na najbliższy dzień roboczy. */
function uspTerminDomyslny(array $form, int $rok, int $nr): string {
    $koniecMies = match ($form['okres']) { 'K' => $nr * 3, 'R' => 12, default => $nr };
    $base = mktime(12, 0, 0, $koniecMies + max(0, $form['termin_mies']), 1, $rok);
    $dzien = min(max(1, $form['termin_dzien']), (int)date('t', $base));
    $t = mktime(12, 0, 0, (int)date('n', $base), $dzien, (int)date('Y', $base));
    while ((int)date('N', $t) >= 6 || in_array(date('Y-m-d', $t), uspSwieta((int)date('Y', $t)), true)) $t += 86400;
    return date('Y-m-d', $t);
}

/** Znaczniki tytułu przelewu podatkowego: /TI/ (N = NIP), /OKR/, /SFP/, /TXT/ (opcjonalnie). */
function uspTytulTokeny(array $z): array {
    $tok = ['/TI/N' . $z['identyfikator'], '/OKR/' . uspOkresKod($z['okres_typ'], (int)$z['okres_rok'], (int)$z['okres_nr']), '/SFP/' . $z['sfp']];
    $txt = trim(preg_replace('/\s+/', ' ', str_replace('/', '-', (string)($z['txt'] ?? ''))));
    if ($txt !== '') $tok[] = '/TXT/' . $txt;
    return $tok;
}

/** Tytuł w jednym wierszu (do skopiowania w bankowości, max 140 znaków). */
function uspTytul(array $z): string {
    return mb_substr(implode('', uspTytulTokeny($z)), 0, 140);
}

/** Wiersze pola 12 ELIXIR-O (4×35): znaczniki pakowane bez rozcinania, /TXT/ przycięty do wiersza. */
function uspTytulLinie(array $z): array {
    $lines = [''];
    foreach (uspTytulTokeny($z) as $t) {
        $cur = &$lines[count($lines) - 1];
        if (mb_strlen($cur . $t) <= 35) { $cur .= $t; unset($cur); continue; }
        unset($cur);
        $lines[] = mb_substr($t, 0, 35);
    }
    return array_slice($lines, 0, 4);
}

/** Wiersz w kształcie dokumentu EODoK dla edok_przelewy_export(). */
function uspPrzelewRow(array $z): array {
    return [
        'id'               => (int)$z['id'],
        'number'           => 'US-' . $z['id'],
        'kierunek'         => 'wydatek',
        'typ_dokumentu'    => 'podatek_us',
        'kontrahent_nazwa' => uspOdbiorca(),
        'kontrahent_nip'   => '',
        'rachunek_bankowy' => $z['rachunek'],
        'kwota_brutto'     => $z['kwota'],
        'waluta'           => 'PLN',
        '_tytul'           => uspTytul($z),
        '_tytul_linie'     => uspTytulLinie($z),
        '_kod'             => '71',
    ];
}

// ── Rejestr ───────────────────────────────────────────────────────────────────

function uspGet(int $id): ?array {
    return db_one("SELECT * FROM us_zobowiazania WHERE id = ?", [$id]) ?: null;
}

/** Lista z filtrami: status ('' = otwarte: do zapłaty + zlecone, 'wszystkie'), rok. */
function uspList(array $f): array {
    $where = []; $p = [];
    $st = (string)($f['status'] ?? '');
    if ($st === '') $where[] = "status IN ('do_zaplaty','zlecony')";
    elseif (isset(USP_STATUSY[$st])) { $where[] = "status = ?"; $p[] = $st; }
    if (!empty($f['rok'])) { $where[] = "okres_rok = CAST(? AS INTEGER)"; $p[] = (int)$f['rok']; }
    if (!empty($f['formularz'])) { $where[] = "formularz = ?"; $p[] = (string)$f['formularz']; }
    return db_all("SELECT * FROM us_zobowiazania" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY termin, id", $p);
}

/** Otwarte zobowiązania po terminie / w ciągu $dni dni. */
function uspDoTerminu(int $dni): array {
    return db_all("SELECT * FROM us_zobowiazania WHERE status IN ('do_zaplaty','zlecony') AND termin <= date('now', 'localtime', ?) ORDER BY termin",
                  ['+' . max(0, $dni) . ' days']);
}

function uspLog(int $zobId, string $akcja, string $opis): void {
    $u = function_exists('current_user') ? (current_user() ?: []) : [];
    db_insert('us_zobowiazania_log', ['zob_id' => $zobId, 'akcja' => $akcja, 'opis' => $opis,
        'user_id' => (int)($u['id'] ?? 0) ?: null, 'user_name' => (string)($u['name'] ?? 'system'), 'created_at' => date('Y-m-d H:i:s')]);
}

function uspHistoria(int $zobId): array {
    return db_all("SELECT * FROM us_zobowiazania_log WHERE zob_id = ? ORDER BY id DESC", [$zobId]);
}

/** Inne zobowiązania z tym samym formularzem i okresem (ostrzeżenie przed podwójną wpłatą). */
function uspDuplikaty(string $formularz, string $typ, int $rok, int $nr, int $pomin = 0): array {
    return db_all("SELECT * FROM us_zobowiazania WHERE formularz = ? AND okres_typ = ? AND okres_rok = CAST(? AS INTEGER) AND okres_nr = CAST(? AS INTEGER)
                    AND status <> 'anulowany' AND id <> CAST(? AS INTEGER)", [$formularz, $typ, $rok, $nr, $pomin]);
}

/**
 * Walidacja formularza zobowiązania. Zwraca [dane do zapisu, błędy, duplikaty].
 * Rachunek: z ustawień (mikrorachunek), chyba że podano inny (np. inny urząd dla podatku spoza mikrorachunku).
 */
function uspValidate(array $post, int $id = 0): array {
    $forms = uspFormularze();
    $code = (string)($post['formularz'] ?? '');
    $errors = [];
    $form = $forms[$code] ?? null;
    if (!$form && $id && ($cur = uspGet($id)) && $cur['formularz'] === $code) {
        $form = ['sfp' => $cur['sfp'], 'okres' => $cur['okres_typ'], 'termin_dzien' => 20, 'termin_mies' => 1]; // wpis wyłączony w słowniku
    }
    if (!$form) return [[], ['Wybierz rodzaj zobowiązania.'], []];
    $rok = (int)($post['okres_rok'] ?? 0);
    $nr  = $form['okres'] === 'R' ? 0 : (int)($post['okres_nr'] ?? 0);
    if ($rok < 2000 || $rok > (int)date('Y') + 1) $errors[] = 'Podaj rok okresu.';
    if ($form['okres'] === 'M' && ($nr < 1 || $nr > 12)) $errors[] = 'Wybierz miesiąc okresu.';
    if ($form['okres'] === 'K' && ($nr < 1 || $nr > 4))  $errors[] = 'Wybierz kwartał okresu.';
    $kwota = _edok_kwota_float((string)($post['kwota'] ?? ''));
    if ($kwota <= 0) $errors[] = 'Kwota musi być większa od zera.';
    $termin = (string)($post['termin'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $termin)) $termin = $errors ? '' : uspTerminDomyslny($form, $rok, $nr);
    $nrb = preg_replace('/\D/', '', (string)($post['rachunek'] ?? '')) ?: uspRachunek();
    if ($nrb === '') $errors[] = 'Najpierw zapisz rachunek urzędu skarbowego w ustawieniach.';
    elseif (!edok_nrb_valid($nrb)) $errors[] = 'Numer rachunku jest nieprawidłowy (suma kontrolna IBAN).';
    $nip = uspNip();
    if (strlen($nip) !== 10) $errors[] = 'Brak NIP organizacji w Ustawieniach organizacji — jest potrzebny w tytule przelewu.';
    $edokId = (int)($post['edok_doc_id'] ?? 0) ?: null;
    if ($edokId && !db_one("SELECT 1 FROM edok_documents WHERE id = ?", [$edokId])) $errors[] = 'Wskazany dokument EODoK nie istnieje.';
    $d = [
        'formularz' => $code, 'sfp' => $form['sfp'], 'okres_typ' => $form['okres'], 'okres_rok' => $rok, 'okres_nr' => $nr,
        'kwota' => number_format($kwota, 2, ',', ''), 'termin' => $termin, 'rachunek' => $nrb, 'identyfikator' => $nip,
        'txt' => mb_substr(trim((string)($post['txt'] ?? '')), 0, 34), 'edok_doc_id' => $edokId,
        'uwagi' => mb_substr(trim((string)($post['uwagi'] ?? '')), 0, 2000),
    ];
    $dup = $errors ? [] : uspDuplikaty($code, $form['okres'], $rok, $nr, $id);
    if ($dup && empty($post['confirm_dup'])) $errors[] = 'Za ten okres jest już zobowiązanie ' . $form['sfp'] . ' — potwierdź, że to kolejna wpłata (np. korekta).';
    return [$d, $errors, $dup];
}

/** Załączniki zobowiązania: pole formularza = prefiks kolumn {pole}_path / {pole}_nazwa. */
const USP_ZALACZNIKI = ['zalacznik' => 'Deklaracja', 'wyliczenie' => 'Wyliczenie'];

/**
 * Wysłane załączniki (deklaracja, wyliczenie). Zwraca kolumny do zapisu ({pole}_path, {pole}_nazwa) — puste,
 * gdy nic nie wysłano; string = błąd (pierwszy nieudany plik).
 */
function uspUpload(): array|string {
    $out = [];
    foreach (USP_ZALACZNIKI as $field => $label) {
        if (empty($_FILES[$field]['tmp_name'])) continue;
        $path = handle_upload($field, 'us_przelewy');
        if (!$path) return 'Nie udało się zapisać pliku „' . $label . '” (PDF, JPG, PNG, DOCX, max 20 MB).';
        $out[$field . '_path']  = $path;
        $out[$field . '_nazwa'] = mb_substr(basename((string)$_FILES[$field]['name']), 0, 200);
    }
    return $out;
}

function uspCreate(array $d, array $user): int {
    $now = date('Y-m-d H:i:s');
    $id = db_insert('us_zobowiazania', $d + ['status' => 'do_zaplaty', 'created_by' => (int)($user['id'] ?? 0) ?: null,
        'created_name' => (string)($user['name'] ?? ''), 'created_at' => $now, 'updated_at' => $now]);
    uspLog($id, 'dodano', $d['sfp'] . ' ' . uspOkresLabel($d['okres_typ'], $d['okres_rok'], $d['okres_nr']) . ', ' . $d['kwota'] . ' zł, termin ' . date('d.m.Y', strtotime($d['termin'])));
    return $id;
}

function uspUpdate(int $id, array $d): void {
    $old = uspGet($id);
    if (($d['termin'] ?? $old['termin']) !== $old['termin']) $d['przypomniano'] = ''; // nowy termin — przypomnienia od nowa
    db_update('us_zobowiazania', $d, $id);
    $zm = [];
    foreach (['kwota' => 'kwota', 'termin' => 'termin', 'rachunek' => 'rachunek', 'txt' => 'opis', 'zalacznik_nazwa' => 'deklaracja', 'wyliczenie_nazwa' => 'wyliczenie'] as $k => $l) {
        if (array_key_exists($k, $d) && (string)$old[$k] !== (string)$d[$k]) $zm[] = $l . ': ' . ($old[$k] ?: '—') . ' → ' . ($d[$k] ?: '—');
    }
    uspLog($id, 'zmieniono', $zm ? implode('; ', $zm) : 'zapisano bez zmian kwoty/terminu');
}

/** Zmiana statusu ręcznie: zapłacony (z datą), do zapłaty (cofnięcie zlecenia), anulowany. */
function uspSetStatus(int $id, string $status, string $data = ''): ?string {
    $z = uspGet($id);
    if (!$z) return 'Nie znaleziono zobowiązania.';
    if (!isset(USP_STATUSY[$status])) return 'Nieznany status.';
    $upd = ['status' => $status];
    if ($status === 'zaplacony') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > date('Y-m-d')) return 'Podaj datę zapłaty (nie z przyszłości).';
        $upd['data_zaplaty'] = $data;
    } else {
        $upd['data_zaplaty'] = null;
    }
    db_update('us_zobowiazania', $upd, $id);
    uspLog($id, 'status', USP_STATUSY[$z['status']]['label'] . ' → ' . USP_STATUSY[$status]['label'] . ($status === 'zaplacony' ? ' (' . date('d.m.Y', strtotime($data)) . ')' : ''));
    return null;
}

/**
 * Plik przelewów dla zaznaczonych zobowiązań z jednego rachunku organizacji. Zapisuje plik w uploads/us_przelewy,
 * oznacza zobowiązania jako zlecone. Zwraca ['nazwa', 'content'] albo string z błędem.
 */
function uspGenerujPlik(array $ids, string $nrbZ, string $format, bool $ponowne): array|string {
    $nrbZ = preg_replace('/\D/', '', $nrbZ);
    $konta = array_map(fn($r) => preg_replace('/\D/', '', (string)$r['nrb']), edok_rachunki_list());
    if (!in_array($nrbZ, $konta, true)) return 'Wybierz rachunek organizacji, z którego ma pójść przelew.';
    if (!in_array($format, USP_FORMATY, true)) $format = 'auto';
    $rows = []; $zob = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $z = uspGet($id);
        if (!$z || !in_array($z['status'], ['do_zaplaty', 'zlecony'], true)) continue;
        if ($z['status'] === 'zlecony' && !$ponowne) return 'Zobowiązanie ' . $z['sfp'] . ' ' . uspOkresLabel($z['okres_typ'], (int)$z['okres_rok'], (int)$z['okres_nr']) . ' było już w pliku — zaznacz potwierdzenie ponownego ujęcia.';
        $zob[] = $z;
        $rows[] = uspPrzelewRow($z);
    }
    if (!$rows) return 'Zaznacz co najmniej jedno zobowiązanie do zapłaty.';
    $plik = edok_przelewy_export($rows, $nrbZ, $format);
    if ($plik['content'] === '') return 'Nie udało się wygenerować pliku przelewu.';
    $nazwa = 'US_' . $plik['prefix'] . '_' . substr($nrbZ, -4) . '_' . date('Y-m-d_His') . '.' . $plik['ext'];
    $dir = rtrim(UPLOAD_DIR, '/') . '/us_przelewy';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $rel = 'us_przelewy/' . $nazwa;
    $saved = @file_put_contents(rtrim(UPLOAD_DIR, '/') . '/' . $rel, $plik['content']) !== false;
    $now = date('Y-m-d H:i:s');
    foreach ($zob as $z) {
        db_update('us_zobowiazania', ['status' => 'zlecony', 'rachunek_z' => $nrbZ, 'plik_format' => $plik['prefix'], 'plik_nazwa' => $nazwa,
            'plik_path' => $saved ? $rel : '', 'zlecono_at' => $now], (int)$z['id']);
        uspLog((int)$z['id'], 'plik', 'Plik przelewu ' . $nazwa . ' z rachunku …' . substr($nrbZ, -4) . ' — tytuł ' . uspTytul($z));
    }
    return ['nazwa' => $nazwa, 'content' => $plik['content']];
}

/** Dokumenty EODoK do powiązania (najnowsze wydatki, z wyszukiwaniem po numerze/kontrahencie). */
function uspEdokDocs(string $q = ''): array {
    $p = [];
    $w = "kierunek = 'wydatek'";
    if ($q !== '') { $w .= " AND (number LIKE ? OR kontrahent_nazwa LIKE ? OR title LIKE ?)"; $p = ["%$q%", "%$q%", "%$q%"]; }
    return db_all("SELECT id, number, title, kontrahent_nazwa, kwota_brutto FROM edok_documents WHERE $w ORDER BY id DESC LIMIT 100", $p);
}

// ── Przypomnienia (cron/agents/usPrzelewyReminders.php) ──────────────────────

/**
 * Jedno zbiorcze przypomnienie dziennie na adres z usp_przypomnienia: zobowiązania z terminem w ciągu
 * usp_przypomnienia_dni dni (raz na zobowiązanie, znacznik „przed”) i po terminie (raz, znacznik „po”).
 * Puste adresy = wyłączone. $dry — tylko lista, bez wysyłki i bez znaczników.
 * @return array{note:string, lines:list<string>, sent:int}
 */
function uspPrzypomnienia(bool $dry = false): array {
    uspMigrate();
    $emails = array_values(array_filter(array_map('trim', explode(',', (string) org_setting('usp_przypomnienia'))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (!$emails && !$dry) return ['note' => 'Przypomnienia wyłączone (brak adresów w ustawieniach Przelewów do US).', 'lines' => [], 'sent' => 0];
    $dni = org_setting('usp_przypomnienia_dni') !== '' ? (int) org_setting('usp_przypomnienia_dni') : 3;
    $today = date('Y-m-d');
    $items = [];
    foreach (uspDoTerminu($dni) as $z) {
        $sent = array_filter(explode(',', (string)$z['przypomniano']));
        $kind = $z['termin'] < $today ? 'po' : 'przed';
        if (in_array($kind, $sent, true)) continue;
        $items[] = ['z' => $z, 'kind' => $kind, 'sent' => $sent];
    }
    if (!$items) return ['note' => 'Brak zobowiązań do przypomnienia.', 'lines' => [], 'sent' => 0];

    $money = fn($v) => number_format(_edok_kwota_float((string)$v), 2, ',', ' ');
    $lines = []; $rows = ''; $txt = '';
    foreach ($items as $it) {
        $z = $it['z'];
        $opis = $z['sfp'] . ' ' . uspOkresLabel($z['okres_typ'], (int)$z['okres_rok'], (int)$z['okres_nr']) . ' — ' . $money($z['kwota']) . ' zł, termin ' . date('d.m.Y', strtotime($z['termin']))
              . ($it['kind'] === 'po' ? ' (PO TERMINIE)' : '') . ($z['status'] === 'zlecony' ? ' — plik przelewu wygenerowany, zapłata nieoznaczona' : '');
        $lines[] = $opis;
        $txt .= '- ' . $opis . "\n";
        $rows .= '<li' . ($it['kind'] === 'po' ? ' style="color:#b91c1c"' : '') . '>' . htmlspecialchars($opis) . '</li>';
    }
    if ($dry) return ['note' => '', 'lines' => $lines, 'sent' => 0];

    $url = rtrim(APP_URL, '/') . '/modules/us_przelewy/router.php';
    $po = count(array_filter($items, fn($i) => $i['kind'] === 'po'));
    $subject = 'Przelewy do US: ' . count($items) . ' zobowiązań' . ($po ? ' (' . $po . ' po terminie)' : '') . ' do zapłaty';
    $html = '<p>Zobowiązania podatkowe wymagające zapłaty:</p><ul>' . $rows . '</ul><p><a href="' . htmlspecialchars($url) . '">Otwórz Przelewy do US</a> — wygeneruj plik przelewu albo oznacz zapłatę.</p>';
    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $n = 0;
    foreach ($emails as $e) { mail_queue_add($e, '', $subject, $html, $txt . "\n" . $url, 'us_przelewy'); $n++; }
    foreach ($items as $it) {
        db_update('us_zobowiazania', ['przypomniano' => implode(',', array_unique(array_merge($it['sent'], [$it['kind']])))], (int)$it['z']['id']);
    }
    return ['note' => '', 'lines' => $lines, 'sent' => $n];
}
