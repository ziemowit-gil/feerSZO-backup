<?php
/**
 * karty30/ti/dydaktyk/pricing.php — Cenniki i rabaty zależne od typu zajęć (kierownik TI).
 *
 * Zakładki (Alpine): Symulator (kalkulacja na żywo, statusy uczestnika, „Zastosuj
 * do zapisu”), Typy zajęć, Reguły rabatowe (kreator z polami zależnymi od warunku),
 * Grupy i zapisy (typ zajęć grupy, cena z cennika vs stawka zapisu, zastosowanie /
 * nadpisanie z uzasadnieniem), Historia (calculated_prices_log + audyt pricing.*).
 * Tailwind (CDN) + Alpine.js; klasy przełączane przez :class są w <style type="text/tailwindcss">
 * (Tailwind CDN nie kompiluje klas dodanych po starcie strony).
 * Logika: modules/ti_pricing/logic/pricingEngine.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_pricing/logic/pricingEngine.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_overpayments/logic/overpayments.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }
ti_pricing_migrate();
ti_course_close_migrate();   // kolumna closed_at (zamykanie grup)
$by  = (string)($me['name'] ?? '');
$uid = (int)($me['user_id'] ?? 0) ?: null;

// ── JSON: kalkulacja (tylko odczyt — symulator) ─────────────────────────────
if (($_GET['json'] ?? '') === 'calc') {
    header('Content-Type: application/json; charset=utf-8');
    $ov = [];
    if (isset($_GET['ov']) && $_GET['ov'] === '1') {
        $ov['statuses']   = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['statuses'] ?? '')))));
        $ov['groups']     = max(1, min(99, (int)($_GET['groups'] ?? 1)));
        $ov['credit']     = max(0, (float)str_replace(',', '.', (string)($_GET['credit'] ?? '0')));
        $ov['start_date'] = ti_pricing_date($_GET['start_date'] ?? '');
    }
    echo json_encode(TiPricingEngine::calculate([
        'lesson_type_id' => (int)($_GET['type'] ?? 0), 'client_id' => (int)($_GET['client'] ?? 0) ?: null,
        'course_id' => (int)($_GET['course'] ?? 0) ?: null, 'date' => (string)($_GET['date'] ?? ''), 'profile' => $ov,
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Operacje (POST, PRG) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    $back = (string)($_POST['_tab'] ?? 'sym');
    $res = null; $ok = null;
    switch ($op) {
        case 'op_delete':
            $er = ti_op_delete((int)($_POST['op_id'] ?? 0), (string)($_POST['reason'] ?? ''), $by, $uid);
            if ($er !== null) { $res = $er; break; }
            $res = 0; $ok = 'Nadpłata usunięta (ślad w dzienniku audytu).'; break;
        case 'op_refund':
            $oid = (int)($_POST['op_id'] ?? 0);
            $orow = db_one("SELECT * FROM overpayment_transactions WHERE id=?", [$oid]);
            if (!$orow) { $res = 'Nie znaleziono nadpłaty.'; break; }
            $gr = trim((string)($_POST['amount'] ?? '')) === '' ? ti_op_gr($orow['amount']) : ti_op_parse_amount((string)$_POST['amount']);
            if ($gr === null) { $res = 'Kwota zwrotu: liczba, np. 120,50.'; break; }
            $x = ti_op_refund($oid, $gr, (string)($_POST['account'] ?? ''), (string)($_POST['title'] ?? ''), '', $by, $uid);
            if (is_string($x)) { $res = $x; break; }
            $ed = db_one("SELECT edok_doc_id FROM overpayment_transactions WHERE id=?", [$x]);
            $res = 0; $ok = 'Zwrot zarejestrowany' . (!empty($ed['edok_doc_id']) ? ' i przekazany do obiegu akceptacji EODoK (dokument #' . (int)$ed['edok_doc_id'] . ').' : '. Dokument w EODoK nie powstał — sprawdź audyt.'); break;
        case 'settle_issue':
        case 'settle_issue_all':
            $mm = preg_match('/^(\d{4})-(\d{2})$/', (string)($_POST['_m'] ?? ''), $mx) ? [(int)$mx[1], max(1, min(12, (int)$mx[2]))] : [(int)date('Y'), (int)date('n')];
            [$by_y, $by_m] = $mm;
            $clients = $op === 'settle_issue' ? [(int)($_POST['client_id'] ?? 0)]
                : array_map(fn($r) => (int)$r['client_id'], db_all(
                    "SELECT DISTINCT e.client_id FROM k30_ti_attendance a
                       JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
                       JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=a.client_id
                      WHERE a.attended=1 AND strftime('%m',s.lesson_date)=? AND strftime('%Y',s.lesson_date)=?", [sprintf('%02d', $by_m), (string)$by_y]));
            $cnt = 0; $sms = 0; $eml = 0;
            foreach (array_filter($clients) as $cl) {
                $bids = array_filter(k30_ti_issue_billing_split($cl, $by_m, $by_y, ''));
                ti_billing_recompute($cl);
                $cnt += count($bids);
                if (!empty($_POST['notify'])) foreach ($bids as $bid) { $n = k30_ti_billing_notify($bid); $sms += !empty($n['sms']) ? 1 : 0; $eml += !empty($n['email']) ? 1 : 0; }
            }
            audit_log('pricing.settle_issue', ['month' => sprintf('%04d-%02d', $by_y, $by_m), 'clients' => count(array_filter($clients)), 'billings' => $cnt, 'notify' => !empty($_POST['notify']), 'by' => $by], $uid);
            $res = 0; $ok = "Wystawiono rozliczeń: {$cnt} (kursantów: " . count(array_filter($clients)) . ') za ' . sprintf('%02d.%04d', $by_m, $by_y) . (!empty($_POST['notify']) ? ". Powiadomienia: SMS {$sms}, e-mail {$eml}." : '. Bez wysyłki powiadomień.'); break;
        case 'settle_payment':
            $cl = (int)($_POST['client_id'] ?? 0);
            $amt = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
            $pc = (int)($_POST['pay_course_id'] ?? 0);
            if ($pc > 0 && !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [$cl, $pc])) $pc = 0;
            if (!$cl || $amt <= 0) { $res = 'Podaj kursanta i kwotę wpłaty.'; break; }
            $pm = in_array($_POST['method'] ?? '', ['transfer', 'cash', 'other'], true) ? $_POST['method'] : 'transfer';
            $rp = ti_payment_add($cl, $amt, trim((string)($_POST['paid_at'] ?? '')), $pm, trim((string)($_POST['note'] ?? '')), 'manual', 0, $pc);
            audit_log('pricing.settle_payment', ['client_id' => $cl, 'amount' => $amt, 'course_id' => $pc, 'by' => $by], $uid);
            $res = 0; $ok = 'Wpłata ' . number_format($amt, 2, ',', ' ') . ' zł zapisana' . ($pc ? ' na grupę' : ' (ogólna)') . '.' . (($rp['credit'] ?? 0) > 0 ? ' Nadpłata: ' . number_format($rp['credit'], 2, ',', ' ') . ' zł.' : ''); break;
        case 'bulk_types':
            $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
            $t1 = (int)($_POST['bt_type'] ?? 0) ?: null; $t2 = (int)($_POST['bt_online'] ?? 0) ?: null;
            if (!$ids) { $res = 'Zaznacz grupy.'; break; }
            if (!$t1 && !$t2) { $res = 'Wybierz typ stacjonarny i/lub online.'; break; }
            $n = 0; $errs = [];
            foreach ($ids as $cid) {
                $cur = ti_pricing_course_types($cid);
                $e = ti_pricing_set_course_types($cid, $t1 ?: ($cur['lesson_type_id'] ?: null), $t2 ?: ($cur['online_lesson_type_id'] ?: null), $by, $uid);
                if ($e) $errs[$e] = true; else $n++;
            }
            $res = 0; $ok = "Typy zajęć przypisane w {$n} grupach" . ($errs ? '. Uwagi: ' . implode(' ', array_keys($errs)) : '') . '.'; break;
        case 'bulk_rate':
            $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
            if (!$ids) { $res = 'Zaznacz grupy.'; break; }
            $ovr = [];
            foreach (['stacjonarna' => 'br_stac', 'online' => 'br_online'] as $m => $fld) {
                $raw = trim((string)($_POST[$fld] ?? ''));
                if ($raw !== '') { $v = ti_pricing_num($raw); if ($v === null) { $res = 'Cena musi być liczbą ≥ 0.'; break 2; } $ovr[$m] = $v; }
            }
            if (!$ovr) { $res = 'Podaj cenę stacjonarną i/lub online.'; break; }
            $okc = 0; $errs = [];
            foreach ($ids as $cid) foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid]) as $e) {
                $r = ti_pricing_apply_to_enrollment($cid, (int)$e['client_id'], $by, $uid, $ovr, (string)($_POST['reason'] ?? ''));
                if ($r['ok']) $okc++; else { $errs[$r['msg']] = true; if (str_contains($r['msg'], 'uzasadnienia')) break 2; }
            }
            if (!$okc && $errs) { $res = implode(' ', array_keys($errs)); break; }
            $res = 0; $ok = "Stawka ustawiona w zaznaczonych grupach: zapisów zaktualizowano {$okc}" . ($errs ? '. Uwagi: ' . implode(' ', array_keys($errs)) : '') . '.'; break;
        case 'open_groups':
            $opened = 0;
            foreach (array_filter(array_map('intval', (array)($_POST['ids'] ?? []))) as $cid) {
                $g = db_one("SELECT id, name, status, closed_at FROM k30_ti_courses WHERE id=?", [$cid]);
                if (!$g || ($g['status'] !== 'archived' && empty($g['closed_at']))) continue;
                ti_course_reopen($cid);
                db()->prepare("UPDATE k30_ti_courses SET status='active', is_active=1 WHERE id=?")->execute([$cid]);
                ti_course_log($cid, 'restore', '', (int)($me['user_id'] ?? 0), $by);
                audit_log('pricing.group_opened', ['course_id' => $cid, 'name' => $g['name'], 'by' => $by], $uid);
                $opened++;
            }
            $res = 0; $ok = $opened ? "Uruchomiono grup: {$opened} (przywrócone z archiwum, odblokowane)." : 'Nie zaznaczono żadnej zamkniętej grupy.'; break;
        case 'close_group':
        case 'close_candidates':
            $ids = $op === 'close_group' ? [(int)($_POST['course_id'] ?? 0)] : array_map('intval', (array)($_POST['ids'] ?? []));
            $closed = 0; $skip = [];
            foreach (array_filter($ids) as $cid) {
                $g = db_one("SELECT c.id, c.name, c.closed_at,
                                    (SELECT COUNT(*) FROM k30_ti_sessions s WHERE s.course_id=c.id AND s.lesson_date>=date('now') AND s.status!='cancelled') AS fut
                               FROM k30_ti_courses c WHERE c.id=?", [$cid]);
                if (!$g) continue;
                if (!empty($g['closed_at'])) { $skip[] = $g['name'] . ' (już zamknięta)'; continue; }
                if ((int)$g['fut'] > 0) { $skip[] = $g['name'] . ' (ma ' . (int)$g['fut'] . ' przyszłych lekcji)'; continue; }
                ti_course_close($cid, (int)($me['user_id'] ?? 0), $by);
                audit_log('pricing.group_closed', ['course_id' => $cid, 'name' => $g['name'], 'by' => $by], $uid);
                $closed++;
            }
            $res = 0; $ok = "Zamknięto grup: {$closed}" . ($skip ? '. Pominięto: ' . implode('; ', $skip) : '') . '. Zamknięta grupa jest zablokowana — odblokowuje ją „Przywróć z archiwum” w panelu grup.'; break;
        case 'sync_types':
            $x = ti_pricing_sync_from_subject_types($by, $uid);
            $ok = "Pobrano z rodzajów zajęć TI: nowe typy {$x['created']}, odświeżone {$x['updated']}, ceny ustawione {$x['priced']}, grupy przypięte {$x['linked']}."; break;
        case 'save_type':  $res = ti_pricing_save_type($_POST, $by, $uid); $ok = 'Typ zajęć zapisany.'; break;
        case 'save_rule':  $res = ti_pricing_save_rule($_POST, $by, $uid); $ok = 'Reguła zapisana.'; break;
        case 'delete_rule': $res = ti_pricing_delete_rule((int)($_POST['id'] ?? 0), $by, $uid) ?? 0; $ok = 'Reguła usunięta.'; break;
        case 'course_types':
            $res = ti_pricing_set_course_types((int)($_POST['course_id'] ?? 0), (int)($_POST['lesson_type_id'] ?? 0) ?: null,
                                               (int)($_POST['online_lesson_type_id'] ?? 0) ?: null, $by, $uid) ?? 0;
            $ok = 'Typy zajęć grupy zapisane.'; break;
        case 'statuses':
            $res = ti_pricing_set_participant_status((int)($_POST['client_id'] ?? 0), (array)($_POST['statuses'] ?? []), $by, $uid) ?? 0;
            $ok = 'Statusy uczestnika zapisane.'; break;
        case 'group_rate':
            $cid = (int)($_POST['course_id'] ?? 0); $ovr = [];
            foreach (['stacjonarna', 'online'] as $m) {
                $raw = trim((string)($_POST['g_' . $m] ?? ''));
                if ($raw !== '') { $v = ti_pricing_num($raw); if ($v === null) { $res = 'Cena musi być liczbą ≥ 0.'; break 2; } $ovr[$m] = $v; }
            }
            if (!$ovr) { $res = 'Podaj cenę stacjonarną i/lub online.'; break; }
            $okc = 0; $errs = [];
            foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid]) as $e) {
                $r = ti_pricing_apply_to_enrollment($cid, (int)$e['client_id'], $by, $uid, $ovr, (string)($_POST['reason'] ?? ''));
                if ($r['ok']) $okc++; else { $errs[$r['msg']] = true; if (str_contains($r['msg'], 'uzasadnienia') || str_contains($r['msg'], 'typu zajęć') || str_contains($r['msg'], 'zamkni')) break; }
            }
            if (!$okc && $errs) { $res = implode(' ', array_keys($errs)); break; }
            $res = 0; $ok = "Stawka ustawiona dla grupy: zapisów zaktualizowano {$okc}" . ($errs ? '; uwagi: ' . implode(' ', array_keys($errs)) : '') . '.'; break;
        case 'apply':
            $ovr = [];
            foreach (['stacjonarna', 'online'] as $m) {
                $raw = trim((string)($_POST['override_' . $m] ?? ''));
                if ($raw !== '') { $v = ti_pricing_num($raw); if ($v === null) { $res = 'Nadpisana cena musi być liczbą ≥ 0.'; break 2; } $ovr[$m] = $v; }
            }
            $r = ti_pricing_apply_to_enrollment((int)($_POST['course_id'] ?? 0), (int)($_POST['client_id'] ?? 0), $by, $uid, $ovr, (string)($_POST['reason'] ?? ''));
            $res = $r['ok'] ? 0 : $r['msg']; $ok = $r['msg']; break;
    }
    if (is_string($res)) flash_set('danger', $res); elseif ($ok) flash_set('success', $ok);
    header('Location: pricing.php?tab=' . urlencode($back) . (!empty($_POST['_course']) ? '&course=' . (int)$_POST['_course'] : '') . (preg_match('/^\d{4}-\d{2}$/', (string)($_POST['_m'] ?? '')) ? '&m=' . $_POST['_m'] : '')); exit;
}

// ── Dane widoku ─────────────────────────────────────────────────────────────
$flash   = flash_get();
// Pierwsze wejście (brak typów): automatycznie pobieramy rodzaje zajęć TI
try { if (!(int)(db_one("SELECT COUNT(*) c FROM lesson_types")['c'] ?? 0)) ti_pricing_sync_from_subject_types($by, $uid); } catch (\Throwable $e) {}
$types   = db_all("SELECT * FROM lesson_types ORDER BY is_active DESC, name COLLATE NOCASE");
$rules   = db_all("SELECT r.*, t.name AS type_name FROM discount_rules r LEFT JOIN lesson_types t ON t.id=r.target_lesson_type_id ORDER BY r.is_active DESC, r.priority, r.id");
$courses = db_all("SELECT c.id, c.name, c.group_code, c.is_online, c.closed_at, pct.lesson_type_id, pct.online_lesson_type_id,
                          (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS n,
                          (SELECT MIN(e.hourly_rate) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS r_min,
                          (SELECT MAX(e.hourly_rate) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS r_max,
                          (SELECT MIN(e.hourly_rate_online) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS ro_min,
                          (SELECT MAX(e.hourly_rate_online) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS ro_max,
                          (SELECT COUNT(*) FROM k30_ti_sessions s WHERE s.course_id=c.id AND s.lesson_date>=date('now') AND s.status!='cancelled') AS fut,
                          (SELECT MAX(s.lesson_date) FROM k30_ti_sessions s WHERE s.course_id=c.id) AS last_lesson
                     FROM k30_ti_courses c LEFT JOIN ti_pricing_course_types pct ON pct.course_id=c.id
                    WHERE c.status NOT IN ('archived','cancelled') ORDER BY c.name COLLATE NOCASE");
$closed_groups = db_all("SELECT c.id, c.name, c.group_code, c.status, c.closed_at, c.closed_name FROM k30_ti_courses c
                          WHERE (c.status='archived' OR c.closed_at IS NOT NULL) AND c.status!='cancelled' ORDER BY c.name COLLATE NOCASE");
$wyg = array_values(array_filter($courses, fn($c) => empty($c['closed_at'] ?? null) && (int)$c['fut'] === 0 && ($c['last_lesson'] === null || $c['last_lesson'] < date('Y-m-d', strtotime('-14 days')))));
$parts   = db_all("SELECT cl.id, cl.name, COALESCE(ps.statuses,'') AS statuses FROM k30_clients cl
                     JOIN k30_ti_student_accounts a ON a.client_id=cl.id LEFT JOIN ti_pricing_participant_status ps ON ps.participant_id=cl.id
                    WHERE a.is_active=1 ORDER BY cl.name COLLATE NOCASE");
$enr_by_course = [];
foreach (db_all("SELECT course_id, client_id FROM k30_ti_enrollments WHERE status='active'") as $e) $enr_by_course[(int)$e['course_id']][] = (int)$e['client_id'];

// Grupy i zapisy: szczegóły jednej grupy (cena z cennika vs stawka zapisu)
$sel_course = (int)($_GET['course'] ?? 0);
$sel_rows = [];
if ($sel_course) {
    $ct = ti_pricing_course_types($sel_course);
    foreach (k30_ti_enrollments($sel_course) as $e) {
        if ($e['status'] !== 'active') continue;
        $row = ['client_id' => (int)$e['client_id'], 'name' => $e['client_name'], 'rate' => (float)$e['hourly_rate'], 'rate_on' => (float)($e['hourly_rate_online'] ?? 0),
                'calc' => null, 'calc_on' => null];
        if ($ct['lesson_type_id']) $row['calc'] = TiPricingEngine::calculate(['lesson_type_id' => (int)$ct['lesson_type_id'], 'client_id' => (int)$e['client_id'], 'course_id' => $sel_course]);
        if ($ct['online_lesson_type_id']) $row['calc_on'] = TiPricingEngine::calculate(['lesson_type_id' => (int)$ct['online_lesson_type_id'], 'client_id' => (int)$e['client_id'], 'course_id' => $sel_course]);
        $sel_rows[] = $row;
    }
}
$log = db_all("SELECT l.*, cl.name AS participant_name, t.name AS type_name, c.name AS course_name FROM calculated_prices_log l
                LEFT JOIN k30_clients cl ON cl.id=l.participant_id LEFT JOIN lesson_types t ON t.id=l.lesson_type_id
                LEFT JOIN k30_ti_courses c ON c.id=l.course_id ORDER BY l.id DESC LIMIT 200");
$audit = db_all("SELECT * FROM audit_logs WHERE action LIKE 'pricing.%' ORDER BY id DESC LIMIT 100");
$tab   = in_array($_GET['tab'] ?? '', ['sym', 'types', 'rules', 'groups', 'settle', 'log'], true) ? $_GET['tab'] : 'sym';

// Rozliczenia: należności i wpłaty per grupa, dłużnicy, nadpłaty (tylko na tej zakładce)
$st_groups = []; $st_tot = ['charges' => 0.0, 'paid' => 0.0, 'debt' => 0.0, 'credit' => 0.0]; $st_debtors = []; $st_op = []; $st_opsum = null;
$st_ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
[$st_y, $st_m] = array_map('intval', explode('-', $st_ym));
if ($tab === 'settle') {
    ti_op_migrate();
    foreach ($courses as $c) {
        $sm = ti_course_billing_summary((int)$c['id'], $st_y, $st_m);
        if (!$sm['participants']) continue;
        $st_groups[] = ['c' => $c, 't' => $sm['totals'], 'p' => $sm['participants']];
        foreach (['charges', 'paid', 'debt', 'credit'] as $k) $st_tot[$k] = round($st_tot[$k] + $sm['totals'][$k], 2);
    }
    $st_debtors = array_slice(ti_clients_with_debt(), 0, 50);
    $st_op = array_values(array_filter(ti_op_list(), fn($o) => $o['status'] === 'available'));
    $st_opsum = ti_op_summary();
}
// ── Wydruk: grupy ze stawkami i rozliczeniami (?print=groups[&course=ID]) ──────────────
if (($_GET['print'] ?? '') === 'groups') {
    $only = (int)($_GET['course'] ?? 0);
    $fm = fn($v) => number_format((float)$v, 2, ',', ' ');
    $typeName = []; foreach ($types as $t) $typeName[(int)$t['id']] = $t['name'];
    header('Content-Type: text/html; charset=utf-8');
    audit_log('pricing.print_groups', ['course' => $only, 'by' => $by], $uid);
    ?><!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Grupy — stawki i rozliczenia</title>
<style>
  body{font:12px/1.4 Arial,sans-serif;color:#000;margin:16px} h1{font-size:16px;margin:0 0 2px} .meta{color:#444;margin-bottom:12px}
  h2{font-size:13px;margin:14px 0 4px;padding-bottom:2px;border-bottom:2px solid #000} h2 small{font-weight:400;color:#444}
  table{border-collapse:collapse;width:100%;margin-bottom:6px} th,td{border:1px solid #999;padding:3px 6px;text-align:left} thead th{background:#eee}
  td.r,th.r{text-align:right;font-variant-numeric:tabular-nums} .neg{font-weight:700} .group{break-inside:avoid}
  .pbtn{margin-bottom:10px;padding:6px 12px;font-size:13px} @media print{.pbtn{display:none}}
</style></head><body>
<button class="pbtn" onclick="window.print()">Drukuj</button>
<h1>Grupy — stawki i rozliczenia</h1>
<div class="meta"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> · wydruk: <?= date('d.m.Y H:i') ?> · wystawił: <?= h($by) ?></div>
<?php foreach ($courses as $c):
    if ($only && (int)$c['id'] !== $only) continue;
    $enr = array_filter(k30_ti_enrollments((int)$c['id']), fn($e) => $e['status'] === 'active');
    if (!$enr && !$only) continue; ?>
<section class="group">
  <h2><?= trim((string)$c['group_code']) !== '' ? h($c['group_code']) . ' · ' : '' ?><?= h($c['name']) ?> <small>
    typ: <?= h($typeName[(int)$c['lesson_type_id']] ?? '—') ?><?= $c['online_lesson_type_id'] ? ' / online: ' . h($typeName[(int)$c['online_lesson_type_id']] ?? '—') : '' ?>
    · <?= $c['is_online'] ? 'online' : 'stacjonarnie' ?> · uczestników: <?= count($enr) ?></small></h2>
  <table><thead><tr><th>Uczestnik</th><th class="r">Stawka zł/h</th><th class="r">Stawka online</th><th class="r">Należności</th><th class="r">Wpłacono</th><th class="r">Saldo</th></tr></thead><tbody>
  <?php $tot = ['c' => 0.0, 'p' => 0.0]; foreach ($enr as $e): $g = ti_group_balance((int)$e['client_id'], (int)$c['id']); $tot['c'] += $g['charges']; $tot['p'] += $g['paid']; ?>
    <tr><td><?= h($e['client_name']) ?></td><td class="r"><?= $fm($e['hourly_rate']) ?></td><td class="r"><?= (float)($e['hourly_rate_online'] ?? 0) > 0 ? $fm($e['hourly_rate_online']) : '—' ?></td>
      <td class="r"><?= $fm($g['charges']) ?></td><td class="r"><?= $fm($g['paid']) ?></td>
      <td class="r <?= $g['debt'] > 0.005 ? 'neg' : '' ?>"><?= $g['debt'] > 0.005 ? '−' . $fm($g['debt']) : ($g['credit'] > 0.005 ? '+' . $fm($g['credit']) : '0,00') ?></td></tr>
  <?php endforeach; if (!$enr): ?><tr><td colspan="6">Brak aktywnych uczestników.</td></tr>
  <?php else: ?><tr><th colspan="3" class="r">Razem</th><th class="r"><?= $fm($tot['c']) ?></th><th class="r"><?= $fm($tot['p']) ?></th><th></th></tr><?php endif; ?>
  </tbody></table>
</section>
<?php endforeach; ?></body></html><?php
    exit;
}

$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$csrf = h(csrf_token());
$fmt = fn($v) => number_format((float)$v, 2, ',', ' ');
?><!doctype html>
<html lang="pl" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cenniki i rabaty — TI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { navy: { 600: '#1d4c80', 700: '#10335c' } } } } };</script>
<style type="text/tailwindcss">
  .tab-on  { @apply border-navy-700 text-navy-700 font-semibold; }
  .tab-off { @apply border-transparent text-slate-500 hover:text-slate-700; }
  .pill-on { @apply bg-navy-700 text-white ring-navy-700; }
  .pill-off{ @apply bg-white text-slate-600 ring-slate-300 hover:bg-slate-50; }
  .inp { @apply w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-navy-600 focus:outline-none focus:ring-1 focus:ring-navy-600; }
  .lbl { @apply block text-xs font-medium text-slate-600 mb-1; }
  .btn { @apply inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium; }
  .btn-pri { @apply inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium bg-navy-700 text-white hover:bg-navy-600; }
  .btn-sec { @apply inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50; }
  .card { @apply rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200; }
  .step-ok   { @apply border-l-4 border-emerald-500 bg-emerald-50; }
  .step-skip { @apply border-l-4 border-slate-300 bg-slate-50 text-slate-500; }
</style>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-full bg-slate-100 text-slate-800 antialiased" x-data="prApp()" x-init="init()">

<header class="bg-navy-700 text-white">
  <div class="mx-auto max-w-7xl px-4 py-4 flex flex-wrap items-center gap-3">
    <a href="billing.php" class="text-white/70 hover:text-white text-sm"><i class="bi bi-arrow-left"></i> Rozliczenia</a>
    <h1 class="text-lg font-semibold">Cenniki i rabaty zajęć</h1>
    <span class="text-white/60 text-sm">cena = stawka godzinowa zapisu kursanta (zł/h)</span>
  </div>
  <nav class="mx-auto max-w-7xl px-4 flex gap-4 overflow-x-auto" aria-label="Zakładki">
    <?php foreach (['sym' => 'Symulator', 'types' => 'Typy zajęć', 'rules' => 'Reguły rabatowe', 'groups' => 'Grupy i zapisy', 'settle' => 'Rozliczenia', 'log' => 'Historia'] as $k => $l): ?>
    <a href="pricing.php?tab=<?= $k ?>" class="whitespace-nowrap border-b-2 pb-2 text-sm <?= $tab === $k ? 'border-amber-400 text-white font-semibold' : 'border-transparent text-white/70 hover:text-white' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $l ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<main class="mx-auto max-w-7xl px-4 py-5 space-y-5">
  <?php if ($flash): ?>
  <div role="status" class="rounded-lg px-4 py-3 text-sm <?= $flash['type'] === 'danger' ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' ?>"><?= h((string)$flash['msg']) ?></div>
  <?php endif; ?>

<?php if ($tab === 'sym'): ?>
  <!-- ═══ Symulator ═══ -->
  <div class="grid gap-5 lg:grid-cols-5">
    <section class="card lg:col-span-2 space-y-3" aria-labelledby="sym-h">
      <h2 id="sym-h" class="font-semibold">Parametry</h2>
      <div><label class="lbl" for="s-course">Grupa (opcjonalnie — ustawia typ zajęć i datę startu)</label>
        <select id="s-course" class="inp" x-model="s.course" @change="onCourse()">
          <option value="">— bez grupy —</option>
          <template x-for="c in courses" :key="c.id"><option :value="c.id" x-text="c.name + (c.lesson_type_id ? '' : ' (bez typu)')"></option></template>
        </select></div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="lbl" for="s-type">Typ zajęć</label>
          <select id="s-type" class="inp" x-model="s.type" @change="calc()">
            <option value="">— wybierz —</option>
            <template x-for="t in types" :key="t.id"><option :value="t.id" x-text="t.name + ' — ' + zl(t.base_price) + '/h'"></option></template>
          </select></div>
        <div><label class="lbl" for="s-date">Data kalkulacji</label><input id="s-date" type="date" class="inp" x-model="s.date" @change="calc()"></div>
      </div>
      <div><label class="lbl" for="s-part">Uczestnik (opcjonalnie — profil z bazy)</label>
        <input type="search" class="inp mb-1" placeholder="Szukaj…" x-model="pq" aria-label="Szukaj uczestnika">
        <select id="s-part" class="inp" size="5" x-model="s.client" @change="onClient()">
          <option value="">— bez uczestnika —</option>
          <template x-for="p in partsFiltered()" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
        </select></div>
      <div class="rounded-lg bg-slate-50 p-3 space-y-2">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="s.ov" @change="calc()"> Ręczny profil (zamiast danych uczestnika)</label>
        <fieldset :disabled="!s.ov" class="space-y-2" :class="s.ov ? '' : 'opacity-50'">
          <legend class="lbl">Statusy</legend>
          <div class="flex flex-wrap gap-1">
            <template x-for="(l, k) in statuses" :key="k">
              <button type="button" class="rounded-full px-2.5 py-1 text-xs ring-1" :class="s.st.includes(k) ? 'pill-on' : 'pill-off'" @click="toggleSt(k)" x-text="l"></button>
            </template>
          </div>
          <div class="grid grid-cols-3 gap-2">
            <div><label class="lbl" for="s-g">Liczba grup</label><input id="s-g" type="number" min="1" max="99" class="inp" x-model="s.groups" @input.debounce.300ms="calc()"></div>
            <div><label class="lbl" for="s-cr">Nadpłata (zł)</label><input id="s-cr" type="number" min="0" step="0.01" class="inp" x-model="s.credit" @input.debounce.300ms="calc()"></div>
            <div><label class="lbl" for="s-sd">Start grupy</label><input id="s-sd" type="date" class="inp" x-model="s.start" @change="calc()"></div>
          </div>
        </fieldset>
      </div>
    </section>

    <section class="card lg:col-span-3 space-y-3" aria-labelledby="res-h" aria-live="polite">
      <h2 id="res-h" class="font-semibold">Wynik</h2>
      <p x-show="!r" class="text-sm text-slate-500">Wybierz typ zajęć (albo grupę z przypisanym typem).</p>
      <template x-if="r && !r.ok"><p class="text-sm text-red-700" x-text="r.error"></p></template>
      <template x-if="r && r.ok">
        <div class="space-y-3">
          <div class="flex flex-wrap items-end gap-6">
            <div><div class="text-xs text-slate-500">Cena bazowa</div><div class="text-lg" x-text="zl(r.base) + '/h'"></div></div>
            <div><div class="text-xs text-slate-500">Cena końcowa</div><div class="text-3xl font-bold text-navy-700" x-text="zl(r.final) + '/h'"></div></div>
            <div x-show="r.base > r.final"><div class="text-xs text-slate-500">Rabat razem</div><div class="text-lg text-emerald-700" x-text="'−' + zl(r.base - r.final) + ' (' + Math.round((1 - r.final / r.base) * 100) + '%)'"></div></div>
          </div>
          <div class="text-xs text-slate-500">Profil: statusy <span x-text="r.profile.statuses.length ? r.profile.statuses.join(', ') : 'brak'"></span>
            · grup <span x-text="r.profile.groups"></span> · nadpłata <span x-text="zl(r.profile.credit)"></span>
            · start <span x-text="r.profile.start_date || '—'"></span></div>
          <ol class="space-y-1.5 text-sm">
            <template x-for="st in r.steps" :key="'a' + st.rule_id">
              <li class="rounded-md px-3 py-2 step-ok">
                <div class="flex flex-wrap justify-between gap-2"><span class="font-medium" x-text="st.name"></span>
                  <span class="tabular-nums" x-text="zl(st.before) + ' → ' + zl(st.after) + '  (−' + zl(st.amount) + ')'"></span></div>
                <div class="text-xs text-slate-600" x-text="(st.type === 'percentage' ? st.value + '%' : zl(st.value)) + ' · priorytet ' + st.priority + ' · ' + st.reason + (st.stackable ? '' : ' · nie łączy się')"></div>
              </li>
            </template>
            <template x-for="sk in r.skipped" :key="'s' + sk.rule_id">
              <li class="rounded-md px-3 py-1.5 text-xs step-skip"><span class="font-medium" x-text="sk.name"></span> — <span x-text="sk.why"></span></li>
            </template>
            <li x-show="!r.steps.length && !r.skipped.length" class="text-sm text-slate-500">Brak aktywnych reguł dla tego typu.</li>
          </ol>
          <!-- Zastosowanie do zapisu (uczestnik zapisany do wybranej grupy) -->
          <div x-show="canApply()" class="border-t border-slate-200 pt-3">
            <form method="post" class="flex flex-wrap items-end gap-2" @submit="if (!confirm('Ustawić stawkę zapisu kursanta z cennika?')) $event.preventDefault()">
              <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="apply"><input type="hidden" name="_tab" value="sym">
              <input type="hidden" name="course_id" :value="s.course"><input type="hidden" name="client_id" :value="s.client">
              <button class="btn-pri"><i class="bi bi-check2-circle"></i> Zastosuj do zapisu kursanta</button>
              <span class="text-xs text-slate-500">ustawi stawkę stacjonarną (i online, jeśli grupa ma typ online) — z zapisem w historii</span>
            </form>
          </div>
        </div>
      </template>
      <!-- Statusy uczestnika (profil cenowy) -->
      <div x-show="s.client" class="border-t border-slate-200 pt-3">
        <form method="post" class="space-y-2">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="statuses"><input type="hidden" name="_tab" value="sym">
          <input type="hidden" name="client_id" :value="s.client">
          <div class="lbl">Statusy uczestnika w cenniku („kontynuacja” wykrywana automatycznie)</div>
          <div class="flex flex-wrap gap-3 text-sm">
            <template x-for="(l, k) in statuses" :key="'c' + k">
              <label x-show="k !== 'kontynuacja'" class="flex items-center gap-1"><input type="checkbox" name="statuses[]" :value="k" :checked="clientStatuses().includes(k)"> <span x-text="l"></span></label>
            </template>
          </div>
          <button class="btn-sec">Zapisz statusy</button>
        </form>
      </div>
    </section>
  </div>

<?php elseif ($tab === 'types'): ?>
  <!-- ═══ Typy zajęć ═══ -->
  <div class="grid gap-5 lg:grid-cols-3">
    <section class="card lg:col-span-2 overflow-x-auto">
      <form method="post" class="mb-3 flex flex-wrap items-center gap-3">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="sync_types"><input type="hidden" name="_tab" value="types">
        <button class="btn-pri">Pobierz z rodzajów zajęć TI</button>
        <span class="text-xs text-slate-500">Tworzy typy z rodzajów zajęć (skrót, nazwa, aktywność), ustawia cenę z najczęstszej stawki zapisów i przypina grupy bez typu.</span>
      </form>
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Nazwa</th><th class="pr-3">Slug</th><th class="pr-3">Tryb</th><th class="pr-3 text-right">Cena bazowa</th><th class="pr-3">Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($types as $t): ?>
          <tr><td class="py-2 pr-3 font-medium"><?= h($t['name']) ?><?php if ($t['description']): ?><div class="text-xs text-slate-500"><?= h($t['description']) ?></div><?php endif; ?></td>
            <td class="pr-3 font-mono text-xs"><?= h($t['slug']) ?></td><td class="pr-3"><?= h(TI_PRICING_MODES[$t['mode']] ?? $t['mode']) ?></td>
            <td class="pr-3 text-right tabular-nums"><?= $fmt($t['base_price']) ?> zł/h</td>
            <td class="pr-3"><?= $t['is_active'] ? '<span class="text-emerald-700">aktywny</span>' : '<span class="text-slate-400">nieaktywny</span>' ?></td>
            <td class="text-right"><button type="button" class="btn-sec" @click='editType(<?= $J($t) ?>)'>Edytuj</button></td></tr>
        <?php endforeach; ?>
        <?php if (!$types): ?><tr><td colspan="6" class="py-6 text-center text-slate-500">Brak typów zajęć — dodaj pierwszy.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <section class="card">
      <h2 class="font-semibold mb-3" x-text="t.id ? 'Edycja typu zajęć' : 'Nowy typ zajęć'"></h2>
      <form method="post" class="space-y-3">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="save_type"><input type="hidden" name="_tab" value="types">
        <input type="hidden" name="id" :value="t.id">
        <div><label class="lbl" for="t-n">Nazwa</label><input id="t-n" name="name" class="inp" required maxlength="120" x-model="t.name" placeholder="np. Warsztat stacjonarny"></div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="lbl" for="t-s">Slug (puste = z nazwy)</label><input id="t-s" name="slug" class="inp font-mono" pattern="[a-z0-9][a-z0-9-]*" x-model="t.slug"></div>
          <div><label class="lbl" for="t-m">Tryb</label><select id="t-m" name="mode" class="inp" x-model="t.mode">
            <?php foreach (TI_PRICING_MODES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <div><label class="lbl" for="t-p">Cena bazowa (zł/h)</label><input id="t-p" name="base_price" class="inp" inputmode="decimal" required x-model="t.base_price"></div>
        <div><label class="lbl" for="t-d">Opis</label><textarea id="t-d" name="description" rows="2" class="inp" x-model="t.description"></textarea></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" x-model="t.is_active"> Aktywny</label>
        <div class="flex gap-2"><button class="btn-pri">Zapisz</button><button type="button" class="btn-sec" x-show="t.id" @click="editType(null)">Nowy</button></div>
      </form>
    </section>
  </div>

<?php elseif ($tab === 'rules'): ?>
  <!-- ═══ Reguły rabatowe ═══ -->
  <div class="grid gap-5 lg:grid-cols-3">
    <section class="card lg:col-span-2 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-2">Prio</th><th class="pr-3">Reguła</th><th class="pr-3">Rabat</th><th class="pr-3">Warunek</th><th class="pr-3">Typ zajęć</th><th class="pr-3">Okres</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($rules as $r): ?>
          <tr class="<?= $r['is_active'] ? '' : 'text-slate-400' ?>">
            <td class="py-2 pr-2 tabular-nums"><?= (int)$r['priority'] ?></td>
            <td class="pr-3 font-medium"><?= h($r['name']) ?><?= $r['stackable'] ? '' : ' <span class="rounded bg-amber-100 px-1.5 text-xs text-amber-800">nie łączy się</span>' ?><?= $r['is_active'] ? '' : ' <span class="text-xs">(wył.)</span>' ?></td>
            <td class="pr-3 whitespace-nowrap"><?= $r['discount_type'] === 'percentage' ? $fmt($r['discount_value']) . '%' : $fmt($r['discount_value']) . ' zł' ?></td>
            <td class="pr-3 text-xs"><?= h(TI_PRICING_CONDITIONS[$r['condition_type']] ?? $r['condition_type']) ?><?= $r['condition_value'] !== null && $r['condition_value'] !== '' ? ': <b>' . h($r['condition_value']) . '</b>' : '' ?></td>
            <td class="pr-3 text-xs"><?= h($r['type_name'] ?? 'wszystkie') ?></td>
            <td class="pr-3 text-xs whitespace-nowrap"><?= h(($r['date_from'] ?: '…') . ' – ' . ($r['date_to'] ?: '…')) ?></td>
            <td class="text-right whitespace-nowrap">
              <button type="button" class="btn-sec" @click='editRule(<?= $J($r) ?>)'>Edytuj</button>
              <form method="post" class="inline" onsubmit="return confirm('Usunąć regułę?')">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="delete_rule"><input type="hidden" name="_tab" value="rules">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn text-red-700 hover:bg-red-50" aria-label="Usuń regułę"><i class="bi bi-trash"></i></button></form></td></tr>
        <?php endforeach; ?>
        <?php if (!$rules): ?><tr><td colspan="7" class="py-6 text-center text-slate-500">Brak reguł rabatowych.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <p class="mt-3 text-xs text-slate-500">Kolejność: rosnąco po priorytecie. Rabat procentowy liczony od ceny po wcześniejszych rabatach. Reguła „nie łączy się” liczona jest od ceny bazowej i wygrywa, tylko gdy daje niższą cenę niż pozostałe razem — potem dalsze reguły są pomijane.</p>
    </section>
    <section class="card">
      <h2 class="font-semibold mb-3" x-text="ru.id ? 'Edycja reguły' : 'Nowa reguła'"></h2>
      <form method="post" class="space-y-3">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="save_rule"><input type="hidden" name="_tab" value="rules">
        <input type="hidden" name="id" :value="ru.id">
        <div><label class="lbl" for="r-n">Nazwa</label><input id="r-n" name="name" class="inp" required maxlength="160" x-model="ru.name" placeholder="np. 10% dla NGO na webinary"></div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="lbl" for="r-dt">Rodzaj rabatu</label><select id="r-dt" name="discount_type" class="inp" x-model="ru.discount_type"><option value="percentage">Procentowy (%)</option><option value="fixed">Kwotowy (zł/h)</option></select></div>
          <div><label class="lbl" for="r-dv" x-text="ru.discount_type === 'percentage' ? 'Wartość (0–100 %)' : 'Wartość (zł/h)'"></label>
            <input id="r-dv" name="discount_value" class="inp" inputmode="decimal" required x-model="ru.discount_value"></div>
        </div>
        <div><label class="lbl" for="r-tt">Typ zajęć</label><select id="r-tt" name="target_lesson_type_id" class="inp" x-model="ru.target_lesson_type_id">
          <option value="">Wszystkie typy</option><template x-for="tt in types" :key="tt.id"><option :value="tt.id" x-text="tt.name"></option></template></select></div>
        <div><label class="lbl" for="r-ct">Warunek</label><select id="r-ct" name="condition_type" class="inp" x-model="ru.condition_type">
          <?php foreach (TI_PRICING_CONDITIONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div x-show="ru.condition_type === 'participant_status'">
          <div class="lbl">Statusy (dowolny z zaznaczonych)</div>
          <div class="flex flex-wrap gap-1">
            <template x-for="(l, k) in statuses" :key="'r' + k">
              <button type="button" class="rounded-full px-2.5 py-1 text-xs ring-1" :class="ruSt().includes(k) ? 'pill-on' : 'pill-off'" @click="toggleRuSt(k)" x-text="l"></button>
            </template>
          </div>
        </div>
        <div x-show="['early_bird','bundle_quantity','virtual_account_overpayment'].includes(ru.condition_type)">
          <label class="lbl" for="r-cv" x-text="{early_bird: 'Minimum dni przed startem grupy', bundle_quantity: 'Minimalna liczba grup uczestnika', virtual_account_overpayment: 'Minimalna nadpłata na koncie (zł)'}[ru.condition_type] || ''"></label>
          <input id="r-cv" class="inp" inputmode="decimal" x-model="ru.condition_value">
        </div>
        <input type="hidden" name="condition_value" :value="ru.condition_value">
        <div class="grid grid-cols-2 gap-2">
          <div><label class="lbl" for="r-df">Od</label><input id="r-df" type="date" name="date_from" class="inp" x-model="ru.date_from"></div>
          <div><label class="lbl" for="r-dt2">Do</label><input id="r-dt2" type="date" name="date_to" class="inp" x-model="ru.date_to"></div>
        </div>
        <div class="grid grid-cols-2 gap-2 items-end">
          <div><label class="lbl" for="r-p">Priorytet (niższy = wcześniej)</label><input id="r-p" type="number" min="0" max="9999" name="priority" class="inp" x-model="ru.priority"></div>
          <div class="space-y-1 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" name="stackable" value="1" x-model="ru.stackable"> Łączy się z innymi</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" x-model="ru.is_active"> Aktywna</label>
          </div>
        </div>
        <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600" x-text="ruPreview()"></p>
        <div class="flex gap-2"><button class="btn-pri">Zapisz regułę</button><button type="button" class="btn-sec" x-show="ru.id" @click="editRule(null)">Nowa</button></div>
      </form>
    </section>
  </div>

<?php elseif ($tab === 'groups'): ?>
  <?php $rng = fn($a, $b) => $a === null ? '—' : (abs((float)$a - (float)$b) < 0.005 ? $fmt($a) : $fmt($a) . '–' . $fmt($b)); ?>
  <div class="flex items-end justify-between gap-3">
    <div><h2 class="text-lg font-semibold text-slate-800">Grupy i stawki</h2>
      <p class="text-sm text-slate-500">Typ zajęć grupy, aktualne stawki w zapisach i szybka zmiana ceny całej grupy.</p></div>
    <div class="flex flex-wrap items-center gap-2">
      <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"><?= count($courses) ?> <?= count($courses) === 1 ? 'grupa' : 'grup' ?></span>
      <a class="btn-sec" href="pricing.php?print=groups" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i>Drukuj grupy</a>
      <?php if ($wyg): ?>
      <form method="post" onsubmit="return confirm('Zamknąć <?= count($wyg) ?> grup do wygaszenia (bez przyszłych lekcji, ostatnia lekcja > 14 dni temu)? Grupy zostaną zarchiwizowane i zablokowane.')">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="close_candidates"><input type="hidden" name="_tab" value="groups">
        <?php foreach ($wyg as $w): ?><input type="hidden" name="ids[]" value="<?= (int)$w['id'] ?>"><?php endforeach; ?>
        <button class="btn-sec"><i class="bi bi-lock" aria-hidden="true"></i>Zamknij grupy do wygaszenia (<?= count($wyg) ?>)</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <form method="post" id="bulkform" class="card flex flex-wrap items-center gap-3 !py-3">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_tab" value="groups">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="bulk-all" onclick="document.querySelectorAll('input[name=&quot;ids[]&quot;][form=bulkform]').forEach(function(x){x.checked=this.checked}.bind(this))"> zaznacz wszystkie</label>
    <span class="text-xs text-slate-500">Zaznacz grupy na kartach (aktywne) lub w sekcji „Zamknięte”, potem wybierz akcję:</span>
    <button type="submit" name="_op" value="close_candidates" class="btn-sec" onclick="return confirm('Zamknąć zaznaczone grupy? Grupy z przyszłymi lekcjami zostaną pominięte. Zostaną zarchiwizowane i zablokowane.')"><i class="bi bi-lock" aria-hidden="true"></i>Zamknij zaznaczone</button>
    <button type="submit" name="_op" value="open_groups" class="btn-pri" onclick="return confirm('Uruchomić ponownie zaznaczone zamknięte grupy (przywrócić z archiwum i odblokować)?')"><i class="bi bi-unlock" aria-hidden="true"></i>Uruchom zaznaczone</button>
    <details class="w-full rounded-lg border border-slate-200 bg-slate-50/60">
      <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-navy-700"><i class="bi bi-sliders mr-1" aria-hidden="true"></i>Więcej akcji masowych (typy zajęć, stawki)</summary>
      <div class="grid gap-3 border-t border-slate-200 p-3 md:grid-cols-2">
        <div class="space-y-2"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Przypisz typy zajęć</div>
          <div class="grid grid-cols-2 gap-2">
            <div><label class="lbl" for="bt1">Stacjonarnie</label><select id="bt1" name="bt_type" class="inp"><option value="">— bez zmian —</option><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
            <div><label class="lbl" for="bt2">Online</label><select id="bt2" name="bt_online" class="inp"><option value="">— bez zmian —</option><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
          </div>
          <button type="submit" name="_op" value="bulk_types" class="btn-sec" onclick="return confirm('Przypisać wybrane typy zajęć zaznaczonym grupom?')">Przypisz typy zaznaczonym</button></div>
        <div class="space-y-2"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ustaw stawkę w zapisach</div>
          <div class="grid grid-cols-2 gap-2">
            <div><label class="lbl" for="br1"><i class="bi bi-building mr-1 text-emerald-700" aria-hidden="true"></i>Stacjonarnie (zł/h)</label><input id="br1" name="br_stac" class="inp" inputmode="decimal"></div>
            <div><label class="lbl" for="br2"><i class="bi bi-camera-video mr-1 text-sky-700" aria-hidden="true"></i>Online (zł/h)</label><input id="br2" name="br_online" class="inp" inputmode="decimal" placeholder="puste = bez zmiany"></div>
          </div>
          <div><label class="lbl" for="br3">Uzasadnienie (wymagane, audyt)</label><input id="br3" name="reason" class="inp" maxlength="300"></div>
          <button type="submit" name="_op" value="bulk_rate" class="btn-pri" onclick="var r=document.getElementById('br3').value.trim(); if(r.length<5){alert('Podaj uzasadnienie (min. 5 znaków).');return false;} return confirm('Ustawić stawkę wszystkim aktywnym uczestnikom zaznaczonych grup?')">Ustaw stawkę zaznaczonym</button></div>
      </div>
    </details>
  </form>
  <div class="grid gap-4 lg:grid-cols-2">
  <?php foreach ($courses as $c): $sel = $sel_course === (int)$c['id']; $varied = (int)$c['n'] && $c['r_min'] !== null && abs((float)$c['r_min'] - (float)$c['r_max']) >= 0.005; ?>
    <article class="card !p-0 overflow-hidden <?= $sel ? 'ring-2 ring-amber-400' : '' ?>" aria-label="Grupa <?= h($c['name']) ?>">
      <header class="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3">
        <input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" form="bulkform" class="h-4 w-4" aria-label="Zaznacz grupę <?= h($c['name']) ?>">
        <?php if (trim((string)$c['group_code']) !== ''): ?><span class="rounded-md bg-navy-700 px-2 py-0.5 font-mono text-xs font-semibold text-white" title="Kod grupy"><?= h($c['group_code']) ?></span><?php endif; ?>
        <h3 class="min-w-0 flex-1 truncate font-semibold text-slate-800"><?= h($c['name']) ?></h3>
        <?php if ($c['is_online']): ?><span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800"><i class="bi bi-camera-video mr-1" aria-hidden="true"></i>online</span><?php endif; ?>
        <span class="rounded-full bg-white px-2 py-0.5 text-xs text-slate-600 ring-1 ring-slate-200"><i class="bi bi-people mr-1" aria-hidden="true"></i><?= (int)$c['n'] ?></span>
      </header>
      <div class="space-y-3 px-4 py-3">
        <form method="post" class="space-y-2">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="course_types"><input type="hidden" name="_tab" value="groups">
          <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="_course" value="<?= $sel_course ?>">
          <div class="grid gap-3 sm:grid-cols-2">
            <section class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3" aria-label="Stacjonarnie">
              <div class="mb-1 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-emerald-800"><span><i class="bi bi-building mr-1" aria-hidden="true"></i>Stacjonarnie</span>
                <span class="tabular-nums text-sm normal-case text-emerald-900"><?= (int)$c['n'] ? $rng($c['r_min'], $c['r_max']) : '—' ?> <span class="text-xs font-normal">zł/h</span></span></div>
              <?php if ($varied): ?><div class="mb-1 text-xs text-amber-700"><i class="bi bi-exclamation-triangle mr-1" aria-hidden="true"></i>stawki zróżnicowane</div><?php endif; ?>
              <label class="lbl" for="lt-<?= (int)$c['id'] ?>">Typ zajęć</label>
              <select id="lt-<?= (int)$c['id'] ?>" name="lesson_type_id" class="inp"><option value="">— brak —</option>
                <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$c['lesson_type_id'] === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?> · <?= $fmt($t['base_price']) ?> zł</option><?php endforeach; ?></select>
            </section>
            <section class="rounded-lg border border-sky-200 bg-sky-50/50 p-3" aria-label="Online">
              <div class="mb-1 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-sky-800"><span><i class="bi bi-camera-video mr-1" aria-hidden="true"></i>Online</span>
                <span class="tabular-nums text-sm normal-case text-sky-900"><?= (int)$c['n'] && (float)$c['ro_max'] > 0 ? $rng($c['ro_min'], $c['ro_max']) : '—' ?> <span class="text-xs font-normal">zł/h</span></span></div>
              <label class="lbl" for="lo-<?= (int)$c['id'] ?>">Typ zajęć</label>
              <select id="lo-<?= (int)$c['id'] ?>" name="online_lesson_type_id" class="inp"><option value="">— jak stacjonarne —</option>
                <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$c['online_lesson_type_id'] === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?> · <?= $fmt($t['base_price']) ?> zł</option><?php endforeach; ?></select>
            </section>
          </div>
          <div class="text-right"><button class="btn-sec">Zapisz typy</button></div>
        </form>
        <?php if ((int)$c['n']): ?>
        <details class="rounded-lg border border-slate-200 bg-slate-50/60 open:bg-white">
          <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-navy-700"><i class="bi bi-cash-coin mr-1" aria-hidden="true"></i>Zmień stawkę całej grupy</summary>
          <form method="post" class="grid gap-2 border-t border-slate-200 p-3 sm:grid-cols-2" onsubmit="if(!this.reason.value.trim() || this.reason.value.trim().length<5){alert('Podaj uzasadnienie zmiany ceny (min. 5 znaków).');return false;} return confirm('Ustawić tę stawkę wszystkim aktywnym uczestnikom grupy?');">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="group_rate"><input type="hidden" name="_tab" value="groups">
            <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="_course" value="<?= $sel_course ?>">
            <div><label class="lbl" for="gs-<?= (int)$c['id'] ?>"><i class="bi bi-building mr-1 text-emerald-700" aria-hidden="true"></i>Stacjonarnie (zł/h)</label><input id="gs-<?= (int)$c['id'] ?>" name="g_stacjonarna" class="inp" inputmode="decimal" placeholder="np. 45,00"></div>
            <div><label class="lbl" for="go-<?= (int)$c['id'] ?>"><i class="bi bi-camera-video mr-1 text-sky-700" aria-hidden="true"></i>Online (zł/h)</label><input id="go-<?= (int)$c['id'] ?>" name="g_online" class="inp" inputmode="decimal" placeholder="puste = bez zmiany"></div>
            <div class="sm:col-span-2"><label class="lbl" for="gr-<?= (int)$c['id'] ?>">Uzasadnienie (wymagane, trafia do audytu)</label><input id="gr-<?= (int)$c['id'] ?>" name="reason" class="inp" maxlength="300"></div>
            <div class="sm:col-span-2 text-right"><button class="btn-pri">Ustaw dla grupy</button></div>
          </form>
        </details>
        <?php endif; ?>
      </div>
      <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-2">
        <div class="flex items-center gap-2 text-xs">
          <?php if ((int)$c['fut'] === 0 && ($c['last_lesson'] === null || $c['last_lesson'] < date('Y-m-d', strtotime('-14 days')))): ?>
            <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800" title="Brak przyszłych lekcji, ostatnia: <?= h((string)($c['last_lesson'] ?? '—')) ?>">do wygaszenia</span>
            <form method="post" onsubmit="return confirm('Zamknąć grupę „<?= h(addslashes($c['name'])) ?>”? Zostanie zarchiwizowana i zablokowana (protokoły, lekcje, uczestnicy). Odblokowuje tylko „Przywróć z archiwum”.')">
              <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="close_group"><input type="hidden" name="_tab" value="groups"><input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
              <button class="rounded px-2 py-0.5 ring-1 ring-slate-300 hover:bg-slate-50"><i class="bi bi-lock mr-1" aria-hidden="true"></i>Zamknij grupę</button>
            </form>
          <?php else: ?><span class="text-slate-500"><?= (int)$c['fut'] ?> przyszłych lekcji</span><?php endif; ?>
        </div>
        <a class="text-xs text-slate-500 hover:underline" href="pricing.php?print=groups&amp;course=<?= (int)$c['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> wydruk</a>
        <a class="text-sm font-medium text-navy-700 hover:underline" href="pricing.php?tab=groups&amp;course=<?= (int)$c['id'] ?>#zapisy">Zapisy i stawki uczestników <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </footer>
    </article>
  <?php endforeach; ?>
  <?php if (!$courses): ?><p class="card text-sm text-slate-500 lg:col-span-2">Brak aktywnych grup.</p><?php endif; ?>
  </div>
  <details class="card" <?= $closed_groups ? '' : 'open' ?>>
    <summary class="cursor-pointer select-none font-semibold">Zamknięte i zarchiwizowane (<?= count($closed_groups) ?>)</summary>
    <?php if (!$closed_groups): ?><p class="mt-2 text-sm text-slate-500">Brak zamkniętych grup.</p><?php else: ?>
    <table class="mt-2 min-w-full text-sm"><thead class="text-left text-xs uppercase text-slate-500"><tr><th class="w-8 py-2"></th><th>Kod</th><th>Grupa</th><th>Stan</th><th>Zamknął</th></tr></thead><tbody class="divide-y divide-slate-100">
    <?php foreach ($closed_groups as $cg): ?>
      <tr><td class="py-2"><input type="checkbox" name="ids[]" value="<?= (int)$cg['id'] ?>" form="bulkform" class="h-4 w-4" aria-label="Zaznacz grupę <?= h($cg['name']) ?>"></td>
        <td class="font-mono text-xs"><?= h((string)$cg['group_code']) ?></td><td class="font-medium"><?= h($cg['name']) ?></td>
        <td class="text-xs"><?= !empty($cg['closed_at']) ? 'zamknięta ' . h(date('d.m.Y', strtotime((string)$cg['closed_at']))) : 'zarchiwizowana' ?></td>
        <td class="text-xs text-slate-500"><?= h((string)($cg['closed_name'] ?? '')) ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
  </details>
  <?php if ($sel_course): $sc = array_values(array_filter($courses, fn($c) => (int)$c['id'] === $sel_course))[0] ?? null; ?>
  <section id="zapisy" class="card overflow-x-auto">
    <h2 class="font-semibold mb-1">Zapisy — <?= !empty($sc['group_code']) ? '<span class="font-mono text-sm">' . h($sc['group_code']) . '</span> · ' : '' ?><?= h($sc['name'] ?? ('#' . $sel_course)) ?></h2>
    <p class="text-xs text-slate-500 mb-3">Cena z cennika wyliczona dla profilu każdego uczestnika. „Zastosuj” ustawia stawkę zapisu; wpisana cena nadpisuje cennik (wymaga uzasadnienia, trafia do audytu).</p>
    <?php if (!$sel_rows): ?><p class="text-sm text-slate-500">Brak aktywnych uczestników.</p><?php endif; ?>
    <?php if ($sel_rows && empty($sc['lesson_type_id'])): ?><p class="text-sm text-amber-800">Najpierw przypisz grupie typ zajęć.</p><?php endif; ?>
    <?php if ($sel_rows && !empty($sc['lesson_type_id'])): ?>
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Uczestnik</th><th class="pr-3 text-right">Stawka teraz</th><th class="pr-3 text-right">Z cennika</th><th class="pr-3">Rabaty</th><th class="pr-3">Zastosuj / nadpisz</th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($sel_rows as $row): $c1 = $row['calc']; $c2 = $row['calc_on'];
        $diff = abs($c1['final'] - $row['rate']) > 0.005 || ($c2 && abs($c2['final'] - $row['rate_on']) > 0.005); ?>
        <tr class="<?= $diff ? 'bg-amber-50/60' : '' ?>">
          <td class="py-2 pr-3 font-medium"><?= h($row['name']) ?><div class="text-xs text-slate-500"><?= h(implode(', ', $c1['profile']['statuses']) ?: 'bez statusu') ?> · grup <?= (int)$c1['profile']['groups'] ?></div></td>
          <td class="pr-3 text-right tabular-nums"><?= $fmt($row['rate']) ?><?php if ($c2): ?><div class="text-xs text-slate-500">online <?= $fmt($row['rate_on']) ?></div><?php endif; ?></td>
          <td class="pr-3 text-right tabular-nums font-semibold"><?= $fmt($c1['final']) ?><?php if ($c2): ?><div class="text-xs font-normal text-slate-500">online <?= $fmt($c2['final']) ?></div><?php endif; ?></td>
          <td class="pr-3 text-xs"><?= h(implode(' · ', array_map(fn($s) => $s['name'] . ' −' . $fmt($s['amount']), $c1['steps'])) ?: '—') ?></td>
          <td class="pr-3">
            <form method="post" class="flex flex-wrap items-center gap-1" onsubmit="var o=this.override_stacjonarna.value||(this.override_online&&this.override_online.value); if(o && this.reason.value.trim().length<5){alert('Nadpisanie ceny wymaga uzasadnienia.');return false;} return confirm('Ustawić stawkę zapisu?')">
              <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="apply"><input type="hidden" name="_tab" value="groups">
              <input type="hidden" name="course_id" value="<?= $sel_course ?>"><input type="hidden" name="_course" value="<?= $sel_course ?>"><input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
              <input name="override_stacjonarna" class="inp !w-24" inputmode="decimal" placeholder="nadpisz" aria-label="Nadpisana stawka stacjonarna">
              <?php if ($c2): ?><input name="override_online" class="inp !w-24" inputmode="decimal" placeholder="online" aria-label="Nadpisana stawka online"><?php endif; ?>
              <input name="reason" class="inp !w-44" maxlength="300" placeholder="uzasadnienie (przy nadpisaniu)" aria-label="Uzasadnienie nadpisania">
              <button class="btn-pri">Zastosuj</button>
            </form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <?php endif; ?>

<?php elseif ($tab === 'settle'): ?>
  <!-- ═══ Rozliczenia ═══ -->
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div><h2 class="text-lg font-semibold text-slate-800">Rozliczenia i nadpłaty</h2>
      <p class="text-sm text-slate-500">Należności, wpłaty i salda kursantów per grupa oraz nadpłaty do rozdysponowania — w jednym miejscu, obok stawek.</p></div>
    <div class="flex flex-wrap gap-2">
      <a class="btn-sec" href="pricing.php?print=groups" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i>Drukuj grupy</a>
      <a class="btn-sec" href="overpayments.php"><i class="bi bi-cash-stack" aria-hidden="true"></i>Nadpłaty — rozliczanie</a>
      <a class="btn-sec" href="../../../rozliczenia/"><i class="bi bi-receipt" aria-hidden="true"></i>Moduł rozliczeń</a>
    </div>
  </div>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card"><div class="text-xs uppercase tracking-wide text-slate-500">Należności</div><div class="text-2xl font-semibold tabular-nums"><?= $fmt($st_tot['charges']) ?> <span class="text-sm font-normal">zł</span></div></div>
    <div class="card"><div class="text-xs uppercase tracking-wide text-slate-500">Wpłacono</div><div class="text-2xl font-semibold tabular-nums text-emerald-700"><?= $fmt($st_tot['paid']) ?> <span class="text-sm font-normal">zł</span></div></div>
    <div class="card"><div class="text-xs uppercase tracking-wide text-slate-500">Niedopłaty</div><div class="text-2xl font-semibold tabular-nums text-red-700"><?= $fmt($st_tot['debt']) ?> <span class="text-sm font-normal">zł</span></div></div>
    <div class="card"><div class="text-xs uppercase tracking-wide text-slate-500">Nadpłaty (do dyspozycji)</div><div class="text-2xl font-semibold tabular-nums text-sky-700"><?= $fmt($st_opsum['by_status']['available'] ?? 0) ?> <span class="text-sm font-normal">zł</span></div>
      <?php if (!empty($st_opsum['unreconciled'])): ?><div class="text-xs text-amber-700">niezgodne salda: <?= (int)$st_opsum['unreconciled'] ?></div><?php endif; ?></div>
  </div>

  <section class="card">
    <div class="flex flex-wrap items-end gap-3">
      <form method="get" class="flex items-end gap-2"><input type="hidden" name="tab" value="settle">
        <div><label class="lbl" for="st-m">Miesiąc rozliczenia</label><input id="st-m" type="month" name="m" value="<?= h($st_ym) ?>" class="inp"></div>
        <button class="btn-sec">Pokaż</button></form>
      <form method="post" class="ml-auto flex flex-wrap items-center gap-3" onsubmit="return confirm('Wystawić rozliczenia za <?= h($st_ym) ?> wszystkim kursantom z obecnościami? <?= 'Przy zaznaczonym powiadomieniu wyślemy SMS i e-mail.' ?>')">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="settle_issue_all"><input type="hidden" name="_tab" value="settle"><input type="hidden" name="_m" value="<?= h($st_ym) ?>">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify" value="1"> wyślij powiadomienia (SMS + e-mail)</label>
        <button class="btn-pri"><i class="bi bi-receipt" aria-hidden="true"></i>Wystaw rozliczenia za miesiąc</button>
      </form>
    </div>
  </section>

  <section class="card overflow-x-auto">
    <h3 class="font-semibold mb-2">Grupy</h3>
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Grupa</th><th class="pr-3 text-right">Uczestn.</th><th class="pr-3 text-right">Należności</th><th class="pr-3 text-right">Wpłacono</th><th class="pr-3 text-right">Niedopłata</th><th class="pr-3 text-right">Nadpłata</th><th></th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($st_groups as $g): $t = $g['t']; ?>
        <tr><td class="py-2 pr-3 font-medium"><?php if (trim((string)$g['c']['group_code']) !== ''): ?><span class="mr-1 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs"><?= h($g['c']['group_code']) ?></span><?php endif; ?><?= h($g['c']['name']) ?></td>
          <td class="pr-3 text-right"><?= count($g['p']) ?></td>
          <td class="pr-3 text-right tabular-nums"><?= $fmt($t['charges']) ?></td><td class="pr-3 text-right tabular-nums text-emerald-700"><?= $fmt($t['paid']) ?></td>
          <td class="pr-3 text-right tabular-nums <?= $t['debt'] > 0.005 ? 'font-semibold text-red-700' : 'text-slate-400' ?>"><?= $fmt($t['debt']) ?></td>
          <td class="pr-3 text-right tabular-nums <?= $t['credit'] > 0.005 ? 'font-semibold text-sky-700' : 'text-slate-400' ?>"><?= $fmt($t['credit']) ?></td>
          <td class="text-right"><a class="text-sm font-medium text-navy-700 hover:underline" href="../../../rozliczenia/grupa.php?id=<?= (int)$g['c']['id'] ?>">Szczegóły →</a></td></tr>
        <tr><td colspan="7" class="pb-3">
          <details class="rounded-lg border border-slate-200"><summary class="cursor-pointer px-3 py-1.5 text-xs font-medium text-navy-700">Uczestnicy i akcje (<?= count($g['p']) ?>)</summary>
            <div class="divide-y divide-slate-100 border-t border-slate-200">
            <?php foreach ($g['p'] as $pr): $gid = (int)$g['c']['id']; $cid = (int)$pr['client_id']; ?>
              <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2 text-sm">
                <div class="min-w-[10rem] flex-1 font-medium"><?= h($pr['client_name']) ?>
                  <div class="text-xs font-normal text-slate-500">nal. <?= $fmt($pr['m_charges']) ?> · wpł. <?= $fmt($pr['m_paid']) ?> w <?= h($st_ym) ?></div></div>
                <div class="tabular-nums text-xs"><?= $pr['debt'] > 0.005 ? '<span class="font-semibold text-red-700">−' . $fmt($pr['debt']) . '</span>' : ($pr['credit'] > 0.005 ? '<span class="font-semibold text-sky-700">+' . $fmt($pr['credit']) . '</span>' : '<span class="text-slate-400">0,00</span>') ?></div>
                <form method="post" class="flex items-center gap-1" onsubmit="return confirm('Wystawić rozliczenie za <?= h($st_ym) ?> dla: <?= h(addslashes($pr['client_name'])) ?>?')">
                  <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="settle_issue"><input type="hidden" name="_tab" value="settle"><input type="hidden" name="_m" value="<?= h($st_ym) ?>"><input type="hidden" name="client_id" value="<?= $cid ?>">
                  <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="notify" value="1">powiadom</label>
                  <button class="btn-sec !py-1 text-xs">Wystaw</button></form>
                <form method="post" class="flex items-center gap-1">
                  <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="settle_payment"><input type="hidden" name="_tab" value="settle"><input type="hidden" name="_m" value="<?= h($st_ym) ?>"><input type="hidden" name="client_id" value="<?= $cid ?>"><input type="hidden" name="pay_course_id" value="<?= $gid ?>">
                  <input name="amount" class="inp !w-24 !py-1" inputmode="decimal" placeholder="wpłata zł" aria-label="Kwota wpłaty — <?= h($pr['client_name']) ?>" required>
                  <input name="paid_at" type="date" class="inp !w-36 !py-1" value="<?= date('Y-m-d') ?>" aria-label="Data wpłaty">
                  <button class="btn-pri !py-1 text-xs">Zaksięguj</button></form>
              </div>
            <?php endforeach; ?></div></details>
        </td></tr>
      <?php endforeach; ?>
      <?php if (!$st_groups): ?><tr><td colspan="7" class="py-6 text-center text-slate-500">Brak rozliczeń w grupach.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <div class="grid gap-5 lg:grid-cols-2">
    <section class="card overflow-x-auto">
      <h3 class="font-semibold mb-2">Niedopłaty — kursanci (max 50)</h3>
      <table class="min-w-full text-sm"><thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Kursant</th><th class="pr-3 text-right">Należności</th><th class="pr-3 text-right">Wpłacono</th><th class="text-right">Do zapłaty</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($st_debtors as $d): ?>
          <tr><td class="py-2 pr-3"><a class="hover:underline" href="../../../rozliczenia/uczestnik.php?client_id=<?= (int)$d['client_id'] ?>"><?= h($d['client_name']) ?></a></td>
            <td class="pr-3 text-right tabular-nums"><?= $fmt($d['charges']) ?></td><td class="pr-3 text-right tabular-nums"><?= $fmt($d['paid']) ?></td>
            <td class="text-right tabular-nums font-semibold text-red-700"><?= $fmt($d['debt']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$st_debtors): ?><tr><td colspan="4" class="py-6 text-center text-slate-500">Brak niedopłat.</td></tr><?php endif; ?>
        </tbody></table>
    </section>
    <section class="card overflow-x-auto">
      <h3 class="font-semibold mb-2">Nadpłaty do rozdysponowania</h3>
      <table class="min-w-full text-sm"><thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Kursant</th><th class="pr-3">Koszyk</th><th class="pr-3 text-right">Kwota</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach (array_slice($st_op, 0, 50) as $o): ?>
          <tr><td class="py-2 pr-3"><?= h($o['participant_name']) ?></td><td class="pr-3 text-xs text-slate-600"><?= h(ti_op_bucket_label((int)$o['course_id'])) ?></td>
            <td class="pr-3 text-right tabular-nums font-semibold text-sky-700"><?= $fmt($o['amount']) ?></td>
            <td class="text-right whitespace-nowrap"><a class="text-xs text-slate-500 hover:underline" href="overpayments.php" title="Zaliczenie na FVAT/grupę, przeksięgowanie, historia">więcej</a></td></tr>
          <tr><td colspan="4" class="pb-2">
            <details class="rounded border border-slate-200"><summary class="cursor-pointer px-2 py-1 text-xs font-medium text-navy-700">Zwrot na rachunek (→ EODoK)</summary>
              <form method="post" class="grid gap-2 border-t border-slate-200 p-2 sm:grid-cols-2" onsubmit="return confirm('Zarejestrować zwrot i przekazać go do obiegu akceptacji EODoK?')">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="op_refund"><input type="hidden" name="_tab" value="settle"><input type="hidden" name="op_id" value="<?= (int)$o['id'] ?>">
                <div><label class="lbl" for="oa-<?= (int)$o['id'] ?>">Rachunek do zwrotu (26 cyfr)</label><input id="oa-<?= (int)$o['id'] ?>" name="account" class="inp font-mono" required placeholder="PL…"></div>
                <div><label class="lbl" for="oq-<?= (int)$o['id'] ?>">Kwota (puste = cała <?= $fmt($o['amount']) ?>)</label><input id="oq-<?= (int)$o['id'] ?>" name="amount" class="inp" inputmode="decimal"></div>
                <div class="sm:col-span-2"><label class="lbl" for="ot-<?= (int)$o['id'] ?>">Tytuł przelewu</label><input id="ot-<?= (int)$o['id'] ?>" name="title" class="inp" required maxlength="140" value="Zwrot nadpłaty za zajęcia — <?= h($o['participant_name']) ?>"></div>
                <div class="sm:col-span-2 text-right"><button class="btn-pri">Zwróć i wyślij do EODoK</button></div>
              </form></details>
            <details class="mt-1 rounded border border-red-200"><summary class="cursor-pointer px-2 py-1 text-xs font-medium text-red-700">Usuń nadpłatę</summary>
              <form method="post" class="flex flex-wrap items-end gap-2 border-t border-red-200 p-2" onsubmit="return confirm('Usunąć wpis nadpłaty <?= h(addslashes($o['participant_name'])) ?> (<?= $fmt($o['amount']) ?> zł) bezpowrotnie? Ślad zostanie tylko w audycie.')">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="op_delete"><input type="hidden" name="_tab" value="settle"><input type="hidden" name="op_id" value="<?= (int)$o['id'] ?>">
                <div class="min-w-[12rem] flex-1"><label class="lbl" for="od-<?= (int)$o['id'] ?>">Powód usunięcia (wymagany)</label><input id="od-<?= (int)$o['id'] ?>" name="reason" class="inp" required minlength="5" maxlength="300"></div>
                <button class="btn-sec !text-red-700">Usuń trwale</button>
                <p class="w-full text-xs text-slate-500">Nie usuwa wpłat w księdze — jeśli saldo nadal ma nadpłatę, „Wykryj nadpłaty" może utworzyć wpis ponownie.</p>
              </form></details></td></tr>
        <?php endforeach; ?>
        <?php if (!$st_op): ?><tr><td colspan="4" class="py-6 text-center text-slate-500">Brak nadpłat do rozdysponowania.</td></tr><?php endif; ?>
        </tbody></table>
    </section>
  </div>

<?php else: ?>
  <!-- ═══ Historia ═══ -->
  <section class="card overflow-x-auto">
    <h2 class="font-semibold mb-2">Historia wyliczeń (zatwierdzone)</h2>
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Kiedy</th><th class="pr-3">Uczestnik</th><th class="pr-3">Grupa / typ</th><th class="pr-3 text-right">Baza</th><th class="pr-3">Kroki</th><th class="pr-3 text-right">Cena</th><th class="pr-3">Źródło</th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($log as $l): $d = json_decode((string)$l['applied_discounts_json'], true) ?: []; ?>
        <tr><td class="py-2 pr-3 whitespace-nowrap text-xs"><?= h(date('d.m.Y H:i', strtotime((string)$l['created_at']))) ?><div class="text-slate-500"><?= h($l['created_by']) ?></div></td>
          <td class="pr-3"><?= h($l['participant_name'] ?? '—') ?></td>
          <td class="pr-3 text-xs"><?= h(($l['course_name'] ?? '—') . ' · ' . ($l['type_name'] ?? '?') . ' · ' . $l['mode']) ?></td>
          <td class="pr-3 text-right tabular-nums"><?= $fmt($l['base_price']) ?></td>
          <td class="pr-3 text-xs"><?= h(implode(' → ', array_map(fn($s) => $s['name'] . ' (' . $fmt($s['after']) . ')', $d['steps'] ?? [])) ?: '—') ?></td>
          <td class="pr-3 text-right tabular-nums font-semibold"><?= $fmt($l['final_price']) ?></td>
          <td class="pr-3 text-xs"><?= $l['source'] === 'override' ? '<span class="text-amber-700">nadpisano</span> — ' . h($l['note']) . ' (cennik ' . $fmt($d['engine_final'] ?? 0) . ')' : 'z cennika' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$log): ?><tr><td colspan="7" class="py-6 text-center text-slate-500">Brak zatwierdzonych wyliczeń.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
  <section class="card overflow-x-auto">
    <h2 class="font-semibold mb-2">Dziennik audytu cennika</h2>
    <ul class="space-y-1 text-xs">
      <?php foreach ($audit as $a): $det = json_decode((string)$a['details'], true) ?: []; ?>
      <li class="border-l-2 border-slate-200 pl-3"><span class="font-medium"><?= h($a['action']) ?></span> · <?= h($a['created_at']) ?> · <?= h($det['by'] ?? '') ?> · IP <?= h((string)$a['ip_address']) ?>
        <span class="text-slate-500"><?= h(mb_strimwidth(json_encode(array_diff_key($det, ['by' => 1]), JSON_UNESCAPED_UNICODE), 0, 220, '…')) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$audit): ?><li class="text-slate-500">Brak wpisów.</li><?php endif; ?>
    </ul>
  </section>
<?php endif; ?>
</main>

<script>
function prApp() {
  const blankType = { id: '', name: '', slug: '', mode: 'dowolna', base_price: '', description: '', is_active: true };
  const blankRule = { id: '', name: '', discount_type: 'percentage', discount_value: '', target_lesson_type_id: '', condition_type: 'none',
                      condition_value: '', date_from: '', date_to: '', priority: 100, stackable: true, is_active: true };
  return {
    types: <?= $J(array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'base_price' => (float)$t['base_price']], array_filter($types, fn($t) => $t['is_active']))) ?>,
    courses: <?= $J(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'lesson_type_id' => $c['lesson_type_id'] ? (int)$c['lesson_type_id'] : null], $courses)) ?>,
    parts: <?= $J(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'statuses' => $p['statuses']], $parts)) ?>,
    enr: <?= $J($enr_by_course) ?>,
    statuses: <?= $J(TI_PRICING_STATUSES) ?>,
    s: { course: '', type: '', client: '', date: new Date().toISOString().slice(0, 10), ov: false, st: [], groups: 1, credit: 0, start: '' },
    pq: '', r: null, t: { ...blankType }, ru: { ...blankRule }, seq: 0,
    init() {},
    zl(v) { return Number(v || 0).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł'; },
    partsFiltered() { const q = this.pq.trim().toLowerCase(); return this.parts.filter(p => !q || p.name.toLowerCase().includes(q)).slice(0, 300); },
    clientStatuses() { const p = this.parts.find(x => String(x.id) === String(this.s.client)); return p && p.statuses ? p.statuses.split(',') : []; },
    toggleSt(k) { this.s.st = this.s.st.includes(k) ? this.s.st.filter(x => x !== k) : [...this.s.st, k]; this.calc(); },
    onCourse() { const c = this.courses.find(x => String(x.id) === String(this.s.course)); if (c && c.lesson_type_id) this.s.type = String(c.lesson_type_id); this.calc(); },
    onClient() { this.calc(); },
    canApply() { return this.r && this.r.ok && this.s.client && this.s.course && (this.enr[this.s.course] || []).includes(Number(this.s.client))
                  && (this.courses.find(x => String(x.id) === String(this.s.course)) || {}).lesson_type_id; },
    async calc() {
      if (!this.s.type) { this.r = null; return; }
      const q = new URLSearchParams({ json: 'calc', type: this.s.type, client: this.s.client, course: this.s.course, date: this.s.date });
      if (this.s.ov) { q.set('ov', '1'); q.set('statuses', this.s.st.join(',')); q.set('groups', this.s.groups); q.set('credit', this.s.credit); q.set('start_date', this.s.start); }
      const my = ++this.seq;
      try { const res = await (await fetch('pricing.php?' + q.toString(), { credentials: 'same-origin' })).json(); if (my === this.seq) this.r = res; }
      catch (e) { this.r = { ok: false, error: 'Błąd kalkulacji.' }; }
    },
    editType(x) { this.t = x ? { ...x, is_active: !!Number(x.is_active), description: x.description || '' } : { ...blankType }; window.scrollTo({ top: 0, behavior: 'smooth' }); },
    editRule(x) {
      this.ru = x ? { ...x, target_lesson_type_id: x.target_lesson_type_id ? String(x.target_lesson_type_id) : '', condition_value: x.condition_value || '',
                      date_from: (x.date_from || '').slice(0, 10), date_to: (x.date_to || '').slice(0, 10), stackable: !!Number(x.stackable), is_active: !!Number(x.is_active) }
                  : { ...blankRule };
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    ruSt() { return (this.ru.condition_value || '').split(',').map(x => x.trim()).filter(Boolean); },
    toggleRuSt(k) { const a = this.ruSt(); this.ru.condition_value = (a.includes(k) ? a.filter(x => x !== k) : [...a, k]).join(','); },
    ruPreview() {
      const v = this.ru.discount_type === 'percentage' ? (this.ru.discount_value || 0) + '%' : this.zl(this.ru.discount_value) + '/h';
      const tt = this.types.find(x => String(x.id) === String(this.ru.target_lesson_type_id));
      const cond = { none: 'zawsze', participant_status: 'gdy uczestnik ma status: ' + (this.ruSt().map(k => this.statuses[k] || k).join(' lub ') || '—'),
        early_bird: 'gdy do startu grupy zostało co najmniej ' + (this.ru.condition_value || '?') + ' dni',
        bundle_quantity: 'gdy uczestnik jest zapisany do co najmniej ' + (this.ru.condition_value || '?') + ' grup',
        virtual_account_overpayment: 'gdy nadpłata na koncie wynosi co najmniej ' + this.zl(this.ru.condition_value) }[this.ru.condition_type];
      return 'Rabat ' + v + ' na ' + (tt ? '„' + tt.name + '”' : 'wszystkie typy zajęć') + ', ' + cond
        + (this.ru.date_from || this.ru.date_to ? ', w okresie ' + (this.ru.date_from || '…') + ' – ' + (this.ru.date_to || '…') : '')
        + (this.ru.stackable ? '.' : ' — nie łączy się z innymi rabatami.');
    },
  };
}
</script>
</body>
</html>
