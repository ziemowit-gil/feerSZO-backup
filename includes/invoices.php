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
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inv_status  ON invoices(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inv_contact ON invoices(contact_id)");
    // Jedna faktura na źródło — druga próba wystawienia z tej samej oferty
    // czy rozliczenia ma trafić na istniejący dokument, nie stworzyć duplikatu.
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_inv_source
                ON invoices(source, source_id) WHERE source_id IS NOT NULL AND deleted_at IS NULL");

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
 * Kolejny numer w serii TI: `TI/nr/mm/rok`, numeracja narastająca w obrębie miesiąca.
 *
 * Numer nadajemy przy WYSTAWIENIU, nie przy tworzeniu szkicu — inaczej usunięty
 * szkic zostawiałby lukę w numeracji. Dla szkicu liczymy numer poglądowo
 * (invoice_ti_number_preview) i nie zapisujemy go.
 */
function invoice_ti_number(int $month, int $year): string
{
    invoices_migrate();
    $mm   = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
    $sfx  = '/' . $mm . '/' . $year;
    $next = 1;

    // Bierzemy tylko numery faktycznie nadane (wystawione), żeby seria była ciągła.
    foreach (db_all(
        "SELECT number FROM invoices
          WHERE source='ti_billing' AND number LIKE ? AND deleted_at IS NULL",
        ['TI/%' . $sfx]
    ) as $r) {
        if (preg_match('#^TI/(\d+)/#', (string)$r['number'], $m)) {
            $next = max($next, (int)$m[1] + 1);
        }
    }
    return 'TI/' . $next . $sfx;
}

/** Okres (miesiąc, rok) rozliczenia TI, z którego powstała faktura. */
function invoice_ti_period(array $inv): ?array
{
    if (($inv['source'] ?? '') !== 'ti_billing' || empty($inv['source_id'])) return null;
    $b = db_one("SELECT month, year FROM k30_ti_billing WHERE id=?", [(int)$inv['source_id']]);
    if (!$b) return null;
    return ['month' => (int)$b['month'], 'year' => (int)$b['year']];
}

/** Numer poglądowy dla szkicu TI — do wydruku roboczego, nie zapisywany. */
function invoice_ti_number_preview(array $inv): string
{
    $p = invoice_ti_period($inv);
    return $p ? invoice_ti_number($p['month'], $p['year']) : '';
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
        $own_number = invoice_ti_number($p['month'], $p['year']);
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
function invoice_from_offer(int $offer_id, int $uid): array
{
    invoices_migrate();

    $existing = db_one(
        "SELECT id FROM invoices WHERE source='offer' AND source_id=? AND deleted_at IS NULL",
        [$offer_id]
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
function invoice_from_ti_billing(int $billing_id, int $uid): array
{
    invoices_migrate();

    $existing = db_one(
        "SELECT id FROM invoices WHERE source='ti_billing' AND source_id=? AND deleted_at IS NULL",
        [$billing_id]
    );
    if ($existing) return ['ok' => true, 'id' => (int)$existing['id'], 'existing' => true];

    $b = db_one(
        "SELECT b.*, c.name AS client_name, c.email AS client_email, c.address AS client_address
           FROM k30_ti_billing b
           JOIN k30_clients   c ON c.id = b.client_id
          WHERE b.id = ?",
        [$billing_id]
    );
    if (!$b) return ['ok' => false, 'error' => 'Nie znaleziono rozliczenia.'];

    $amount = (float)$b['amount'];
    if ($amount <= 0) return ['ok' => false, 'error' => 'Rozliczenie ma kwotę zero — nie ma czego fakturować.'];

    $cfg    = invoices_config();
    $okres  = str_pad((string)(int)$b['month'], 2, '0', STR_PAD_LEFT) . '/' . (int)$b['year'];
    $hours  = (float)$b['hours_billed'];

    // Grupa (kurs) na pozycji faktury — rozliczenie TI jest per kursant i miesiąc,
    // ale na dokumencie musi być widać, za jakie zajęcia. Kursant może być
    // zapisany do kilku grup; wtedy wymieniamy wszystkie.
    $groups = array_column(db_all(
        "SELECT DISTINCT co.name
           FROM k30_ti_enrollments e
           JOIN k30_ti_courses    co ON co.id = e.course_id
          WHERE e.client_id = ?
       ORDER BY co.name",
        [(int)$b['client_id']]
    ), 'name');
    $group_txt = $groups ? implode(', ', $groups) : '';

    // Kwota rozliczenia TI jest kwotą do zapłaty (brutto dla nabywcy). Przy stawce
    // VAT wyliczamy z niej netto, żeby suma faktury zgadzała się z rozliczeniem.
    $rate = $cfg['vat'];
    $net  = is_numeric($rate) ? round($amount / (1 + ((float)$rate / 100)), 2) : $amount;
    $qty  = $hours > 0 ? $hours : 1;

    $items = [[
        'name'     => 'Zajęcia' . ($group_txt !== '' ? ' — ' . $group_txt : '') . ', okres ' . $okres
                      . ($hours > 0 ? ' (' . rtrim(rtrim(number_format($hours, 2, ',', ' '), '0'), ',') . ' godz.)' : ''),
        'unit'     => $hours > 0 ? 'godz.' : 'usł.',
        'qty'      => $qty,
        'unit_net' => round($net / $qty, 2),
        'vat_rate' => $rate,
    ]];

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
        'payment_to' => (string)($b['due_date'] ?? '') ?: date('Y-m-d', strtotime('+' . $cfg['days'] . ' days')),
        'notes'      => 'Rozliczenie TI ' . $okres . ' — ' . $b['client_name']
                        . ($group_txt !== '' ? ' (' . $group_txt . ')' : ''),
    ];

    return ['ok' => true, 'id' => invoice_create($data, $items, $uid)];
}
