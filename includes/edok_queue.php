<?php
/**
 * EODoK — Kolejka do opisu: wczytywanie faktur z XML (KSeF FA) i przyjmowanie
 * załączników z e-maila. Silnik kolejki (tabela edok_queue, zapis plików) jest
 * w includes/edok.php; tu jest to, co dokłada się do niej „z zewnątrz”.
 */
require_once __DIR__ . '/edok.php';
require_once __DIR__ . '/crm_offers.php'; // CRM_OFFER_VAT_RATES (stawki VAT w parserze XML)

/** Kwota z XML (kropka) → format formularza EODoK („1234,50”). */
function _edok_xml_money(float $v): string {
    return number_format($v, 2, ',', '');
}

/**
 * Parsuje fakturę ustrukturyzowaną (FA(2)/FA(3)) do pól formularza edok/add.php.
 * Zwraca [] gdy to nie jest faktura KSeF. Stawka VAT jest ustawiana tylko, gdy faktura
 * ma jedną stawkę — przy mieszanych zostaje puste, a suma VAT trafia do kwoty VAT.
 */
/**
 * Wyłuskuje numer transakcji z bramki płatności z tekstu (opis płatności innej, dodatkowy opis faktury, pole z OCR).
 * Rozpoznaje m.in.: DotPay (M12345-67890), PayU (kod w nawiasie), Przelewy24 / P24 / Tpay / PayPal z numerem po nazwie.
 */
function edok_gateway_txn_from_text(string $text): string {
    $text = trim($text);
    if ($text === '') return '';
    if (preg_match('/\b(M\d{4,6}-\d{3,8})\b/', $text, $m)) return $m[1];                                        // DotPay
    if (preg_match('/(?:payu|p24|przelewy\s*24|tpay|paypal|blue\s*media|paynow|stripe|hotpay)[^A-Za-z0-9]{0,12}\(?([A-Za-z0-9][A-Za-z0-9\-_]{7,39})\)?/i', $text, $m)) return $m[1];
    if (preg_match('/\(([A-Z0-9]{10,40})\)/', $text, $m) && preg_match('/pay|p24|przelew|bramk|internet/i', $text)) return $m[1];   // PayU: „(KSV8N68TCP…)"
    return '';
}

function edok_parse_invoice_xml(string $xml, string $kontrahent = 'Podmiot1'): array {
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    // Uwaga: dokument z prefiksem przestrzeni nazw (ns0:Faktura) jest dla SimpleXML „pusty” w bool — porównujemy ściśle.
    if ($sx === false || $sx->getName() !== 'Faktura') return [];
    $one = function (string $path) use ($sx): string {
        $n = $sx->xpath($path);
        return $n ? trim((string)$n[0]) : '';
    };
    $num = fn(string $tag): float => (float)str_replace(',', '.', $one('//*[local-name()="Fa"]/*[local-name()="' . $tag . '"]'));
    $fa = '//*[local-name()="Fa"]';

    // Netto/VAT wg stawek: P_13_x = netto, P_14_x = VAT.
    $rates = ['23' => ['P_13_1', 'P_14_1'], '8' => ['P_13_2', 'P_14_2'], '5' => ['P_13_3', 'P_14_3']];
    $netto = 0.0; $vat = 0.0; $used = [];
    foreach ($rates as $code => [$n, $v]) {
        $nv = $num($n);
        if ($nv != 0.0) { $used[] = (string)$code; }
        $netto += $nv; $vat += $num($v);
    }
    // P_13_4/5 (taksówki, procedura szczególna), P_13_6_1..3 (0%), P_13_7 (zw.)
    $x = $num('P_13_4'); $netto += $x; $vat += $num('P_14_4'); if ($x) $used[] = 'x';
    foreach (['P_13_6_1', 'P_13_6_2', 'P_13_6_3'] as $t) { $x = $num($t); $netto += $x; if ($x) $used[] = '0'; }
    $zw = $num('P_13_7'); $netto += $zw; if ($zw) $used[] = 'zw';
    // P_13_8/9 (poza terytorium kraju / art. 100 ust. 1 pkt 4), P_13_10 (odwrotne obciążenie) → „np.”; P_13_11 (marża) — bez stawki.
    foreach (['P_13_8', 'P_13_9', 'P_13_10'] as $t) { $x = $num($t); $netto += $x; if ($x) $used[] = 'np'; }
    $x = $num('P_13_11'); $netto += $x; if ($x) $used[] = 'x';
    $used = array_values(array_unique($used));

    $brutto = $num('P_15');
    $typ = $one($fa . '/*[local-name()="RodzajFaktury"]');
    $wiersze = $sx->xpath($fa . '/*[local-name()="FaWiersz"]/*[local-name()="P_7"]') ?: [];
    $opis = $wiersze ? trim((string)$wiersze[0]) : '';
    if (count($wiersze) > 1) $opis .= ' (+' . (count($wiersze) - 1) . ' poz.)';
    // Faktura korygująca (KOR, KOR_ZAL, KOR_ROZ): kwoty w XML to różnica względem korygowanej (może być ujemna).
    $kor = str_starts_with($typ, 'KOR');
    if ($kor) {
        $nr_kor = $one($fa . '/*[local-name()="DaneFaKorygowanej"]/*[local-name()="NrFaKorygowanej"]');
        $data_kor = $one($fa . '/*[local-name()="DaneFaKorygowanej"]/*[local-name()="DataWystFaKorygowanej"]');
        $przyczyna = $one($fa . '/*[local-name()="PrzyczynaKorekty"]');
        $opis = trim('Korekta faktury' . ($nr_kor !== '' ? " nr {$nr_kor}" : '') . ($data_kor !== '' ? " z dnia {$data_kor}" : '')
            . ($przyczyna !== '' ? " — {$przyczyna}" : '') . ($opis !== '' ? ". {$opis}" : ''));
    }

    // Płatność (FA(3): Platnosc) — Zaplacono=1 → faktura zapłacona przed wystawieniem; FormaPlatnosci: 1 gotówka, 2 karta,
    // 3 bon, 4 czek, 5 kredyt, 6 przelew, 7 płatność mobilna.
    $pl = $fa . '/*[local-name()="Platnosc"]';
    $zaplacona = $one($pl . '/*[local-name()="Zaplacono"]') === '1';
    $forma = match ($one($pl . '/*[local-name()="FormaPlatnosci"]')) {
        '1' => 'gotowka', '2', '7' => 'karta', '6' => 'przelew', '' => '', default => 'inna',
    };

    return [
        'typ_dokumentu'    => str_starts_with($typ, 'KOR') ? 'faktura_korygujaca' : 'faktura_vat',
        'nr_faktury'       => $one($fa . '/*[local-name()="P_2"]'),
        'kontrahent_nazwa' => $one('//*[local-name()="' . $kontrahent . '"]//*[local-name()="Nazwa"]') ?: $one('//*[local-name()="' . $kontrahent . '"]//*[local-name()="PelnaNazwa"]'),
        'kontrahent_nip'   => $one('//*[local-name()="' . $kontrahent . '"]//*[local-name()="NIP"]'),
        'data_wystawienia' => $one($fa . '/*[local-name()="P_1"]'),
        'data_sprzedazy'   => $one($fa . '/*[local-name()="P_6"]'),
        'kwota_netto'      => $brutto ? _edok_xml_money($netto) : '',
        'stawka_vat'       => count($used) === 1 && isset(CRM_OFFER_VAT_RATES[$used[0]]) ? $used[0] : '',
        'kwota_vat'        => $brutto ? _edok_xml_money($vat) : '',
        'kwota_brutto'     => $brutto ? _edok_xml_money($brutto) : '',
        'waluta'           => $one($fa . '/*[local-name()="KodWaluty"]') ?: 'PLN',
        'termin_platnosci' => $one($fa . '/*[local-name()="Platnosc"]/*[local-name()="TerminPlatnosci"]/*[local-name()="Termin"]'),
        'rachunek_bankowy' => preg_replace('/\s+/', '', $one($fa . '/*[local-name()="Platnosc"]/*[local-name()="RachunekBankowy"]/*[local-name()="NrRB"]')),
        'description'      => $opis,
        'nr_transakcji_bramki' => (function () use ($sx) {
            $t = [];
            foreach (['OpisPlatnosci', 'Wartosc', 'Klucz', 'Tresc'] as $tag) foreach ($sx->xpath('//*[local-name()="' . $tag . '"]') ?: [] as $n) $t[] = trim((string)$n);
            return edok_gateway_txn_from_text(implode(' | ', $t));
        })(),
        'zaplacono_przed'  => $zaplacona ? '1' : '',
        'data_zaplaty'     => $zaplacona ? $one($pl . '/*[local-name()="DataZaplaty"]') : '',
        'forma_zaplaty'    => $zaplacona ? $forma : '',
    ];
}

/** Dane z pliku XML w uploads/ (ścieżka względna) albo [] przy braku/niepoprawnym pliku. */
function edok_parse_invoice_xml_file(string $rel): array {
    $abs = UPLOAD_DIR . $rel;
    if (!is_file($abs) || strtolower(pathinfo($abs, PATHINFO_EXTENSION)) !== 'xml' || filesize($abs) > 5 * 1024 * 1024) return [];
    return edok_parse_invoice_xml((string)file_get_contents($abs));
}

// ── Przyjmowanie plików z e-maila ───────────────────────────────────────────

/** Czy nadawca może wrzucać pliki do kolejki: użytkownik z rolą upload/admin albo adres z listy dozwolonych. */
function edok_queue_mail_sender_allowed(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '') return false;
    $u = db_one("SELECT id, role FROM users WHERE LOWER(email) = ?", [$email]);
    if ($u && ($u['role'] === 'admin' || db_one("SELECT 1 FROM edok_user_roles WHERE user_id = ? AND role = 'upload'", [(int)$u['id']]))) return true;
    foreach (preg_split('/[\s,;]+/', strtolower(org_setting('edok_queue_mail_allowed')), -1, PREG_SPLIT_NO_EMPTY) as $rule) {
        if ($rule === $email || ($rule[0] === '@' && str_ends_with($email, $rule))) return true;
    }
    return false;
}

// ── Dziennik odbioru skrzynki (pulpit edok/mailbox.php) ─────────────────────

/** Tabela dziennika: co przyszło, od kogo i co z tym zrobiono (kolejka / dokument / duplikat / pominięto / błąd). */
function edok_mailbox_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    db()->exec("CREATE TABLE IF NOT EXISTS edok_mailbox_log (
        id          {$pk},
        created_at  VARCHAR(19) NOT NULL,
        received_at VARCHAR(25) NOT NULL DEFAULT '',
        msg_key     VARCHAR(255) NOT NULL DEFAULT '',
        att_key     VARCHAR(255) NOT NULL DEFAULT '',
        from_addr   VARCHAR(255) NOT NULL DEFAULT '',
        subject     VARCHAR(500) NOT NULL DEFAULT '',
        att_name    VARCHAR(255) NOT NULL DEFAULT '',
        action      VARCHAR(20) NOT NULL DEFAULT '',
        reason      VARCHAR(500) NOT NULL DEFAULT '',
        queue_id    INTEGER,
        doc_id      INTEGER
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_edok_mblog_msg ON edok_mailbox_log(msg_key, att_key)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_edok_mblog_created ON edok_mailbox_log(created_at)");
}

/** Wpis do dziennika. action: queued | doc | dup | skipped | ignored | noatt | error. */
function edok_mailbox_log(array $m, string $action, string $reason = '', array $x = []): void {
    try {
        edok_mailbox_migrate();
        // Decyzje na poziomie wiadomości (bez załącznika) zapisujemy raz — bez Mail.ReadWrite wiadomość zostaje nieprzeczytana i wracałaby co 5 minut.
        if (($x['att_id'] ?? '') === '' && in_array($action, ['skipped', 'noatt'], true) && ($m['id'] ?? '') !== ''
            && db_one("SELECT 1 FROM edok_mailbox_log WHERE msg_key = ? AND att_key = '' AND action = ?", [(string)$m['id'], $action])) return;
        db_insert('edok_mailbox_log', [
            'created_at' => date('Y-m-d H:i:s'), 'received_at' => mb_substr((string)($m['receivedDateTime'] ?? ''), 0, 25),
            'msg_key' => (string)($m['id'] ?? ''), 'att_key' => (string)($x['att_id'] ?? ''),
            'from_addr' => mb_substr(strtolower((string)($m['from']['emailAddress']['address'] ?? '')), 0, 255),
            'subject' => mb_substr((string)($m['subject'] ?? ''), 0, 500), 'att_name' => mb_substr((string)($x['att_name'] ?? ''), 0, 255),
            'action' => $action, 'reason' => mb_substr($reason, 0, 500),
            'queue_id' => $x['queue_id'] ?? null, 'doc_id' => $x['doc_id'] ?? null,
        ]);
    } catch (\Throwable $e) { error_log('[edok mailbox log] ' . $e->getMessage()); }
}

/** Załącznik już obsłużony (kolejka albo dziennik: kolejka/dokument/duplikat/ignorowany) — błędy są ponawiane. */
function edok_mailbox_att_done(string $mid, string $aid): bool {
    edok_mailbox_migrate();
    if (db_one("SELECT 1 FROM edok_queue WHERE mail_msg_id = ? AND mail_att_id = ?", [$mid, $aid])) return true;
    return (bool)db_one("SELECT 1 FROM edok_mailbox_log WHERE msg_key = ? AND att_key = ? AND action IN ('queued','doc','dup','ignored')", [$mid, $aid]);
}

/** Odbicia, autoodpowiedzi i własna skrzynka — NIE faktury (no-reply bywa jednak prawdziwym nadawcą faktur, więc go nie odrzucamy). */
const EDOK_MAIL_AUTO_SUBJECT = '/^(auto(matic)?[ -]?(reply|response|antwort)|automatyczna odpowied|odpowied[zź] automatyczna|out of office|poza biurem|nieobecno|undeliver|niedostarcz|delivery (status|failure)|mail delivery|returned mail|zwrócona wiadomo|autoreply|abwesenheit|read:|przeczytano:)/iu';
const EDOK_MAIL_BOUNCE_SENDER = '/^(mailer-daemon|postmaster|bounces?)([+.@_-]|$)/i';

function edok_queue_mail_skip_reason(string $from, string $subject, string $mailbox): ?string {
    $from = strtolower(trim($from));
    if ($from === '') return 'brak nadawcy';
    if ($from === strtolower(trim($mailbox))) return 'nadawca to ta sama skrzynka';
    if (preg_match(EDOK_MAIL_BOUNCE_SENDER, $from)) return 'odbicie / adres systemowy';
    if (preg_match(EDOK_MAIL_AUTO_SUBJECT, trim($subject))) return 'odpowiedź automatyczna';
    return null;
}

// ── Automatyczny dokument z faktury XML (KSeF FA) z e-maila ─────────────────

/** Dokument ZAKUPU z faktury XML przyjętej e-mailem (kontrahent = sprzedawca, Podmiot1); do uzupełnienia przez księgową (klasyfikacja, opis). */
function edok_create_purchase_doc_from_mail(array $d, string $rel, string $ksef, string $from, string $received): int {
    $now = date('Y-m-d H:i:s');
    $doc = [
        'number' => edok_next_number(), 'title' => $d['nr_faktury'] ?: 'faktura z e-maila', 'kierunek' => 'wydatek', 'typ_dokumentu' => $d['typ_dokumentu'],
        'description' => ($d['description'] !== '' ? $d['description'] . '. ' : '') . 'Faktura przyjęta e-mailem od ' . $from . ' (plik XML).',
        'kontrahent_nazwa' => $d['kontrahent_nazwa'] !== '' ? $d['kontrahent_nazwa'] : '(sprzedawca bez nazwy)', 'kontrahent_nip' => $d['kontrahent_nip'],
        'nr_faktury' => $d['nr_faktury'], 'data_wystawienia' => $d['data_wystawienia'] ?: null, 'data_sprzedazy' => $d['data_sprzedazy'] ?: null,
        'data_wplywu' => $received !== '' ? $received : date('Y-m-d'), 'kwota_netto' => $d['kwota_netto'], 'stawka_vat' => $d['stawka_vat'],
        'kwota_vat' => $d['kwota_vat'], 'kwota_brutto' => $d['kwota_brutto'], 'waluta' => $d['waluta'],
        'termin_platnosci' => $d['termin_platnosci'] ?: null, 'rachunek_bankowy' => $d['rachunek_bankowy'], 'nr_transakcji_bramki' => $d['nr_transakcji_bramki'],
        'zaplacono_przed' => $d['zaplacono_przed'], 'data_zaplaty' => $d['data_zaplaty'] ?: null, 'forma_zaplaty' => $d['forma_zaplaty'],
        'file_path' => $rel, 'file_size' => is_file(UPLOAD_DIR . $rel) ? filesize(UPLOAD_DIR . $rel) : null,
        'ksef_reference' => $ksef, 'status' => 'w_obiegu', 'created_by' => null, 'creator_name' => 'e-mail: ' . $from . ' (auto)',
        'created_at' => $now, 'updated_at' => $now,
    ];
    try { $doc['tytul_przelewu'] = edok_generate_tytul_przelewu($doc); } catch (\Throwable $e) {}
    $doc_id = db_insert('edok_documents', $doc);
    if ($ksef !== '') {
        db_insert('edok_ksef_queue', ['ksef_reference' => $ksef, 'invoice_number' => $d['nr_faktury'], 'seller_name' => $doc['kontrahent_nazwa'], 'seller_nip' => $d['kontrahent_nip'],
            'gross_value' => $d['kwota_brutto'], 'currency' => $d['waluta'], 'issue_date' => $d['data_wystawienia'], 'ksef_date' => date('Y-m-d'), 'created_at' => $now, 'doc_id' => $doc_id]);
    }
    try { edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu', 'Automatyczny import faktury XML z e-maila (' . $from . ')' . ($ksef !== '' ? ', nr KSeF: ' . $ksef : '') . '. Dokument wymaga uzupełnienia opisu i dekretacji.'); } catch (\Throwable $e) { error_log('[edok mail autodoc] audyt: ' . $e->getMessage()); }   // dokument już istnieje — błąd audytu nie może go „cofnąć”
    return $doc_id;
}

/**
 * Próba założenia dokumentu z załącznika XML. Zwraca ['action'=>'doc'|'dup'|null, 'doc_id'=>?int, 'reason'=>string]; null = to nie jest faktura FA albo brakuje
 * wymaganych pól — plik trafia wtedy do zwykłej kolejki „do opisu”. Duplikat: numer KSeF (z nazwy pliku) albo para numer faktury + NIP kontrahenta.
 */
function edok_mail_xml_autodoc(string $xml, string $attName, string $from, string $received): array {
    $mine = edok_parse_invoice_xml($xml, 'Podmiot1');
    if (!$mine) return ['action' => null, 'doc_id' => null, 'reason' => 'XML nie jest fakturą ustrukturyzowaną (FA) — do kolejki'];
    $base = pathinfo($attName, PATHINFO_FILENAME);
    $ksef = preg_match('/^\d{10}-\d{8}-[0-9A-Fa-f]{12}-[0-9A-Fa-f]{2}$/', $base) ? strtoupper($base) : '';
    if ($ksef !== '' && (db_one("SELECT doc_id FROM edok_ksef_queue WHERE ksef_reference = ?", [$ksef]) || db_one("SELECT id FROM edok_documents WHERE ksef_reference = ?", [$ksef]))) {
        $e = db_one("SELECT id FROM edok_documents WHERE ksef_reference = ?", [$ksef]);
        return ['action' => 'dup', 'doc_id' => $e ? (int)$e['id'] : null, 'reason' => 'duplikat — numer KSeF ' . $ksef . ' już w EODoK'];
    }
    $isSales = in_array(preg_replace('/\D/', '', $mine['kontrahent_nip']), edok_org_nips(), true);
    $d = $isSales ? edok_parse_invoice_xml($xml, 'Podmiot2') : $mine;
    if ($d['nr_faktury'] === '' || $d['kwota_brutto'] === '' || ($d['kontrahent_nip'] === '' && !$isSales))
        return ['action' => null, 'doc_id' => null, 'reason' => 'XML bez numeru faktury, kwoty lub NIP kontrahenta — do kolejki'];
    $kier = $isSales ? 'przychod' : 'wydatek';
    $dup = db_one("SELECT id FROM edok_documents WHERE kierunek = ? AND nr_faktury = ? AND kontrahent_nip = ?", [$kier, $d['nr_faktury'], $d['kontrahent_nip']]);
    if ($dup) return ['action' => 'dup', 'doc_id' => (int)$dup['id'], 'reason' => 'duplikat — faktura ' . $d['nr_faktury'] . ' (NIP ' . $d['kontrahent_nip'] . ') już w EODoK'];
    $rel = edok_queue_save_bytes($xml, 'xml');
    $doc_id = $isSales ? edok_create_sales_doc($d, $rel, $ksef, 'e-mail: ' . $from . ' (auto)') : edok_create_purchase_doc_from_mail($d, $rel, $ksef, $from, $received);
    return ['action' => 'doc', 'doc_id' => $doc_id, 'reason' => ($isSales ? 'faktura sprzedaży' : 'faktura zakupu') . ' ' . $d['nr_faktury']];
}

/**
 * Skanuje skrzynkę (org_setting edok_queue_mailbox) i dodaje załączniki PDF/JPG/PNG/DOCX/XML do kolejki „do opisu”.
 * Nadawcy: lista dozwolonych ALBO — gdy włączone edok_queue_mail_any_sender — każdy (poza odbiciami/autoodpowiedziami).
 * XML faktury (KSeF FA): przy edok_queue_mail_autodoc=1 od razu zakłada dokument EODoK (zakup/sprzedaż) z deduplikacją.
 * Każda decyzja trafia do edok_mailbox_log (pulpit edok/mailbox.php). Deduplikacja po id wiadomości Graph + id załącznika,
 * więc brak uprawnienia do oznaczania jako przeczytane nie powoduje dubli; wiadomości z błędem zostają nieprzeczytane i są ponawiane.
 * @return array ['messages'=>int, 'added'=>int, 'docs'=>int, 'dups'=>int, 'skipped_senders'=>string[], 'errors'=>string[]]
 */
function edok_queue_mail_ingest(): array {
    $out = ['messages' => 0, 'added' => 0, 'docs' => 0, 'dups' => 0, 'skipped_senders' => [], 'errors' => []];
    $mailbox = trim(org_setting('edok_queue_mailbox'));
    if ($mailbox === '' || org_setting('edok_queue_mail_enabled') !== '1') { $out['errors'][] = 'Przyjmowanie e-maili jest wyłączone.'; return $out; }
    edok_migrate(); edok_mailbox_migrate();
    if (!function_exists('current_user')) require_once __DIR__ . '/auth.php';   // edok_log() (audyt dokumentów z XML) czyta current_user(); cron go nie ładuje
    $anySender = org_setting('edok_queue_mail_any_sender') === '1';
    $autoDoc   = org_setting('edok_queue_mail_autodoc') === '1';
    if (!class_exists('M365Graph', false)) require_once __DIR__ . '/m365.php';   // class_exists: testy hermetyczne podstawiają atrapę Graph
    try {
        $g = new M365Graph();
        $msgs = $g->unread_inbox_messages($mailbox, gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days')), 50);
    } catch (\Throwable $e) { $out['errors'][] = $e->getMessage(); edok_mailbox_log([], 'error', 'Skrzynka: ' . $e->getMessage()); return $out; }

    foreach ($msgs as $m) {
        $out['messages']++;
        $mid = (string)($m['id'] ?? '');
        $from = strtolower((string)($m['from']['emailAddress']['address'] ?? ''));
        $subject = (string)($m['subject'] ?? '');
        $received = substr((string)($m['receivedDateTime'] ?? ''), 0, 10);
        $read = function () use ($g, $mailbox, $mid) { try { $g->mark_message_read($mailbox, $mid); } catch (\Throwable $e) {} };
        if ($why = edok_queue_mail_skip_reason($from, $subject, $mailbox)) { edok_mailbox_log($m, 'skipped', $why); $read(); continue; }
        if (!$anySender && !edok_queue_mail_sender_allowed($from)) {
            $out['skipped_senders'][] = $from ?: '(brak nadawcy)';
            edok_mailbox_log($m, 'skipped', 'nadawca spoza listy dozwolonych (włącz „przyjmuj od każdego nadawcy”, aby przyjmować faktury od dostawców)');
            $read();
            continue;
        }
        if (empty($m['hasAttachments'])) { edok_mailbox_log($m, 'noatt', 'wiadomość bez załączników (może zawierać link do faktury)'); $read(); continue; }
        try {
            $hadError = false; $msgDocs = [];
            $atts = $g->get_message_attachments($mailbox, $mid);
            // XML najpierw — dokumenty z XML powstają przed obsługą PDF-ów tej samej wiadomości (adnotacja w kolejce).
            usort($atts, fn($a, $b) => (int)(strtolower(pathinfo((string)($b['name'] ?? ''), PATHINFO_EXTENSION)) === 'xml') <=> (int)(strtolower(pathinfo((string)($a['name'] ?? ''), PATHINFO_EXTENSION)) === 'xml'));
            foreach ($atts as $att) {
                $aid = (string)($att['id'] ?? ''); $name = (string)($att['name'] ?? 'plik'); $size = (int)($att['size'] ?? 0);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $x = ['att_id' => $aid, 'att_name' => $name];
                if (!in_array($ext, EDOK_QUEUE_EXT, true)) { if ($ext !== '' && !in_array($ext, ['ics', 'vcf', 'p7s', 'asc', 'sig'], true)) { if (!edok_mailbox_att_done($mid, $aid)) edok_mailbox_log($m, 'ignored', 'typ .' . $ext . ' nie jest obsługiwany (PDF, JPG, PNG, DOCX, XML) — sprawdź wiadomość ręcznie', $x); } continue; }
                if ($size <= 0 || $size > 20 * 1024 * 1024) { if (!edok_mailbox_att_done($mid, $aid)) edok_mailbox_log($m, 'ignored', 'rozmiar poza zakresem (maks. 20 MB)', $x); continue; }
                // Małe obrazki to zwykle logo/stopka podpisu, nie dokument.
                if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && $size < 20 * 1024) continue;
                if (edok_mailbox_att_done($mid, $aid)) continue;
                try { $bytes = $g->get_attachment_content($mailbox, $mid, $aid); }
                catch (\Throwable $e) { $out['errors'][] = $name . ': ' . $e->getMessage(); edok_mailbox_log($m, 'error', 'pobieranie: ' . $e->getMessage(), $x); $hadError = true; continue; }
                try {
                    if ($ext === 'xml' && $autoDoc && strlen($bytes) <= 5 * 1024 * 1024) {
                        $r = edok_mail_xml_autodoc($bytes, $name, $from, $received);
                        if ($r['action'] === 'doc') { $out['docs']++; $msgDocs[] = $r['doc_id']; edok_mailbox_log($m, 'doc', $r['reason'], $x + ['doc_id' => $r['doc_id']]); continue; }
                        if ($r['action'] === 'dup') { $out['dups']++; edok_mailbox_log($m, 'dup', $r['reason'], $x + ['doc_id' => $r['doc_id']]); continue; }
                    }
                    $rel = edok_queue_save_bytes($bytes, $ext);
                    $note = $subject;
                    if ($msgDocs && $ext !== 'xml') $note = trim($subject . ' — z tej wiadomości powstał dokument EODoK (z XML); ten plik to prawdopodobnie wizualizacja faktury');
                    $qid = db_insert('edok_queue', [
                        'file_path' => $rel, 'orig_name' => $name, 'file_size' => strlen($bytes),
                        'note' => $note, 'uploaded_by' => 0, 'uploader_name' => 'e-mail: ' . $from,
                        'created_at' => date('Y-m-d H:i:s'),
                        'source' => 'mail', 'mail_from' => $from, 'mail_subject' => $subject,
                        'mail_msg_id' => $mid, 'mail_att_id' => $aid,
                    ]);
                    $out['added']++;
                    edok_mailbox_log($m, 'queued', 'do kolejki „do opisu”', $x + ['queue_id' => $qid]);
                } catch (\Throwable $e) { $out['errors'][] = $name . ': ' . $e->getMessage(); edok_mailbox_log($m, 'error', $e->getMessage(), $x); $hadError = true; }
            }
            if (!$hadError) $read();   // przy błędzie wiadomość zostaje nieprzeczytana → ponowienie w następnym przebiegu (deduplikacja chroni przed dublami)
        } catch (\Throwable $e) { $out['errors'][] = $subject . ': ' . $e->getMessage(); edok_mailbox_log($m, 'error', $e->getMessage()); }
    }
    return $out;
}

/**
 * Pobiera z KSeF faktury sprzedaży (organizacja jako sprzedawca) i korekty z zakresu dat i zakłada z nich
 * dokumenty PRZYCHODOWE EODoK (faktura_sprzedazy / korekta_sprzedazy), z XML jako dokumentem źródłowym.
 * Dedup po numerze KSeF (edok_ksef_queue + edok_documents.ksef_reference).
 * @return array ['imported'=>int, 'skipped'=>int, 'errors'=>string[], 'new_doc_ids'=>int[]]
 */
function edok_ksef_sales_sync(string $from, string $to): array {
    require_once __DIR__ . '/kdok_ksef.php';
    edok_migrate();
    $st = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'new_doc_ids' => []];
    if (!org_setting('kdok_ksef_nip')) throw new RuntimeException('Brak NIP w konfiguracji KSeF.');
    foreach (kdok_ksef_date_chunks($from, $to, 3) as [$cf, $ct]) {
        try { $refs = kdok_ksef_query_by_date(null, $cf, $ct, 'Issue', 'seller'); }
        catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '429')) { sleep(5); try { $refs = kdok_ksef_query_by_date(null, $cf, $ct, 'Issue', 'seller'); } catch (\Throwable $e2) { $st['errors'][] = "{$cf}–{$ct}: " . $e2->getMessage(); continue; } }
            else { $st['errors'][] = "{$cf}–{$ct}: " . $e->getMessage(); continue; }
        }
        foreach ($refs as $ref) {
            if ($ref === '') continue;
            if (db_one("SELECT 1 FROM edok_ksef_queue WHERE ksef_reference = ?", [$ref]) || db_one("SELECT 1 FROM edok_documents WHERE ksef_reference = ?", [$ref])) { $st['skipped']++; continue; }
            try {
                $xml = kdok_ksef_get_invoice_xml(null, $ref);
                $d = edok_parse_invoice_xml($xml, 'Podmiot2'); // kontrahent = nabywca
                if (!$d) throw new RuntimeException('nie udało się odczytać XML');
                $doc_id = edok_create_sales_doc($d, edok_queue_save_bytes($xml, 'xml'), $ref, 'KSeF (import faktur sprzedaży)');
                $st['imported']++; $st['new_doc_ids'][] = $doc_id;
            } catch (\Throwable $e) { $st['errors'][] = "Ref {$ref}: " . $e->getMessage(); }
        }
    }
    return $st;
}

/** Cyfry NIP-ów organizacji (do rozpoznania, czy faktura z XML jest naszą sprzedażą). */
function edok_org_nips(): array {
    $out = [];
    foreach (['org_nip', 'kdok_ksef_nip', 'ksef_nip'] as $k) { $n = preg_replace('/\D/', '', org_setting($k)); if (strlen($n) === 10) $out[$n] = true; }
    return array_map('strval', array_keys($out)); // klucze-liczby → z powrotem na tekst (porównania ścisłe)
}

/** Czy XML to faktura wystawiona przez organizację (sprzedawca Podmiot1 = nasz NIP). */
function edok_xml_is_sales(string $xml): bool {
    $d = edok_parse_invoice_xml($xml, 'Podmiot1');
    return $d && in_array(preg_replace('/\D/', '', $d['kontrahent_nip']), edok_org_nips(), true);
}

/**
 * Zakłada dokument PRZYCHODOWY (faktura_sprzedazy / korekta_sprzedazy) z danych sprzedaży
 * (edok_parse_invoice_xml($xml, 'Podmiot2') — kontrahent = nabywca). $rel = plik XML w uploads/.
 */
function edok_create_sales_doc(array $d, string $rel, string $ref, string $creator): int {
    $typ = $d['typ_dokumentu'] === 'faktura_korygujaca' ? 'korekta_sprzedazy' : 'faktura_sprzedazy';
    $nabywca = $d['kontrahent_nazwa'] !== '' ? $d['kontrahent_nazwa'] : '(nabywca bez nazwy)';
    $now = date('Y-m-d H:i:s');
    $doc_id = db_insert('edok_documents', [
        'number' => edok_next_number(), 'title' => $d['nr_faktury'] ?: ($ref ?: 'faktura sprzedaży'), 'kierunek' => 'przychod', 'typ_dokumentu' => $typ,
        'description' => ($d['description'] !== '' ? $d['description'] . '. ' : '') . 'Faktura sprzedaży' . ($ref !== '' ? ' pobrana z KSeF, nr ref.: ' . $ref : ' zaimportowana z pliku XML'),
        'kontrahent_nazwa' => $nabywca, 'kontrahent_nip' => $d['kontrahent_nip'], 'zrodlo_przychodu' => $nabywca,
        'nr_faktury' => $d['nr_faktury'], 'data_wystawienia' => $d['data_wystawienia'] ?: null, 'data_sprzedazy' => $d['data_sprzedazy'] ?: null,
        'data_wplywu' => $d['data_wystawienia'] ?: date('Y-m-d'), 'kwota_netto' => $d['kwota_netto'], 'stawka_vat' => $d['stawka_vat'],
        'kwota_vat' => $d['kwota_vat'], 'kwota_brutto' => $d['kwota_brutto'], 'waluta' => $d['waluta'],
        'termin_platnosci' => $d['termin_platnosci'] ?: null, 'file_path' => $rel,
        'file_size' => is_file(UPLOAD_DIR . $rel) ? filesize(UPLOAD_DIR . $rel) : null,
        'ksef_reference' => $ref, 'status' => 'w_obiegu', 'created_by' => current_user()['id'] ?? null, 'creator_name' => $creator,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    if ($ref !== '') {
        db_insert('edok_ksef_queue', ['ksef_reference' => $ref, 'invoice_number' => $d['nr_faktury'], 'seller_name' => $nabywca, 'seller_nip' => $d['kontrahent_nip'],
            'gross_value' => $d['kwota_brutto'], 'currency' => $d['waluta'], 'issue_date' => $d['data_wystawienia'], 'ksef_date' => date('Y-m-d'), 'created_at' => $now, 'doc_id' => $doc_id]);
    }
    edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu', ($ref !== '' ? 'Import z KSeF (faktura sprzedaży), nr ref.: ' . $ref : 'Import faktury sprzedaży z pliku XML') . '. Uzupełnij klasyfikację przychodu (rodzaj działalności) przed kontrolą.');
    return $doc_id;
}

/**
 * Import faktur sprzedaży z wgranych plików XML ($_FILES[$field] w wersji multi). Pliki, które nie są
 * naszą sprzedażą (sprzedawca ≠ NIP organizacji), są odrzucane — zakupy idą przez kolejkę/formularz.
 * @return array ['imported'=>int, 'skipped'=>int, 'errors'=>string[], 'new_doc_ids'=>int[]]
 */
function edok_sales_xml_import(array $files): array {
    edok_migrate();
    $st = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'new_doc_ids' => []];
    foreach ($files['name'] ?? [] as $i => $name) {
        if ($name === '' || ($files['error'][$i] ?? 0) === UPLOAD_ERR_NO_FILE) continue;
        if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xml' || $files['size'][$i] > 5 * 1024 * 1024) { $st['errors'][] = "{$name}: nie jest plikiem XML (max 5 MB)"; continue; }
        $xml = (string)file_get_contents($files['tmp_name'][$i]);
        $mine = edok_parse_invoice_xml($xml, 'Podmiot1');
        if (!$mine) { $st['errors'][] = "{$name}: to nie jest faktura ustrukturyzowana (FA)"; continue; }
        if (!in_array(preg_replace('/\D/', '', $mine['kontrahent_nip']), edok_org_nips(), true)) { $st['errors'][] = "{$name}: sprzedawcą nie jest organizacja (to zakup — użyj kolejki lub „Nowy dokument”)"; continue; }
        $d = edok_parse_invoice_xml($xml, 'Podmiot2');
        if ($d['nr_faktury'] !== '' && db_one("SELECT 1 FROM edok_documents WHERE kierunek = 'przychod' AND nr_faktury = ? AND typ_dokumentu IN ('faktura_sprzedazy','korekta_sprzedazy')", [$d['nr_faktury']])) { $st['skipped']++; continue; }
        $st['new_doc_ids'][] = edok_create_sales_doc($d, edok_queue_save_bytes($xml, 'xml'), '', (current_user()['name'] ?? '') . ' (import XML sprzedaży)');
        $st['imported']++;
    }
    return $st;
}
