<?php
/**
 * karty30/ti/dydaktyk/billing.php — Miesięczne rozliczenia zajęć TI
 * (strona kierownika w panelu dydaktyka — nowe UI, sidebar).
 *
 * Przeniesione z modułu administracyjnego (karty30/ti/billing.php), który
 * teraz przekierowuje tutaj — wzorzec jak żetony/okresy/wyłączenia.
 * Sesja panelu dydaktyka: current_user()/is_admin() są tu puste, tożsamość
 * z dyd_require(); usuwanie/faktury (operacje administracyjne) zostają
 * w gestii admina i są tu wyłączone.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/stripe.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/payu.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/p24.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }   // ekran kierownika
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();
stripe_migrate();
payu_migrate();
ti_payments_migrate();

// Moduł Faktury jest opcjonalny — wczytujemy go tylko gdy włączony, ale PRZED
// renderem, bo wiersze rozliczeń używają INVOICE_STATUSES i invoices_migrate().
if (module_enabled('invoices_enabled')) {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/invoices.php';
    invoices_migrate();
    // Backend Betterfly (opcjonalny) — logika wystawiania/statusu faktur TI.
    $bf = dirname(dirname(dirname(__DIR__))) . '/includes/betterfly_invoices.php';
    if (is_file($bf)) require_once $bf;
}
$bf_backend = function_exists('betterfly_is_ti_backend') && betterfly_is_ti_backend();

$PAGE_TITLE = 'Rozliczenia TI';
$can_write  = true;   // kierownik z definicji pisze (dyd_is_staff wyżej)
$can_delete = false;  // usuwanie/korekty administracyjne — tylko moduł admina
$course_id  = (int)($_GET['course_id'] ?? 0);
$course     = $course_id ? k30_ti_course_get($course_id) : null;

// Filtr okresu
$month = (int)($_GET['month'] ?? date('m'));
$year  = (int)($_GET['year']  ?? date('Y'));
$month = max(1, min(12, $month));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Tryb wystawiania: '' = z FVAT (jak dotąd — rozliczenie czeka na fakturę),
    // 'statement' = tylko zestawienie, bez FVAT (bez przycisków faktury, poza
    // listą brakujących faktur i „Podsumowaniem do FVAT”).
    $doc_mode = ($_POST['doc_mode'] ?? '') === 'statement' ? 'statement' : '';
    $set_mode = static function (array $bids, string $mode): void {
        if (!$bids) return;
        $ph = implode(',', array_fill(0, count($bids), '?'));
        db()->prepare("UPDATE k30_ti_billing SET doc_mode=? WHERE id IN ($ph) AND COALESCE(invoice_no,'')='' AND COALESCE(invoice_path,'')=''")
            ->execute(array_merge([$mode], array_map('intval', $bids)));
    };

    if ($op === 'issue') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $notes     = trim($_POST['notes'] ?? '');
        if ($client_id) {
            $bids  = k30_ti_issue_billing_split($client_id, $month, $year, $notes);
            $set_mode($bids, $doc_mode);
            ti_billing_recompute($client_id);
            $sms_c = 0; $eml_c = 0;
            foreach ($bids as $bid) {
                $n = k30_ti_billing_notify($bid);
                if (!empty($n['sms']))   $sms_c++;
                if (!empty($n['email'])) $eml_c++;
            }
            $cnt   = count($bids);
            $extra = ($sms_c || $eml_c)
                ? ' Wysłano: ' . ($sms_c ? "SMS ({$sms_c})" : '') . ($sms_c && $eml_c ? ' i ' : '') . ($eml_c ? "e-mail ({$eml_c})" : '') . '.'
                : ' (brak danych kontaktowych do powiadomień).';
            flash_set('success', ($cnt > 1 ? "Wystawiono {$cnt} rozliczeń (osobno per kurs)" : 'Rozliczenie wystawione')
                . ($doc_mode === 'statement' ? ' — tylko zestawienie, bez FVAT.' : '.') . $extra);
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    if ($op === 'issue_all') {
        $clients_with_sessions = db_all(
            "SELECT DISTINCT e.client_id FROM k30_ti_attendance a
             JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=a.client_id
             WHERE a.attended=1 AND strftime('%m',s.lesson_date)=? AND strftime('%Y',s.lesson_date)=?",
            [sprintf('%02d',$month), (string)$year]
        );
        $sms = 0; $eml = 0; $bill_cnt = 0;
        foreach ($clients_with_sessions as $c) {
            $bids = k30_ti_issue_billing_split((int)$c['client_id'], $month, $year);
            $set_mode($bids, $doc_mode);
            ti_billing_recompute((int)$c['client_id']);
            $bill_cnt += count($bids);
            foreach ($bids as $bid) {
                $n = k30_ti_billing_notify($bid);
                if (!empty($n['sms']))   $sms++;
                if (!empty($n['email'])) $eml++;
            }
        }
        flash_set('success', "Wystawiono {$bill_cnt} rozliczeń (dla " . count($clients_with_sessions) . " kursantów)"
            . ($doc_mode === 'statement' ? ' — tylko zestawienia, bez FVAT' : '') . ". Powiadomienia: SMS {$sms}, e-mail {$eml}.");
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Przeniesienie salda na to rozliczenie: nadpłata innej grupy albo
    // niedopłata z innego okresu / grupy (ti_transfer_credit / ti_transfer_debt)
    if ($op === 'transfer_balance') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $amt = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['amount'] ?? '0'));
        [$kind, $src] = array_pad(explode(':', (string)($_POST['source'] ?? ''), 2), 2, '');
        $hrs = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['hours'] ?? '0'));
        if ($amt <= 0 && $hrs > 0) {   // podano tylko godziny — przelicz po stawce źródła
            foreach (ti_balance_transfer_sources($bid)[$kind === 'credit' ? 'credits' : 'debts'] as $_s)
                if ((int)($_s[$kind === 'credit' ? 'course_id' : 'billing_id']) === (int)$src) $amt = round($hrs * (float)$_s['rate'], 2);
        }
        $err = $kind === 'credit' ? ti_transfer_credit($bid, (int)$src, $amt, $dyd_name)
             : ($kind === 'debt' ? ti_transfer_debt($bid, (int)$src, $amt, $dyd_name) : 'Wybierz, co przenieść.');
        flash_set($err ? 'danger' : 'success', $err ?? ($kind === 'credit'
            ? 'Nadpłata przeniesiona do grupy tego rozliczenia (' . number_format($amt, 2, ',', ' ') . ' zł).'
            : 'Niedopłata przeniesiona na to rozliczenie (' . number_format($amt, 2, ',', ' ') . ' zł).'));
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Zmiana trybu dokumentu jednego rozliczenia (z FVAT ↔ tylko zestawienie)
    if ($op === 'set_doc_mode') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b0  = $bid ? db_one("SELECT id, invoice_no, invoice_path FROM k30_ti_billing WHERE id=?", [$bid]) : null;
        if (!$b0) flash_set('danger', 'Nie znaleziono rozliczenia.');
        elseif ($doc_mode === 'statement' && (trim((string)$b0['invoice_no']) !== '' || trim((string)$b0['invoice_path']) !== ''))
            flash_set('danger', 'Rozliczenie ma już zarejestrowaną fakturę — najpierw ją usuń, potem zmień na samo zestawienie.');
        else {
            db()->prepare("UPDATE k30_ti_billing SET doc_mode=? WHERE id=?")->execute([$doc_mode, $bid]);
            flash_set('success', $doc_mode === 'statement' ? 'Rozliczenie: tylko zestawienie, bez FVAT.' : 'Rozliczenie: z fakturą VAT.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Wycofanie rozliczenia (kierownik): status 'cancelled' + kto/kiedy/dlaczego;
    // przypisane wpłaty wracają jako nadpłata (ponowna alokacja). Zablokowane,
    // gdy z rozliczenia jest faktura — dokument księgowy wymaga korekty, nie
    // wycofania podstawy. Odwracalne przyciskiem „Przywróć”.
    if ($op === 'withdraw_billing') {
        $bid    = (int)($_POST['billing_id'] ?? 0);
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
        $b0     = $bid ? db_one("SELECT * FROM k30_ti_billing WHERE id=? AND status!='cancelled'", [$bid]) : null;
        $inv    = null;
        if ($b0 && module_enabled('invoices_enabled')) {
            try { $inv = db_one("SELECT id, number FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=0 AND deleted_at IS NULL", [$bid]); } catch (\Throwable $e) {}
        }
        if (!$b0) {
            flash_set('danger', 'Nie znaleziono rozliczenia (albo jest już wycofane).');
        } elseif ($reason === '') {
            flash_set('danger', 'Podaj powód wycofania rozliczenia.');
        } elseif ($inv || trim((string)$b0['invoice_no']) !== '' || trim((string)$b0['invoice_path']) !== '') {
            flash_set('danger', 'Z tego rozliczenia wystawiono fakturę' . ($inv ? ' ' . (string)($inv['number'] ?: '#' . $inv['id']) : (trim((string)$b0['invoice_no']) !== '' ? ' ' . (string)$b0['invoice_no'] : ''))
                . ' — nie można go wycofać. Potrzebna jest korekta faktury (albo usuń zarejestrowany skan, jeśli był błędny).');
        } else {
            db()->prepare("UPDATE k30_ti_billing SET status='cancelled', paid_amount=0, cancelled_at=datetime('now'), cancelled_by_name=?, cancel_reason=? WHERE id=?")
                ->execute([$dyd_name, $reason, $bid]);
            $r = ti_billing_recompute((int)$b0['client_id']);
            flash_set('success', 'Rozliczenie wycofane.' . ((float)$b0['paid_amount'] > 0.005
                ? ' Przypisane wpłaty (' . number_format((float)$b0['paid_amount'], 2, ',', ' ') . ' zł) wróciły na konto jako nadpłata.' : ''));
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }
    if ($op === 'restore_billing') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b0  = $bid ? db_one("SELECT id, client_id FROM k30_ti_billing WHERE id=? AND status='cancelled' AND cancelled_at IS NOT NULL", [$bid]) : null;
        if (!$b0) {
            flash_set('danger', 'Nie znaleziono wycofanego rozliczenia.');
        } else {
            db()->prepare("UPDATE k30_ti_billing SET status='issued', cancelled_at=NULL, cancelled_by_name='', cancel_reason='' WHERE id=?")->execute([$bid]);
            ti_billing_recompute((int)$b0['client_id']);
            flash_set('success', 'Rozliczenie przywrócone.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Ponowne wysłanie powiadomienia o rozliczeniu (SMS + e-mail)
    if ($op === 'notify') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        if ($bid) {
            $n = k30_ti_billing_notify($bid, true);
            if (!empty($n['ok'])) {
                $parts = [];
                if (!empty($n['sms']))   $parts[] = 'SMS';
                if (!empty($n['email'])) $parts[] = 'e-mail';
                flash_set('success', $parts ? 'Wysłano powiadomienie: ' . implode(' i ', $parts) . '.' : 'Brak danych kontaktowych (telefon/e-mail).');
            } else {
                flash_set('danger', $n['msg'] ?? 'Nie udało się wysłać powiadomienia.');
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // „Opłacone" = zarejestruj wpłatę na pozostałą do zapłaty kwotę (księga + saldo)
    if ($op === 'set_paid') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$bid]) : null;
        if ($b) {
            $due       = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
            $remaining = round($due - (float)($b['paid_amount'] ?? 0), 2);
            if ($remaining > 0) {
                $r = ti_payment_add((int)$b['client_id'], $remaining, date('Y-m-d'), 'manual',
                                    'Oznaczono jako opłacone (rozl. '.$b['month'].'/'.$b['year'].')',
                                    'manual', 0, (int)($b['course_id'] ?? 0));
                flash_set('success', 'Zarejestrowano wpłatę ' . number_format($remaining,2,',',' ') . ' zł.' . (!empty($r['emailed']) ? ' Wysłano e-mail.' : ''));
            } else {
                flash_set('info', 'Rozliczenie jest już w pełni pokryte.');
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Ręczna wpłata (dowolna kwota) — może utworzyć nadpłatę
    if ($op === 'add_payment') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $amount    = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
        $paid_at   = trim($_POST['paid_at'] ?? '');
        $method    = in_array($_POST['method'] ?? '', ['transfer','cash','stripe','payu','other'], true) ? $_POST['method'] : 'transfer';
        $note      = trim($_POST['note'] ?? '');
        // Model kombinowany: wpłatę można zaksięgować na konkretną grupę (0 = ogólna na konto)
        $pay_course = (int)($_POST['pay_course_id'] ?? 0);
        if ($pay_course > 0 && !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [$client_id, $pay_course])) {
            $pay_course = 0;
        }
        if ($client_id && $amount > 0) {
            $r = ti_payment_add($client_id, $amount, $paid_at, $method, $note, 'manual', 0, $pay_course);
            $gname = $pay_course ? (db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$pay_course])['name'] ?? '') : '';
            $msg = 'Wpłata ' . number_format($amount,2,',',' ') . ' zł zapisana' . ($gname !== '' ? ' na grupę „' . $gname . '”' : ' (ogólna)') . '.';
            if ($r['credit'] > 0) $msg .= ' Nadpłata: ' . number_format($r['credit'],2,',',' ') . ' zł' . (!empty($r['emailed']) ? ' (wysłano e-mail).' : '.');
            flash_set('success', $msg);
        } else {
            flash_set('danger', 'Podaj kursanta i kwotę wpłaty.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Usunięcie wpłaty (korekta)
    if ($op === 'del_payment') {
        ti_payment_delete((int)($_POST['payment_id'] ?? 0));
        flash_set('success', 'Wpłata usunięta, saldo przeliczone.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Wygeneruj link do zapłaty Stripe dla rozliczenia
    if ($op === 'stripe_link') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT b.*, cl.name AS client_name, cl.email AS client_email FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id WHERE b.id=?", [$bid]) : null;
        if (!$b) { flash_set('danger','Nie znaleziono rozliczenia.'); }
        elseif (!stripe_enabled()) { flash_set('danger','Płatności Stripe nie są skonfigurowane (Administracja → Płatności / Stripe).'); }
        else {
            $amount = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
            $back   = rtrim(APP_URL,'/') . '/karty30/ti/billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'');
            try {
                $r = stripe_create_checkout(
                    'k30_ti_billing', $bid, $amount,
                    'Zajęcia TI — ' . ($b['client_name'] ?? '') . ' (' . $month . '/' . $year . ')',
                    $back . '&paid=1', $back,
                    (string)($b['client_email'] ?? '')
                );
                flash_set('success', 'Link do zapłaty utworzony: ' . $r['url']);
            } catch (\Throwable $e) {
                flash_set('danger', 'Stripe: ' . $e->getMessage());
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Wygeneruj link do zapłaty PayU dla rozliczenia
    if ($op === 'payu_link') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT b.*, cl.name AS client_name, cl.email AS client_email FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id WHERE b.id=?", [$bid]) : null;
        if (!$b) { flash_set('danger','Nie znaleziono rozliczenia.'); }
        elseif (!payu_enabled()) { flash_set('danger','Płatności PayU nie są skonfigurowane (Integracje → Płatności / PayU).'); }
        else {
            $amount = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
            $back   = rtrim(APP_URL,'/') . '/karty30/ti/billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'');
            $notify = rtrim(APP_URL,'/') . '/api/payu_webhook.php';
            try {
                $r = payu_create_order(
                    'k30_ti_billing', $bid, $amount,
                    'Zajęcia TI — ' . ($b['client_name'] ?? '') . ' (' . $month . '/' . $year . ')',
                    $back . '&paid=1', $notify,
                    (string)($b['client_email'] ?? '')
                );
                flash_set('success', 'Link do zapłaty PayU utworzony: ' . $r['url']);
            } catch (\Throwable $e) {
                flash_set('danger', 'PayU: ' . $e->getMessage());
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Wygeneruj link do zapłaty Przelewy24 dla rozliczenia
    if ($op === 'p24_link') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT b.*, cl.name AS client_name, cl.email AS client_email FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id WHERE b.id=?", [$bid]) : null;
        if (!$b) { flash_set('danger','Nie znaleziono rozliczenia.'); }
        elseif (!p24_enabled()) { flash_set('danger','Płatności Przelewy24 nie są skonfigurowane (Integracje → Płatności / Przelewy24).'); }
        else {
            $amount = (float)$b['amount'] + (float)($b['adjustment'] ?? 0);
            $back   = rtrim(APP_URL,'/') . '/karty30/ti/billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'');
            $notify = rtrim(APP_URL,'/') . '/api/p24_webhook.php';
            try {
                $r = p24_create_order(
                    'k30_ti_billing', $bid, $amount,
                    'Zajęcia TI — ' . ($b['client_name'] ?? '') . ' (' . $month . '/' . $year . ')',
                    $back . '&paid=1', $notify,
                    (string)($b['client_email'] ?? '')
                );
                flash_set('success', 'Link do zapłaty Przelewy24 utworzony: ' . $r['url']);
            } catch (\Throwable $e) {
                flash_set('danger', 'Przelewy24: ' . $e->getMessage());
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Korekta rozliczenia — opłata dodatkowa (+) lub rabat (−)
    if ($op === 'set_adjustment') {
        $bid  = (int)($_POST['billing_id'] ?? 0);
        $kind = ($_POST['adj_kind'] ?? 'fee') === 'discount' ? 'discount' : 'fee';
        $val  = (float)str_replace(',', '.', (string)($_POST['adj_value'] ?? '0'));
        $val  = abs($val);
        $adj  = $kind === 'discount' ? -$val : $val;
        $note = trim($_POST['adj_note'] ?? '');
        if ($bid) {
            db()->prepare("UPDATE k30_ti_billing SET adjustment=?, adjustment_note=? WHERE id=?")
               ->execute([$adj, $note, $bid]);
            flash_set('success', $adj == 0 ? 'Korekta usunięta.' : 'Korekta zapisana.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Indywidualny termin płatności dla pojedynczego rozliczenia
    if ($op === 'set_due') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $due = trim($_POST['due_date'] ?? '');
        // Walidacja formatu YYYY-MM-DD (puste = wyczyść termin)
        $val = ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) ? $due : null;
        if ($bid) {
            db()->prepare("UPDATE k30_ti_billing SET due_date=? WHERE id=?")->execute([$val, $bid]);
            flash_set('success', $val ? 'Termin płatności zapisany.' : 'Termin płatności wyczyszczony.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Płatnik rozliczenia (beneficjent|rodzic|pfron|feer) + opcjonalna nazwa
    if ($op === 'set_payer') {
        $bid  = (int)($_POST['billing_id'] ?? 0);
        $type = (string)($_POST['payer_type'] ?? '');
        if (!isset(K30_TI_PAYERS[$type])) $type = '';
        $name = mb_substr(trim((string)($_POST['payer_name'] ?? '')), 0, 200);
        if ($bid) {
            db()->prepare("UPDATE k30_ti_billing SET payer_type=?, payer_name=? WHERE id=?")
               ->execute([$type, $name, $bid]);
            flash_set('success', 'Płatnik zapisany.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Faktura wystawiana w SZO (moduł Faktury → Fakturownia/KSeF). Tworzy wyłącznie
    // SZKIC — wystawienie dokumentu jest osobną decyzją na jego karcie.
    // OSOBNE od upload_invoice poniżej, które rejestruje skan faktury z zewnątrz.
    // FVAT demo dla uczestnika — tylko administrator. Jeden krok: szkic → numer
    // TEST/… → PDF. Nie zużywa numeru produkcyjnego i nie blokuje prawdziwej faktury.
    if ($op === 'demo_invoice') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        if (true) { // operacja administracyjna — niedostępna w panelu
            flash_set('danger', 'Faktura demo jest dostępna tylko dla administratora.');
        } elseif (!module_enabled('invoices_enabled')) {
            flash_set('warning', 'Moduł Faktury jest wyłączony.');
        } else {
            $res = invoice_demo_issue('ti_billing', $bid, $uid);
            if (empty($res['ok'])) {
                flash_set('danger', 'Nie udało się wygenerować faktury demo: ' . (string)$res['error']);
            } else {
                header('Location: ' . APP_URL . '/crm/invoices/pdf.php?id=' . (int)$res['id']);
                exit;
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    if ($op === 'make_invoice') {
        $_dm = db_one("SELECT doc_mode FROM k30_ti_billing WHERE id=?", [(int)($_POST['billing_id'] ?? 0)]);
        if (($_dm['doc_mode'] ?? '') === 'statement') {
            flash_set('warning', 'To rozliczenie jest w trybie „tylko zestawienie, bez FVAT” — przełącz je na FVAT, żeby wystawić lub zarejestrować fakturę.');
            header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
        }
        $bid = (int)($_POST['billing_id'] ?? 0);
        if (!module_enabled('invoices_enabled')) {
            flash_set('warning', 'Moduł Faktury jest wyłączony.');
        } elseif ($bf_backend) {
            // Backend Betterfly: faktura z wiersza rozliczenia (per kurs lub zbiorcza).
            try {
                $res = betterfly_issue_ti_from_billing($bid, ['uid' => $uid]);
                if (!empty($res['via_edok'])) {
                    flash_set('success', 'Faktura utworzona w Betterfly i skierowana do obiegu EODoK (dokument #' . (int)$res['edok_doc_id'] . '). Zatwierdzenie po akceptacji obiegu.');
                } else {
                    flash_set('success', 'Faktura wystawiona w Betterfly' . (!empty($res['number']) ? ' (nr ' . $res['number'] . ').' : '.'));
                }
            } catch (\Throwable $e) {
                flash_set('danger', 'Betterfly: ' . $e->getMessage());
            }
        } else {
            $res = invoice_from_ti_billing($bid, $uid);
            if (empty($res['ok'])) {
                flash_set('danger', 'Nie udało się przygotować faktury: ' . (string)$res['error']);
            } else {
                if (!empty($res['warnings'])) flash_set('warning', 'Faktura TI: ' . implode(' ', $res['warnings']));
                header('Location: ' . APP_URL . '/crm/invoices/view.php?id=' . (int)$res['id']);
                exit;
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Faktura (FVAT) wystawiona poza panelem — numer, data, rodzaj + WYMAGANY skan PDF.
    // Faktury wystawiamy w zewnętrznym systemie (domyślnie Comarch ERP Optima); tu trzymamy skan.
    if ($op === 'upload_invoice') {
        $_dm = db_one("SELECT doc_mode FROM k30_ti_billing WHERE id=?", [(int)($_POST['billing_id'] ?? 0)]);
        if (($_dm['doc_mode'] ?? '') === 'statement') {
            flash_set('warning', 'To rozliczenie jest w trybie „tylko zestawienie, bez FVAT” — przełącz je na FVAT, żeby wystawić lub zarejestrować fakturę.');
            header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
        }
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$bid]) : null;
        if (!$b) { flash_set('danger','Nie znaleziono rozliczenia.'); }
        else {
            $kind   = ($_POST['invoice_kind'] ?? '') === 'oneoff' ? 'oneoff' : '';
            $inv_no = mb_substr(trim((string)($_POST['invoice_no'] ?? '')), 0, 60);
            $inv_on = trim((string)($_POST['invoice_issued_on'] ?? ''));
            $inv_on = preg_match('/^\d{4}-\d{2}-\d{2}$/', $inv_on) ? $inv_on : null;
            try {
                $up  = k30_ti_invoice_upload('invoice', 'fv' . $bid);
                $has = $up || !empty($b['invoice_path']);
                if (!$has) {
                    // Blokada: bez skanu faktury nic nie zapisujemy
                    flash_set('danger', 'Skan faktury (PDF) jest wymagany — bez pliku nie można zapisać danych faktury.');
                } else {
                    if ($up && !empty($b['invoice_path'])) k30_ti_invoice_delete_file($b['invoice_path']);
                    $sql = "UPDATE k30_ti_billing SET invoice_kind=?, invoice_no=?, invoice_issued_on=?, invoice_system=?";
                    $par = [$kind, $inv_no, $inv_on, k30_ti_invoice_system()];
                    if ($up) {
                        $sql .= ", invoice_path=?, invoice_name=?, invoice_at=datetime('now')";
                        $par[] = $up['stored']; $par[] = $up['name'];
                    }
                    $sql .= " WHERE id=?"; $par[] = $bid;
                    db()->prepare($sql)->execute($par);
                    flash_set('success', ($up ? 'Skan faktury zapisany.' : 'Dane faktury zapisane.')
                                       . ($kind === 'oneoff' ? ' Oznaczono jako faktura jednorazowa.' : ''));
                }
            } catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Usunięcie faktury z rozliczenia
    if ($op === 'delete_invoice') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $b   = $bid ? db_one("SELECT invoice_path FROM k30_ti_billing WHERE id=?", [$bid]) : null;
        if ($b) {
            if (!empty($b['invoice_path'])) k30_ti_invoice_delete_file($b['invoice_path']);
            db()->prepare("UPDATE k30_ti_billing SET invoice_path='', invoice_name='', invoice_at=NULL,
                           invoice_kind='', invoice_no='', invoice_issued_on=NULL, invoice_system='' WHERE id=?")->execute([$bid]);
            flash_set('success', 'Faktura usunięta.');
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Miękkie usuwanie rozliczenia (status='cancelled') — tylko admin
    if ($op === 'delete') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $bid = (int)($_POST['billing_id'] ?? 0);
        if ($bid) db()->prepare("UPDATE k30_ti_billing SET status='cancelled' WHERE id=?")->execute([$bid]);
        flash_set('success','Rozliczenie usunięte.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Ponowne wystawienie rozliczenia — przelicza godziny i kwotę z AKTUALNYCH
    // lekcji. Potrzebne, gdy po wystawieniu doszły albo zostały poprawione
    // zajęcia: bez tego rozliczenie zostaje z nieaktualną kwotą.
    //
    // Blokujemy, gdy z rozliczenia wystawiono fakturę produkcyjną — kwota na
    // wystawionym dokumencie przestałaby zgadzać się z podstawą, a faktury nie
    // poprawia się przez przeliczenie należności, tylko korektą.
    // Powiadomień NIE wysyłamy ponownie: kursant już je dostał.
    if ($op === 'reissue_billing') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        $bill = $bid ? db_one(
            "SELECT id, client_id, month, year, COALESCE(course_id,0) AS course_id, amount, hours_billed, notes
               FROM k30_ti_billing WHERE id=?", [$bid]
        ) : null;

        if (!$bill) {
            flash_set('danger', 'Nie znaleziono rozliczenia.');
        } elseif (!$can_delete) {
            flash_set('danger', 'Brak uprawnień do ponownego wystawiania rozliczeń.');
        } else {
            $prod = null;
            if (module_enabled('invoices_enabled')) {
                try {
                    $prod = db_one("SELECT id, number FROM invoices
                                     WHERE source='ti_billing' AND source_id=? AND is_test=0 AND deleted_at IS NULL", [$bid]);
                } catch (\Throwable $e) {}
            }
            if ($prod) {
                flash_set('danger', 'Nie można przeliczyć: z tego rozliczenia wystawiono fakturę '
                    . h((string)($prod['number'] ?: '#' . $prod['id']))
                    . '. Kwota na dokumencie przestałaby się zgadzać — potrzebna jest korekta faktury.');
            } else {
                $old_a = (float)$bill['amount'];
                $old_h = (float)$bill['hours_billed'];

                k30_ti_issue_billing((int)$bill['client_id'], (int)$bill['month'], (int)$bill['year'],
                                     (string)($bill['notes'] ?? ''), (int)$bill['course_id']);
                ti_billing_recompute((int)$bill['client_id']);

                $fresh = db_one("SELECT amount, hours_billed FROM k30_ti_billing WHERE id=?", [$bid]);
                $new_a = (float)($fresh['amount'] ?? 0);
                $new_h = (float)($fresh['hours_billed'] ?? 0);

                $diff = abs($new_a - $old_a) > 0.005 || abs($new_h - $old_h) > 0.005
                    ? sprintf(' Zmiana: %s → %s zł, %s → %s godz.',
                        number_format($old_a, 2, ',', ' '), number_format($new_a, 2, ',', ' '),
                        rtrim(rtrim(number_format($old_h, 2, ',', ' '), '0'), ','),
                        rtrim(rtrim(number_format($new_h, 2, ',', ' '), '0'), ','))
                    : ' Bez zmian — dane lekcji są takie same.';

                flash_set('success', 'Rozliczenie przeliczone ponownie.' . $diff
                    . ' Powiadomienia nie zostały wysłane ponownie.');
            }
        }
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // TRWAŁE usunięcie rozliczenia — tylko administrator. OSOBNE od op='delete',
    // które jedynie anuluje (status='cancelled') i zostawia ślad w historii.
    //
    // Blokujemy usunięcie, gdy z rozliczenia wystawiono fakturę produkcyjną:
    // dokument księgowy musi mieć podstawę, a jego numer jest już nadany.
    // Fakturę DEMO usuwamy razem z rozliczeniem — nie jest dokumentem.
    if ($op === 'purge_billing') {
        $bid = (int)($_POST['billing_id'] ?? 0);
        http_response_code(403); die('Operacja administracyjna — niedostępna w panelu.');

        $bill = $bid ? db_one("SELECT id, client_id, month, year FROM k30_ti_billing WHERE id=?", [$bid]) : null;
        if (!$bill) {
            flash_set('danger', 'Nie znaleziono rozliczenia.');
            header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
        }

        $prod = null;
        if (module_enabled('invoices_enabled')) {
            try {
                $prod = db_one("SELECT id, number FROM invoices
                                 WHERE source='ti_billing' AND source_id=? AND is_test=0 AND deleted_at IS NULL", [$bid]);
            } catch (\Throwable $e) {}
        }
        if ($prod) {
            flash_set('danger', 'Nie można usunąć: z tego rozliczenia wystawiono fakturę '
                . h((string)($prod['number'] ?: '#' . $prod['id']))
                . '. Najpierw anuluj fakturę albo użyj anulowania rozliczenia.');
            header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
        }

        // Faktury demo i ich pozycje odchodzą razem z podstawą.
        if (module_enabled('invoices_enabled')) {
            try {
                db()->prepare("DELETE FROM invoice_items WHERE invoice_id IN
                               (SELECT id FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=1)")->execute([$bid]);
                db()->prepare("DELETE FROM invoices WHERE source='ti_billing' AND source_id=? AND is_test=1")->execute([$bid]);
            } catch (\Throwable $e) {}
        }

        // Skan faktury wgrany do rozliczenia — plik z dysku.
        try {
            $sc = db_one("SELECT invoice_path FROM k30_ti_billing WHERE id=?", [$bid]);
            if (!empty($sc['invoice_path']) && function_exists('k30_ti_invoice_delete_file')) {
                k30_ti_invoice_delete_file((string)$sc['invoice_path']);
            }
        } catch (\Throwable $e) {}

        // Wnioski o przeniesienie płatności odchodzą kaskadą
        // (k30_ti_payment_deferrals.billing_id ON DELETE CASCADE).
        db()->prepare("DELETE FROM k30_ti_billing WHERE id=?")->execute([$bid]);

        // Alokacja wpłat NIE jest osobną tabelą — wynika z ti_client_allocation()
        // i zapisuje się w k30_ti_billing.paid_amount. Po usunięciu należności
        // trzeba ją przeliczyć, inaczej saldo kursanta zostaje nieaktualne
        // (np. wpłata dalej „pokrywa" nieistniejące już rozliczenie).
        try { ti_billing_recompute((int)$bill['client_id']); } catch (\Throwable $e) {}

        if (function_exists('log_user_action')) {
            log_user_action((int)$bill['client_id'], $uid, 'ti_billing_purged',
                'Trwale usunięto rozliczenie TI ' . str_pad((string)(int)$bill['month'], 2, '0', STR_PAD_LEFT)
                . '/' . (int)$bill['year'] . ' (id ' . $bid . ')');
        }

        flash_set('success', 'Rozliczenie trwale usunięte, saldo kursanta przeliczone.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Wniosek o przeniesienie płatności na następny miesiąc
    if ($op === 'request_deferral') {
        $bid    = (int)($_POST['billing_id'] ?? 0);
        $reason = trim($_POST['deferral_reason'] ?? '');
        $confirm = !empty($_POST['deferral_confirm']);
        if (!$bid || !$reason || !$confirm) {
            flash_set('danger', 'Wypełnij powód i zaznacz potwierdzenie.');
            header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
        }
        $did = ti_deferral_request($bid, $reason, (int)($_SESSION['user_id'] ?? 0));
        flash_set($did ? 'success' : 'danger', $did ? 'Wniosek o przeniesienie płatności złożony. Oczekuje na akceptację administratora.' : 'Nie można złożyć wniosku — rozliczenie ma nieprawidłowy status lub wniosek już istnieje.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Zatwierdzenie wniosku o przeniesienie (tylko admin)
    if ($op === 'approve_deferral') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $did  = (int)($_POST['deferral_id'] ?? 0);
        $note = trim($_POST['decide_note'] ?? '');
        $ok   = $did && ti_deferral_approve($did, (int)($_SESSION['user_id'] ?? 0), $note);
        flash_set($ok ? 'success' : 'danger', $ok ? 'Płatność przeniesiona na następny miesiąc.' : 'Błąd — wniosek nie istnieje lub jest już rozpatrzony.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }

    // Odrzucenie wniosku o przeniesienie (tylko admin)
    if ($op === 'reject_deferral') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $did  = (int)($_POST['deferral_id'] ?? 0);
        $note = trim($_POST['decide_note'] ?? '');
        $ok   = $did && ti_deferral_reject($did, (int)($_SESSION['user_id'] ?? 0), $note);
        flash_set($ok ? 'warning' : 'danger', $ok ? 'Wniosek odrzucony.' : 'Błąd — wniosek nie istnieje.');
        header('Location: billing.php?month='.$month.'&year='.$year.($course_id?'&course_id='.$course_id:'')); exit;
    }
}

// Pobierz rozliczenia za wybrany miesiąc
$where = ["b.month=? AND b.year=?", "b.status!='cancelled'"]; $params = [$month, $year];
if ($course_id) {
    // Filtruj per kurs — klienci w tym kursie
    $where[] = "b.client_id IN (SELECT client_id FROM k30_ti_enrollments WHERE course_id=?)";
    $params[] = $course_id;
}
$billings = db_all(
    "SELECT b.*, cl.name AS client_name, cl.email AS client_email
     FROM k30_ti_billing b
     JOIN k30_clients cl ON cl.id=b.client_id
     WHERE " . implode(' AND ', $where) . " ORDER BY cl.name",
    $params
);

// Wycofane w tym miesiącu (ze śladem kto/kiedy/dlaczego) — do wglądu i przywrócenia
$withdrawn = db_all(
    "SELECT b.*, cl.name AS client_name FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id
      WHERE b.month=? AND b.year=? AND b.status='cancelled' AND b.cancelled_at IS NOT NULL"
    . ($course_id ? " AND b.client_id IN (SELECT client_id FROM k30_ti_enrollments WHERE course_id=?)" : '') . "
      ORDER BY b.cancelled_at DESC", $course_id ? [$month, $year, $course_id] : [$month, $year]
);

// Podgląd nieopłaconych (kalkulacja bez zapisu)
$preview_where = []; $prev_params = [];
if ($course_id) {
    $preview_where[] = "e.course_id=?";
    $prev_params[] = $course_id;
}
$pq = $preview_where ? 'AND ' . implode(' AND ', $preview_where) : '';
$enrolled_active = db_all(
    "SELECT DISTINCT e.client_id, cl.name AS client_name
     FROM k30_ti_enrollments e
     JOIN k30_clients cl ON cl.id=e.client_id
     WHERE e.status='active' $pq ORDER BY cl.name",
    $prev_params
);
$billed_ids = array_column($billings, 'client_id');

// Saldo (nadpłata/niedopłata) per kursant — dla wyświetlanych rozliczeń
$balances = [];
foreach (array_unique($billed_ids) as $bcid) { $balances[(int)$bcid] = ti_client_balance((int)$bcid); }

// Rozbicie kosztów per kurs dla wystawionych rozliczeń łącznych (course_id=0).
// Dla rozliczeń per kurs (course_id>0) wystarczy nazwa kursu z bazy.
$billing_courses = [];
foreach ($billings as $b) {
    if ((int)$b['course_id'] === 0) {
        $calc = k30_ti_calculate_billing((int)$b['client_id'], (int)$b['month'], (int)$b['year']);
        if (!empty($calc['courses'])) $billing_courses[(int)$b['id']] = $calc['courses'];
    }
}

// Mapa nazw kursów dla rozliczeń per-kurs
$billing_course_names = [];
foreach ($billings as $b) {
    if ((int)$b['course_id'] > 0) {
        $cn = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [(int)$b['course_id']]);
        $billing_course_names[(int)$b['id']] = $cn['name'] ?? '?';
    }
}

// Kursanci z niedopłatą (globalnie) — flaga dla panelu admina
$debtors = ti_clients_with_debt();

// Oczekujące wnioski o przeniesienie płatności
$pending_deferrals = ti_deferrals_pending();

// Mapa płatności Stripe dla wyświetlanych rozliczeń (source_type=k30_ti_billing)
$stripe_pay = [];
$bids = array_column($billings, 'id');
if ($bids) {
    $in = implode(',', array_fill(0, count($bids), '?'));
    foreach (db_all("SELECT * FROM stripe_payments WHERE source_type='k30_ti_billing' AND source_id IN ($in) ORDER BY id", $bids) as $sp) {
        $stripe_pay[(int)$sp['source_id']] = $sp; // ostatni wygrywa
    }
}
$stripe_on = stripe_enabled();

// Mapa płatności PayU dla wyświetlanych rozliczeń
$payu_pay = [];
if ($bids) {
    $in = implode(',', array_fill(0, count($bids), '?'));
    foreach (db_all("SELECT * FROM payu_payments WHERE source_type='k30_ti_billing' AND source_id IN ($in) ORDER BY id", $bids) as $pp) {
        $payu_pay[(int)$pp['source_id']] = $pp; // ostatni wygrywa
    }
}
$payu_on = payu_enabled();

// Mapa płatności Przelewy24 dla wyświetlanych rozliczeń
$p24_pay = [];
if ($bids) {
    $in = implode(',', array_fill(0, count($bids), '?'));
    foreach (db_all("SELECT * FROM p24_payments WHERE source_type='k30_ti_billing' AND source_id IN ($in) ORDER BY id", $bids) as $p4) {
        $p24_pay[(int)$p4['source_id']] = $p4; // ostatni wygrywa
    }
}
$p24_on = p24_enabled();

// Podgląd kwot dla nieopłaconych
$preview = [];
foreach ($enrolled_active as $e) {
    $cid = (int)$e['client_id'];
    if (in_array($cid, $billed_ids)) continue;
    $calc = k30_ti_calculate_billing($cid, $month, $year);
    if ($calc['hours_billed'] > 0 || $calc['amount'] > 0) {
        $preview[$cid] = array_merge($calc, ['client_name' => $e['client_name']]);
    }
}

// Miesiące do nawigacji
$months_pl = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',
              7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];

$KP_TITLE  = 'Rozliczenia kursantów — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
echo '<link rel="stylesheet" href="../assets/ti_skin.css?v=' . (is_file($_skin_css) ? (int)filemtime($_skin_css) : 1) . '">';
$KIER_CUR = 'billing.php'; $KIER_LABEL = 'Rozliczenia kursantów';
include __DIR__ . '/_kierownik_bar.php';
echo '<main id="main" class="dyd-wrap">';
?>

<nav aria-label="breadcrumb" class="mb-3 d-none"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="../index.php">Zajęcia TI</a></li>
  <?php if ($course): ?>
  <li class="breadcrumb-item"><a href="kurs.php?id=<?= $course_id ?>"><?= h($course['name']) ?></a></li>
  <?php endif; ?>
  <li class="breadcrumb-item active">Rozliczenia miesięczne</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-receipt text-primary me-2"></i>
    Rozliczenia TI<?= $course ? ' — '.h($course['name']) : '' ?>
  </h4>
  <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= APP_URL ?>/rozliczenia/index.php?m=<?= sprintf('%04d-%02d', $year, $month) ?>"
     title="Nowy moduł Rozliczenia — pulpit, grupy, uczestnicy, faktury">
    <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Moduł Rozliczenia
  </a>
  <?php if ($course_id): ?>
  <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
     href="billing_fv_summary.php?course_id=<?= $course_id ?>&amp;month=<?= $month ?>&amp;year=<?= $year ?>"
     title="Pozycje do faktury dla całej grupy — do przepisania do systemu fakturowego">
    <i class="bi bi-printer me-1" aria-hidden="true"></i>Podsumowanie do FVAT — grupa
  </a>
  <?php endif; ?>
</div>

<div class="alert alert-light border d-flex align-items-start gap-2 py-2 px-3 small" role="note">
  <i class="bi bi-info-circle text-primary mt-1" aria-hidden="true"></i>
  <div>
    <strong>Model kombinowany:</strong> każda grupa (przedmiot) ma osobne rozliczenie i osobne saldo —
    kursant zapisany do kilku grup dostaje kilka rozliczeń, każde ze swoim modelem.
    Wpłatę można zaksięgować na wskazaną grupę (nadpłata zostaje wtedy w tej grupie) albo ogólnie na konto (FIFO).
    Faktury wystawiamy w systemie <strong><?= h(k30_ti_invoice_system()) ?></strong> — w panelu rejestrujemy numer
    i obowiązkowy skan PDF, a pozycje drukujemy przyciskiem „Podsumowanie do FVAT".
  </div>
</div>

<?= flash_html() ?>

<?php if (isset($_GET['paid'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="bi bi-check-circle me-1"></i>Dziękujemy — płatność została zainicjowana. Status zaktualizuje się po potwierdzeniu przez operatora płatności (webhook).
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Nawigacja miesięczna -->
<div class="d-flex align-items-center gap-2 mb-4">
  <?php
    $prev_m = $month === 1 ? 12 : $month - 1;
    $prev_y = $month === 1 ? $year - 1 : $year;
    $next_m = $month === 12 ? 1 : $month + 1;
    $next_y = $month === 12 ? $year + 1 : $year;
    $base   = '?'.($course_id?'course_id='.$course_id.'&':'');
  ?>
  <a href="<?= $base ?>month=<?= $prev_m ?>&year=<?= $prev_y ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-chevron-left"></i>
  </a>
  <h5 class="mb-0 fw-bold"><?= $months_pl[$month] ?> <?= $year ?></h5>
  <a href="<?= $base ?>month=<?= $next_m ?>&year=<?= $next_y ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-chevron-right"></i>
  </a>
  <?php if ($preview && $can_write): ?>
  <form method="post" class="ms-auto" onsubmit="return confirm('Wystawić rozliczenia dla wszystkich klientów z lekcjami w tym miesiącu?')">
    <input type="hidden" name="_csrf"  value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op"    value="issue_all">
    <div class="btn-group btn-group-sm" role="group" aria-label="Tryb wystawiania">
      <button type="submit" name="doc_mode" value="" class="btn btn-primary">
        <i class="bi bi-receipt-cutoff me-1"></i>Wystaw wszystkie (<?= count($preview) ?>) — z FVAT
      </button>
      <button type="submit" name="doc_mode" value="statement" class="btn btn-outline-primary"
              title="Same zestawienia godzin i należności — bez faktur VAT">
        <i class="bi bi-file-earmark-text me-1"></i>Tylko zestawienia
      </button>
    </div>
  </form>
  <?php endif; ?>
</div>

<!-- Niedopłaty (flaga dla admina) -->
<?php if ($debtors): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-danger border-4">
  <div class="card-header fw-semibold d-flex align-items-center bg-danger-subtle text-danger-emphasis">
    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>Niedopłaty
    <span class="badge bg-danger ms-2"><?= count($debtors) ?></span>
    <span class="ms-auto small fw-normal">Łącznie brakuje: <?= number_format(array_sum(array_map(fn($d)=>(float)$d['debt'],$debtors)),2,',',' ') ?> zł</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
      <caption class="visually-hidden">Kursanci z niedopłatą</caption>
      <thead class="table-light"><tr><th>Kursant</th><th>Należności</th><th>Wpłacono</th><th>Brakuje</th></tr></thead>
      <tbody>
        <?php foreach ($debtors as $d): ?>
        <tr>
          <td class="fw-semibold"><?= h($d['client_name']) ?></td>
          <td><?= number_format((float)$d['charges'],2,',',' ') ?> zł</td>
          <td><?= number_format((float)$d['paid'],2,',',' ') ?> zł</td>
          <td class="fw-bold text-danger"><?= number_format((float)$d['debt'],2,',',' ') ?> zł</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Oczekujące wnioski o przeniesienie płatności -->
<?php if ($pending_deferrals): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-warning border-4">
  <div class="card-header fw-semibold d-flex align-items-center bg-warning-subtle text-warning-emphasis">
    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>Wnioski o przeniesienie płatności
    <span class="badge bg-warning text-dark ms-2"><?= count($pending_deferrals) ?></span>
    <span class="ms-auto small fw-normal text-body-secondary">Wymagają akceptacji administratora</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
      <caption class="visually-hidden">Oczekujące wnioski o przeniesienie płatności</caption>
      <thead class="table-light">
        <tr><th>Kursant</th><th>Kwota</th><th>Z okresu</th><th>Na okres</th><th>Powód</th><th>Złożono przez</th><th class="text-end">Akcja</th></tr>
      </thead>
      <tbody>
        <?php
          $months_pl_d = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                          7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
        ?>
        <?php foreach ($pending_deferrals as $def): ?>
        <tr>
          <td class="fw-semibold"><?= h($def['client_name']) ?></td>
          <td class="fw-bold"><?= number_format((float)$def['amount'],2,',','') ?> zł</td>
          <td><?= $months_pl_d[(int)$def['from_month']] ?? '?' ?> <?= (int)$def['from_year'] ?></td>
          <td><?= $months_pl_d[(int)$def['to_month']] ?? '?' ?> <?= (int)$def['to_year'] ?></td>
          <td style="max-width:260px"><?= h($def['reason']) ?></td>
          <td class="text-muted"><?= h($def['requested_by_name'] ?? '—') ?><br>
            <span class="text-muted"><?= h(substr((string)$def['requested_at'],0,16)) ?></span>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($can_delete): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Zatwierdzić przeniesienie płatności <?= number_format((float)$def['amount'],2,',','') ?> zł na <?= $months_pl_d[(int)$def['to_month']] ?? '' ?> <?= (int)$def['to_year'] ?>?')">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="approve_deferral">
              <input type="hidden" name="deferral_id" value="<?= (int)$def['id'] ?>">
              <input type="hidden" name="month"       value="<?= $month ?>">
              <input type="hidden" name="year"        value="<?= $year ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-success py-0 px-2">
                <i class="bi bi-check-lg me-1"></i>Zatwierdź
              </button>
            </form>
            <button type="button" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2"
                    data-bs-toggle="modal" data-bs-target="#rejectDef<?= (int)$def['id'] ?>">
              <i class="bi bi-x-lg me-1"></i>Odrzuć
            </button>
            <?php else: ?>
            <span class="text-muted small">Tylko admin może zatwierdzić</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<!-- Modale odrzucenia wniosków -->
<?php foreach ($pending_deferrals as $def): ?>
<div class="modal fade" id="rejectDef<?= (int)$def['id'] ?>" tabindex="-1" aria-labelledby="rejectDef<?= (int)$def['id'] ?>_t" aria-hidden="true">
  <div class="modal-dialog"><form method="post" class="modal-content">
    <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op"         value="reject_deferral">
    <input type="hidden" name="deferral_id" value="<?= (int)$def['id'] ?>">
    <input type="hidden" name="month"       value="<?= $month ?>">
    <input type="hidden" name="year"        value="<?= $year ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="rejectDef<?= (int)$def['id'] ?>_t"><i class="bi bi-x-circle text-danger me-2"></i>Odrzuć wniosek — <?= h($def['client_name']) ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <p class="small text-body-secondary">Wniosek o przeniesienie <strong><?= number_format((float)$def['amount'],2,',','') ?> zł</strong> z <?= $months_pl_d[(int)$def['from_month']] ?? '' ?> <?= (int)$def['from_year'] ?> na <?= $months_pl_d[(int)$def['to_month']] ?? '' ?> <?= (int)$def['to_year'] ?>.</p>
      <p class="small mb-2">Powód kursanta: <em><?= h($def['reason']) ?></em></p>
      <label class="form-label fw-semibold" for="rdn<?= (int)$def['id'] ?>">Uwaga do odrzucenia <span class="text-body-secondary fw-normal">(opcjonalnie)</span></label>
      <textarea class="form-control" id="rdn<?= (int)$def['id'] ?>" name="decide_note" rows="2" placeholder="np. Termin płatności nieprzekraczalny z powodu…"></textarea>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Odrzuć wniosek</button>
    </div>
  </form></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Wystawione rozliczenia -->
<?php if ($billings): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-check-circle text-success me-2"></i>Wystawione rozliczenia
    <span class="badge bg-secondary ms-2"><?= count($billings) ?></span>
    <span class="ms-auto text-muted small fw-normal">
      Razem: <?= number_format(array_sum(array_map(fn($b)=>(float)$b['amount']+(float)($b['adjustment']??0),$billings)),2,',','') ?> zł
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Klient</th><th>Godz.</th><th>Korekta</th><th>Do zapłaty</th><th>Termin</th><th>Status</th><th class="text-end">Akcje</th></tr>
      </thead>
      <tbody>
        <?php foreach ($billings as $b):
          $bs   = K30_TI_BILLING_STATUSES[$b['status']] ?? ['label'=>$b['status'],'color'=>'#666','bg'=>'#eee'];
          $adj  = (float)($b['adjustment'] ?? 0);
          $base = (float)$b['amount'];
          $tot  = $base + $adj;
        ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($b['client_name']) ?></div>
            <?php if ((int)$b['course_id'] > 0): ?>
            <div class="mt-1">
              <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle" style="font-size:.75rem">
                <i class="bi bi-mortarboard me-1"></i><?= h($billing_course_names[(int)$b['id']] ?? '?') ?>
              </span>
            </div>
            <?php else: $bc = $billing_courses[(int)$b['id']] ?? []; ?>
            <?php if (count($bc) > 1): ?>
            <div class="mt-1">
              <?php foreach ($bc as $bcc): if ($bcc['amount'] <= 0 && $bcc['hours_billed'] <= 0) continue; ?>
              <span class="badge bg-light text-secondary border me-1" style="font-size:.72rem;font-weight:500">
                <?= h($bcc['course_name']) ?>:
                <?php if ($bcc['model'] !== 2): ?>
                  <?= number_format($bcc['amount'],2,',','') ?> zł
                <?php else: ?>
                  <?= number_format($bcc['hours_billed'],2,',','') ?> h · <?= number_format($bcc['amount'],2,',','') ?> zł
                <?php endif; ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php elseif (!empty($bc)): ?>
            <div class="text-muted" style="font-size:.78rem"><?= h($bc[0]['course_name'] ?? '') ?></div>
            <?php endif; ?>
            <?php endif; ?>
            <?php $bpay = k30_ti_client_payment((int)$b['client_id']); ?>
            <?php if ($bpay['codes']): ?>
            <div class="small">
              <?php foreach ($bpay['codes'] as $code): ?>
              <span class="badge <?= $code===9999 ? 'bg-warning text-dark' : 'bg-light text-secondary border' ?>" title="Kod modelu rozliczania"><?= $code===9999 ? '9999 · indyw.' : 'kod '.(int)$code ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bpay['account']) || !empty($bpay['title'])): ?>
            <div class="text-muted" style="font-size:.72rem"><i class="bi bi-bank me-1"></i><?= h($bpay['account'] ?: '—') ?><?php if (!empty($bpay['title'])): ?> · „<?= h($bpay['title']) ?>"<?php endif; ?></div>
            <?php endif; ?>
            <?php
              // Saldo TEJ grupy (model kombinowany) — obok salda całego konta
              $gb = (int)$b['course_id'] > 0 ? ti_group_balance((int)$b['client_id'], (int)$b['course_id']) : null;
              if ($gb && ($gb['credit'] > 0.005 || $gb['debt'] > 0.005)): ?>
            <div class="small mt-1">
              <?php if ($gb['credit'] > 0.005): ?>
              <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle" title="Nadpłata przypisana do tej grupy">
                <i class="bi bi-piggy-bank me-1"></i>nadpłata grupy <?= number_format($gb['credit'],2,',',' ') ?> zł</span>
              <?php else: ?>
              <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle" title="Niedopłata w tej grupie">
                <i class="bi bi-exclamation-triangle me-1"></i>niedopłata grupy <?= number_format($gb['debt'],2,',',' ') ?> zł</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php $tsrc = $can_write && $b['status'] !== 'cancelled' ? ti_balance_transfer_sources((int)$b['id']) : ['credits'=>[], 'debts'=>[]];
            if ($tsrc['credits'] || $tsrc['debts']): $tf = 'tf-' . (int)$b['id']; ?>
            <details class="small mt-1">
              <summary class="text-primary" style="cursor:pointer;font-size:.74rem"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Przenieś saldo z innej grupy / okresu</summary>
              <form method="post" class="d-flex flex-wrap align-items-end gap-1 mt-1"
                    onsubmit="return confirm('Przenieść wskazaną kwotę na to rozliczenie?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_op" value="transfer_balance">
                <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
                <label class="visually-hidden" for="<?= $tf ?>-s">Źródło</label>
                <select id="<?= $tf ?>-s" name="source" class="form-select form-select-sm" style="max-width:260px"
                        onchange="var o=this.selectedOptions[0], f=this.form, r=parseFloat(o.dataset.rate)||0; f.amount.value=o.dataset.max||''; f.amount.max=o.dataset.max||''; f.hours.disabled=!r; f.hours.value=r?(Math.round(parseFloat(o.dataset.max)/r*100)/100):''; f.hours.dataset.rate=r;">
                  <?php if ($tsrc['credits']): ?><optgroup label="Nadpłata → do tej grupy">
                    <?php foreach ($tsrc['credits'] as $tc): ?>
                    <option value="credit:<?= $tc['course_id'] ?>" data-max="<?= number_format($tc['amount'], 2, '.', '') ?>" data-rate="<?= number_format($tc['rate'], 2, '.', '') ?>"><?= h($tc['label']) ?> — <?= number_format($tc['amount'], 2, ',', ' ') ?> zł<?= h(ti_transfer_hours_note($tc['amount'], $tc['rate'])) ?></option>
                    <?php endforeach; ?></optgroup><?php endif; ?>
                  <?php if ($tsrc['debts']): ?><optgroup label="Niedopłata → na to rozliczenie">
                    <?php foreach ($tsrc['debts'] as $td): ?>
                    <option value="debt:<?= $td['billing_id'] ?>" data-max="<?= number_format($td['amount'], 2, '.', '') ?>" data-rate="<?= number_format($td['rate'], 2, '.', '') ?>"><?= h($td['label']) ?> — <?= number_format($td['amount'], 2, ',', ' ') ?> zł<?= h(ti_transfer_hours_note($td['amount'], $td['rate'])) ?></option>
                    <?php endforeach; ?></optgroup><?php endif; ?>
                </select>
                <?php $tfirst = $tsrc['credits'][0] ?? $tsrc['debts'][0]; $trate = (float)$tfirst['rate']; ?>
                <label class="small mb-0" for="<?= $tf ?>-h">godz.</label>
                <input id="<?= $tf ?>-h" type="number" name="hours" step="0.25" min="0.25" data-rate="<?= number_format($trate, 2, '.', '') ?>"
                       value="<?= $trate > 0 ? round($tfirst['amount'] / $trate, 2) : '' ?>"<?= $trate > 0 ? '' : ' disabled' ?>
                       title="Liczba godzin — przeliczana na zł po stawce" class="form-control form-control-sm" style="width:80px"
                       oninput="var r=parseFloat(this.dataset.rate)||0; if (r && this.value) this.form.amount.value=(Math.round(parseFloat(this.value)*r*100)/100).toFixed(2);">
                <label class="small mb-0" for="<?= $tf ?>-a">zł</label>
                <input id="<?= $tf ?>-a" type="number" name="amount" step="0.01" min="0.01" max="<?= number_format($tfirst['amount'], 2, '.', '') ?>"
                       value="<?= number_format($tfirst['amount'], 2, '.', '') ?>" class="form-control form-control-sm" style="width:100px"
                       oninput="var h=this.form.hours, r=parseFloat(h.dataset.rate)||0; if (r && this.value) h.value=Math.round(parseFloat(this.value)/r*100)/100;">
                <button type="submit" class="btn btn-sm btn-outline-primary py-0">Przenieś</button>
              </form>
            </details>
            <?php endif; ?>
            <?php // Nadpłatę pokazujemy tylko przy grupie (wyżej) — plakietka konta tylko przy niedopłacie
            $bbal = $balances[(int)$b['client_id']] ?? null; if ($bbal && $bbal['debt'] > 0.005): ?>
            <div class="small mt-1"><span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle" title="Niedopłata na całym koncie kursanta (wszystkie grupy)"><i class="bi bi-exclamation-triangle me-1"></i>niedopłata <?= number_format($bbal['debt'],2,',',' ') ?> zł</span></div>
            <?php endif; ?>
            <div class="text-muted" style="font-size:.72rem">
              <i class="bi bi-person-badge me-1"></i>Płatnik: <?= h(k30_ti_billing_payer_label($b)) ?>
            </div>
            <?php if (!empty($b['invoice_path'])): ?>
            <div style="font-size:.72rem">
              <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
              <a href="billing_invoice.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener">Faktura<?= !empty($b['invoice_no']) ? ' '.h($b['invoice_no']) : '' ?><?= !empty($b['invoice_at']) ? ' ('.h(substr($b['invoice_at'],0,10)).')' : '' ?></a>
              <?php if (($b['invoice_kind'] ?? '') === 'oneoff'): ?>
              <span class="badge bg-warning text-dark" style="font-size:.66rem" title="Faktura jednorazowa">jednorazowa</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (module_enabled('invoices_enabled')): ?>
            <?php
              // Czy z tego rozliczenia już powstała faktura w module Faktury.
              $_fv = null;
              try {
                  $_fv = db_one("SELECT id, number, status FROM invoices
                                  WHERE source='ti_billing' AND source_id=? AND deleted_at IS NULL", [(int)$b['id']]);
              } catch (\Throwable $e) { /* moduł jeszcze nie migrowany */ }
              // Backend Betterfly: powiązanie tego rozliczenia z fakturą Betterfly.
              $_bf = null;
              if ($bf_backend && function_exists('betterfly_link_for_billing')) {
                  try { $_bf = betterfly_link_for_billing($b); } catch (\Throwable $e) {}
              }
            ?>
            <div style="font-size:.72rem" class="mt-1">
              <?php
                // Grupa wyłączona z fakturowania — pokazujemy powód zamiast przycisku,
                // żeby nie wyglądało to na brakującą funkcję. Liczymy PRZED łańcuchem
                // warunków, bo używa go też przycisk demo poniżej.
                $_noinv = false;
                if (!empty($b['course_id'])) {
                    try {
                        $_noinv = !empty(db_one("SELECT COALESCE(no_invoice,0) AS n FROM k30_ti_courses WHERE id=?",
                                                [(int)$b['course_id']])['n']);
                    } catch (\Throwable $e) {}
                }
              ?>
              <?php if ($_bf): ?>
                <i class="bi bi-receipt text-success me-1"></i>
                Betterfly: <?= h($_bf['number'] ?: 'bufor') ?>
                <span class="text-muted">(<?= (int)$_bf['doc_status'] === 1 ? 'zatwierdzona' : 'bufor' ?><?= (int)($_bf['edok_doc_id'] ?? 0) > 0 ? ' · w obiegu EODoK' : '' ?>)</span>
                <?php if ((int)($_bf['edok_doc_id'] ?? 0) > 0): ?>
                  <a href="<?= APP_URL ?>/edok/view.php?id=<?= (int)$_bf['edok_doc_id'] ?>" title="Dokument w obiegu EODoK"><i class="bi bi-box-arrow-up-right"></i></a>
                <?php endif; ?>
              <?php elseif ($_fv): ?>
                <i class="bi bi-receipt text-success me-1"></i>
                <a href="<?= APP_URL ?>/crm/invoices/view.php?id=<?= (int)$_fv['id'] ?>">
                  <?= h($_fv['number'] ?: 'szkic faktury') ?></a>
                <span class="text-muted">(<?= h(INVOICE_STATUSES[$_fv['status']]['label'] ?? $_fv['status']) ?>)</span>
              <?php elseif ($_noinv): ?>
                <span class="text-body-secondary" title="Grupa oznaczona jako niefakturowana (ustawienie grupy)">
                  <i class="bi bi-slash-circle me-1"></i>grupa nie fakturowana
                </span>
              <?php elseif (($b['doc_mode'] ?? '') === 'statement'): ?>
                <span class="badge text-bg-light border" title="Wystawione jako samo zestawienie — faktura VAT nie jest wymagana">
                  <i class="bi bi-file-earmark-text me-1"></i>tylko zestawienie, bez FVAT</span>
                <form method="post" class="d-inline ms-1">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op" value="set_doc_mode">
                  <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0" style="font-size:.72rem">przełącz na FVAT</button>
                </form>
              <?php elseif ((float)$b['amount'] > 0): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op" value="make_invoice">
                  <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0" style="font-size:.72rem">
                    <i class="bi bi-receipt me-1"></i>Wystaw fakturę<?= $bf_backend ? ' (Betterfly)' : '' ?>
                  </button>
                </form>
                <a href="faktura_podglad.php?billing_id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener"
                   class="btn btn-link btn-sm p-0 ms-2" style="font-size:.72rem"
                   title="Podgląd PDF faktury tak, jak zobaczy ją kursant/płatnik — bez tworzenia dokumentu i numeru">
                  <i class="bi bi-eye me-1"></i>Podgląd FV</a>
                <form method="post" class="d-inline ms-2">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op" value="set_doc_mode">
                  <input type="hidden" name="doc_mode" value="statement">
                  <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-body-secondary" style="font-size:.72rem"
                          title="Bez faktury VAT — samo zestawienie godzin i należności">tylko zestawienie</button>
                </form>
              <?php endif; ?>
              <?php if (empty($_fv) && empty($_bf) && empty($b['invoice_no']) && empty($b['invoice_path'])): ?>
                <form method="post" class="d-inline ms-2"
                      onsubmit="var r = prompt('Powód wycofania rozliczenia (widoczny w historii):'); if (!r || !r.trim()) return false; this.reason.value = r.trim(); return true;">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op" value="withdraw_billing">
                  <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-danger" style="font-size:.72rem"
                          title="Wycofaj rozliczenie — przypisane wpłaty wrócą jako nadpłata; można przywrócić">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Wycofaj</button>
                </form>
              <?php endif; ?>
              <?php
                // Demo dla tego uczestnika — obok, niezależnie od faktury prawdziwej.
                $_fvd = null;
                if (false && (float)$b['amount'] > 0) {
                    try {
                        $_fvd = db_one("SELECT id, number FROM invoices
                                         WHERE source='ti_billing' AND source_id=? AND is_test=1 AND deleted_at IS NULL",
                                       [(int)$b['id']]);
                    } catch (\Throwable $e) {}
                }
              ?>
              <?php if ($can_delete): ?>
                <form method="post" class="d-inline ms-2"
                      onsubmit="return confirm('Przeliczyć rozliczenie ponownie z aktualnych lekcji?\n\nKwota i godziny mogą się zmienić. Powiadomienia NIE zostaną wysłane ponownie.')">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op" value="reissue_billing">
                  <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0" style="font-size:.72rem"
                          title="Przelicz godziny i kwotę z aktualnych lekcji. Zablokowane, gdy wystawiono fakturę.">
                    <i class="bi bi-arrow-repeat me-1"></i>Wystaw ponownie
                  </button>
                </form>
              <?php endif; ?>
              <?php if (false): /* admin-only */ ?>
                <form method="post" class="d-inline ms-2"
                      onsubmit="return confirm('TRWALE usunąć to rozliczenie?\n\nOdejdą też alokacje wpłat i faktura demo, a saldo kursanta zostanie przeliczone. Operacja jest nieodwracalna.')">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op" value="purge_billing">
                  <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-danger" style="font-size:.72rem"
                          title="Trwale usuń rozliczenie (admin). Zablokowane, gdy wystawiono z niego fakturę.">
                    <i class="bi bi-trash me-1"></i>Usuń rozliczenie
                  </button>
                </form>
              <?php endif; ?>
              <?php if (false && (float)$b['amount'] > 0 && !$_noinv): ?>
                <form method="post" class="d-inline ms-2">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op" value="demo_invoice">
                  <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-danger" style="font-size:.72rem"
                          title="Wygeneruj FVAT demo dla tego uczestnika (numer TEST/…, nie idzie do KSeF ani do nabywcy)">
                    <i class="bi bi-file-earmark-pdf me-1"></i><?= $_fvd ? 'Demo: ' . h((string)$_fvd['number']) : 'FVAT demo' ?>
                  </button>
                </form>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($b['notes']): ?><div class="text-muted small"><?= h($b['notes']) ?></div><?php endif; ?>
          </td>
          <td><?= number_format((float)$b['hours_billed'],2,',','') ?> h<br>
            <span class="text-muted small"><?= number_format($base,2,',','') ?> zł</span>
          </td>
          <td>
            <?php if ($adj != 0): ?>
              <span class="fw-semibold <?= $adj > 0 ? 'text-danger' : 'text-success' ?>">
                <?= ($adj > 0 ? '+' : '−') . number_format(abs($adj),2,',','') ?> zł
              </span>
              <div class="text-muted small"><?= $adj > 0 ? 'opłata dod.' : 'rabat' ?><?= $b['adjustment_note'] ? ': '.h($b['adjustment_note']) : '' ?></div>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <?php $paid = (float)($b['paid_amount'] ?? 0); $rem = round($tot - $paid, 2); ?>
          <td class="fw-bold">
            <?= number_format($tot,2,',','') ?> zł
            <?php if ($paid > 0.005 && $rem > 0.005): ?>
            <div class="small text-danger fw-normal">wpłacono <?= number_format($paid,2,',',' ') ?> · brakuje <?= number_format($rem,2,',',' ') ?> zł</div>
            <?php elseif ($paid > 0.005): ?>
            <div class="small text-success fw-normal">pokryte</div>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($b['due_date'])):
              $overdue = $b['status'] !== 'paid' && $b['due_date'] < date('Y-m-d'); ?>
              <span class="<?= $overdue ? 'text-danger fw-semibold' : 'text-body-secondary' ?>" <?= $overdue ? 'title="Po terminie"' : '' ?>>
                <?= date('d.m.Y', strtotime($b['due_date'])) ?><?php if ($overdue): ?> <i class="bi bi-exclamation-triangle-fill"></i><?php endif; ?>
              </span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge" style="background:<?= h($bs['bg']) ?>;color:<?= h($bs['color']) ?>;border:1px solid <?= h($bs['color']) ?>44;font-size:.74rem">
              <?= h($bs['label']) ?>
            </span>
            <?php if ($b['status'] !== 'paid' && $rem > 0.005 && $paid > 0.005): ?>
            <span class="badge bg-danger ms-1" title="Częściowo opłacone">niedopłata</span>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a href="../student_billing.php?client_id=<?= (int)$b['client_id'] ?>"
               class="btn btn-xs btn-sm btn-outline-primary py-0 px-2"
               title="Zestawienie płatności kursanta">
              <i class="bi bi-person-lines-fill"></i>
            </a>
            <a href="hours_pdf.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener"
               class="btn btn-xs btn-sm btn-outline-primary py-0 px-2"
               title="Rozpiska godzin dla beneficjenta (PDF)">
              <i class="bi bi-clock-history"></i>
            </a>
            <?php if ($can_write): ?>
            <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                    title="Edytuj rozliczenie (korekta, termin, płatnik, faktura)"
                    data-bs-toggle="modal" data-bs-target="#editBill<?= (int)$b['id'] ?>">
              <i class="bi bi-pencil-square me-1"></i>Edytuj
            </button>
            <?php endif; ?>
            <?php if ($b['status'] === 'issued' && $can_write):
              $sp = $stripe_pay[(int)$b['id']] ?? null; ?>
            <?php if ($stripe_on && $sp && $sp['status'] === 'pending' && !empty($sp['checkout_url'])): ?>
            <a href="<?= h($sp['checkout_url']) ?>" target="_blank" rel="noopener"
               class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Otwórz link do zapłaty Stripe">
              <i class="bi bi-link-45deg me-1"></i>Link
            </a>
            <?php elseif ($stripe_on): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="stripe_link">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Wygeneruj link do zapłaty Stripe">
                <i class="bi bi-credit-card me-1"></i>Stripe
              </button>
            </form>
            <?php endif; ?>
            <?php // ── PayU ──
              $pu = $payu_pay[(int)$b['id']] ?? null; ?>
            <?php if ($payu_on && $pu && $pu['status'] === 'pending' && !empty($pu['redirect_uri'])): ?>
            <a href="<?= h($pu['redirect_uri']) ?>" target="_blank" rel="noopener"
               class="btn btn-xs btn-sm btn-outline-success py-0 px-2" title="Otwórz link do zapłaty PayU">
              <i class="bi bi-link-45deg me-1"></i>PayU
            </a>
            <?php elseif ($payu_on): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="payu_link">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-success py-0 px-2" title="Wygeneruj link do zapłaty PayU">
                <i class="bi bi-wallet2 me-1"></i>PayU
              </button>
            </form>
            <?php endif; ?>
            <?php // ── Przelewy24 ──
              $p4 = $p24_pay[(int)$b['id']] ?? null; ?>
            <?php if ($p24_on && $p4 && $p4['status'] === 'pending' && !empty($p4['redirect_uri'])): ?>
            <a href="<?= h($p4['redirect_uri']) ?>" target="_blank" rel="noopener"
               class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Otwórz link do zapłaty Przelewy24">
              <i class="bi bi-link-45deg me-1"></i>P24
            </a>
            <?php elseif ($p24_on): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="p24_link">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Wygeneruj link do zapłaty Przelewy24">
                <i class="bi bi-wallet2 me-1"></i>P24
              </button>
            </form>
            <?php endif; ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="set_paid">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-success py-0 px-2">
                <i class="bi bi-check-lg me-1"></i>Opłacone
              </button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Wysłać powiadomienie (SMS + e-mail) o tym rozliczeniu?')">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="notify">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                      title="<?= !empty($b['notified_at']) ? 'Powiadomiono '.h(substr($b['notified_at'],0,16)).' — wyślij ponownie' : 'Wyślij powiadomienie SMS + e-mail' ?>">
                <i class="bi bi-send<?= !empty($b['notified_at']) ? '-check' : '' ?>"></i>
              </button>
            </form>
            <?php endif; ?>
            <?php if ($can_delete): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć rozliczenie dla <?= h(addslashes($b['client_name'])) ?>?')">
              <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"         value="delete">
              <input type="hidden" name="billing_id"  value="<?= (int)$b['id'] ?>">
              <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Usuń rozliczenie">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
            <?php if ($can_write && in_array($b['status'], ['issued','draft'], true)): ?>
            <button type="button" class="btn btn-xs btn-sm btn-outline-warning py-0 px-2"
                    title="Złóż wniosek o przeniesienie płatności na następny miesiąc"
                    data-bs-toggle="modal" data-bs-target="#deferBill<?= (int)$b['id'] ?>">
              <i class="bi bi-calendar-arrow-right"></i>
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modale edycji rozliczeń (poza tabelą — czytelne, bez obcinania) -->
<?php if ($can_write): foreach ($billings as $b):
  $adj      = (float)($b['adjustment'] ?? 0);
  $tot      = (float)$b['amount'] + $adj;
  $payer_def = k30_ti_default_payer((int)$b['client_id']);
  $period   = ($months_pl[(int)$b['month']] ?? $b['month']) . ' ' . (int)$b['year'];
?>
<div class="modal fade" id="editBill<?= (int)$b['id'] ?>" tabindex="-1" aria-labelledby="editBillLbl<?= (int)$b['id'] ?>" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="editBillLbl<?= (int)$b['id'] ?>">
          <i class="bi bi-receipt text-primary me-2" aria-hidden="true"></i>Rozliczenie — <?= h($b['client_name']) ?>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <?php
          $cbal      = $balances[(int)$b['client_id']] ?? ti_client_balance((int)$b['client_id']);
          $cpayments = ti_payments_for_client((int)$b['client_id']);
          // Model kombinowany: rozbicie salda na grupy + grupy kursanta do wyboru przy wpłacie
          $cgroups   = ti_client_group_balances((int)$b['client_id']);
          $cenr      = db_all("SELECT e.course_id, c.name FROM k30_ti_enrollments e
                               JOIN k30_ti_courses c ON c.id=e.course_id
                               WHERE e.client_id=? AND e.status='active' ORDER BY c.name", [(int)$b['client_id']]);
          $cnames    = [];
          foreach ($cenr as $ce) $cnames[(int)$ce['course_id']] = (string)$ce['name'];
        ?>
        <p class="text-body-secondary small mb-3">
          Okres: <strong><?= h($period) ?></strong> · Do zapłaty: <strong><?= number_format($tot,2,',',' ') ?> zł</strong>
          <?php if ($cbal['credit'] > 0.005): ?> · <span class="text-success fw-semibold">nadpłata: <?= number_format($cbal['credit'],2,',',' ') ?> zł</span>
          <?php elseif ($cbal['debt'] > 0.005): ?> · <span class="text-danger fw-semibold">niedopłata: <?= number_format($cbal['debt'],2,',',' ') ?> zł</span>
          <?php else: ?> · <span class="text-success">saldo rozliczone</span><?php endif; ?>
        </p>

        <!-- Saldo per grupa (model kombinowany) -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-collection text-primary me-2" aria-hidden="true"></i>Rozliczenia per grupa</h3>
          <?php if ($cgroups['groups']): ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-2" style="font-size:.82rem">
              <caption class="visually-hidden">Należności, wpłaty i saldo kursanta w podziale na grupy</caption>
              <thead class="table-light">
                <tr><th scope="col">Grupa / przedmiot</th><th scope="col" class="text-end">Należności</th>
                    <th scope="col" class="text-end">Pokryte</th><th scope="col" class="text-end">Saldo grupy</th></tr>
              </thead>
              <tbody>
                <?php foreach ($cgroups['groups'] as $gcid => $g): ?>
                <tr<?= (int)$gcid === (int)$b['course_id'] ? ' class="table-primary"' : '' ?>>
                  <th scope="row" class="fw-normal">
                    <?= h($g['course_name']) ?>
                    <?php if ((int)$gcid === (int)$b['course_id']): ?>
                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle ms-1">to rozliczenie</span>
                    <?php endif; ?>
                  </th>
                  <td class="text-end"><?= number_format($g['charges'],2,',',' ') ?> zł</td>
                  <td class="text-end"><?= number_format($g['paid'],2,',',' ') ?> zł</td>
                  <td class="text-end fw-semibold">
                    <?php if ($g['debt'] > 0.005): ?>
                      <span class="text-danger">−<?= number_format($g['debt'],2,',',' ') ?> zł</span>
                    <?php elseif ($g['credit'] > 0.005): ?>
                      <span class="text-success">+<?= number_format($g['credit'],2,',',' ') ?> zł</span>
                    <?php else: ?>
                      <span class="text-body-secondary">0,00 zł</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p class="text-body-secondary small mb-2">Brak rozliczeń w podziale na grupy.</p>
          <?php endif; ?>
          <p class="form-text mb-0">
            Nadpłata <strong>przypisana do grupy</strong> pokrywa tylko kolejne zajęcia w tej grupie.
            <?php if ($cgroups['general_credit'] > 0.005): ?>
            Nadpłata ogólna (do wykorzystania w dowolnej grupie):
            <strong class="text-success"><?= number_format($cgroups['general_credit'],2,',',' ') ?> zł</strong>.
            <?php else: ?>
            Wpłaty bez wskazania grupy pokrywają należności od najstarszej (FIFO).
            <?php endif; ?>
          </p>
        </section>

        <!-- Wpłaty i saldo -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-cash-stack text-success me-2" aria-hidden="true"></i>Wpłaty i saldo</h3>
          <form method="post" class="row g-2 align-items-end mb-2">
            <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="add_payment">
            <input type="hidden" name="client_id"  value="<?= (int)$b['client_id'] ?>">
            <div class="col-sm-3">
              <label class="form-label small mb-0" for="payamt<?= (int)$b['id'] ?>">Kwota (zł)</label>
              <input type="text" name="amount" id="payamt<?= (int)$b['id'] ?>" class="form-control form-control-sm" placeholder="0,00" inputmode="decimal">
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-0" for="paydt<?= (int)$b['id'] ?>">Data</label>
              <input type="date" name="paid_at" id="paydt<?= (int)$b['id'] ?>" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-0" for="paymeth<?= (int)$b['id'] ?>">Metoda</label>
              <select name="method" id="paymeth<?= (int)$b['id'] ?>" class="form-select form-select-sm">
                <option value="transfer">Przelew</option>
                <option value="cash">Gotówka</option>
                <option value="other">Inna</option>
              </select>
            </div>
            <div class="col-sm-3 d-flex align-items-end">
              <button class="btn btn-sm btn-success w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj wpłatę</button>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-0" for="paygrp<?= (int)$b['id'] ?>">Zaksięguj na grupę</label>
              <select name="pay_course_id" id="paygrp<?= (int)$b['id'] ?>" class="form-select form-select-sm">
                <option value="0">— wpłata ogólna (pokrywa najstarsze należności) —</option>
                <?php foreach ($cenr as $ce): ?>
                <option value="<?= (int)$ce['course_id'] ?>" <?= (int)$ce['course_id'] === (int)$b['course_id'] ? 'selected' : '' ?>>
                  <?= h($ce['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6"><label class="form-label small mb-0" for="paynote<?= (int)$b['id'] ?>">Notatka (opcjonalnie)</label>
              <input type="text" name="note" id="paynote<?= (int)$b['id'] ?>" class="form-control form-control-sm" placeholder="np. przelew za marzec"></div>
            <div class="col-12"><span class="form-text">Wpłata wyższa niż należność utworzy nadpłatę (rodzic/opiekun dostanie e-mail). Wpłata zaksięgowana na grupę pokrywa wyłącznie należności tej grupy — nadwyżka zostaje jako nadpłata tej grupy.</span></div>
          </form>
          <?php if ($cpayments): ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
              <caption class="visually-hidden">Historia wpłat kursanta</caption>
              <thead class="table-light"><tr><th>Data</th><th>Kwota</th><th>Grupa</th><th>Metoda</th><th>Notatka</th><th class="text-end">Akcje</th></tr></thead>
              <tbody>
                <?php foreach ($cpayments as $pm):
                  $mlabel = ['transfer'=>'Przelew','cash'=>'Gotówka','stripe'=>'Stripe','payu'=>'PayU','p24'=>'Przelewy24','other'=>'Inna','internal'=>'Przeniesienie'][$pm['method']] ?? $pm['method']; ?>
                <tr>
                  <td class="text-nowrap"><?= h(substr($pm['paid_at'] ?: $pm['created_at'], 0, 10)) ?></td>
                  <td class="fw-semibold <?= (float)$pm['amount'] < 0 ? 'text-body-secondary' : 'text-success' ?>"><?= (float)$pm['amount'] < 0 ? '−' : '+' ?><?= number_format(abs((float)$pm['amount']),2,',',' ') ?> zł</td>
                  <td><?php $pmc = (int)($pm['course_id'] ?? 0); ?>
                    <?php if ($pmc > 0): ?>
                      <span class="badge bg-light text-secondary border"><?= h($cnames[$pmc] ?? ('Grupa #'.$pmc)) ?></span>
                    <?php else: ?>
                      <span class="text-body-secondary">ogólna</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($mlabel) ?></td>
                  <td class="text-body-secondary"><?= h(mb_substr($pm['note'] ?? '', 0, 60)) ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę wpłatę? Saldo zostanie przeliczone.')">
                      <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="_op"          value="del_payment">
                      <input type="hidden" name="payment_id"   value="<?= (int)$pm['id'] ?>">
                      <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1" title="Usuń wpłatę"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p class="text-body-secondary small mb-0">Brak zarejestrowanych wpłat.</p>
          <?php endif; ?>
        </section>

        <!-- Korekta -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-percent text-secondary me-2" aria-hidden="true"></i>Korekta (opłata dodatkowa / rabat)</h3>
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="set_adjustment">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <div class="col-sm-4">
              <label class="form-label small mb-0" for="adjkind<?= (int)$b['id'] ?>">Rodzaj</label>
              <select name="adj_kind" id="adjkind<?= (int)$b['id'] ?>" class="form-select form-select-sm">
                <option value="fee"      <?= $adj > 0 ? 'selected' : '' ?>>Opłata dodatkowa (+)</option>
                <option value="discount" <?= $adj < 0 ? 'selected' : '' ?>>Rabat (−)</option>
              </select>
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-0" for="adjval<?= (int)$b['id'] ?>">Kwota (zł)</label>
              <input type="text" name="adj_value" id="adjval<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= $adj != 0 ? number_format(abs($adj),2,',','') : '' ?>" placeholder="0,00">
            </div>
            <div class="col-sm-5">
              <label class="form-label small mb-0" for="adjnote<?= (int)$b['id'] ?>">Opis (opcjonalnie)</label>
              <input type="text" name="adj_note" id="adjnote<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($b['adjustment_note'] ?? '') ?>" placeholder="np. materiały, rabat">
            </div>
            <div class="col-12 d-flex align-items-center gap-2">
              <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz korektę</button>
              <span class="form-text mb-0">Wpisz 0, aby usunąć korektę.</span>
            </div>
          </form>
        </section>

        <!-- Termin płatności -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-calendar-event text-secondary me-2" aria-hidden="true"></i>Termin płatności</h3>
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="set_due">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <div class="col-sm-6">
              <label class="form-label small mb-0" for="due<?= (int)$b['id'] ?>">Termin (indywidualny)</label>
              <input type="date" name="due_date" id="due<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($b['due_date'] ?? '') ?>">
            </div>
            <div class="col-12 d-flex align-items-center gap-2">
              <button class="btn btn-sm btn-outline-primary"><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Zapisz termin</button>
              <span class="form-text mb-0">Puste = bez terminu.</span>
            </div>
          </form>
        </section>

        <!-- Płatnik -->
        <section class="border rounded p-3 mb-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-person-badge text-secondary me-2" aria-hidden="true"></i>Płatnik</h3>
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="set_payer">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <div class="col-sm-5">
              <label class="form-label small mb-0" for="payer<?= (int)$b['id'] ?>">Płatnik</label>
              <select name="payer_type" id="payer<?= (int)$b['id'] ?>" class="form-select form-select-sm">
                <option value="" <?= empty($b['payer_type']) ? 'selected' : '' ?>>— domyślnie (<?= h(K30_TI_PAYERS[$payer_def]) ?>) —</option>
                <?php foreach (K30_TI_PAYERS as $pk => $pl): ?>
                <option value="<?= h($pk) ?>" <?= ($b['payer_type'] ?? '') === $pk ? 'selected' : '' ?>><?= h($pl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-7">
              <label class="form-label small mb-0" for="payername<?= (int)$b['id'] ?>">Nazwa płatnika (opcjonalnie)</label>
              <input type="text" name="payer_name" id="payername<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($b['payer_name'] ?? '') ?>" placeholder="np. dane firmy / opiekuna do faktury">
            </div>
            <div class="col-12">
              <button class="btn btn-sm btn-outline-primary"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Zapisz płatnika</button>
            </div>
          </form>
        </section>

        <!-- Faktura (FVAT) -->
        <section class="border rounded p-3">
          <h3 class="h6 fw-semibold mb-2"><i class="bi bi-file-earmark-pdf text-secondary me-2" aria-hidden="true"></i>Faktura (FVAT)</h3>
          <div class="alert alert-info py-2 px-3 small d-flex align-items-start gap-2" role="note">
            <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
            <div>
              Faktury wystawiamy w systemie <strong><?= h(k30_ti_invoice_system()) ?></strong> — panel ich nie generuje.
              Tutaj rejestrujemy numer i <strong>obowiązkowy skan</strong> wystawionej faktury.
              Pozycje do przepisania na fakturę wydrukujesz przyciskiem „Podsumowanie do FVAT".
            </div>
          </div>
          <p class="mb-2">
            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
               href="billing_fv_summary.php?id=<?= (int)$b['id'] ?>">
              <i class="bi bi-printer me-1" aria-hidden="true"></i>Podsumowanie do FVAT (PDF)
            </a>
            <?php if ((int)$b['course_id'] > 0): ?>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
               href="billing_fv_summary.php?course_id=<?= (int)$b['course_id'] ?>&amp;month=<?= (int)$b['month'] ?>&amp;year=<?= (int)$b['year'] ?>">
              <i class="bi bi-printer me-1" aria-hidden="true"></i>Cała grupa (PDF)
            </a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
               href="hours_pdf.php?id=<?= (int)$b['id'] ?>"
               title="Szczegółowa rozpiska zajęć i godzin dla beneficjenta">
              <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Rozpiska godzin (PDF)
            </a>
          </p>
          <?php if (!empty($b['invoice_path'])): ?>
          <p class="small mb-2">
            <i class="bi bi-file-earmark-pdf text-danger me-1" aria-hidden="true"></i>Skan załączony:
            <a href="billing_invoice.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener"><?= h($b['invoice_name'] ?: 'faktura.pdf') ?></a>
            <?= !empty($b['invoice_at']) ? '<span class="text-body-secondary">('.h(substr($b['invoice_at'],0,10)).')</span>' : '' ?>
            <?php if (($b['invoice_kind'] ?? '') === 'oneoff'): ?>
            <span class="badge bg-warning text-dark ms-1">faktura jednorazowa</span>
            <?php endif; ?>
            <?php if (!empty($b['invoice_no'])): ?>
            <span class="text-body-secondary">· nr <?= h($b['invoice_no']) ?></span>
            <?php endif; ?>
          </p>
          <?php else: ?>
          <p class="small text-danger mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Brak skanu faktury — dane faktury zapiszesz dopiero po wgraniu pliku PDF.</p>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="upload_invoice">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <div class="col-sm-4">
              <label class="form-label small mb-0" for="invkind<?= (int)$b['id'] ?>">Rodzaj</label>
              <select name="invoice_kind" id="invkind<?= (int)$b['id'] ?>" class="form-select form-select-sm">
                <option value=""       <?= ($b['invoice_kind'] ?? '') !== 'oneoff' ? 'selected' : '' ?>>Faktura do rozliczenia (cykliczna)</option>
                <option value="oneoff" <?= ($b['invoice_kind'] ?? '') === 'oneoff' ? 'selected' : '' ?>>Faktura jednorazowa</option>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label small mb-0" for="invno<?= (int)$b['id'] ?>">Numer faktury</label>
              <input type="text" name="invoice_no" id="invno<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($b['invoice_no'] ?? '') ?>" placeholder="np. FV/123/2026">
            </div>
            <div class="col-sm-4">
              <label class="form-label small mb-0" for="invdate<?= (int)$b['id'] ?>">Data wystawienia</label>
              <input type="date" name="invoice_issued_on" id="invdate<?= (int)$b['id'] ?>" class="form-control form-control-sm"
                     value="<?= h($b['invoice_issued_on'] ?? '') ?>">
            </div>
            <div class="col-sm-8">
              <label class="form-label small mb-0" for="inv<?= (int)$b['id'] ?>">
                Skan faktury (PDF)<?= empty($b['invoice_path']) ? ' — wymagany' : '' ?>
              </label>
              <input type="file" name="invoice" id="inv<?= (int)$b['id'] ?>" accept="application/pdf"
                     class="form-control form-control-sm" <?= empty($b['invoice_path']) ? 'required aria-required="true"' : '' ?>>
              <span class="form-text">Bez skanu danych faktury nie da się zapisać.</span>
            </div>
            <div class="col-12 d-flex align-items-center gap-2">
              <button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i><?= !empty($b['invoice_path']) ? 'Zapisz / wymień skan' : 'Zapisz fakturę ze skanem' ?></button>
            </div>
          </form>
          <?php if (!empty($b['invoice_path'])): ?>
          <form method="post" class="mt-2" onsubmit="return confirm('Usunąć fakturę (skan i dane) z tego rozliczenia?')">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"        value="delete_invoice">
            <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń fakturę</button>
          </form>
          <?php endif; ?>
        </section>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
<?php endif; ?>

<!-- Modale: wniosek o przeniesienie płatności -->
<?php if ($can_write):
  $months_pl_def = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                    7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
  foreach ($billings as $b):
    if (!in_array($b['status'], ['issued','draft'], true)) continue;
    $def_to_m = (int)$b['month'] === 12 ? 1 : (int)$b['month'] + 1;
    $def_to_y = (int)$b['month'] === 12 ? (int)$b['year'] + 1 : (int)$b['year'];
    $def_amount = round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2);
?>
<div class="modal fade" id="deferBill<?= (int)$b['id'] ?>" tabindex="-1" aria-labelledby="deferBillLbl<?= (int)$b['id'] ?>" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"        value="request_deferral">
      <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
      <input type="hidden" name="month"      value="<?= $month ?>">
      <input type="hidden" name="year"       value="<?= $year ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="deferBillLbl<?= (int)$b['id'] ?>">
          <i class="bi bi-calendar-arrow-right text-warning me-2"></i>Przeniesienie płatności — <?= h($b['client_name']) ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="small text-body-secondary mb-3">
          Kwota <strong><?= number_format($def_amount,2,',',' ') ?> zł</strong>
          za <strong><?= ($months_pl_def[(int)$b['month']] ?? $b['month']) . ' ' . (int)$b['year'] ?></strong>
          zostanie przeniesiona na <strong><?= ($months_pl_def[$def_to_m] ?? '?') . ' ' . $def_to_y ?></strong>
          i pojawi się jako pozycja na saldzie kursanta z adnotacją okresu.
        </p>
        <div class="alert alert-warning py-2 small d-flex gap-2 align-items-start">
          <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
          <span>Wniosek wymaga akceptacji administratora. Do czasu zatwierdzenia oryginalne rozliczenie pozostaje aktywne.</span>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="defReason<?= (int)$b['id'] ?>">
            Powód przeniesienia <span class="text-danger">*</span>
          </label>
          <textarea class="form-control" id="defReason<?= (int)$b['id'] ?>" name="deferral_reason"
                    rows="3" required maxlength="1000"
                    placeholder="np. trudna sytuacja finansowa, oczekiwanie na przelew zagranicę, uzgodnione z kursantem…"></textarea>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="defConfirm<?= (int)$b['id'] ?>" name="deferral_confirm" value="1" required>
          <label class="form-check-label fw-semibold" for="defConfirm<?= (int)$b['id'] ?>">
            Potwierdzam, że płatność powinna zostać przeniesiona na następny miesiąc
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-warning">
          <i class="bi bi-calendar-arrow-right me-1"></i>Złóż wniosek o przeniesienie
        </button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; endif; ?>

<!-- Podgląd — klienci z lekcjami bez rozliczenia -->
<?php if ($preview): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-hourglass-split text-warning me-2"></i>Do wystawienia
    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-2"><?= count($preview) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Klient</th><th>Godz. obecności</th><th>Szacowana kwota</th><th class="text-end">Akcja</th></tr>
      </thead>
      <tbody>
        <?php foreach ($preview as $cid => $p): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($p['client_name']) ?></div>
            <?php if (!empty($p['courses']) && count($p['courses']) > 1): ?>
            <div class="mt-1">
              <?php foreach ($p['courses'] as $pc): if ($pc['amount'] <= 0 && $pc['hours_billed'] <= 0) continue; ?>
              <span class="badge bg-light text-secondary border me-1" style="font-size:.72rem;font-weight:500">
                <?= h($pc['course_name']) ?>:
                <?php if ($pc['model'] !== 2): ?>
                  <?= number_format($pc['amount'],2,',','') ?> zł
                <?php else: ?>
                  <?= number_format($pc['hours_billed'],2,',','') ?> h · <?= number_format($pc['amount'],2,',','') ?> zł
                <?php endif; ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php elseif (!empty($p['courses'])): ?>
            <div class="text-muted" style="font-size:.78rem"><?= h($p['courses'][0]['course_name'] ?? '') ?></div>
            <?php endif; ?>
          </td>
          <td><?= number_format($p['hours_billed'],2,',','') ?> h</td>
          <td class="fw-semibold"><?= number_format($p['amount'],2,',','') ?> zł</td>
          <td class="text-end">
            <?php if ($can_write): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"        value="issue">
              <input type="hidden" name="client_id"  value="<?= $cid ?>">
              <button type="submit" name="doc_mode" value="" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2">
                <i class="bi bi-receipt me-1"></i>Wystaw
              </button>
              <button type="submit" name="doc_mode" value="statement" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                      title="Tylko zestawienie, bez FVAT">zestawienie</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif (!$billings): ?>
<div class="alert alert-info">Brak lekcji odbyłych w <?= $months_pl[$month] ?> <?= $year ?>.</div>
<?php endif; ?>
<?php if (!empty($withdrawn)): ?>
<details class="card border-0 shadow-sm mt-3">
  <summary class="card-header d-flex align-items-center gap-2" style="cursor:pointer">
    <i class="bi bi-arrow-counterclockwise text-danger" aria-hidden="true"></i>Wycofane rozliczenia
    <span class="badge text-bg-secondary"><?= count($withdrawn) ?></span>
  </summary>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Kursant</th><th class="text-end">Kwota</th><th>Wycofał(a)</th><th>Kiedy</th><th>Powód</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($withdrawn as $w): ?>
        <tr class="text-body-secondary">
          <td><?= h($w['client_name']) ?></td>
          <td class="text-end"><?= number_format((float)$w['amount'] + (float)($w['adjustment'] ?? 0), 2, ',', ' ') ?> zł</td>
          <td><?= h($w['cancelled_by_name'] ?: '—') ?></td>
          <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$w['cancelled_at']))) ?></td>
          <td class="small"><?= h($w['cancel_reason']) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline" onsubmit="return confirm('Przywrócić to rozliczenie? Wpłaty zostaną ponownie przypisane.')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_op" value="restore_billing">
              <input type="hidden" name="billing_id" value="<?= (int)$w['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary py-0">Przywróć</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>
<?php if (false): ?>
<?php endif; ?>

</main>
<?php $PRINT_TITLE = 'Rozliczenia kursantów TI'; include __DIR__ . '/_print_page.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
