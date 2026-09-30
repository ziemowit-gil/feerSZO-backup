<?php
/**
 * platnosci/admin.php — obsługa portalu płatności przez biuro (logowanie SZO, rola admin).
 *
 * Ustawienia (prefiks rachunków wirtualnych, symulacja P24), dostęp uczestników
 * (indywidualny NRB, link z tokenem, blokada), pozycje do zapłaty (ręczne, import
 * nieopłaconych rozliczeń TI, anulowanie), potwierdzanie / odrzucanie zgłoszonych
 * przelewów na NRB, lista transakcji. Wszystkie zapisy — transakcje PDO + audit_logs.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/modules/payment_portal/logic/paymentPortal.php';

require_role('admin');
pp_migrate();
$me  = current_user();
$by  = (string)($me['name'] ?? $me['email'] ?? 'admin');
$uid = (int)($me['id'] ?? 0) ?: null;

// Eksport numerów rachunków wirtualnych do pliku TXT (TI = kursanci z numerem, CRM = aktywne kontakty)
if (($_GET['export'] ?? '') === 'txt') {
    $g = (string)($_GET['g'] ?? 'all');
    $bank = pp_vnrb_bank(); $rrrr = pp_vnrb_rrrr();
    // TI w trybie „cyfry": same cyfry, jedna liczba w linii, bez nagłówka — n12 = część NNNN (nr kursanta), nrb = pełny 26-cyfrowy NRB
    $digits = in_array($g, ['ti_n12', 'ti_nrb'], true);
    if ($digits) {
        $lines = [];
        foreach (db_all("SELECT a.student_no FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id=a.client_id ORDER BY c.name") as $r) {
            if (!preg_match('/^\d{12}$/', (string)$r['student_no'])) continue;
            $lines[] = $g === 'ti_n12' ? $r['student_no'] : (string)pp_vnrb_build($bank, $rrrr, $r['student_no']);
        }
        audit_log('payments.vnrb_export', ['group' => $g, 'rows' => count($lines), 'by' => $by], $uid);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $g . '_' . date('Ymd') . '.txt"');
        echo implode("\r\n", $lines), $lines ? "\r\n" : ''; exit;
    }
    $lines = ["NRB\tgrupa\tid\tnazwa"];
    if ($g === 'ti' || $g === 'all') {
        foreach (db_all("SELECT a.client_id, a.student_no, c.name FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id=a.client_id ORDER BY c.name") as $r) {
            $n = preg_match('/^\d{12}$/', (string)$r['student_no']) ? pp_vnrb_build($bank, $rrrr, $r['student_no']) : null;
            if ($n) $lines[] = $n . "\tTI\t" . $r['client_id'] . "\t" . str_replace(["\t", "\r", "\n"], ' ', (string)$r['name']);
        }
    }
    if ($g === 'crm' || $g === 'all') {
        foreach (crm_all("SELECT id, imie_nazwisko, nip FROM crm_contacts WHERE crm_active=1 ORDER BY imie_nazwisko") as $r) {
            $n = pp_nrb_normalize((string)pp_vnrb_for_crm((int)$r['id'], $r['nip']));
            if ($n !== '') $lines[] = $n . "\tCRM\t" . $r['id'] . "\t" . str_replace(["\t", "\r", "\n"], ' ', (string)$r['imie_nazwisko']);
        }
    }
    audit_log('payments.vnrb_export', ['group' => $g, 'rows' => count($lines) - 1, 'by' => $by], $uid);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="rachunki_wirtualne_' . preg_replace('/\W/', '', $g) . '_' . date('Ymd') . '.txt"');
    echo implode("\r\n", $lines), "\r\n"; exit;
}

// Pobranie listy z generatora (TXT, jeden NRB w linii)
if (($_GET['export'] ?? '') === 'gen') {
    $l = pp_gen_list((string)($_GET['s'] ?? ''), (int)($_GET['n'] ?? 0));
    if (is_string($l)) { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); echo $l; exit; }
    audit_log('payments.vnrb_gen_download', ['start' => preg_replace('/\D/', '', (string)$_GET['s']), 'count' => (int)$_GET['n'], 'by' => $by], $uid);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="nrb_' . preg_replace('/\D/', '', (string)$_GET['s']) . '_+' . (int)$_GET['n'] . '.txt"');
    echo implode("\r\n", $l), "\r\n"; exit;
}

if (($_GET['export'] ?? '') === 'csv') pp_vnrb_export_csv($by);
if (($_GET['pdf'] ?? '') === 'notice') pp_vnrb_notice_send(in_array($_GET['scope'] ?? '', ['client', 'last', 'unnotified', 'all'], true) ? $_GET['scope'] : 'client', (int)($_GET['client'] ?? 0), $by);
if (($_GET['report'] ?? '') === 'status') pp_vnrb_status_print((string)($_GET['cat'] ?? ''), $by);
if (($_GET['report'] ?? '') === 'print') pp_vnrb_report_print(($_GET['scope'] ?? 'all') === 'last' ? 'last' : 'all', $by);

$genres = null;
$link_once = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $pid = (int)($_POST['participant_id'] ?? 0);
    $err = null; $ok = null;
    switch ($op) {
        case 'settings':
            $bank = preg_replace('/\D/', '', (string)($_POST['pp_nrb_bank'] ?? ''));
            $pref = preg_replace('/\D/', '', (string)($_POST['pp_nrb_prefix'] ?? ''));
            if ($bank !== '' && strlen($bank) !== 8) { $err = 'Numer rozliczeniowy banku ma 8 cyfr.'; break; }
            if ($pref !== '' && strlen($pref) !== 4) { $err = 'Identyfikator Klienta (RRRR) z dokumentu aktywacji ma 4 cyfry.'; break; }
            $gen = pp_nrb_normalize((string)($_POST['pp_general_nrb'] ?? ''));
            if ($gen !== '' && !pp_nrb_valid($gen)) { $err = 'Rachunek ogólny: nieprawidłowy numer (26 cyfr, suma kontrolna).'; break; }
            org_setting_set('pp_nrb_bank', $bank); org_setting_set('pp_nrb_prefix', $pref); org_setting_set('pp_general_nrb', $gen);
            org_setting_set('pp_p24_simulation', !empty($_POST['pp_p24_simulation']) ? '1' : '0');
            audit_log('payments.settings', ['bank' => $bank, 'prefix' => $pref, 'general_nrb' => $gen, 'simulation' => !empty($_POST['pp_p24_simulation']), 'by' => $by], $uid);
            $ok = 'Ustawienia zapisane.'; break;
        case 'gen_series':
            $gk = (string)($_POST['gen_grp'] ?? '');
            if (!isset(pp_series()[$gk])) { $err = 'Wybierz serię.'; break; }
            $gs = preg_replace('/\D/', '', (string)($_POST['gen_start'] ?? '')); $gn = (int)($_POST['gen_count'] ?? 0);
            $l = pp_gen_list($gs, $gn);
            if (is_string($l)) { $err = $l; break; }
            org_setting_set('pp_gen_last_' . $gk, $gs . '|' . $gn);
            $genres = ['grp' => $gk, 'start' => $gs, 'count' => $gn, 'list' => $l];
            $ok = 'Wygenerowano ' . count($l) . ' numerów (zapamiętano wartości dla serii).'; break;
        case 'series':
            $new = []; 
            foreach (array_keys(pp_series()) as $k) {
                $c = preg_replace('/\D/', '', (string)($_POST['series_' . $k] ?? ''));
                if (strlen($c) !== 8) { $err = 'Kod serii ma 8 cyfr (seria: ' . pp_series()[$k]['label'] . ').'; break 2; }
                $new[$k] = $c;
            }
            if (count(array_unique($new)) !== count($new)) { $err = 'Kody serii muszą być różne.'; break; }
            foreach ($new as $k => $c) org_setting_set('pp_series_' . $k, $c);
            audit_log('payments.series_codes', $new + ['by' => $by], $uid);
            $ok = 'Kody serii zapisane. Numery już w puli zostają bez zmian — nowy kod dotyczy numerów startowych dla banku i rozpoznawania przy imporcie.'; break;
        case 'pool_import':
            $grp = in_array($_POST['pool_grp'] ?? '', ['auto', 'ti', 'inni', 'spoza_ti', 'reczny'], true) ? $_POST['pool_grp'] : 'auto';
            $txt = (string)($_POST['pool_text'] ?? '');
            if (trim($txt) === '') { $err = 'Wklej listę numerów w pole tekstowe.'; break; }
            $x = pp_vnrb_pool_import($txt, $grp, $by, $uid);
            $ok = "Import: dodano {$x['added']}, duplikaty {$x['dup']}, błędne " . count($x['bad'])
                . ($x['bad'] ? ' (np. ' . implode('; ', array_slice($x['bad'], 0, 5)) . ')' : '') . '.';
            if (!empty($_POST['pool_assign'])) {
                $y = pp_vnrb_pool_assign_ti($by, $uid, $x['added'] ? $x['batch'] : null);
                $ok .= " Przypisano kursantom TI: {$y['assigned']}; wolnych w puli TI: {$y['left']}" . ($y['nopool'] ? "; bez numeru z braku puli: {$y['nopool']}" : '') . '.';
            }
            break;
        case 'pool_assign_last':
            $lb = (string)org_setting('pp_pool_last_batch');
            if ($lb === '') { $err = 'Brak ostatniego importu.'; break; }
            $x = pp_vnrb_pool_assign_ti($by, $uid, $lb);
            $ok = "Ostatni import: przypisano kursantom TI {$x['assigned']}" . ($x['nopool'] ? "; bez numeru z braku puli: {$x['nopool']}" : '') . '.'; break;
        case 'vnrb_release':
            $err = pp_vnrb_release((string)($_POST['kind'] ?? 'ti'), (int)($_POST['rid'] ?? 0), (string)($_POST['reason'] ?? ''), $by, $uid);
            $ok = 'Numer odpięty i zwrócony do puli.'; break;
        case 'vnrb_block':
            $blk = !empty($_POST['block']);
            $err = pp_vnrb_block((string)($_POST['nrb'] ?? ''), $blk, (string)($_POST['reason'] ?? ''), $by, $uid);
            $ok = $blk ? 'Numer zablokowany — wpłaty na niego nie będą księgowane automatycznie.' : 'Numer odblokowany.'; break;
        case 'notice_save':
            $nid = pp_notice_save((int)($_POST['notice_id'] ?? 0), (string)($_POST['scope'] ?? 'unnotified'), (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''), (string)($_POST['sms_text'] ?? ''), $by);
            if (is_string($nid)) { $err = $nid; break; }
            $ok = 'Szkic zapisany (#' . $nid . '). Sprawdź podgląd i zatwierdź treść, aby zaplanować wysyłkę.'; break;
        case 'notice_approve':
            $err = pp_notice_approve((int)($_POST['notice_id'] ?? 0), $by, $uid);
            $ok = 'Treść zatwierdzona. Wiadomości (e-mail i SMS) wyślą się automatycznie o 08:00 następnego dnia.'; break;
        case 'notice_reschedule':
            $err = pp_notice_reschedule((int)($_POST['notice_id'] ?? 0), (string)($_POST['send_at'] ?? ''), $by, $uid);
            $ok = 'Termin wysyłki zmieniony.'; break;
        case 'notice_exclude':
            $exc = !empty($_POST['exclude']);
            $err = pp_notice_exclude((int)($_POST['notice_id'] ?? 0), (int)($_POST['client_id'] ?? 0), $exc, $by, $uid);
            $ok = $exc ? 'Osoba wykluczona z tej wysyłki — wiadomość do niej nie wyjdzie.' : 'Osoba przywrócona do wysyłki.'; break;
        case 'notice_cancel':
            $err = pp_notice_cancel((int)($_POST['notice_id'] ?? 0), $by, $uid);
            $ok = 'Wysyłka anulowana.'; break;
        case 'pool_notify':
            $lb = (string)org_setting('pp_pool_last_batch');
            $x = pp_vnrb_pool_notify(!empty($_POST['only_last']) && $lb !== '' ? $lb : null, $by, $uid);
            $ok = "Wysłano powiadomienia o rachunkach: kursantów {$x['students']} (SMS {$x['sms']}, e-mail {$x['email']}); bez powiadomienia zostało: {$x['left']}."; break;
        case 'pool_assign_crm':
            $x = pp_vnrb_pool_assign_crm($by, $uid);
            $ok = "Serii „inni”: przypisano kontrahentom CRM {$x['assigned']}; wolnych w puli: {$x['left']}" . ($x['nopool'] ? "; bez numeru z braku puli: {$x['nopool']}" : '') . '.'; break;
        case 'pool_assign_other':
            $x = pp_vnrb_pool_assign_other($by, $uid);
            $ok = "Serii „spoza TI”: przypisano uczestnikom {$x['assigned']}; wolnych w puli: {$x['left']}" . ($x['nopool'] ? "; bez numeru z braku puli: {$x['nopool']}" : '') . '.'; break;
        case 'pool_assign_ti':
            $x = pp_vnrb_pool_assign_ti($by, $uid);
            $ok = "Przypisano kursantom TI: {$x['assigned']}; wolnych w puli: {$x['left']}" . ($x['nopool'] ? "; bez numeru z braku puli: {$x['nopool']}" : '') . '.'; break;
        case 'user':
            $u = pp_user_ensure($pid, $by, $uid);
            $err = is_string($u) ? $u : null; $ok = 'Dostęp uczestnika utworzony.'; break;
        case 'nrb':
            $raw = ($_POST['generate'] ?? '') === '1' ? (string)pp_nrb_generate($pid) : (string)($_POST['nrb'] ?? '');
            if (($_POST['generate'] ?? '') === '1' && $raw === '') { $err = 'Najpierw ustaw numer rozliczeniowy banku i prefiks rachunków wirtualnych.'; break; }
            $err = pp_set_nrb($pid, $raw, $by, $uid); $ok = 'Numer rachunku zapisany.'; break;
        case 'link':
            if (!pp_user($pid)) { $err = 'Najpierw utwórz dostęp.'; break; }
            $_SESSION['pp_link_once'] = ['pid' => $pid, 'url' => pp_issue_link($pid, $by, $uid)];
            $ok = 'Nowy link dostępu wygenerowany (poprzedni przestał działać).'; break;
        case 'active':
            db()->prepare("UPDATE payment_portal_users SET is_active=? WHERE participant_id=?")->execute([!empty($_POST['on']) ? 1 : 0, $pid]);
            audit_log('payments.portal_user_active', ['participant_id' => $pid, 'active' => !empty($_POST['on']), 'by' => $by], $uid);
            $ok = !empty($_POST['on']) ? 'Dostęp włączony.' : 'Dostęp zablokowany.'; break;
        case 'item':
            $amt = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['amount'] ?? ''));
            $r = pp_add_item($pid, (string)($_POST['title'] ?? ''), $amt, (string)($_POST['reference_type'] ?? 'manual'),
                             (int)($_POST['reference_id'] ?? 0) ?: null, trim((string)($_POST['due_date'] ?? '')) ?: null, $by, $uid);
            $err = is_string($r) ? $r : null; $ok = 'Pozycja dodana.'; break;
        case 'cancel_item':
            $err = pp_cancel_item((int)($_POST['item_id'] ?? 0), $by, $uid); $ok = 'Pozycja anulowana.'; break;
        case 'import_ti':
            $n = pp_import_ti($pid ?: null, $by, $uid); $ok = "Zaimportowano nieopłacone rozliczenia TI: {$n}."; break;
        case 'confirm':
            $date = trim((string)($_POST['bank_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $err = 'Podaj datę zaksięgowania z wyciągu.'; break; }
            $err = pp_settle((int)($_POST['tx_id'] ?? 0), ['gateway' => 'nrb', 'bank_date' => $date, 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300), 'confirmed_by' => $by], $by, $uid);
            $ok = 'Wpłata potwierdzona — pozycje opłacone.'; break;
        case 'bank_autopost':
            $x = pp_bank_autopost($by, $uid, false);
            $ok = "Zaksięgowano wpłat na numery wirtualne: {$x['posted']} na kwotę " . pp_fmt($x['amount']) . ($x['skipped'] ? ". Pominięto (bez dopasowania): {$x['skipped']}" : '') . '.'; break;
        case 'bank_auto':
            $r = pp_bank_auto_match($by, $uid);
            $ok = "Rozliczono z wyciągów EODoK: {$r['matched']}." . ($r['review'] ? " Do decyzji: {$r['review']}." : ''); break;
        case 'bank_assign':
            $err = pp_bank_assign((int)($_POST['bank_tx_id'] ?? 0), (int)($_POST['tx_id'] ?? 0), 'ręcznie', $by, $uid);
            $ok = 'Wpływ z wyciągu przypisany — płatność rozliczona.'; break;
        case 'reject':
            $reason = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300);
            if (mb_strlen($reason) < 5) { $err = 'Podaj powód odrzucenia.'; break; }
            $err = pp_fail((int)($_POST['tx_id'] ?? 0), $reason, $by, $uid); $ok = 'Płatność odrzucona — pozycje wróciły do zapłaty.'; break;
    }
    flash_set($err ? 'danger' : 'success', $err ?? $ok);
    header('Location: admin.php' . ($pid ? '?p=' . $pid : '')); exit;
}
$link_once = $_SESSION['pp_link_once'] ?? null; unset($_SESSION['pp_link_once']);
$flash = flash_get();

$q    = trim((string)($_GET['q'] ?? ''));
$pid  = (int)($_GET['p'] ?? 0);
$found = $q !== '' ? db_all("SELECT c.id, c.name, c.email, u.id AS pu FROM k30_clients c LEFT JOIN payment_portal_users u ON u.participant_id=c.id
                              WHERE c.name LIKE ? OR c.email LIKE ? ORDER BY c.name LIMIT 30", ['%' . $q . '%', '%' . $q . '%']) : [];
$sel  = $pid ? db_one("SELECT id, name, email FROM k30_clients WHERE id=?", [$pid]) : null;
$pu   = $sel ? pp_user($pid) : null;
if ($sel) pp_sync_ti_items($pid);
$sel_items = $sel ? db_all("SELECT * FROM payable_items WHERE participant_id=? ORDER BY status='pending' DESC, status='processing' DESC, id DESC LIMIT 100", [$pid]) : [];
$nrb_pending = db_all("SELECT t.*, c.name FROM portal_transactions t JOIN k30_clients c ON c.id=t.participant_id
                        WHERE t.status='pending' AND t.payment_method='individual_nrb' ORDER BY t.id");
$bank_c = pp_bank_candidates();
$txs = db_all("SELECT t.*, c.name FROM portal_transactions t JOIN k30_clients c ON c.id=t.participant_id ORDER BY t.id DESC LIMIT 50");
$stats = db_one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) p, COALESCE(SUM(CASE WHEN status='processing' THEN amount END),0) pr FROM payable_items");
$csrf = h(csrf_token());
$sim  = org_setting('pp_p24_simulation') === '1';
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portal płatności — obsługa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { navy: { 50: '#eef3f9', 600: '#1d4c80', 700: '#10335c' } } } } };</script>
<style type="text/tailwindcss">
  .inp { @apply w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-navy-600 focus:outline-none focus:ring-1 focus:ring-navy-600; }
  .lbl { @apply block text-xs font-medium text-slate-600 mb-1; }
  .bp { @apply inline-flex items-center gap-1 rounded-md bg-navy-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-navy-600; }
  .bs { @apply inline-flex items-center gap-1 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50; }
  .card { @apply rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200; }
  [x-cloak] { display: none !important; }
</style>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
<header class="bg-navy-700 text-white"><div class="mx-auto max-w-7xl px-4 py-4 flex flex-wrap items-center gap-3">
  <a href="../index.php" class="text-sm text-white/70 hover:text-white"><i class="bi bi-arrow-left"></i> SZO</a>
  <h1 class="text-lg font-semibold">Portal płatności — obsługa</h1>
  <div class="ml-auto flex flex-wrap gap-2 text-sm">
    <span class="rounded-lg bg-white/10 px-3 py-1">do zapłaty <strong><?= h(pp_fmt((float)$stats['p'])) ?></strong></span>
    <span class="rounded-lg bg-white/10 px-3 py-1">w trakcie <strong><?= h(pp_fmt((float)$stats['pr'])) ?></strong></span>
    <span class="rounded-lg bg-amber-400/90 px-3 py-1 text-navy-700">przelewy do potwierdzenia <strong><?= count($nrb_pending) ?></strong></span>
  </div>
</div></header>
<main class="mx-auto max-w-7xl px-4 py-5 space-y-5" x-data="{ tab: 'przeglad', init() { try { var t = location.hash.slice(1) || localStorage.getItem('pp_tab'); if (['przeglad','przelewy','uczestnicy','rachunki','korespondencja','ustawienia'].includes(t)) this.tab = t; <?= ($q !== '' || $pid) ? "this.tab = 'uczestnicy';" : '' ?> } catch (e) {} }, go(t) { this.tab = t; try { localStorage.setItem('pp_tab', t); history.replaceState(null, '', '#' + t); } catch (e) {} } }">
  <?php if ($flash): ?><div role="status" class="rounded-lg px-4 py-3 text-sm <?= $flash['type'] === 'danger' ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' ?>"><?= h((string)$flash['msg']) ?></div><?php endif; ?>
  <?php if ($link_once): ?>
  <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm ring-1 ring-amber-300" x-data="{ c: false }">
    <strong>Link dostępu (widoczny tylko teraz):</strong>
    <code class="ml-1 break-all"><?= h($link_once['url']) ?></code>
    <button type="button" class="bs ml-2" @click="navigator.clipboard.writeText(<?= h(json_encode($link_once['url'])) ?>); c = true" x-text="c ? 'skopiowano' : 'kopiuj'"></button>
    <div class="mt-1 text-xs text-slate-600">Przekaż go uczestnikowi (e-mail / SMS). W bazie zapisany jest tylko skrót — ponownie go nie wyświetlimy.</div>
  </div>
  <?php endif; ?>

  <?php foreach (pp_pool_alarms() as $al): $crit = $al['level'] === 'crit'; ?>
  <div role="alert" class="flex flex-wrap items-center gap-3 rounded-lg px-4 py-3 text-sm ring-1 <?= $crit ? 'bg-red-50 text-red-900 ring-red-300' : 'bg-amber-50 text-amber-900 ring-amber-300' ?>">
    <i class="bi <?= $crit ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
    <div class="min-w-0 flex-1"><strong>Pula numerów — <?= h($al['label']) ?>:</strong> <?= h($al['msg']) ?>
      <span class="text-xs">Zamów w banku: numer kontrahenta <span class="font-mono"><?= h($al['order_start']) ?></span>, liczba następnych <strong><?= (int)$al['order_count'] ?></strong>.</span></div>
    <button type="button" class="bs" @click="go('rachunki')">Przejdź do rachunków</button>
  </div>
  <?php endforeach; ?>
  <nav class="flex flex-wrap gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200" aria-label="Sekcje obsługi płatności">
    <?php foreach (['przeglad' => ['Przegląd', 'bi-speedometer2', 0], 'przelewy' => ['Przelewy i wpływy', 'bi-cash-coin', count($nrb_pending) + count($bank_c)], 'uczestnicy' => ['Uczestnicy', 'bi-people', 0], 'rachunki' => ['Rachunki wirtualne', 'bi-bank', 0], 'korespondencja' => ['Korespondencja', 'bi-envelope-paper', (int)(db_one("SELECT COUNT(*) c FROM pp_notice_batches WHERE status IN ('draft','approved')")['c'] ?? 0)], 'ustawienia' => ['Ustawienia', 'bi-gear', 0]] as $tk => [$tl, $ti, $tb]): ?>
    <button type="button" @click="go('<?= $tk ?>')" :class="tab === '<?= $tk ?>' ? 'bg-navy-700 text-white shadow' : 'text-slate-600 hover:bg-slate-100'" :aria-current="tab === '<?= $tk ?>' ? 'page' : null"
            class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition"><i class="bi <?= $ti ?>" aria-hidden="true"></i><?= $tl ?>
      <?php if ($tb): ?><span class="rounded-full bg-amber-400 px-1.5 text-xs font-semibold text-navy-700"><?= $tb ?></span><?php endif; ?></button>
    <?php endforeach; ?>
  </nav>

  <!-- Główna: tylko to, co wymaga działania -->
  <?php $ap0 = pp_bank_autopost('', null, true); $nb0 = count(array_filter(pp_vnrb_status_rows(), fn($r0) => $r0['cat'] === 'brak')); $unn0 = (int)(db_one("SELECT COUNT(*) c FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NOT NULL AND notified_at IS NULL")['c'] ?? 0);
        $todo = [
          ['Przelewy do potwierdzenia', count($nrb_pending), 'bi-hourglass-split', 'przelewy', 'Uczestnicy zgłosili przelew — potwierdź wpływ.'],
          ['Wpływy na numery wirtualne', (int)$ap0['posted'], 'bi-cash-coin', 'przelewy', 'Wpłaty czekają na zaksięgowanie (' . pp_fmt($ap0['amount']) . ').'],
          ['Wpływy do dopasowania', count($bank_c), 'bi-link-45deg', 'przelewy', 'Wpływy z wyciągów pasujące do płatności.'],
          ['Kursanci bez numeru rachunku', $nb0, 'bi-person-x', 'rachunki', 'Nadaj numer z puli albo zamów w banku.'],
          ['Numery bez powiadomienia', $unn0, 'bi-envelope', 'rachunki', 'Nadane numery, o których kursant nie wie.'],
        ]; ?>
  <div x-show="tab === 'przeglad'" x-cloak class="space-y-5">
    <section aria-labelledby="todo-h">
      <h2 id="todo-h" class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Do zrobienia</h2>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($todo as [$tl, $tn, $ti, $tt, $td]): ?>
        <button type="button" @click="go('<?= $tt ?>')" class="card text-left transition hover:shadow-md <?= $tn ? 'ring-2 ring-amber-300' : 'opacity-70' ?>">
          <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-full <?= $tn ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500' ?>"><i class="bi <?= $ti ?> text-lg" aria-hidden="true"></i></span>
            <div class="min-w-0"><div class="text-2xl font-semibold tabular-nums"><?= (int)$tn ?></div><div class="text-sm font-medium"><?= h($tl) ?></div></div></div>
          <div class="mt-1 text-xs text-slate-500"><?= h($td) ?></div>
        </button>
      <?php endforeach; ?>
      </div>
    </section>
    <section class="card" aria-labelledby="find-h">
      <h2 id="find-h" class="mb-2 font-semibold">Znajdź uczestnika</h2>
      <form method="get" class="flex gap-2"><label class="sr-only" for="qq">Szukaj uczestnika</label>
        <input id="qq" name="q" class="inp" placeholder="imię, nazwisko lub e-mail" value="<?= h($q) ?>"><button class="bp">Szukaj</button></form>
      <p class="mt-2 text-xs text-slate-500">Pozostałe narzędzia: <button type="button" class="underline" @click="go('przelewy')">przelewy i wpływy</button> · <button type="button" class="underline" @click="go('rachunki')">rachunki wirtualne</button> · <button type="button" class="underline" @click="go('ustawienia')">ustawienia</button>.</p>
    </section>
  </div>

  <div x-show="tab === 'przelewy'" x-cloak class="space-y-5">
  <!-- Przelewy do potwierdzenia -->
  <section class="card" aria-labelledby="nrb-h">
    <h2 id="nrb-h" class="font-semibold mb-2">Przelewy na NRB do potwierdzenia</h2>
    <?php if (!$nrb_pending): ?><p class="text-sm text-slate-500">Brak zgłoszonych przelewów.</p><?php endif; ?>
    <?php foreach ($nrb_pending as $t): ?>
    <div class="flex flex-wrap items-end gap-3 border-t border-slate-100 py-3 first:border-0">
      <div class="min-w-[16rem] flex-1"><div class="font-medium"><?= h($t['name']) ?> — <?= h(pp_fmt((float)$t['total_amount'])) ?></div>
        <div class="text-xs text-slate-500">tytuł: <span class="font-mono"><?= h($t['transfer_title']) ?></span> · zgłoszono <?= h(date('d.m.Y H:i', strtotime((string)$t['created_at']))) ?></div></div>
      <form method="post" class="flex flex-wrap items-end gap-2" x-data="{ note: '' }">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="participant_id" value="0">
        <div><label class="lbl" for="bd<?= (int)$t['id'] ?>">Data z wyciągu</label><input id="bd<?= (int)$t['id'] ?>" type="date" name="bank_date" class="inp" value="<?= date('Y-m-d') ?>"></div>
        <div><label class="lbl" for="nt<?= (int)$t['id'] ?>">Uwagi / powód odrzucenia</label><input id="nt<?= (int)$t['id'] ?>" name="note" class="inp" maxlength="300" x-model="note"></div>
        <button name="_op" value="confirm" class="bp" onclick="return confirm('Potwierdzić wpłatę <?= h(pp_fmt((float)$t['total_amount'])) ?>?')">Potwierdź zaksięgowanie</button>
        <button name="_op" value="reject" class="bs" :disabled="note.trim().length < 5" title="Wymaga powodu">Odrzuć</button>
      </form>
    </div>
    <?php endforeach; ?>
  </section>

  <!-- Wpływy na numery wirtualne — automatyczne księgowanie -->
  <?php $ap = pp_bank_autopost('', null, true); ?>
  <section class="card" aria-labelledby="ap-h">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 id="ap-h" class="font-semibold">Wpływy na numery wirtualne do zaksięgowania <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= (int)$ap['posted'] ?></span></h2>
      <?php if ($ap['posted']): ?>
      <form method="post" onsubmit="return confirm('Zaksięgować <?= (int)$ap['posted'] ?> wpłat na kwotę <?= h(pp_fmt($ap['amount'])) ?> w księdze TI? Każda wpłata trafi do salda kursanta (FIFO).')">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="bank_autopost"><input type="hidden" name="participant_id" value="0">
        <button class="bp"><i class="bi bi-cash-coin" aria-hidden="true"></i>Zaksięguj wszystkie (<?= h(pp_fmt($ap['amount'])) ?>)</button></form>
      <?php endif; ?>
    </div>
    <p class="mt-1 text-xs text-slate-500">Wpływy z wyciągów EODoK, w których rachunek docelowy (albo numer w tytule) to indywidualny rachunek kursanta. Każdy wpływ księgujemy raz; bez potwierdzania pojedynczych przelewów.</p>
    <?php if ($ap['rows']): ?>
    <div class="mt-2 max-h-72 overflow-auto"><table class="min-w-full text-sm"><thead class="sticky top-0 bg-white text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Data</th><th class="pr-3">Kursant</th><th class="pr-3">Wpłacający / tytuł</th><th class="pr-3 text-right">Kwota</th></tr></thead><tbody class="divide-y divide-slate-100">
      <?php foreach ($ap['rows'] as $ar): ?><tr><td class="py-1.5 pr-3 whitespace-nowrap text-xs"><?= h(substr($ar['date'], 0, 10)) ?></td><td class="pr-3 font-medium"><?= h($ar['name']) ?></td>
        <td class="pr-3 text-xs text-slate-600"><?= h(mb_strimwidth(trim($ar['payer'] . ' — ' . $ar['title']), 0, 80, '…')) ?></td><td class="pr-3 text-right tabular-nums"><?= h(pp_fmt($ar['amount'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><p class="mt-2 text-sm text-slate-500">Brak nowych wpływów na numery wirtualne.</p><?php endif; ?>
  </section>

  <!-- Wpływy z wyciągów EODoK -->
  <section class="card" aria-labelledby="bk-h">
    <div class="flex flex-wrap items-center gap-2 mb-2">
      <h2 id="bk-h" class="font-semibold">Wpływy z wyciągów EODoK</h2>
      <span class="text-xs text-slate-500">dopasowanie po kodzie płatności w tytule („SZO…”) albo po rachunku wirtualnym uczestnika; uruchamia się też przy imporcie wyciągu MT940</span>
      <form method="post" class="ml-auto"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="bank_auto"><input type="hidden" name="participant_id" value="0">
        <button class="bp"><i class="bi bi-magic"></i> Dopasuj teraz</button></form>
    </div>
    <?php if (!$bank_c): ?><p class="text-sm text-slate-500">Brak wpływów do dopasowania — wszystko rozliczone albo nie ma pasujących operacji.</p><?php endif; ?>
    <?php foreach (array_slice($bank_c, 0, 40) as $c): $b = $c['bank']; $t = $c['tx']; ?>
    <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 py-2 first:border-0 text-sm">
      <div class="min-w-[14rem] flex-1">
        <div><span class="font-medium"><?= h(date('d.m.Y', strtotime((string)$b['data_waluty']))) ?> · <?= h(pp_fmt((float)$b['kwota'])) ?></span> · <?= h($b['kontrahent_nazwa']) ?></div>
        <div class="text-xs text-slate-500 break-all">„<?= h($b['tytul']) ?>”</div>
      </div>
      <div class="min-w-[14rem] flex-1"><div>→ <?= h($t['name']) ?> · <?= h(pp_fmt((float)$t['total_amount'])) ?> · <span class="font-mono text-xs"><?= h($t['transfer_title']) ?></span></div>
        <div class="text-xs <?= $c['amount_ok'] ? 'text-emerald-700' : 'text-amber-700' ?>"><?= h(implode(' · ', $c['reasons'])) ?></div></div>
      <form method="post" onsubmit="return confirm('Rozliczyć płatność tym wpływem<?= $c['amount_ok'] ? '' : ' mimo różnej kwoty' ?>?')">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="bank_assign"><input type="hidden" name="participant_id" value="0">
        <input type="hidden" name="bank_tx_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>">
        <button class="bs">Przypisz</button></form>
    </div>
    <?php endforeach; ?>
  </section>

  </div>

  <div x-show="tab === 'uczestnicy'" x-cloak>
    <!-- Uczestnik -->
    <section class="card space-y-4" aria-labelledby="u-h">
      <h2 id="u-h" class="font-semibold">Uczestnik</h2>
      <form method="get" class="flex gap-2"><label class="sr-only" for="q">Szukaj uczestnika</label>
        <input id="q" name="q" class="inp" placeholder="Imię, nazwisko lub e-mail" value="<?= h($q) ?>"><button class="bs">Szukaj</button></form>
      <?php if ($found): ?><ul class="divide-y divide-slate-100 text-sm">
        <?php foreach ($found as $f): ?><li class="flex justify-between py-1.5"><a class="text-navy-700 hover:underline" href="admin.php?p=<?= (int)$f['id'] ?>"><?= h($f['name']) ?></a><span class="text-slate-500"><?= h((string)$f['email']) ?><?= $f['pu'] ? ' · ma dostęp' : '' ?></span></li><?php endforeach; ?>
      </ul><?php endif; ?>

      <?php if ($sel): ?>
      <div class="rounded-lg bg-slate-50 p-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2"><span class="font-semibold"><?= h($sel['name']) ?></span><span class="text-sm text-slate-500"><?= h((string)$sel['email']) ?></span>
          <?php if ($pu): ?><span class="rounded-full px-2 py-0.5 text-xs <?= $pu['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' ?>"><?= $pu['is_active'] ? 'dostęp aktywny' : 'dostęp zablokowany' ?></span><?php endif; ?></div>
        <?php if (!$pu): ?>
        <form method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="user"><input type="hidden" name="participant_id" value="<?= $pid ?>">
          <button class="bp">Utwórz dostęp do portalu</button></form>
        <?php else: ?>
        <div class="grid gap-3 md:grid-cols-2">
          <form method="post" class="space-y-2"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="nrb"><input type="hidden" name="participant_id" value="<?= $pid ?>">
            <label class="lbl" for="nrb">Indywidualny NRB</label>
            <input id="nrb" name="nrb" class="inp font-mono" value="<?= h(pp_nrb_format((string)$pu['individual_nrb'])) ?>" placeholder="puste = rachunek ogólny">
            <div class="flex gap-2"><button class="bs">Zapisz</button><button class="bs" name="generate" value="1" title="Z prefiksu rachunków wirtualnych">Wygeneruj</button></div></form>
          <div class="space-y-2">
            <form method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="link"><input type="hidden" name="participant_id" value="<?= $pid ?>">
              <button class="bp" onclick="return confirm('Wygenerować nowy link? Poprzedni przestanie działać.')"><i class="bi bi-link-45deg"></i> Nowy link dostępu</button></form>
            <p class="text-xs text-slate-500">Ostatnie wejście: <?= $pu['last_login_at'] ? h(date('d.m.Y H:i', strtotime((string)$pu['last_login_at']))) : '—' ?> · link z <?= $pu['token_created_at'] ? h(date('d.m.Y', strtotime((string)$pu['token_created_at']))) : '—' ?></p>
            <form method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="active"><input type="hidden" name="participant_id" value="<?= $pid ?>">
              <input type="hidden" name="on" value="<?= $pu['is_active'] ? '0' : '1' ?>"><button class="bs"><?= $pu['is_active'] ? 'Zablokuj dostęp' : 'Włącz dostęp' ?></button></form>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <h3 class="font-semibold">Pozycje uczestnika</h3>
      <div class="flex flex-wrap gap-2">
        <form method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="import_ti"><input type="hidden" name="participant_id" value="<?= $pid ?>">
          <button class="bs"><i class="bi bi-download"></i> Pobierz nieopłacone rozliczenia TI</button></form>
      </div>
      <form method="post" class="grid gap-2 md:grid-cols-6 items-end" x-data="{ t: 'manual' }">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="item"><input type="hidden" name="participant_id" value="<?= $pid ?>">
        <div class="md:col-span-2"><label class="lbl" for="it-t">Nazwa</label><input id="it-t" name="title" class="inp" required maxlength="200" placeholder="np. Faktura FVAT 12/2026"></div>
        <div><label class="lbl" for="it-a">Kwota (zł)</label><input id="it-a" name="amount" class="inp" inputmode="decimal" required></div>
        <div><label class="lbl" for="it-r">Rodzaj</label><select id="it-r" name="reference_type" class="inp" x-model="t">
          <?php foreach (PP_REF_TYPES as $k => $l): if ($k === 'ti_billing') continue; ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div x-show="t !== 'manual'"><label class="lbl" for="it-i">ID w systemie</label><input id="it-i" name="reference_id" type="number" min="1" class="inp" placeholder="np. id faktury"></div>
        <div><label class="lbl" for="it-d">Termin</label><input id="it-d" type="date" name="due_date" class="inp"></div>
        <div class="md:col-span-6"><button class="bp">Dodaj pozycję</button></div>
      </form>
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-2">Pozycja</th><th class="pr-2">Rodzaj</th><th class="pr-2 text-right">Kwota</th><th class="pr-2">Termin</th><th class="pr-2">Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($sel_items as $it): ?>
          <tr><td class="py-1.5 pr-2"><?= h($it['title']) ?></td><td class="pr-2 text-xs"><?= h(PP_REF_TYPES[$it['reference_type']] ?? $it['reference_type']) ?><?= $it['reference_id'] ? ' #' . (int)$it['reference_id'] : '' ?></td>
            <td class="pr-2 text-right tabular-nums"><?= h(pp_fmt((float)$it['amount'])) ?></td><td class="pr-2 text-xs"><?= $it['due_date'] ? h(date('d.m.Y', strtotime((string)$it['due_date']))) : '—' ?></td>
            <td class="pr-2 text-xs"><?= h(PP_ITEM_STATUS[$it['status']] ?? $it['status']) ?></td>
            <td class="text-right"><?php if ($it['status'] === 'pending'): ?><form method="post" onsubmit="return confirm('Anulować pozycję?')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="cancel_item"><input type="hidden" name="participant_id" value="<?= $pid ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="text-xs text-red-700 hover:underline">anuluj</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$sel_items): ?><tr><td colspan="6" class="py-4 text-center text-slate-500">Brak pozycji.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>
  </div>

  <div x-show="tab === 'korespondencja'" x-cloak class="space-y-5">
    <?php $kb = db_all("SELECT * FROM pp_notice_batches ORDER BY CASE status WHEN 'approved' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, COALESCE(send_at, created_at) DESC LIMIT 60");
          $kn = ['draft' => 'bg-slate-100 text-slate-700', 'approved' => 'bg-amber-100 text-amber-900', 'sent' => 'bg-emerald-100 text-emerald-900', 'cancelled' => 'bg-slate-100 text-slate-500 line-through']; ?>
    <section class="card space-y-3" aria-labelledby="kr-h">
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="kr-h" class="font-semibold">Zaplanowana korespondencja — powiadomienia o numerach rachunków</h2>
        <button type="button" class="bs" @click="go('rachunki'); $nextTick(() => { var e = document.getElementById('powiadomienia'); if (e) e.scrollIntoView({behavior: 'smooth'}); })"><i class="bi bi-plus-lg" aria-hidden="true"></i>Nowa wysyłka</button></div>
      <p class="text-xs text-slate-500">Treść zatwierdza admin; wysyłka rusza automatycznie o wyznaczonej godzinie (domyślnie 08:00 następnego dnia po zatwierdzeniu). Terminu zatwierdzonej wysyłki nie trzeba zmieniać — możesz to zrobić poniżej (06:00–22:00).</p>
      <?php if (!$kb): ?><p class="text-sm text-slate-500">Brak wysyłek. Przygotuj pierwszą w zakładce Rachunki wirtualne → Import i przypisanie.</p><?php endif; ?>
      <div class="divide-y divide-slate-100">
      <?php foreach ($kb as $b): $st0 = json_decode((string)$b['stats'], true) ?: []; $rc = in_array($b['status'], ['draft', 'approved'], true) ? count(pp_notice_recipients($b['scope'], $b['batch'], (int)$b['id'])) : (int)($st0['students'] ?? 0); ?>
        <div class="py-3 text-sm">
          <div class="flex flex-wrap items-center gap-2">
            <strong>#<?= (int)$b['id'] ?></strong><span class="rounded-full px-2 py-0.5 text-xs <?= $kn[$b['status']] ?? '' ?>"><?= h(PP_NOTICE_STATUSES[$b['status']] ?? $b['status']) ?></span>
            <span class="font-medium"><?= h($b['subject']) ?></span>
            <span class="text-xs text-slate-500">odbiorców: <?= $rc ?><?= $b['send_at'] ? ' · termin: ' . h(date('d.m.Y H:i', strtotime($b['send_at']))) : '' ?><?= $b['sent_at'] ? ' · wysłano: ' . h(date('d.m.Y H:i', strtotime($b['sent_at']))) : '' ?> · autor: <?= h($b['created_by']) ?><?= $b['approved_by'] ? ' · zatwierdził: ' . h($b['approved_by']) : '' ?><?= $b['status'] === 'sent' ? ' · SMS ' . (int)($st0['sms'] ?? 0) . ', e-mail ' . (int)($st0['email'] ?? 0) : '' ?></span>
          </div>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <?php if ($b['status'] === 'approved'): ?>
            <form method="post" class="flex flex-wrap items-center gap-1"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="notice_reschedule"><input type="hidden" name="notice_id" value="<?= (int)$b['id'] ?>">
              <label class="sr-only" for="rs-<?= (int)$b['id'] ?>">Nowy termin wysyłki</label><input id="rs-<?= (int)$b['id'] ?>" type="datetime-local" name="send_at" class="inp !w-52 !py-1" value="<?= h(date('Y-m-d\TH:i', strtotime((string)$b['send_at']))) ?>" required>
              <button class="bs !py-1">Zmień termin</button></form>
            <?php endif; ?>
            <?php if ($b['status'] === 'draft'): ?><a class="bs !py-1" href="admin.php?pn=<?= (int)$b['id'] ?>#powiadomienia">Edytuj szkic</a><?php endif; ?>
            <?php if (in_array($b['status'], ['draft', 'approved'], true)): ?>
            <form method="post" onsubmit="return confirm('Anulować tę wysyłkę?')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="notice_cancel"><input type="hidden" name="notice_id" value="<?= (int)$b['id'] ?>"><button class="bs !py-1">Anuluj</button></form>
            <?php endif; ?>
            <details class="text-xs"><summary class="cursor-pointer text-navy-700">Treść<?= $b['status'] === 'sent' ? ' i odbiorcy' : '' ?></summary>
              <div class="mt-2 max-w-2xl space-y-2 rounded border border-slate-200 bg-slate-50 p-3">
                <div><strong>Temat:</strong> <?= h($b['subject']) ?></div>
                <pre class="whitespace-pre-wrap font-sans"><?= h($b['body']) ?></pre>
                <?php if (trim($b['sms_text']) !== ''): ?><div><strong>SMS:</strong> <?= h($b['sms_text']) ?></div><?php endif; ?>
                <?php $live = in_array($b['status'], ['draft', 'approved'], true);
                      $ids = $b['status'] === 'sent' ? array_map('intval', $st0['clients'] ?? []) : pp_notice_recipients($b['scope'], $b['batch'], (int)$b['id']);
                      $exc_ids = $live ? array_map('intval', array_column(db_all("SELECT client_id FROM pp_notice_exclusions WHERE batch_id=?", [(int)$b['id']]), 'client_id')) : [];
                      $all_ids = array_values(array_unique(array_merge($ids, $exc_ids)));
                      if ($all_ids): $nm = array_column(db_all("SELECT id, name FROM k30_clients WHERE id IN (" . implode(',', array_map('intval', $all_ids)) . ") ORDER BY name COLLATE NOCASE"), 'name', 'id'); ?>
                <div><strong>Odbiorcy (<?= count($ids) ?><?= $exc_ids ? ', wykluczeni: ' . count($exc_ids) : '' ?>):</strong>
                  <ul class="mt-1 space-y-1"><?php foreach ($nm as $cid0 => $nm0): $is_ex = in_array((int)$cid0, $exc_ids, true); ?>
                    <li class="flex items-center gap-2"><span class="<?= $is_ex ? 'text-slate-400 line-through' : '' ?>"><?= h($nm0) ?></span>
                      <?php if ($live): ?><form method="post" class="inline"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="notice_exclude"><input type="hidden" name="notice_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="client_id" value="<?= (int)$cid0 ?>"><?php if (!$is_ex): ?><input type="hidden" name="exclude" value="1"><?php endif; ?>
                        <button class="rounded px-2 py-0.5 text-[11px] ring-1 <?= $is_ex ? 'text-emerald-700 ring-emerald-300' : 'text-red-700 ring-red-300' ?>"><?= $is_ex ? 'przywróć' : 'anuluj wysyłkę do tej osoby' ?></button></form><?php endif; ?></li>
                  <?php endforeach; ?></ul></div><?php endif; ?>
              </div></details>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    </section>
  </div>

  <div x-show="tab === 'ustawienia'" x-cloak class="max-w-2xl">
    <!-- Ustawienia -->
    <section class="card space-y-3" aria-labelledby="s-h">
      <h2 id="s-h" class="font-semibold">Ustawienia</h2>
      <form method="post" class="space-y-3"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="settings">
        <div><label class="lbl" for="sb">Numer rozliczeniowy banku (8 cyfr)</label><input id="sb" name="pp_nrb_bank" class="inp font-mono" value="<?= h(pp_vnrb_bank()) ?>" inputmode="numeric"></div>
        <div><label class="lbl" for="sp">Identyfikator Klienta RRRR (4 cyfry, z dokumentu Aktywacji)</label><input id="sp" name="pp_nrb_prefix" class="inp font-mono" value="<?= h(pp_vnrb_rrrr()) ?>" inputmode="numeric"></div>
        <p class="text-xs text-slate-500">NRB = cyfry kontrolne + bank (8) + RRRR (4) + NNNN (12: nr kursanta TI albo ID uczestnika) — suma kontrolna liczona automatycznie. Pozostałe grupy: generator poniżej.</p>
        <div><label class="lbl" for="sg">Rachunek ogólny do wpłat (gdy uczestnik nie ma rachunku wirtualnego)</label>
          <input id="sg" name="pp_general_nrb" class="inp font-mono" value="<?= h(pp_nrb_format((string)org_setting('pp_general_nrb'))) ?>" placeholder="26 cyfr"></div>
        <p class="text-xs text-slate-500">Na rachunku ogólnym wpłatę rozpoznajemy po kodzie w tytule przelewu — uczestnik widzi wyraźne ostrzeżenie, żeby go nie zmieniać.</p>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pp_p24_simulation" value="1"<?= $sim ? ' checked' : '' ?>> Symulacja Przelewy24 (gdy bramka nie jest skonfigurowana)</label>
        <p class="text-xs text-slate-500">Przelewy24: <?= p24_enabled() ? '<span class="text-emerald-700">skonfigurowane (' . h(p24_environment()) . ')</span>' : '<span class="text-amber-700">nieskonfigurowane</span>' ?> — dane w <a class="underline" href="../admin/p24_settings.php">ustawieniach P24</a>.</p>
        <button class="bp">Zapisz</button></form>
    </section>
  </div>

  <div x-show="tab === 'rachunki'" x-cloak class="space-y-5" x-data="{ sub: 'stan' }">
  <div class="flex flex-wrap gap-2" role="tablist" aria-label="Rachunki wirtualne">
    <?php foreach (['stan' => 'Stan kursantów', 'zamow' => 'Zamów w banku', 'import' => 'Import i przypisanie'] as $sk => $sl): ?>
    <button type="button" role="tab" @click="sub = '<?= $sk ?>'" :aria-selected="sub === '<?= $sk ?>'" :class="sub === '<?= $sk ?>' ? 'bg-navy-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50'" class="rounded-full px-4 py-1.5 text-sm font-medium"><?= $sl ?></button>
    <?php endforeach; ?>
  </div>
  <!-- Raport stanu: kto bez numeru / bez powiadomienia / wirtualny -->
  <?php $vs = pp_vnrb_status_rows(); $vsc = array_fill_keys(array_keys(PP_VSTATUS), 0); foreach ($vs as $r0) $vsc[$r0['cat']]++; ?>
  <section class="card space-y-3" aria-labelledby="vr-h" x-data="{ f: '' }" x-show="sub === 'stan'">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 id="vr-h" class="font-semibold">Stan rachunków kursantów TI</h2>
      <div class="flex flex-wrap items-center gap-2">
        <a class="bs" href="admin.php?report=status" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i>Drukuj raport</a>
        <a class="bs" href="admin.php?export=csv"><i class="bi bi-filetype-csv" aria-hidden="true"></i>Eksport CSV numerów</a>
        <button type="button" class="bp" @click="sub = 'import'; $nextTick(() => { var e = document.getElementById('powiadomienia'); if (e) e.scrollIntoView({behavior: 'smooth', block: 'start'}); })"><i class="bi bi-envelope-paper" aria-hidden="true"></i>Wyślij masowo e-mail i SMS z numerem</button>
        <span class="text-xs text-slate-400">|</span>
        <span class="text-xs font-medium text-slate-600"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF „nadano numer”:</span>
        <a class="bp" href="admin.php?pdf=notice&scope=all" target="_blank" rel="noopener">Wszyscy z numerem</a>
        <a class="bs" href="admin.php?pdf=notice&scope=unnotified" target="_blank" rel="noopener">Bez powiadomienia</a>
        <a class="bs" href="admin.php?pdf=notice&scope=last" target="_blank" rel="noopener">Ostatni import</a>
      </div>
    </div>
    <div class="flex flex-wrap gap-2" role="group" aria-label="Filtr stanu">
      <button type="button" @click="f = ''" :class="f === '' ? 'bg-navy-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-300'" class="rounded-full px-3 py-1 text-xs font-medium">Wszyscy (<?= count($vs) ?>)</button>
      <?php foreach (PP_VSTATUS as $k => $l): ?>
      <button type="button" @click="f = '<?= $k ?>'" :class="f === '<?= $k ?>' ? 'bg-navy-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-300'" class="rounded-full px-3 py-1 text-xs font-medium"><?= h($l) ?> (<?= $vsc[$k] ?>)</button>
      <?php endforeach; ?>
      <a class="ml-auto text-xs text-slate-500 hover:underline" :href="'admin.php?report=status&cat=' + f" target="_blank" rel="noopener" x-show="f !== ''">drukuj tylko ten stan →</a>
    </div>
    <div class="max-h-96 overflow-auto"><table class="min-w-full text-sm">
      <thead class="sticky top-0 bg-white text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Kursant</th><th class="pr-3">Stan</th><th class="pr-3">Rachunek</th><th class="pr-3">Powiadomiono</th><th class="pr-3 text-right">PDF</th><th class="pr-3 text-right">Opcje numeru</th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($vs as $r0): ?>
        <tr x-show="f === '' || f === '<?= $r0['cat'] ?>'"><td class="py-1.5 pr-3 font-medium"><a class="hover:underline" href="admin.php?q=<?= urlencode($r0['name']) ?>"><?= h($r0['name']) ?></a></td>
          <td class="pr-3 text-xs"><span class="rounded-full px-2 py-0.5 <?= ['brak' => 'bg-red-50 text-red-800', 'nie_powiad' => 'bg-amber-50 text-amber-800', 'powiad' => 'bg-emerald-50 text-emerald-800', 'reczny' => 'bg-sky-50 text-sky-800', 'wirtualny' => 'bg-slate-100 text-slate-700', 'bez_rozl' => 'bg-slate-100 text-slate-700'][$r0['cat']] ?>"><?= h(PP_VSTATUS[$r0['cat']]) ?></span></td>
          <td class="pr-3 font-mono text-xs"><?= $r0['nrb'] !== '' ? h(pp_nrb_format($r0['nrb'])) : '—' ?></td>
          <td class="pr-3 text-xs text-slate-500"><?= h($r0['notified_at'] ?: '—') ?></td>
          <td class="pr-3 text-right"><?php if ($r0['nrb'] !== ''): ?><a class="text-xs text-navy-700 hover:underline" href="admin.php?pdf=notice&scope=client&client=<?= (int)$r0['client_id'] ?>" target="_blank" rel="noopener" title="PDF z informacją o numerze — <?= h($r0['name']) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</a><?php else: ?><span class="text-slate-300">—</span><?php endif; ?></td>
          <td class="pr-3 text-right text-xs">
            <?php if ($r0['nrb'] !== ''): $blk0 = pp_vnrb_is_blocked($r0['nrb']); ?>
            <a class="text-slate-500 hover:underline" href="admin.php?hist=<?= (int)$r0['client_id'] ?>#rachunki">historia</a>
            <details class="relative inline-block text-left"><summary class="cursor-pointer text-navy-700"><?= $blk0 ? '<i class="bi bi-lock-fill text-red-700" title="zablokowany"></i> ' : '' ?>opcje</summary>
              <div class="absolute right-0 z-10 mt-1 w-72 space-y-2 rounded-lg bg-white p-3 text-left shadow-lg ring-1 ring-slate-200">
                <form method="post" class="space-y-1"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="vnrb_block"><input type="hidden" name="participant_id" value="0"><input type="hidden" name="nrb" value="<?= h($r0['nrb']) ?>"><?php if (!$blk0): ?><input type="hidden" name="block" value="1"><?php endif; ?>
                  <?php if (!$blk0): ?><input name="reason" class="inp !py-1" placeholder="powód blokady" maxlength="300" required minlength="5" aria-label="Powód blokady"><?php endif; ?>
                  <button class="bs !py-1"><?= $blk0 ? 'Odblokuj numer' : 'Zablokuj numer' ?></button>
                  <div class="text-[11px] text-slate-500">Zablokowany numer: wpłaty nie są księgowane automatycznie o 20:00.</div></form>
                <form method="post" class="space-y-1" onsubmit="return confirm('Odpiąć numer od kursanta <?= h(addslashes($r0['name'])) ?> i zwrócić go do puli?')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="vnrb_release"><input type="hidden" name="participant_id" value="0"><input type="hidden" name="kind" value="ti"><input type="hidden" name="rid" value="<?= (int)$r0['client_id'] ?>">
                  <input name="reason" class="inp !py-1" placeholder="powód odpięcia" maxlength="300" required minlength="5" aria-label="Powód odpięcia">
                  <button class="bs !py-1 !text-red-700" <?= $blk0 ? 'disabled title="najpierw odblokuj"' : '' ?>>Odepnij numer (do puli)</button></form>
              </div></details>
            <?php else: ?><span class="text-slate-300">—</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$vs): ?><tr><td colspan="6" class="py-6 text-center text-slate-500">Brak kursantów TI.</td></tr><?php endif; ?>
      </tbody></table></div>
  </section>

  <?php $hist_id = (int)($_GET['hist'] ?? 0); if ($hist_id): $hn = db_one("SELECT name FROM k30_clients WHERE id=?", [$hist_id]); $hh = pp_vnrb_history($hist_id); ?>
  <section class="card space-y-2" x-show="sub === 'stan'" aria-labelledby="hi-h">
    <div class="flex items-center justify-between"><h2 id="hi-h" class="font-semibold">Historia numeru — <?= h($hn['name'] ?? ('#' . $hist_id)) ?></h2><a class="bs" href="admin.php#rachunki">Zamknij</a></div>
    <?php if (!$hh): ?><p class="text-sm text-slate-500">Brak wpisów w audycie dla tego uczestnika.</p><?php endif; ?>
    <ul class="space-y-1 text-xs"><?php foreach ($hh as $he): $hd = json_decode((string)$he['details'], true) ?: []; ?>
      <li class="border-l-2 border-slate-200 pl-3"><span class="font-medium"><?= h($he['action']) ?></span> · <?= h($he['created_at']) ?> · <?= h($hd['by'] ?? '') ?>
        <span class="text-slate-500"><?= h(mb_strimwidth(json_encode(array_diff_key($hd, ['by' => 1, 'row' => 1]), JSON_UNESCAPED_UNICODE), 0, 200, '…')) ?></span></li><?php endforeach; ?></ul>
  </section>
  <?php endif; ?>
  <?php $crm_nums = db_all("SELECT contact_id, nrb, assigned_at FROM pp_vnrb_crm ORDER BY assigned_at DESC LIMIT 200"); $crm_names = [];
        if ($crm_nums) { try { foreach (crm_all("SELECT id, imie_nazwisko FROM crm_contacts WHERE id IN (" . implode(',', array_map('intval', array_column($crm_nums, 'contact_id'))) . ")") as $cn) $crm_names[(int)$cn['id']] = $cn['imie_nazwisko']; } catch (\Throwable $e) {} } ?>
  <section class="card space-y-2" x-show="sub === 'stan'" aria-labelledby="crm-h">
    <h2 id="crm-h" class="font-semibold">Kontrahenci CRM z numerem <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= count($crm_nums) ?></span></h2>
    <?php if (!$crm_nums): ?><p class="text-sm text-slate-500">Żaden kontrahent CRM nie ma jeszcze nadanego numeru (przycisk „Przypisz kontrahentom” w podzakładce Import i przypisanie).</p><?php else: ?>
    <div class="max-h-72 overflow-auto"><table class="min-w-full text-sm"><thead class="sticky top-0 bg-white text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Kontrahent</th><th class="pr-3">Rachunek</th><th class="pr-3">Nadano</th><th class="pr-3 text-right">Opcje numeru</th></tr></thead><tbody class="divide-y divide-slate-100">
      <?php foreach ($crm_nums as $cr): $cb = pp_vnrb_is_blocked($cr['nrb']); ?>
      <tr><td class="py-1.5 pr-3 font-medium"><?= h($crm_names[(int)$cr['contact_id']] ?? ('kontakt #' . (int)$cr['contact_id'])) ?></td><td class="pr-3 font-mono text-xs"><?= h(pp_nrb_format($cr['nrb'])) ?><?= $cb ? ' <i class="bi bi-lock-fill text-red-700" title="zablokowany"></i>' : '' ?></td><td class="pr-3 text-xs text-slate-500"><?= h((string)$cr['assigned_at']) ?></td>
        <td class="pr-3 text-right text-xs"><details class="relative inline-block text-left"><summary class="cursor-pointer text-navy-700">opcje</summary>
          <div class="absolute right-0 z-10 mt-1 w-72 space-y-2 rounded-lg bg-white p-3 text-left shadow-lg ring-1 ring-slate-200">
            <form method="post" class="space-y-1"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="vnrb_block"><input type="hidden" name="participant_id" value="0"><input type="hidden" name="nrb" value="<?= h($cr['nrb']) ?>"><?php if (!$cb): ?><input type="hidden" name="block" value="1"><input name="reason" class="inp !py-1" placeholder="powód blokady" maxlength="300" required minlength="5" aria-label="Powód blokady"><?php endif; ?><button class="bs !py-1"><?= $cb ? 'Odblokuj numer' : 'Zablokuj numer' ?></button></form>
            <form method="post" class="space-y-1" onsubmit="return confirm('Odpiąć numer od kontrahenta i zwrócić go do puli?')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="vnrb_release"><input type="hidden" name="participant_id" value="0"><input type="hidden" name="kind" value="crm"><input type="hidden" name="rid" value="<?= (int)$cr['contact_id'] ?>"><input name="reason" class="inp !py-1" placeholder="powód odpięcia" maxlength="300" required minlength="5" aria-label="Powód odpięcia"><button class="bs !py-1 !text-red-700" <?= $cb ? 'disabled title="najpierw odblokuj"' : '' ?>>Odepnij numer (do puli)</button></form>
          </div></details></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>

  <!-- Serie rachunków wirtualnych: SZO podaje bankowi tylko 1 numer startowy na serię -->
  <section class="card space-y-3" aria-labelledby="vg-h" x-show="sub !== 'stan'">
    <h2 id="vg-h" class="font-semibold" x-text="sub === 'zamow' ? 'Serie rachunków — numery startowe i zamówienie w banku' : 'Import listy z banku i przypisanie numerów'">Rachunki wirtualne</h2>
    <div x-show="sub === 'zamow'" class="space-y-3">
    <p class="text-xs text-slate-500">Struktura: 2 cyfry kontrolne + <span class="font-mono text-red-600">bank (8)</span> + <span class="font-mono text-purple-700">RRRR (4)</span> + <span class="font-mono text-emerald-700">kod serii (8) + numer od banku (4)</span>. Przekaż bankowi po jednym numerze startowym z każdej serii (końcówka 12 cyfr = kod serii 8 cyfr + 0001) — kolejne numery, rosnąco, wygeneruje bank. Gdy wkleisz listę od banku (niżej), SZO nada numery kursantom i uczestnikom. Numerów nie generujemy samodzielnie.</p>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left"><th>Seria</th><th>Kod</th><th>Numer kontrahenta (dla banku)</th><th>Numer startowy</th><th>Następny z puli (12 cyfr)</th><th>W puli / nadane</th></tr></thead><tbody>
    <?php foreach (pp_series() as $sk => $se): $st = pp_series_start($sk); $pc = db_one("SELECT COUNT(*) n, SUM(participant_id IS NULL AND crm_contact_id IS NULL) f FROM pp_vnrb_pool WHERE grp=?", [$sk]); $nx = db_one("SELECT nrb FROM pp_vnrb_pool WHERE grp=? AND participant_id IS NULL ORDER BY substr(nrb,15,12) LIMIT 1", [$sk]); ?>
      <tr class="border-t"><td><?= h($se['label']) ?></td><td class="font-mono"><?= h($se['code']) ?></td>
        <td class="font-mono font-semibold"><?= h($se['code'] . '0001') ?></td>
        <td class="font-mono"><?= $st ? h(pp_nrb_format($st)) : 'ustaw bank i RRRR' ?></td>
        <td class="font-mono"><?= $nx ? h(trim(chunk_split(substr($nx['nrb'], 14, 12), 4, ' '))) : '—' ?></td>
        <td class="text-xs">w puli <?= (int)($pc['n'] ?? 0) ?> · nadane <?= (int)($pc['n'] ?? 0) - (int)($pc['f'] ?? 0) ?> · wolne <?= (int)($pc['f'] ?? 0) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <details class="rounded-lg border border-slate-200 p-3">
      <summary class="cursor-pointer text-sm font-semibold">Kody serii (8 cyfr)</summary>
      <form method="post" class="mt-2 grid gap-2 md:grid-cols-5 items-end"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="series">
        <?php foreach (pp_series() as $sk => $se): ?><div><label class="lbl" for="sc-<?= h($sk) ?>"><?= h($se['label']) ?></label><input id="sc-<?= h($sk) ?>" name="series_<?= h($sk) ?>" class="inp font-mono" inputmode="numeric" maxlength="8" value="<?= h($se['code']) ?>"></div><?php endforeach; ?>
        <div><button class="bp">Zapisz kody</button></div>
        <p class="md:col-span-5 text-xs text-slate-500">Zmień tylko przed zamówieniem numerów w banku. Kod służy do rozpoznania serii przy imporcie listy.</p>
      </form></details>
    <?php
      $gsel = $genres['grp'] ?? (string)($_POST['gen_grp'] ?? 'ti'); if (!isset(pp_series()[$gsel])) $gsel = 'ti';
      $glast = []; foreach (pp_series() as $k => $_se) $glast[$k] = pp_gen_last($k);
      $need = (int)(db_one("SELECT COUNT(*) c FROM k30_ti_student_accounts a LEFT JOIN payment_portal_users u ON u.participant_id=a.client_id WHERE (u.individual_nrb IS NULL OR u.individual_nrb='') AND COALESCE(a.is_virtual,0)=0")['c'] ?? 0);
      $free = (int)(db_one("SELECT COUNT(*) c FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NULL")['c'] ?? 0);
    ?>
    <form method="post" class="rounded-lg border border-slate-200 p-3 space-y-2" x-data='{ last: <?= h(json_encode($glast)) ?>, g: "<?= h($gsel) ?>", s: "<?= h($genres['start'] ?? $glast[$gsel][0]) ?>", n: <?= (int)($genres['count'] ?? $glast[$gsel][1]) ?> }'>
      <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="gen_series">
      <h3 class="font-semibold text-sm">Generator numerów (numer kontrahenta + liczba następnych)</h3>
      <p class="text-xs text-slate-500">Działa jak formularz banku. Ostatnie wartości każdej serii są zapamiętywane. Kursantów TI bez rachunku: <strong><?= $need ?></strong>, wolnych w puli TI: <strong><?= $free ?></strong> → brakuje <strong><?= max(0, $need - $free) ?></strong>.</p>
      <div class="grid gap-2 md:grid-cols-4 items-end">
        <div><label class="lbl" for="gg">Seria</label><select id="gg" name="gen_grp" class="inp" x-model="g" @change="s = last[g][0]; n = last[g][1]"><?php foreach (pp_series() as $k => $se): ?><option value="<?= h($k) ?>"><?= h($se['label']) ?></option><?php endforeach; ?></select></div>
        <div><label class="lbl" for="gs">Numer kontrahenta (12 cyfr)</label><input id="gs" name="gen_start" class="inp font-mono" inputmode="numeric" maxlength="12" x-model="s"></div>
        <div><label class="lbl" for="gc">Liczba następnych numerów</label><input id="gc" name="gen_count" type="number" min="0" max="9999" class="inp" x-model="n"></div>
        <div><button class="bp">Generuj</button></div>
      </div>
      <?php if ($genres): $gl = $genres['list']; $cnt = count($gl); ?>
      <div class="text-sm" aria-live="polite">Wygenerowano <strong><?= $cnt ?></strong> numerów, od <span class="font-mono"><?= h(pp_nrb_format($gl[0])) ?></span> do <span class="font-mono"><?= h(pp_nrb_format($gl[$cnt - 1])) ?></span>. Kliknij w pole, aby zaznaczyć całość.</div>
      <textarea readonly rows="8" class="inp font-mono text-xs" aria-label="Wygenerowane numery" onclick="this.select()"><?= h(implode("\n", array_map('pp_nrb_format', $gl))) ?></textarea>
      <?php endif; ?>
    </form>
    </div>
    <div x-show="sub === 'import'" class="space-y-3">
    <?php $pool = db_all("SELECT grp, COUNT(*) n, SUM(participant_id IS NULL AND crm_contact_id IS NULL) free FROM pp_vnrb_pool GROUP BY grp"); ?>
    <form method="post" class="rounded-lg border border-slate-200 p-3 space-y-2">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="pool_import">
      <h3 class="font-semibold text-sm">Krok 2: import listy wygenerowanej przez bank (TXT)</h3>
      <p class="text-sm text-amber-800">Zamów w banku numery wg generatora powyżej (numer kontrahenta + liczba następnych), a gdy bank je wygeneruje, wklej tu listę — SZO rozpozna serię i nada numery.</p>
      <p class="text-xs text-slate-500">Jeden numer w linii (26 cyfr, „PL" i spacje dozwolone). Sprawdzamy sumę kontrolną i duplikaty; numery trafiają do puli danej grupy.</p>
      <div class="grid gap-2 md:grid-cols-4">
        <div><label class="lbl" for="pl-g">Grupa</label><select id="pl-g" name="pool_grp" class="inp"><option value="auto">Wykryj po numerze (zalecane)</option><option value="ti">TI (kursanci)</option><option value="inni">Kontrahenci inni</option><option value="spoza_ti">Uczestnicy spoza TI</option><option value="reczny">Ręczne</option></select></div>
      </div>
      <label class="lbl" for="pl-t">Wklej listę numerów (jeden w linii)</label><textarea id="pl-t" name="pool_text" rows="10" required class="inp font-mono" placeholder="76102029063286111100000001"></textarea>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pool_assign" value="1" checked> Po wczytaniu od razu przypisz numery serii TI kursantom bez rachunku</label>
      <div class="flex flex-wrap items-center gap-3"><button class="bp">Wczytaj do puli</button>
        <span class="text-xs text-slate-500">W puli: <?php foreach ($pool as $pl): ?><?= h($pl['grp']) ?> <?= (int)$pl['n'] ?> (wolnych <?= (int)$pl['free'] ?>) · <?php endforeach; if (!$pool) echo 'pusta'; ?></span></div>
    </form>
    <?php $lastb = (string)org_setting('pp_pool_last_batch');
      $lrows = $lastb !== '' ? db_all("SELECT p.nrb, p.grp, p.participant_id, p.assigned_at, c.name FROM pp_vnrb_pool p LEFT JOIN k30_clients c ON c.id=p.participant_id WHERE p.batch=? ORDER BY substr(p.nrb,15,12)", [$lastb]) : [];
      $lfree = count(array_filter($lrows, fn($r) => !$r['participant_id'])); ?>
    <form method="post" class="flex flex-wrap items-center gap-3" onsubmit="return confirm('Przypisać numery z ostatniego importu kursantom TI bez rachunku (od pierwszego z puli)?')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="pool_assign_last">
      <button class="bp"<?= $lfree ? '' : ' disabled' ?>>Przypisz ostatnio zaimportowane numery kursantom bez rachunku</button>
      <span class="text-xs text-slate-500">Z ostatniego importu wolnych: <?= $lfree ?> z <?= count($lrows) ?></span>
      <a class="bs" href="admin.php?report=print&scope=last" target="_blank" rel="noopener">Drukuj raport (ostatni import)</a>
      <a class="bs" href="admin.php?report=print&scope=all" target="_blank" rel="noopener">Drukuj raport (wszyscy kursanci)</a></form>
    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-3">
      <span class="text-sm font-semibold"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF „nadano numer rachunku”</span>
      <a class="bs" href="admin.php?pdf=notice&scope=last" target="_blank" rel="noopener">Ostatni import</a>
      <a class="bs" href="admin.php?pdf=notice&scope=unnotified" target="_blank" rel="noopener">Bez powiadomienia</a>
      <a class="bs" href="admin.php?pdf=notice&scope=all" target="_blank" rel="noopener">Wszyscy z numerem</a>
      <span class="text-xs text-slate-500">Jedna strona na kursanta: numer, informacja, że wpłaca tu wszystkie należności z tytułu szkoleń.</span>
    </div>
    <?php $unn = (int)(db_one("SELECT COUNT(*) c FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NOT NULL AND notified_at IS NULL")['c'] ?? 0); ?>
    <div class="grid gap-3 md:grid-cols-3">
      <div class="rounded-lg border border-slate-200 p-3 space-y-2">
        <div class="text-sm font-semibold">Powiadom kursantów TI</div>
        <div class="text-xs text-slate-500">Bez powiadomienia: <strong><?= $unn ?></strong>. Treść zatwierdza admin, wysyłka rusza o 08:00 następnego dnia — sekcja poniżej.</div>
        <a class="bs" href="#powiadomienia">Przejdź do powiadomień</a></div>
      <form method="post" class="rounded-lg border border-slate-200 p-3 space-y-2"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="pool_assign_crm">
        <div class="text-sm font-semibold">Kontrahenci CRM (seria „inni")</div>
        <div class="text-xs text-slate-500">Przypisuje wolne numery serii „inni" aktywnym kontaktom CRM bez numeru.</div>
        <button class="bs">Przypisz kontrahentom</button></form>
      <form method="post" class="rounded-lg border border-slate-200 p-3 space-y-2"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="pool_assign_other">
        <div class="text-sm font-semibold">Uczestnicy spoza TI</div>
        <div class="text-xs text-slate-500">Przypisuje numery serii „spoza TI" uczestnikom z dostępem do portalu, bez konta TI.</div>
        <button class="bs">Przypisz uczestnikom</button></form>
    </div>
    <section id="powiadomienia" class="rounded-lg border border-slate-200 p-3 space-y-3" aria-labelledby="pn-h">
      <?php $pn = pp_notice_default(); $pn_list = db_all("SELECT * FROM pp_notice_batches ORDER BY id DESC LIMIT 6");
            $pn_edit = (int)($_GET['pn'] ?? 0) ? db_one("SELECT * FROM pp_notice_batches WHERE id=? AND status='draft'", [(int)$_GET['pn']]) : null; ?>
      <h3 id="pn-h" class="font-semibold text-sm"><i class="bi bi-send-check" aria-hidden="true"></i> Powiadomienia o numerze rachunku — zatwierdzanie i wysyłka</h3>
      <p class="text-xs text-slate-500">Przygotuj treść (znaczniki: <code>{imie_nazwisko}</code> <code>{numer}</code> <code>{numer_cyfry}</code> <code>{tytul}</code> <code>{organizacja}</code>), zapisz szkic, sprawdź podgląd i <strong>zatwierdź</strong>. Zatwierdzona wiadomość (e-mail i SMS) wyśle się automatycznie o <strong>08:00 następnego dnia</strong>. Wirtualni kursanci są pomijani.</p>
      <?php foreach ($pn_list as $b): $rc = count(pp_notice_recipients($b['scope'], $b['batch'], (int)$b['id'])); $pv = null; foreach (pp_notice_recipients($b['scope'], $b['batch'], (int)$b['id']) as $c0) { $pv = pp_notice_vars($c0); if ($pv) break; } ?>
      <div class="rounded-lg bg-slate-50 p-3 text-sm">
        <div class="flex flex-wrap items-center gap-2"><strong>#<?= (int)$b['id'] ?></strong> <span class="rounded-full bg-white px-2 py-0.5 text-xs ring-1 ring-slate-200"><?= h(PP_NOTICE_STATUSES[$b['status']] ?? $b['status']) ?></span>
          <span class="text-xs text-slate-500"><?= $b['scope'] === 'last' ? 'ostatni import' : 'wszyscy bez powiadomienia' ?> · odbiorców teraz: <?= $rc ?><?= $b['send_at'] ? ' · wysyłka: ' . h(date('d.m.Y H:i', strtotime($b['send_at']))) : '' ?><?= $b['approved_by'] ? ' · zatwierdził: ' . h($b['approved_by']) : '' ?></span>
          <span class="ml-auto flex gap-2">
            <?php if ($b['status'] === 'draft'): ?>
            <a class="bs" href="admin.php?pn=<?= (int)$b['id'] ?>#powiadomienia">Edytuj</a>
            <form method="post" onsubmit="return confirm('Zatwierdzić treść? Wiadomości wyślą się o 08:00 następnego dnia (<?= $rc ?> odbiorców).')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="notice_approve"><input type="hidden" name="notice_id" value="<?= (int)$b['id'] ?>"><button class="bp">Zatwierdź treść</button></form>
            <?php endif; ?>
            <?php if (in_array($b['status'], ['draft', 'approved'], true)): ?>
            <form method="post" onsubmit="return confirm('Anulować tę wysyłkę?')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="notice_cancel"><input type="hidden" name="notice_id" value="<?= (int)$b['id'] ?>"><button class="bs">Anuluj</button></form>
            <?php endif; ?></span></div>
        <?php if ($pv && in_array($b['status'], ['draft', 'approved'], true)): ?>
        <details class="mt-2"><summary class="cursor-pointer text-xs text-navy-700">Podgląd (dla: <?= h($pv['name']) ?>)</summary>
          <div class="mt-2 rounded border border-slate-200 bg-white p-3 text-sm"><div class="mb-1 text-xs text-slate-500">Temat: <?= h(pp_notice_render($b['subject'], $pv)) ?></div><div><?= pp_notice_render($b['body'], $pv, true) ?></div>
            <?php if (trim($b['sms_text']) !== ''): ?><div class="mt-2 border-t pt-2 text-xs text-slate-600">SMS: <?= h(pp_notice_render($b['sms_text'], $pv)) ?></div><?php endif; ?></div></details>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <form method="post" class="space-y-2 rounded-lg border border-slate-200 p-3"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="notice_save"><input type="hidden" name="notice_id" value="<?= (int)($pn_edit['id'] ?? 0) ?>">
        <div class="text-sm font-semibold"><?= $pn_edit ? 'Edycja szkicu #' . (int)$pn_edit['id'] : 'Nowa wysyłka (szkic)' ?></div>
        <div class="flex flex-wrap items-center gap-2 text-xs" x-data='{ tpl: <?= h(json_encode(pp_notice_templates(), JSON_UNESCAPED_UNICODE)) ?> }'>
          <span class="text-slate-500">Wybierz wersję treści (podmienia pola poniżej):</span>
          <?php foreach (pp_notice_templates() as $tk => $tt): ?>
          <button type="button" class="bs" @click="if (confirm('Podmienić temat, treść e-maila i SMS na: <?= h(addslashes($tt['label'])) ?>?')) { document.getElementById('pn-su').value = tpl['<?= $tk ?>'].subject; document.getElementById('pn-bo').value = tpl['<?= $tk ?>'].body; document.getElementById('pn-sm').value = tpl['<?= $tk ?>'].sms; }"><?= h($tt['label']) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="grid gap-2 md:grid-cols-3">
          <div><label class="lbl" for="pn-sc">Odbiorcy</label><select id="pn-sc" name="scope" class="inp"><option value="unnotified"<?= ($pn_edit['scope'] ?? '') !== 'last' ? ' selected' : '' ?>>Wszyscy z numerem, bez powiadomienia</option><option value="last"<?= ($pn_edit['scope'] ?? '') === 'last' ? ' selected' : '' ?>>Tylko z ostatniego importu</option></select></div>
          <div class="md:col-span-2"><label class="lbl" for="pn-su">Temat e-maila</label><input id="pn-su" name="subject" class="inp" maxlength="200" required value="<?= h($pn_edit['subject'] ?? $pn['subject']) ?>"></div>
        </div>
        <div><label class="lbl" for="pn-bo">Treść e-maila</label><textarea id="pn-bo" name="body" rows="9" class="inp" required><?= h($pn_edit['body'] ?? $pn['body']) ?></textarea></div>
        <div><label class="lbl" for="pn-sm">Treść SMS (puste = bez SMS; bez polskich znaków jest bezpieczniej)</label><textarea id="pn-sm" name="sms_text" rows="2" class="inp" maxlength="320"><?= h($pn_edit['sms_text'] ?? $pn['sms']) ?></textarea></div>
        <button class="bp">Zapisz szkic</button>
      </form>
    </section>
    <?php if ($lrows): ?>
    <div class="overflow-x-auto"><table class="w-full text-sm"><caption class="text-left font-semibold text-sm mb-1">Ostatni import — przypisania</caption>
      <thead><tr class="text-left"><th>Lp.</th><th>Rachunek</th><th>Seria</th><th>Kursant</th><th>Przypisano</th></tr></thead><tbody>
      <?php foreach ($lrows as $i => $lr): ?>
      <tr class="border-t"><td><?= $i + 1 ?></td><td class="font-mono"><?= h(pp_nrb_format($lr['nrb'])) ?></td><td><?= h($lr['grp']) ?></td>
        <td><?= $lr['participant_id'] ? '<a class="underline" href="admin.php?q=' . urlencode((string)($lr['name'] ?? '')) . '">' . h($lr['name'] ?? ('#' . $lr['participant_id'])) . '</a>' : '<span class="text-slate-500">wolny</span>' ?></td>
        <td class="text-xs"><?= h((string)$lr['assigned_at']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('Przypisać wolne rachunki z puli TI kursantom bez rachunku?')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="pool_assign_ti">
      <button class="bs">Przypisz wolne rachunki z puli kursantom TI bez numeru</button></form>
    </div>
  </section>
  </div>

  <div x-show="tab === 'przelewy'" x-cloak>
  <!-- Transakcje -->
  <section class="card overflow-x-auto" aria-labelledby="t-h">
    <h2 id="t-h" class="font-semibold mb-2">Ostatnie transakcje</h2>
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-2">Kiedy</th><th class="pr-2">Uczestnik</th><th class="pr-2">Metoda</th><th class="pr-2">Identyfikator</th><th class="pr-2 text-right">Kwota</th><th class="pr-2">Status</th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($txs as $t): ?>
        <tr><td class="py-1.5 pr-2 text-xs whitespace-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$t['created_at']))) ?></td><td class="pr-2"><a class="text-navy-700 hover:underline" href="admin.php?p=<?= (int)$t['participant_id'] ?>"><?= h($t['name']) ?></a></td>
          <td class="pr-2 text-xs"><?= $t['payment_method'] === 'p24' ? 'Przelewy24' : 'NRB' ?></td><td class="pr-2 font-mono text-xs"><?= h($t['transfer_title']) ?></td>
          <td class="pr-2 text-right tabular-nums"><?= h(pp_fmt((float)$t['total_amount'])) ?></td><td class="pr-2 text-xs"><?= h(PP_TX_STATUS[$t['status']] ?? $t['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$txs): ?><tr><td colspan="6" class="py-4 text-center text-slate-500">Brak transakcji.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
  </div>
</main>
</body>
</html>
