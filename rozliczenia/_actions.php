<?php
/**
 * rozliczenia/_actions.php — wspólna obsługa akcji POST modułu Rozliczenia.
 *
 * Wymaga wcześniejszego _boot.php ($rz_can_write, $rz_year, $rz_month).
 * Wywołuje wyłącznie istniejącą logikę TI (karty30.php / ti_payments.php).
 * Po wykonaniu akcji przekierowuje na $rz_redirect (PRG) — nigdy nie wraca.
 */
if (!isset($rz_redirect)) $rz_redirect = 'index.php?m=' . sprintf('%04d-%02d', $rz_year, $rz_month);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
if (!$rz_can_write) { http_response_code(403); die('Brak uprawnień.'); }
csrf_check();

$op = (string)($_POST['_op'] ?? '');

/** Wystawienie rozliczeń (model kombinowany: osobno na każdą grupę) + opcjonalne powiadomienia. */
if ($op === 'issue') {
    $client_ids = array_map('intval', (array)($_POST['client_ids'] ?? []));
    if (!$client_ids && !empty($_POST['client_id'])) $client_ids = [(int)$_POST['client_id']];
    $notify = ($_POST['notify'] ?? '') === '1';
    $cnt = 0; $mails = 0;
    foreach (array_unique(array_filter($client_ids)) as $cid) {
        $bids = k30_ti_issue_billing_split($cid, $rz_month, $rz_year);
        ti_billing_recompute($cid);
        $cnt += count($bids);
        if ($notify) {
            foreach ($bids as $bid) {
                $r = k30_ti_billing_notify($bid, true, 'mail');
                if (!empty($r['email'])) $mails++;
            }
        }
    }
    flash_set($cnt ? 'success' : 'warning',
        $cnt ? "Wystawiono {$cnt} rozliczeń (osobno na każdą grupę)." . ($notify ? " Wysłano maili: {$mails}." : '')
             : 'Nie było czego wystawić dla wskazanych uczestników.');
    header('Location: ' . $rz_redirect); exit;
}

/** Wysyłka maila „zestawienie należności" dla wskazanych rozliczeń. */
if ($op === 'mail') {
    $ids = array_map('intval', (array)($_POST['billing_ids'] ?? []));
    if (!$ids && !empty($_POST['billing_id'])) $ids = [(int)$_POST['billing_id']];
    $sent = 0; $skipped = 0;
    foreach (array_unique(array_filter($ids)) as $bid) {
        $r = k30_ti_billing_notify($bid, true, 'mail');
        if (!empty($r['email'])) $sent++; else $skipped++;
    }
    flash_set($sent ? 'success' : 'warning',
        $sent ? "Wysłano maile z rozliczeniem: {$sent}." . ($skipped ? " Pominięto (brak adresu e-mail): {$skipped}." : '')
              : 'Nie wysłano żadnego maila — brak adresów e-mail odbiorców.');
    header('Location: ' . $rz_redirect); exit;
}

/** Wpłata — z opcjonalnym zaksięgowaniem na konkretną grupę. */
if ($op === 'payment') {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $amount    = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
    $paid_at   = trim((string)($_POST['paid_at'] ?? ''));
    $method    = in_array($_POST['method'] ?? '', ['transfer','cash','stripe','payu','other'], true) ? (string)$_POST['method'] : 'transfer';
    $note      = trim((string)($_POST['note'] ?? ''));
    $course_id = (int)($_POST['course_id'] ?? 0);
    if ($course_id > 0 && !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [$client_id, $course_id])) {
        $course_id = 0;
    }
    if ($client_id > 0 && $amount > 0) {
        $r     = ti_payment_add($client_id, $amount, $paid_at, $method, $note, 'manual', 0, $course_id);
        $gname = $course_id ? (db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$course_id])['name'] ?? '') : '';
        $msg   = 'Zapisano wpłatę ' . rz_zl($amount) . ($gname !== '' ? ' na grupę „' . $gname . '”' : ' (ogólną)') . '.';
        if ($r['credit'] > 0.005) $msg .= ' Nadpłata: ' . rz_zl($r['credit']) . (!empty($r['emailed']) ? ' — wysłano e-mail.' : '.');
        flash_set('success', $msg);
    } else {
        flash_set('danger', 'Podaj uczestnika i kwotę wpłaty większą od zera.');
    }
    header('Location: ' . $rz_redirect); exit;
}

/** Usunięcie wpłaty (korekta) — tylko administrator. */
if ($op === 'payment_del') {
    if (!$rz_can_admin) { http_response_code(403); die('Brak uprawnień.'); }
    ti_payment_delete((int)($_POST['payment_id'] ?? 0));
    flash_set('success', 'Wpłata usunięta, salda przeliczone.');
    header('Location: ' . $rz_redirect); exit;
}

/** Faktura: numer, data, rodzaj + WYMAGANY skan PDF (bez pliku nic nie zapisujemy). */
if ($op === 'invoice') {
    $bid = (int)($_POST['billing_id'] ?? 0);
    $b   = $bid ? db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$bid]) : null;
    if (!$b) {
        flash_set('danger', 'Nie znaleziono rozliczenia.');
    } else {
        $kind   = ($_POST['invoice_kind'] ?? '') === 'oneoff' ? 'oneoff' : '';
        $inv_no = mb_substr(trim((string)($_POST['invoice_no'] ?? '')), 0, 60);
        $inv_on = trim((string)($_POST['invoice_issued_on'] ?? ''));
        $inv_on = preg_match('/^\d{4}-\d{2}-\d{2}$/', $inv_on) ? $inv_on : null;
        try {
            $up = k30_ti_invoice_upload('invoice', 'fv' . $bid);
            if (!$up && empty($b['invoice_path'])) {
                flash_set('danger', 'Skan faktury (PDF) jest wymagany — bez pliku nie zapisujemy danych faktury.');
            } else {
                if ($up && !empty($b['invoice_path'])) k30_ti_invoice_delete_file($b['invoice_path']);
                $sql = "UPDATE k30_ti_billing SET invoice_kind=?, invoice_no=?, invoice_issued_on=?, invoice_system=?";
                $par = [$kind, $inv_no, $inv_on, k30_ti_invoice_system()];
                if ($up) { $sql .= ", invoice_path=?, invoice_name=?, invoice_at=datetime('now')"; $par[] = $up['stored']; $par[] = $up['name']; }
                $sql .= " WHERE id=?"; $par[] = $bid;
                db()->prepare($sql)->execute($par);
                flash_set('success', 'Zapisano dane faktury' . ($up ? ' wraz ze skanem.' : '.'));
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
    }
    header('Location: ' . $rz_redirect); exit;
}
