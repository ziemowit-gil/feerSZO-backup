<?php
/**
 * EODoK — Kolejka do opisu: wczytywanie faktur z XML (KSeF FA) i przyjmowanie
 * załączników z e-maila. Silnik kolejki (tabela edok_queue, zapis plików) jest
 * w includes/edok.php; tu jest to, co dokłada się do niej „z zewnątrz”.
 */
require_once __DIR__ . '/edok.php';

/** Kwota z XML (kropka) → format formularza EODoK („1234,50”). */
function _edok_xml_money(float $v): string {
    return number_format($v, 2, ',', '');
}

/**
 * Parsuje fakturę ustrukturyzowaną (FA(2)/FA(3)) do pól formularza edok/add.php.
 * Zwraca [] gdy to nie jest faktura KSeF. Stawka VAT jest ustawiana tylko, gdy faktura
 * ma jedną stawkę — przy mieszanych zostaje puste, a suma VAT trafia do kwoty VAT.
 */
function edok_parse_invoice_xml(string $xml): array {
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$sx || $sx->getName() !== 'Faktura') return [];
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
        'kontrahent_nazwa' => $one('//*[local-name()="Podmiot1"]//*[local-name()="Nazwa"]') ?: $one('//*[local-name()="Podmiot1"]//*[local-name()="PelnaNazwa"]'),
        'kontrahent_nip'   => $one('//*[local-name()="Podmiot1"]//*[local-name()="NIP"]'),
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

/**
 * Skanuje skrzynkę (org_setting edok_queue_mailbox) i dodaje załączniki PDF/JPG/PNG/DOCX/XML
 * od dozwolonych nadawców do kolejki. Deduplikacja po id wiadomości Graph + id załącznika,
 * więc brak uprawnienia do oznaczania jako przeczytane nie powoduje dubli.
 * @return array ['messages'=>int, 'added'=>int, 'skipped_senders'=>string[], 'errors'=>string[]]
 */
function edok_queue_mail_ingest(): array {
    $out = ['messages' => 0, 'added' => 0, 'skipped_senders' => [], 'errors' => []];
    $mailbox = trim(org_setting('edok_queue_mailbox'));
    if ($mailbox === '' || org_setting('edok_queue_mail_enabled') !== '1') { $out['errors'][] = 'Przyjmowanie e-maili jest wyłączone.'; return $out; }
    edok_migrate();
    require_once __DIR__ . '/m365.php';
    try {
        $g = new M365Graph();
        $msgs = $g->unread_inbox_messages($mailbox, gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days')), 50);
    } catch (\Throwable $e) { $out['errors'][] = $e->getMessage(); return $out; }

    foreach ($msgs as $m) {
        $out['messages']++;
        $mid = (string)($m['id'] ?? '');
        $from = strtolower((string)($m['from']['emailAddress']['address'] ?? ''));
        $subject = (string)($m['subject'] ?? '');
        if (!edok_queue_mail_sender_allowed($from)) {
            $out['skipped_senders'][] = $from ?: '(brak nadawcy)';
            try { $g->mark_message_read($mailbox, $mid); } catch (\Throwable $e) {}
            continue;
        }
        if (empty($m['hasAttachments'])) { try { $g->mark_message_read($mailbox, $mid); } catch (\Throwable $e) {} continue; }
        try {
            foreach ($g->get_message_attachments($mailbox, $mid) as $att) {
                $aid = (string)($att['id'] ?? ''); $name = (string)($att['name'] ?? 'plik'); $size = (int)($att['size'] ?? 0);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, EDOK_QUEUE_EXT, true) || $size <= 0 || $size > 20 * 1024 * 1024) continue;
                // Małe obrazki to zwykle logo/stopka podpisu, nie dokument.
                if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && $size < 20 * 1024) continue;
                if (db_one("SELECT 1 FROM edok_queue WHERE mail_msg_id = ? AND mail_att_id = ?", [$mid, $aid])) continue;
                try { $bytes = $g->get_attachment_content($mailbox, $mid, $aid); }
                catch (\Throwable $e) { $out['errors'][] = $name . ': ' . $e->getMessage(); continue; }
                $rel = edok_queue_save_bytes($bytes, $ext);
                db_insert('edok_queue', [
                    'file_path' => $rel, 'orig_name' => $name, 'file_size' => strlen($bytes),
                    'note' => $subject, 'uploaded_by' => 0, 'uploader_name' => 'e-mail: ' . $from,
                    'created_at' => date('Y-m-d H:i:s'),
                    'source' => 'mail', 'mail_from' => $from, 'mail_subject' => $subject,
                    'mail_msg_id' => $mid, 'mail_att_id' => $aid,
                ]);
                $out['added']++;
            }
            $g->mark_message_read($mailbox, $mid);
        } catch (\Throwable $e) { $out['errors'][] = $subject . ': ' . $e->getMessage(); }
    }
    return $out;
}
