<?php
/**
 * includes/invoices.php — moduł Faktury: rejestr + integracja z fakturownia.pl.
 *
 * Podział odpowiedzialności:
 *   • SZO   — kontekst dokumentu: skąd się wziął (oferta CRM / rozliczenie TI /
 *             ręcznie), kto go wystawił, do czego jest przypisany.
 *   • Fakturownia — dokument księgowy: numeracja, PDF, status płatności.
 *
 * Kierunek: wystawiamy z SZO (invoice_push), a potem tylko odświeżamy stan
 * z Fakturowni (invoice_sync). Nie edytujemy dokumentu po wystawieniu — od tego
 * momentu źródłem prawdy jest Fakturownia.
 *
 * Wymaga: db.php, auth.php, functions.php
 */

declare(strict_types=1);

require_once __DIR__ . '/fakturownia.php';

/** Statusy faktury w SZO. */
const INVOICE_STATUSES = [
    'szkic'      => ['label' => 'Szkic',       'color' => '#6B7280'],
    'wystawiona' => ['label' => 'Wystawiona',  'color' => '#0176D3'],
    'zaplacona'  => ['label' => 'Zapłacona',   'color' => '#2E844A'],
    'anulowana'  => ['label' => 'Anulowana',   'color' => '#939393'],
    'blad'       => ['label' => 'Błąd wysyłki','color' => '#B3261E'],
];

/** Skąd pochodzi faktura. */
const INVOICE_SOURCES = [
    'manual'     => 'Ręcznie',
    'offer'      => 'Oferta CRM',
    'ti_billing' => 'Rozliczenie TI',
];

// ── Schemat ──────────────────────────────────────────────────────────────────

function invoices_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        number          TEXT,
        kind            TEXT    NOT NULL DEFAULT 'vat',
        status          TEXT    NOT NULL DEFAULT 'szkic',
        source          TEXT    NOT NULL DEFAULT 'manual',
        source_id       INTEGER,
        contact_id      INTEGER REFERENCES crm_contacts(id) ON DELETE SET NULL,
        buyer_name      TEXT    NOT NULL DEFAULT '',
        buyer_tax_no    TEXT    NOT NULL DEFAULT '',
        buyer_street    TEXT    NOT NULL DEFAULT '',
        buyer_post_code TEXT    NOT NULL DEFAULT '',
        buyer_city      TEXT    NOT NULL DEFAULT '',
        buyer_email     TEXT    NOT NULL DEFAULT '',
        currency        TEXT    NOT NULL DEFAULT 'PLN',
        issue_date      DATE,
        sell_date       DATE,
        payment_to      DATE,
        total_net       DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_vat       DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_gross     DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes           TEXT,
        fakturownia_id  INTEGER,
        fakturownia_url TEXT,
        pdf_path        TEXT,
        paid_at         DATETIME,
        issued_at       DATETIME,
        last_sync_at    DATETIME,
        last_error      TEXT,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at      DATETIME
    )");

    // KSeF: numer nadany przez system, numery referencyjne sesji i wysyłki oraz UPO.
    foreach ([
        "ALTER TABLE invoices ADD COLUMN ksef_number    TEXT",
        "ALTER TABLE invoices ADD COLUMN ksef_reference TEXT",
        "ALTER TABLE invoices ADD COLUMN ksef_session   TEXT",
        "ALTER TABLE invoices ADD COLUMN upo_path       TEXT",
    ] as $_sql) {
        try { $pdo->exec($_sql); } catch (\Throwable $e) {}
    }

    // Faktura testowa: numer z przedrostkiem TEST, nie idzie do żadnego systemu
    // zewnętrznego i nie zakłada koszulki w SZO. Osobna kolumna, nie sam prefiks
    // numeru — po numerze nie da się filtrować pewnie, a decyzje zależą od tej
    // flagi. Musi istnieć PRZED indeksem idx_inv_source_test, który jej używa.
    try { $pdo->exec("ALTER TABLE invoices ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inv_status  ON invoices(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inv_contact ON invoices(contact_id)");
    // Jedna faktura na źródło — druga próba wystawienia z tej samej oferty czy
    // rozliczenia ma trafić na istniejący dokument, nie stworzyć duplikatu.
    // is_test jest częścią klucza: faktura demo NIE MOŻE zajmować miejsca
    // prawdziwej, więc z jednego źródła mogą istnieć równolegle dwie — jedna
    // produkcyjna i jedna testowa.
    try { $pdo->exec("DROP INDEX IF EXISTS idx_inv_source"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_inv_source_test
                ON invoices(source, source_id, is_test) WHERE source_id IS NOT NULL AND deleted_at IS NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_items (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id   INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
        name         TEXT    NOT NULL,
        unit         TEXT    NOT NULL DEFAULT 'szt.',
        qty          DECIMAL(12,3) NOT NULL DEFAULT 1,
        unit_net     DECIMAL(12,2) NOT NULL DEFAULT 0,
        vat_rate     TEXT    NOT NULL DEFAULT '23',
        line_net     DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_vat     DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_gross   DECIMAL(12,2) NOT NULL DEFAULT 0,
        sort_order   INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inv_items ON invoice_items(invoice_id, sort_order)");
}

// ── Konfiguracja ─────────────────────────────────────────────────────────────

/** Poświadczenia i domyślne wartości z ustawień organizacji. */
function invoices_config(): array
{
    return [
        'account'   => trim(org_setting('fakturownia_account')),
        'token'     => trim(org_setting('fakturownia_token')),
        // Domyślnie ZWOLNIONE z VAT — działalność statutowa i usługi edukacyjne
        // fundacji są zwolnione przedmiotowo. Stawkę można nadpisać per pozycja.
        'vat'       => trim(org_setting('fakturownia_default_vat'))  ?: 'zw',
        'days'      => (int)(org_setting('fakturownia_payment_days') ?: 14),
        'kind'      => trim(org_setting('fakturownia_default_kind')) ?: 'vat',
        // Podstawa zwolnienia — wymagana na fakturze ze stawką „zw"
        // (art. 106e ust. 1 pkt 19 ustawy o VAT). Domyślnie zwolnienie PODMIOTOWE
        // z uwagi na nieprzekroczenie limitu sprzedaży 200 000 zł.
        'zw_basis'  => trim(org_setting('fakturownia_vat_exempt_basis'))
                       ?: 'art. 113 ust. 1 ustawy o podatku od towarów i usług '
                          . '— sprzedaż nie przekroczyła limitu 200 000 zł',
    ];
}

/** Czy integracja jest skonfigurowana (da się wystawiać). */
function invoices_api_ready(): bool
{
    $c = invoices_config();
    return $c['account'] !== '' && $c['token'] !== '';
}

/** Katalog na lokalne kopie PDF. */
function invoices_pdf_dir(): string
{
    $dir = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__) . '/uploads'), '/') . '/invoices';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// ── Odczyt ───────────────────────────────────────────────────────────────────

function invoice_get(int $id): ?array
{
    invoices_migrate();
    $inv = db_one("SELECT * FROM invoices WHERE id=? AND deleted_at IS NULL", [$id]);
    if (!$inv) return null;
    $inv['items'] = db_all("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order, id", [$id]);
    return $inv;
}

/**
 * Rejestr faktur z filtrami.
 *
 * @param array{q?:string,status?:string,source?:string,contact_id?:int,from?:string,to?:string} $f
 */
function invoice_list(array $f = [], int $limit = 50, int $offset = 0): array
{
    invoices_migrate();
    $w = ['i.deleted_at IS NULL'];
    $p = [];

    if (!empty($f['q'])) {
        $like = '%' . trim((string)$f['q']) . '%';
        $w[]  = '(i.number LIKE ? OR i.buyer_name LIKE ? OR i.buyer_tax_no LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    if (!empty($f['status']))     { $w[] = 'i.status = ?';     $p[] = (string)$f['status']; }
    if (!empty($f['source']))     { $w[] = 'i.source = ?';     $p[] = (string)$f['source']; }
    if (!empty($f['contact_id'])) { $w[] = 'i.contact_id = ?';  $p[] = (int)$f['contact_id']; }
    if (!empty($f['from']))       { $w[] = 'i.issue_date >= ?'; $p[] = (string)$f['from']; }
    if (!empty($f['to']))         { $w[] = 'i.issue_date <= ?'; $p[] = (string)$f['to']; }

    $where = implode(' AND ', $w);
    $total = (int)(db_one("SELECT COUNT(*) AS c FROM invoices i WHERE {$where}", $p)['c'] ?? 0);

    $rows = db_all(
        "SELECT i.*, c.imie_nazwisko AS contact_name
           FROM invoices i
           LEFT JOIN crm_contacts c ON c.id = i.contact_id
          WHERE {$where}
       ORDER BY COALESCE(i.issue_date, i.created_at) DESC, i.id DESC
          LIMIT ? OFFSET ?",
        array_merge($p, [$limit, $offset])
    );
    return ['rows' => $rows, 'total' => $total];
}

/** Podsumowanie rejestru — kwoty i liczniki po statusie. */
function invoice_stats(): array
{
    invoices_migrate();
    $out = ['count' => 0, 'gross' => 0.0, 'unpaid' => 0.0, 'by_status' => []];
    foreach (db_all("SELECT status, COUNT(*) AS c, SUM(total_gross) AS g
                       FROM invoices WHERE deleted_at IS NULL GROUP BY status") as $r) {
        $out['by_status'][$r['status']] = ['count' => (int)$r['c'], 'gross' => (float)$r['g']];
        $out['count'] += (int)$r['c'];
        $out['gross'] += (float)$r['g'];
        if ($r['status'] === 'wystawiona') $out['unpaid'] += (float)$r['g'];
    }
    return $out;
}

// ── Zapis ────────────────────────────────────────────────────────────────────

/** Przelicza wiersz pozycji (netto/VAT/brutto) na podstawie ilości i stawki. */
function invoice_item_calc(array $it): array
{
    $qty  = (float)($it['qty']      ?? 1);
    $net  = (float)($it['unit_net'] ?? 0);
    $rate = (string)($it['vat_rate'] ?? '23');

    $line_net = round($qty * $net, 2);
    // Stawki nienumeryczne (zw, np, oo) nie generują VAT.
    $line_vat = is_numeric($rate) ? round($line_net * ((float)$rate / 100), 2) : 0.0;

    return [
        'name'       => trim((string)($it['name'] ?? '')),
        'unit'       => trim((string)($it['unit'] ?? '')) ?: 'szt.',
        'qty'        => $qty,
        'unit_net'   => $net,
        'vat_rate'   => $rate,
        'line_net'   => $line_net,
        'line_vat'   => $line_vat,
        'line_gross' => round($line_net + $line_vat, 2),
        'sort_order' => (int)($it['sort_order'] ?? 0),
    ];
}

/** Zapisuje pozycje faktury (zastępując dotychczasowe) i przelicza sumy. */
function invoice_save_items(int $invoice_id, array $items): void
{
    invoices_migrate();
    db()->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$invoice_id]);

    $i = 0;
    $net = $vat = $gross = 0.0;
    foreach ($items as $raw) {
        $it = invoice_item_calc($raw);
        if ($it['name'] === '') continue;          // wiersz bez nazwy pomijamy
        $it['sort_order'] = $i++;
        $it['invoice_id'] = $invoice_id;
        db_insert('invoice_items', $it);
        $net   += $it['line_net'];
        $vat   += $it['line_vat'];
        $gross += $it['line_gross'];
    }

    db()->prepare("UPDATE invoices SET total_net=?, total_vat=?, total_gross=?, updated_at=? WHERE id=?")
        ->execute([round($net, 2), round($vat, 2), round($gross, 2), date('Y-m-d H:i:s'), $invoice_id]);
}

/** Tworzy szkic faktury. Zwraca id. */
function invoice_create(array $d, array $items, int $uid): int
{
    invoices_migrate();
    $cfg = invoices_config();
    $now = date('Y-m-d H:i:s');

    $issue = trim((string)($d['issue_date'] ?? '')) ?: date('Y-m-d');
    $id = db_insert('invoices', [
        'kind'            => (string)($d['kind'] ?? $cfg['kind']),
        'status'          => 'szkic',
        'source'          => (string)($d['source'] ?? 'manual'),
        'source_id'       => !empty($d['source_id']) ? (int)$d['source_id'] : null,
        'contact_id'      => !empty($d['contact_id']) ? (int)$d['contact_id'] : null,
        'buyer_name'      => trim((string)($d['buyer_name']      ?? '')),
        'buyer_tax_no'    => trim((string)($d['buyer_tax_no']    ?? '')),
        'buyer_street'    => trim((string)($d['buyer_street']    ?? '')),
        'buyer_post_code' => trim((string)($d['buyer_post_code'] ?? '')),
        'buyer_city'      => trim((string)($d['buyer_city']      ?? '')),
        'buyer_email'     => trim((string)($d['buyer_email']     ?? '')),
        'currency'        => trim((string)($d['currency'] ?? '')) ?: 'PLN',
        'issue_date'      => $issue,
        'sell_date'       => trim((string)($d['sell_date'] ?? '')) ?: $issue,
        'payment_to'      => trim((string)($d['payment_to'] ?? ''))
                             ?: date('Y-m-d', strtotime($issue . ' +' . $cfg['days'] . ' days')),
        'notes'           => trim((string)($d['notes'] ?? '')) ?: null,
        'is_test'         => !empty($d['is_test']) ? 1 : 0,
        'created_by'      => $uid,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    invoice_save_items($id, $items);
    return $id;
}

/** Aktualizuje szkic. Po wystawieniu dokument jest niezmienny. */
function invoice_update(int $id, array $d, ?array $items, int $uid): ?string
{
    $inv = invoice_get($id);
    if (!$inv) return 'Nie znaleziono faktury.';
    if ($inv['status'] !== 'szkic') {
        return 'Faktura została już wystawiona — dokumentu nie można edytować w SZO.';
    }

    $fields = [];
    foreach (['kind','buyer_name','buyer_tax_no','buyer_street','buyer_post_code','buyer_city',
              'buyer_email','currency','issue_date','sell_date','payment_to','notes'] as $f) {
        if (array_key_exists($f, $d)) $fields[$f] = trim((string)$d[$f]);
    }
    if (array_key_exists('contact_id', $d)) $fields['contact_id'] = ((int)$d['contact_id']) ?: null;
    if (array_key_exists('is_test', $d))    $fields['is_test']    = !empty($d['is_test']) ? 1 : 0;
    $fields['updated_at'] = date('Y-m-d H:i:s');

    db_update('invoices', $fields, $id);
    if ($items !== null) invoice_save_items($id, $items);
    return null;
}

/** Soft-delete szkicu. Wystawionej faktury nie usuwamy — trzeba ją anulować. */
function invoice_delete(int $id): ?string
{
    $inv = invoice_get($id);
    if (!$inv) return 'Nie znaleziono faktury.';
    if ($inv['status'] !== 'szkic' && $inv['status'] !== 'blad') {
        return 'Wystawioną fakturę można tylko anulować (korekta powstaje w Fakturowni).';
    }
    db()->prepare("UPDATE invoices SET deleted_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
    return null;
}

// ── Numeracja faktur TI ──────────────────────────────────────────────────────

/**
 * Format numeru faktury: `SERIA/RRRR/MM/NNN`.
 *
 * Dlaczego tak:
 *  • RRRR/MM przed numerem kolejnym sprawia, że numery SORTUJĄ SIĘ chronologicznie
 *    jako zwykły tekst — w rejestrze, eksporcie do arkusza i w nazwie pliku PDF.
 *    Przy „nr/mm/rok" sortowanie tekstowe daje bezsens (10 przed 9).
 *  • NNN z zerami wiodącymi — 001…999 układa się poprawnie także tekstowo.
 *  • Prefiks serii mówi, skąd dokument pochodzi (TI = zajęcia, FV = pozostałe),
 *    a art. 106e ust. 1 pkt 2 ustawy o VAT wprost dopuszcza więcej niż jedną serię,
 *    byle numer jednoznacznie identyfikował fakturę.
 *  • Sekwencja jest ciągła w obrębie serii i miesiąca.
 *
 * Przykłady: TI/2026/08/001, FV/2026/08/007, TEST/FV/2026/08/001.
 *
 * Numer nadajemy przy WYSTAWIENIU, nie przy tworzeniu szkicu — inaczej usunięty
 * szkic zostawiałby lukę w numeracji.
 */
const INVOICE_SERIES_TI  = 'TI';
const INVOICE_SERIES_OWN = 'FV';

/**
 * Kolejny numer w danej serii dla miesiąca.
 *
 * Do sekwencji wliczamy też numery nadane STARYM formatem (SERIA/nr/MM/RRRR,
 * czasem z segmentem grupy na końcu) — inaczej po zmianie formatu numeracja
 * zaczęłaby się od nowa i powtórzyła istniejące dokumenty.
 */
function invoice_series_number(string $series, int $month, int $year, bool $test = false): string
{
    invoices_migrate();
    $mm  = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
    $pre = $test ? INVOICE_TEST_PREFIX : '';
    $max = 0;

    $scan = function (string $like, string $re) use (&$max): void {
        foreach (db_all(
            "SELECT number FROM invoices WHERE number LIKE ? AND deleted_at IS NULL", [$like]
        ) as $r) {
            if (preg_match($re, (string)$r['number'], $m)) $max = max($max, (int)$m[1]);
        }
    };

    // Separator przed serią MUSI być opcjonalny: numer produkcyjny zaczyna się od
    // serii („TI/…"), a testowy ma przed nią prefiks („TEST/TI/…"). Wymóg ukośnika
    // sprawiał, że numery produkcyjne nie liczyły się do sekwencji — i każdy nowy
    // dostawał 001, czyli duplikat.
    $ser = preg_quote($series, '#');

    // Format bieżący: SERIA/RRRR/MM/NNN
    $scan($pre . $series . '/' . $year . '/' . $mm . '/%',
          '#(?:^|/)' . $ser . '/' . $year . '/' . $mm . '/(\d+)$#');
    // Format historyczny: SERIA/nr/MM/RRRR[/grupa]
    $scan($pre . $series . '/%/' . $mm . '/' . $year . '%',
          '#(?:^|/)' . $ser . '/(\d+)/#');

    return $pre . $series . '/' . $year . '/' . $mm . '/' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

/** Przedrostek numeru faktury testowej. */
const INVOICE_TEST_PREFIX = 'TEST/';

/** Czy faktura jest testowa (nie trafia do systemów zewnętrznych ani do akt). */
function invoice_is_test(array $inv): bool
{
    return !empty($inv['is_test']);
}

/**
 * Czy fakturę należy wystawić w KSeF.
 *
 * KSeF obejmuje obrót między podatnikami (B2B). Faktura dla osoby fizycznej
 * nieprowadzącej działalności — czyli bez NIP-u — jest poza tym obowiązkiem
 * i wystawiamy ją lokalnie, z numerem nadanym przez SZO.
 *
 * Rozstrzyga obecność poprawnego NIP-u nabywcy: nie mamy innego pewnego sygnału,
 * a brak NIP-u przy sprzedaży konsumenckiej jest regułą, nie wyjątkiem.
 * Dla faktur z rozliczeń TI to przypadek domyślny — nabywcą jest zwykle kursant
 * albo jego opiekun.
 */
function invoice_ksef_applicable(array $inv): bool
{
    if (invoice_is_test($inv)) return false;   // dokument testowy nigdy nie wychodzi
    $nip = preg_replace('/\D+/', '', (string)($inv['buyer_tax_no'] ?? '')) ?? '';
    return strlen($nip) === 10;
}

/**
 * Rodzaj nabywcy: 'OF' (osoba fizyczna — brak NIP) albo 'NIP' (podatnik).
 *
 * Decyduje o tym samym co invoice_ksef_applicable(), ale służy do POKAZANIA
 * operatorowi, z kim ma do czynienia — brak NIP-u znaczy sprzedaż konsumencka,
 * a więc dokument poza KSeF.
 */
function invoice_buyer_kind(?string $tax_no): string
{
    return strlen(preg_replace('/\D+/', '', (string)$tax_no) ?? '') === 10 ? 'NIP' : 'OF';
}

/**
 * Ustala nabywcę, jaki powstanie z rozliczenia TI — bez tworzenia faktury.
 *
 * Ta sama logika co invoice_from_ti_billing(): płatnik rozliczenia, a gdy go nie
 * ma — sam kursant; NIP dociągamy z kartoteki CRM po nazwie. Dzięki temu panel
 * generowania pokazuje „OF" albo NIP ZANIM operator kliknie.
 *
 * @return array{name:string,tax_no:string,kind:string}
 */
function invoice_ti_buyer_preview(array $billing_row): array
{
    $name = trim((string)($billing_row['payer_name'] ?? '')) ?: trim((string)($billing_row['client_name'] ?? ''));
    $nip  = '';
    if ($name !== '') {
        try {
            $c = db_one("SELECT nip FROM crm_contacts WHERE crm_active=1 AND LOWER(imie_nazwisko)=LOWER(?) LIMIT 1", [$name]);
            $nip = (string)($c['nip'] ?? '');
        } catch (\Throwable $e) { $nip = ''; }
    }
    return ['name' => $name, 'tax_no' => $nip, 'kind' => invoice_buyer_kind($nip)];
}

/** Powód, dla którego faktura nie idzie do KSeF — do pokazania operatorowi. */
function invoice_ksef_skip_reason(array $inv): string
{
    if (invoice_is_test($inv)) return 'Faktura testowa — nie jest wysyłana do KSeF ani do systemu księgowego.';
    return invoice_ksef_applicable($inv)
        ? ''
        : 'Nabywca bez NIP — sprzedaż na rzecz osoby fizycznej jest poza KSeF.';
}

/**
 * Numer faktury z rozliczenia TI (seria TI).
 *
 * @param int $course_id Zachowany dla zgodności wywołań — grupa nie wchodzi już
 *                       do numeru; jest widoczna w pozycji faktury i w załączniku.
 */
function invoice_ti_number(int $month, int $year, int $course_id = 0, bool $test = false): string
{
    return invoice_series_number(INVOICE_SERIES_TI, $month, $year, $test);
}

/** Okres i grupa rozliczenia TI, z którego powstała faktura. */
function invoice_ti_period(array $inv): ?array
{
    if (($inv['source'] ?? '') !== 'ti_billing' || empty($inv['source_id'])) return null;
    // course_id doszedł ALTER-em (model kombinowany) — COALESCE dla starszych baz.
    $b = db_one(
        "SELECT month, year, COALESCE(course_id,0) AS course_id FROM k30_ti_billing WHERE id=?",
        [(int)$inv['source_id']]
    );
    if (!$b) return null;
    return [
        'month'     => (int)$b['month'],
        'year'      => (int)$b['year'],
        'course_id' => (int)$b['course_id'],
    ];
}

/** Numer poglądowy dla szkicu TI — do wydruku roboczego, nie zapisywany. */
function invoice_ti_number_preview(array $inv): string
{
    $p = invoice_ti_period($inv);
    return $p ? invoice_ti_number($p['month'], $p['year'], $p['course_id']) : '';
}

/**
 * Kolejny numer własnej serii (FV) — faktury z ofert CRM i wystawiane ręcznie.
 * Format i zasady jak w invoice_series_number().
 */
function invoice_own_number(int $month, int $year, bool $test = false): string
{
    return invoice_series_number(INVOICE_SERIES_OWN, $month, $year, $test);
}

/**
 * Wystawia fakturę WŁASNĄ — numer nadaje SZO, dokument powstaje z szablonu
 * (includes/invoice_pdf.php). Bez Fakturowni i bez KSeF.
 *
 * Po co: dopóki żaden system zewnętrzny nie jest podłączony (albo gdy organizacja
 * korzysta ze zwolnienia i nie musi wysyłać faktur do KSeF), to jest kompletna
 * ścieżka od źródła do gotowego dokumentu. Numer jest nadawany raz i dokument
 * przestaje być edytowalny — dalej zachowuje się jak każda wystawiona faktura.
 *
 * @return array{ok:bool,error?:string,number?:string}
 */
function invoice_issue_local(int $id): array
{
    $inv = invoice_get($id);
    if (!$inv)                          return ['ok' => false, 'error' => 'Nie znaleziono faktury.'];
    if (!empty($inv['number']))         return ['ok' => false, 'error' => 'Ta faktura ma już nadany numer.'];
    if (!empty($inv['fakturownia_id'])) return ['ok' => false, 'error' => 'Faktura jest wystawiona w Fakturowni.'];
    if (!$inv['items'])                 return ['ok' => false, 'error' => 'Faktura bez pozycji — dodaj co najmniej jedną.'];
    if (trim((string)$inv['buyer_name']) === '') return ['ok' => false, 'error' => 'Brak nazwy nabywcy.'];

    // Seria zależy od źródła: TI ma własne oznaczenie z grupą, reszta serię FV.
    $test = invoice_is_test($inv);
    if ($inv['source'] === 'ti_billing' && ($p = invoice_ti_period($inv))) {
        $number = invoice_ti_number($p['month'], $p['year'], $p['course_id'], $test);
    } else {
        $d = $inv['issue_date'] ? strtotime((string)$inv['issue_date']) : time();
        $number = invoice_own_number((int)date('n', $d), (int)date('Y', $d), $test);
    }

    $now = date('Y-m-d H:i:s');
    db()->prepare(
        "UPDATE invoices SET number=?, status='wystawiona', issued_at=?, last_error=NULL, updated_at=? WHERE id=?"
    )->execute([$number, $now, $now, $id]);

    return ['ok' => true, 'number' => $number];
}

/**
 * Zapisuje PDF faktury (z szablonu SZO) w katalogu faktur i zwraca nazwę pliku.
 * Używane przy wystawieniu własnym — dokument musi istnieć na dysku, żeby dało
 * się go załączyć do maila i pobrać później bez ponownego renderowania.
 */
function invoice_pdf_store(int $id): ?string
{
    $inv = invoice_get($id);
    if (!$inv) return null;

    require_once __DIR__ . '/invoice_pdf.php';
    try {
        $pdf = invoice_pdf_render($inv);
    } catch (\Throwable $e) {
        return null;
    }

    $name = 'FV_' . $id . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)$inv['number']) . '.pdf';
    $path = invoices_pdf_dir() . '/' . $name;
    if (@file_put_contents($path, $pdf) === false) return null;

    db()->prepare("UPDATE invoices SET pdf_path=?, updated_at=? WHERE id=?")
        ->execute([$name, date('Y-m-d H:i:s'), $id]);
    return $name;
}

/**
 * Wysyła fakturę PDF do nabywcy.
 *
 * Załącznik bierzemy z zapisanej kopii — nie renderujemy drugi raz, żeby nabywca
 * dostał dokładnie ten dokument, który operator zobaczył przy wystawieniu.
 *
 * @return array{ok:bool,error?:string,to?:string}
 */
function invoice_send_to_buyer(int $id): array
{
    $inv = invoice_get($id);
    if (!$inv) return ['ok' => false, 'error' => 'Nie znaleziono faktury.'];

    if (invoice_is_test($inv)) {
        return ['ok' => false, 'error' => 'Faktura testowa nie jest wysyłana do nabywcy.'];
    }

    $to = trim((string)$inv['buyer_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Nabywca nie ma poprawnego adresu e-mail — uzupełnij go na fakturze.'];
    }
    if (empty($inv['number'])) {
        return ['ok' => false, 'error' => 'Faktura nie została wystawiona — nie ma czego wysyłać.'];
    }

    $file = (string)($inv['pdf_path'] ?? '');
    if ($file === '' || !is_file(invoices_pdf_dir() . '/' . basename($file))) {
        $file = (string)invoice_pdf_store($id);
        if ($file === '') return ['ok' => false, 'error' => 'Nie udało się przygotować PDF do wysyłki.'];
    }
    $path = invoices_pdf_dir() . '/' . basename($file);

    require_once __DIR__ . '/mail_queue.php';
    $org   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $kwota = number_format((float)$inv['total_gross'], 2, ',', ' ') . ' ' . $inv['currency'];
    $term  = $inv['payment_to'] ? date('d.m.Y', strtotime((string)$inv['payment_to'])) : '—';

    $html = '<p>Dzień dobry,</p>'
          . '<p>w załączeniu przesyłamy fakturę <strong>' . h((string)$inv['number']) . '</strong>'
          . ' na kwotę <strong>' . h($kwota) . '</strong>, z terminem płatności ' . h($term) . '.</p>'
          . '<p>W tytule przelewu prosimy podać numer faktury.</p>'
          . ($org !== '' ? '<p>Z poważaniem,<br>' . h($org) . '</p>' : '');

    try {
        mail_queue_add(
            $to,
            (string)$inv['buyer_name'],
            'Faktura ' . $inv['number'] . ($org !== '' ? ' — ' . $org : ''),
            $html,
            '',
            'invoice',
            $id,
            '',
            true,   // natychmiast — operator czeka na potwierdzenie wysyłki
            [['path' => $path, 'name' => 'Faktura_' . preg_replace('/[^A-Za-z0-9\-_]+/', '-', (string)$inv['number']) . '.pdf',
              'mime' => 'application/pdf', 'size' => (int)@filesize($path)]]
        );
        mail_queue_process(1);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Błąd wysyłki: ' . $e->getMessage()];
    }

    return ['ok' => true, 'to' => $to];
}

/**
 * Wystawia fakturę DEMO ze źródła jednym krokiem: szkic → numer TEST/… → PDF.
 *
 * Dla administratora, do sprawdzenia jak wygląda gotowy dokument na prawdziwych
 * danych, bez ryzyka. Demo nie zużywa numeru produkcyjnego, nie idzie do
 * Fakturowni ani KSeF i nie jest wysyłane nabywcy (patrz invoice_is_test).
 * Ma własną „przegrodę" w indeksie źródła, więc nie blokuje faktury prawdziwej.
 *
 * @param string $source 'offer' albo 'ti_billing'
 * @return array{ok:bool,error?:string,id?:int,number?:string,pdf?:string}
 */
function invoice_demo_issue(string $source, int $source_id, int $uid): array
{
    $r = match ($source) {
        'offer'      => invoice_from_offer($source_id, $uid, true),
        'ti_billing' => invoice_from_ti_billing($source_id, $uid, true),
        default      => ['ok' => false, 'error' => 'Nieznane źródło faktury demo.'],
    };
    if (empty($r['ok'])) return $r;

    $id  = (int)$r['id'];
    $inv = invoice_get($id);

    // Istniejące demo mogło już zostać wystawione — wtedy tylko je pokazujemy.
    if ($inv && empty($inv['number'])) {
        $iss = invoice_issue_local($id);
        if (empty($iss['ok'])) return ['ok' => false, 'error' => (string)$iss['error']];
    }

    $pdf = invoice_pdf_store($id);
    $inv = invoice_get($id);

    return [
        'ok'     => true,
        'id'     => $id,
        'number' => (string)($inv['number'] ?? ''),
        'pdf'    => (string)($pdf ?? ''),
    ];
}

// ── Fakturownia: wystawianie i synchronizacja ────────────────────────────────

/**
 * Wystawia fakturę w Fakturowni.
 *
 * Zapisuje numer, link i lokalną kopię PDF. Przy błędzie ustawia status „blad"
 * i zachowuje komunikat — dokument zostaje w SZO, można poprawić i ponowić.
 *
 * @return array{ok:bool,error?:string}
 */
function invoice_push(int $id): array
{
    $inv = invoice_get($id);
    if (!$inv) return ['ok' => false, 'error' => 'Nie znaleziono faktury.'];
    if (!empty($inv['fakturownia_id'])) {
        return ['ok' => false, 'error' => 'Ta faktura jest już wystawiona w Fakturowni.'];
    }
    if (!$inv['items']) return ['ok' => false, 'error' => 'Faktura bez pozycji — dodaj co najmniej jedną.'];
    if (trim((string)$inv['buyer_name']) === '') return ['ok' => false, 'error' => 'Brak nazwy nabywcy.'];
    if (invoice_is_test($inv)) {
        return ['ok' => false, 'error' => 'Faktura testowa — nie wystawiamy jej w systemie zewnętrznym. Użyj wystawienia w SZO.'];
    }

    $cfg = invoices_config();
    if ($cfg['account'] === '' || $cfg['token'] === '') {
        return ['ok' => false, 'error' => 'Integracja z Fakturownią nie jest skonfigurowana (Administracja → Faktury).'];
    }

    $positions = [];
    foreach ($inv['items'] as $it) {
        $positions[] = [
            'name'             => $it['name'],
            'quantity'         => (float)$it['qty'],
            'quantity_unit'    => $it['unit'],
            'total_price_net'  => (float)$it['line_net'],
            'tax'              => $it['vat_rate'],
        ];
    }

    // Faktury z TI mają własną serię TI/nr/mm/rok — narzucamy ją Fakturowni,
    // żeby oznaczenie zgadzało się z panelem. Pozostałe źródła numeruje Fakturownia.
    $own_number = null;
    if ($inv['source'] === 'ti_billing' && ($p = invoice_ti_period($inv))) {
        $own_number = invoice_ti_number($p['month'], $p['year'], $p['course_id']);
    }

    $res = fakturownia_create_invoice($cfg['account'], $cfg['token'], [
        'kind'            => $inv['kind'],
        'number'          => $own_number,
        'sell_date'       => $inv['sell_date'],
        'issue_date'      => $inv['issue_date'],
        'payment_to'      => $inv['payment_to'],
        'buyer_name'      => $inv['buyer_name'],
        'buyer_tax_no'    => $inv['buyer_tax_no'],
        'buyer_post_code' => $inv['buyer_post_code'],
        'buyer_city'      => $inv['buyer_city'],
        'buyer_street'    => $inv['buyer_street'],
        'buyer_email'     => $inv['buyer_email'],
        'currency'        => $inv['currency'],
        'positions'       => $positions,
    ]);

    $now = date('Y-m-d H:i:s');
    if (empty($res['success'])) {
        db()->prepare("UPDATE invoices SET status='blad', last_error=?, updated_at=? WHERE id=?")
            ->execute([mb_substr((string)($res['error'] ?? 'Nieznany błąd'), 0, 500), $now, $id]);
        return ['ok' => false, 'error' => (string)($res['error'] ?? 'Nieznany błąd')];
    }

    db()->prepare(
        "UPDATE invoices
            SET status='wystawiona', number=?, fakturownia_id=?, fakturownia_url=?,
                issued_at=?, last_error=NULL, last_sync_at=?, updated_at=?
          WHERE id=?"
    )->execute([
        // Fakturownia zwraca nadany numer; przy własnej serii to nasz numer.
        (string)($res['number'] ?? '') ?: (string)$own_number,
        (int)$res['invoice_id'], (string)($res['invoice_url'] ?? ''),
        $now, $now, $now, $id,
    ]);

    // Kopia PDF jest wygodą, nie warunkiem powodzenia — brak nie unieważnia wystawienia.
    invoice_pdf_fetch($id);

    return ['ok' => true];
}

/**
 * Odświeża stan faktury z Fakturowni (numer, kwoty, status płatności).
 *
 * @return array{ok:bool,error?:string,status?:string}
 */
function invoice_sync(int $id): array
{
    $inv = invoice_get($id);
    if (!$inv) return ['ok' => false, 'error' => 'Nie znaleziono faktury.'];
    if (empty($inv['fakturownia_id'])) return ['ok' => false, 'error' => 'Faktura nie została jeszcze wystawiona.'];

    $cfg = invoices_config();
    $res = fakturownia_get_invoice($cfg['account'], $cfg['token'], (int)$inv['fakturownia_id']);
    $now = date('Y-m-d H:i:s');

    if (empty($res['ok'])) {
        db()->prepare("UPDATE invoices SET last_error=?, last_sync_at=?, updated_at=? WHERE id=?")
            ->execute([mb_substr((string)$res['error'], 0, 500), $now, $now, $id]);
        return ['ok' => false, 'error' => (string)$res['error']];
    }

    $f = $res['invoice'];

    // „paid" w Fakturowni bywa kwotą tekstową — porównujemy liczbowo z brutto.
    $paid    = (float)($f['paid'] ?? 0);
    $gross   = (float)($f['price_gross'] ?? $inv['total_gross']);
    $status  = $inv['status'];
    $paid_at = $inv['paid_at'];

    if (!empty($f['cancelled']) || ($f['status'] ?? '') === 'cancelled') {
        $status = 'anulowana';
    } elseif ($gross > 0 && $paid + 0.005 >= $gross) {
        $status  = 'zaplacona';
        $paid_at = $paid_at ?: ($f['paid_date'] ?? $now);
    } else {
        $status = 'wystawiona';
    }

    db()->prepare(
        "UPDATE invoices
            SET status=?, number=?, total_net=?, total_vat=?, total_gross=?,
                paid_at=?, last_error=NULL, last_sync_at=?, updated_at=?
          WHERE id=?"
    )->execute([
        $status,
        (string)($f['number'] ?? $inv['number']),
        (float)($f['price_net']   ?? $inv['total_net']),
        round((float)($f['price_gross'] ?? $inv['total_gross']) - (float)($f['price_net'] ?? $inv['total_net']), 2),
        (float)($f['price_gross'] ?? $inv['total_gross']),
        $paid_at, $now, $now, $id,
    ]);

    return ['ok' => true, 'status' => $status];
}

/** Pobiera i zapisuje lokalną kopię PDF. Zwraca ścieżkę albo null. */
function invoice_pdf_fetch(int $id): ?string
{
    $inv = invoice_get($id);
    if (!$inv || empty($inv['fakturownia_id'])) return null;

    $cfg = invoices_config();
    $pdf = fakturownia_invoice_pdf($cfg['account'], $cfg['token'], (int)$inv['fakturownia_id']);
    if ($pdf === null) return null;

    $name = 'FV_' . $id . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)$inv['number']) . '.pdf';
    $path = invoices_pdf_dir() . '/' . $name;
    if (@file_put_contents($path, $pdf) === false) return null;

    db()->prepare("UPDATE invoices SET pdf_path=?, updated_at=? WHERE id=?")
        ->execute([$name, date('Y-m-d H:i:s'), $id]);
    return $path;
}

// ── Budowanie faktury ze źródeł ──────────────────────────────────────────────

/** Rozbija adres kontaktu CRM na pola nabywcy wymagane przez Fakturownię. */
function invoice_buyer_from_contact(array $c): array
{
    $street = trim((string)($c['addr_street'] ?? ''));
    if ($street !== '') {
        $street = trim($street . ' ' . trim((string)($c['addr_house'] ?? '')));
        $flat   = trim((string)($c['addr_flat'] ?? ''));
        if ($flat !== '') $street .= '/' . $flat;
    }
    // Starsze kontakty mają tylko jednolinijkowy `adres` — wtedy zostawiamy go w ulicy.
    if ($street === '') $street = trim((string)($c['adres'] ?? ''));

    return [
        'contact_id'      => (int)$c['id'],
        'buyer_name'      => (string)$c['imie_nazwisko'],
        'buyer_tax_no'    => (string)($c['nip'] ?? ''),
        'buyer_street'    => $street,
        'buyer_post_code' => (string)($c['addr_postal'] ?? ''),
        'buyer_city'      => (string)($c['addr_city'] ?? ''),
        'buyer_email'     => (string)($c['email'] ?? ''),
    ];
}

/**
 * Tworzy szkic faktury z oferty CRM (wybrany wariant).
 *
 * @return array{ok:bool,id?:int,error?:string}
 */
function invoice_from_offer(int $offer_id, int $uid, bool $demo = false): array
{
    invoices_migrate();

    // Faktura demo żyje obok produkcyjnej — szukamy tylko w swojej „przegrodzie".
    $existing = db_one(
        "SELECT id FROM invoices WHERE source='offer' AND source_id=? AND is_test=? AND deleted_at IS NULL",
        [$offer_id, $demo ? 1 : 0]
    );
    if ($existing) return ['ok' => true, 'id' => (int)$existing['id'], 'existing' => true];

    $offer = db_one("SELECT * FROM crm_offers WHERE id=? AND deleted_at IS NULL", [$offer_id]);
    if (!$offer) return ['ok' => false, 'error' => 'Nie znaleziono oferty.'];

    // Oferta dla osoby fizycznej wymaga potwierdzenia — bez niego nie fakturujemy.
    if (!empty($offer['requires_confirmation']) && empty($offer['confirmation_id'])) {
        return ['ok' => false, 'error' => 'Oferta wymaga potwierdzenia przez osobę fizyczną — nie można jej zafakturować.'];
    }

    $variant_id = (int)($offer['selected_variant_id'] ?? 0);
    if (!$variant_id) {
        $v = db_one("SELECT id FROM crm_offer_variants WHERE offer_id=? ORDER BY sort_order, id LIMIT 1", [$offer_id]);
        $variant_id = (int)($v['id'] ?? 0);
    }
    if (!$variant_id) return ['ok' => false, 'error' => 'Oferta nie ma wariantu z pozycjami.'];

    $rows = db_all(
        "SELECT name, unit, qty, unit_net, vat_rate, discount_pct
           FROM crm_offer_items
          WHERE offer_id=? AND variant_id=? AND is_optional=0
       ORDER BY sort_order, id",
        [$offer_id, $variant_id]
    );
    if (!$rows) return ['ok' => false, 'error' => 'Wybrany wariant oferty nie ma pozycji obowiązkowych.'];

    $items = [];
    foreach ($rows as $r) {
        // Rabat pozycji przenosimy do ceny jednostkowej — Fakturownia liczy od niej.
        $unit = (float)$r['unit_net'] * (1 - ((float)$r['discount_pct'] / 100));
        $items[] = [
            'name'     => $r['name'],
            'unit'     => $r['unit'],
            'qty'      => (float)$r['qty'],
            'unit_net' => round($unit, 2),
            'vat_rate' => (string)$r['vat_rate'],
        ];
    }

    $contact = db_one("SELECT * FROM crm_contacts WHERE id=?", [(int)$offer['contact_id']]);
    if (!$contact) return ['ok' => false, 'error' => 'Oferta nie ma powiązanego kontaktu.'];

    $data = invoice_buyer_from_contact($contact) + [
        'source'     => 'offer',
        'source_id'  => $offer_id,
        'is_test'    => $demo ? 1 : 0,
        'currency'   => (string)$offer['currency'],
        'payment_to' => date('Y-m-d', strtotime('+' . max(0, (int)$offer['payment_terms_days']) . ' days')),
        'notes'      => 'Oferta ' . $offer['offer_number'] . ' — ' . $offer['title'],
    ];

    return ['ok' => true, 'id' => invoice_create($data, $items, $uid)];
}

/**
 * Tworzy szkic faktury z rozliczenia TI (miesiąc kursanta).
 *
 * Nabywcą jest płatnik rozliczenia (payer_name), a gdy go nie ma — sam kursant.
 * Kursant TI nie musi mieć kartoteki CRM, dlatego contact_id bywa puste i dane
 * nabywcy trzeba uzupełnić w szkicu przed wystawieniem.
 *
 * @return array{ok:bool,id?:int,error?:string}
 */
function invoice_from_ti_billing(int $billing_id, int $uid, bool $demo = false): array
{
    invoices_migrate();

    $existing = db_one(
        "SELECT id FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=? AND deleted_at IS NULL",
        [$billing_id, $demo ? 1 : 0]
    );
    if ($existing) return ['ok' => true, 'id' => (int)$existing['id'], 'existing' => true];

    $b = db_one(
        "SELECT b.*, COALESCE(b.course_id,0) AS course_id,
                c.name AS client_name, c.email AS client_email, c.address AS client_address
           FROM k30_ti_billing b
           JOIN k30_clients   c ON c.id = b.client_id
          WHERE b.id = ?",
        [$billing_id]
    );
    if (!$b) return ['ok' => false, 'error' => 'Nie znaleziono rozliczenia.'];

    $amount = (float)$b['amount'];
    if ($amount <= 0) return ['ok' => false, 'error' => 'Rozliczenie ma kwotę zero — nie ma czego fakturować.'];

    // Grupa może być wyłączona z fakturowania (k30_ti_courses.no_invoice) — np.
    // zajęcia finansowane z dotacji. Sprawdzamy w modelu, nie tylko w interfejsie,
    // żeby reguła obowiązywała też generowanie zbiorcze i przyszłe wywołania.
    if ((int)$b['course_id'] > 0) {
        try {
            $co = db_one("SELECT name, COALESCE(no_invoice,0) AS no_invoice FROM k30_ti_courses WHERE id=?", [(int)$b['course_id']]);
            if ($co && !empty($co['no_invoice'])) {
                return ['ok' => false, 'error' => 'Grupa „' . (string)$co['name'] . '" jest wyłączona z fakturowania.'];
            }
        } catch (\Throwable $e) { /* kolumna dochodzi migracją */ }
    }

    $cfg    = invoices_config();
    $okres  = str_pad((string)(int)$b['month'], 2, '0', STR_PAD_LEFT) . '/' . (int)$b['year'];

    // Grupa (kurs) na pozycji faktury. W modelu kombinowanym rozliczenie dotyczy
    // JEDNEJ grupy (k30_ti_billing.course_id) — wtedy tylko ją nazywamy. Dopiero
    // rozliczenie łączne (course_id = 0) wymienia wszystkie grupy kursanta.
    $course_id = (int)$b['course_id'];
    if ($course_id > 0) {
        $groups = array_column(db_all(
            "SELECT name FROM k30_ti_courses WHERE id = ?", [$course_id]
        ), 'name');
    } else {
        $groups = array_column(db_all(
            "SELECT DISTINCT co.name
               FROM k30_ti_enrollments e
               JOIN k30_ti_courses    co ON co.id = e.course_id
              WHERE e.client_id = ?
           ORDER BY co.name",
            [(int)$b['client_id']]
        ), 'name');
    }
    $group_txt = $groups ? implode(', ', $groups) : '';

    // Pozycje z kalkulatora rozliczeń (per grupa, per stawka, ryczałt jako usługa)
    // + korekta; suma = należność (amount + adjustment). Patrz invoice_ti_items().
    $ti = invoice_ti_items($b, (string)$cfg['vat']);
    if (!$ti['items']) return ['ok' => false, 'error' => 'Brak pozycji do zafakturowania.'];
    $items = $ti['items'];

    // Termin zapłaty z rozliczenia — ale nie wcześniejszy niż data wystawienia
    // faktury (rozliczenie mogło być wystawione dawno, np. faktura po terminie).
    $pay_to = (string)($b['due_date'] ?? '');
    if ($pay_to === '' || $pay_to < date('Y-m-d')) {
        if ($pay_to !== '') $ti['warnings'][] = 'Termin płatności z rozliczenia (' . date('d.m.Y', strtotime($pay_to)) . ') już minął — na fakturze ustawiono ' . $cfg['days'] . ' dni od dziś.';
        $pay_to = date('Y-m-d', strtotime('+' . $cfg['days'] . ' days'));
    }

    $buyer_name = trim((string)$b['payer_name']) ?: (string)$b['client_name'];

    // Jeśli płatnik ma kartotekę w CRM (po nazwie), podłączamy ją — dzięki temu
    // faktura pojawi się na osi czasu kontaktu.
    $contact = db_one(
        "SELECT * FROM crm_contacts WHERE crm_active=1 AND LOWER(imie_nazwisko)=LOWER(?) LIMIT 1",
        [$buyer_name]
    );

    $data = $contact ? invoice_buyer_from_contact($contact) : [
        'buyer_name'   => $buyer_name,
        'buyer_street' => (string)($b['client_address'] ?? ''),
        'buyer_email'  => (string)($b['client_email'] ?? ''),
    ];
    $data += [
        'source'     => 'ti_billing',
        'source_id'  => $billing_id,
        'is_test'    => $demo ? 1 : 0,
        'payment_to' => $pay_to,
        'notes'      => 'Rozliczenie TI ' . $okres . ' — ' . $b['client_name']
                        . ($group_txt !== '' ? ' (' . $group_txt . ')' : ''),
    ];

    // Płatnik wyglądający na firmę/instytucję bez NIP → faktura poszłaby jak dla
    // osoby fizycznej, poza KSeF (B2B w KSeF jest obowiązkowe). Nie blokujemy —
    // NIP uzupełnia się w kartotece CRM płatnika — ale operator musi to zobaczyć.
    if (trim((string)($data['buyer_tax_no'] ?? '')) === '' && invoice_name_looks_company((string)$data['buyer_name'])) {
        $ti['warnings'][] = 'Nabywca „' . $data['buyer_name'] . '" wygląda na firmę/instytucję, a nie ma NIP (brak kartoteki CRM z NIP o tej nazwie) — faktura trafi poza KSeF jak dla osoby fizycznej.';
    }

    return ['ok' => true, 'id' => invoice_create($data, $items, $uid), 'warnings' => $ti['warnings']];
}

/** Heurystyka: nazwa nabywcy wskazuje na firmę/instytucję (nie osobę fizyczną). */
function invoice_name_looks_company(string $name): bool
{
    return (bool)preg_match('/\b(sp\.?\s*z\s*o\.?\s*o\.?|spółka|s\.\s?a\.|sp\.\s?[jkp]\.|fundacja|stowarzyszenie|pfron|urząd|gmina|powiat|szkoła|przedszkole|zakład|instytut|uczelnia|uniwersytet|ośrodek|centrum|firma|ltd|gmbh|inc)\b/iu', $name);
}

/**
 * Pozycje faktury z rozliczenia TI (wiersz k30_ti_billing).
 *
 * Źródło: k30_ti_billing_fv_positions() — to samo co pozycje FV i wydruk
 * rozliczenia: osobno każda grupa, godzinowo z rozbiciem per stawka (zmiana
 * ceny w trakcie miesiąca), ryczałt jako 1 usługa, korekta rozliczenia
 * (adjustment: rabat z poleceń, ręczna korekta) jako osobna pozycja — należność
 * kursanta to amount + adjustment i tyle musi wynosić faktura.
 *
 * Kwoty rozliczenia są BRUTTO dla nabywcy; przy stawce liczbowej netto
 * liczone jest z brutto. Gdy przeliczenie „na żywo" nie zgadza się z kwotą
 * zapisaną w rozliczeniu (np. obecność poprawiona po wystawieniu), faktura
 * idzie wg ZAPISANEJ kwoty jedną pozycją, a warnings to zgłasza.
 *
 * @return array{items: list<array>, warnings: list<string>, positions_total: float, due: float}
 */
function invoice_ti_items(array $b, string $vat): array
{
    require_once __DIR__ . '/karty30.php';
    $warnings = [];
    $amount   = round((float)$b['amount'], 2);
    $adj      = round((float)($b['adjustment'] ?? 0), 2);
    $due      = round($amount + $adj, 2);
    $to_net   = fn(float $gross) => is_numeric($vat) ? round($gross / (1 + ((float)$vat / 100)), 2) : round($gross, 2);

    // Pozycje FV zawierają już korektę (Rabat / Opłata dodatkowa) — suma = należność
    $pos   = k30_ti_billing_fv_positions($b);
    $total = round(array_sum(array_column($pos, 'value')), 2);
    $items = [];
    if ($pos && abs($total - $due) < 0.01) {
        foreach ($pos as $p) {
            $qty = (float)$p['qty'] > 0 ? (float)$p['qty'] : 1.0;
            $net = $to_net((float)$p['value']);
            // qty × cena musi dać wartość pozycji; przy VAT liczbowym cena netto
            // z zaokrągleniem mogłaby rozjechać sumę — wtedy pozycja jako 1 usługa.
            $unit = round($net / $qty, 2);
            $unit_lbl = (string)$p['unit'];
            if (abs(round($unit * $qty, 2) - $net) >= 0.005) { $qty = 1.0; $unit = $net; $unit_lbl = 'usł.'; }
            $items[] = ['name' => (string)$p['name'], 'unit' => $unit_lbl, 'qty' => $qty,
                        'unit_net' => $unit, 'vat_rate' => $vat];
        }
    } else {
        $live = round($total - $adj, 2);
        if ($pos) $warnings[] = sprintf('Rozliczenie nieaktualne: zapisano %s zł, przeliczenie z obecności daje %s zł — faktura wg kwoty zapisanej (jedna pozycja). Przelicz rozliczenie, jeśli to błąd.',
                                        number_format($amount, 2, ',', ' '), number_format($live, 2, ',', ' '));
        $okres = str_pad((string)(int)$b['month'], 2, '0', STR_PAD_LEFT) . '/' . (int)$b['year'];
        if ($amount > 0) $items[] = ['name' => 'Zajęcia TI, okres ' . $okres, 'unit' => 'usł.', 'qty' => 1.0,
                                     'unit_net' => $to_net($amount), 'vat_rate' => $vat];
        if (abs($adj) >= 0.005) {
            $note = trim((string)($b['adjustment_note'] ?? ''));
            $items[] = ['name' => ($adj < 0 ? 'Rabat' : 'Opłata dodatkowa') . ($note !== '' ? ' — ' . $note : ''),
                        'unit' => 'usł.', 'qty' => 1.0, 'unit_net' => $to_net($adj), 'vat_rate' => $vat];
        }
    }
    $gross = 0.0;
    foreach ($items as $it) $gross += invoice_item_calc($it)['line_gross'];
    if (abs(round($gross, 2) - $due) >= 0.01) {
        $warnings[] = sprintf('Suma faktury %s zł różni się od należności %s zł (zaokrąglenia VAT).',
                              number_format($gross, 2, ',', ' '), number_format($due, 2, ',', ' '));
    }
    return ['items' => $items, 'warnings' => $warnings, 'positions_total' => $total, 'due' => $due];
}
