<?php
/**
 * modules/ti_pricing/logic/pricingEngine.php — silnik cen i rabatów zależnych od typu zajęć (TI).
 *
 * Cena = stawka godzinowa (zł/h) zapisu kursanta. Algorytm TiPricingEngine::calculate():
 *   1. cena bazowa typu zajęć (lesson_types.base_price);
 *   2. profil uczestnika: statusy (ti_pricing_participant_status + automatyczna
 *      „kontynuacja” przy wcześniejszych zapisach), liczba aktywnych grup,
 *      nadpłata na koncie (ti_client_balance), data startu grupy;
 *   3. aktywne reguły dla typu (albo wszystkich typów) obowiązujące w dniu
 *      kalkulacji, rosnąco po priority (remis: id) — każda sprawdzana warunkiem;
 *   4. łączenie: reguła stackable=0 („nie łączy się”) liczona jest od ceny BAZOWEJ;
 *      jeśli daje niższą cenę niż dotychczasowe rabaty razem — zastępuje je (te idą
 *      do pominiętych z powodem), inaczej sama jest pominięta. Po zastosowaniu reguły
 *      wyłącznej dalsze reguły są pomijane. Zwykłe reguły: procent od ceny bieżącej
 *      (kolejno), kwota odejmowana; cena nigdy poniżej 0 — zawsze korzystniej dla uczestnika.
 * Każdy krok (zastosowany i pominięty, z powodem) trafia do wyniku — ten sam zapis
 * idzie do calculated_prices_log przy zatwierdzeniu.
 *
 * Zapis zmian (typy, reguły, statusy, zastosowanie ceny do zapisu, nadpisanie)
 * w transakcjach PDO z wpisem audit_logs (akcje pricing.*). Kwoty na groszach.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

const TI_PRICING_CONDITIONS = [
    'none'                        => 'Bez warunku',
    'participant_status'          => 'Status uczestnika',
    'early_bird'                  => 'Early bird (dni przed startem grupy)',
    'bundle_quantity'             => 'Pakiet (min. liczba grup uczestnika)',
    'virtual_account_overpayment' => 'Nadpłata na koncie (min. zł)',
];
const TI_PRICING_STATUSES = [
    'standard' => 'Standard', 'student' => 'Uczeń / student', 'ngo' => 'Organizacja pozarządowa (NGO)',
    'wolontariusz' => 'Wolontariusz', 'senior' => 'Senior', 'niepelnosprawnosc' => 'Osoba z niepełnosprawnością',
    'kontynuacja' => 'Kontynuacja nauki (automatycznie)',
];
const TI_PRICING_MODES = ['dowolna' => 'Dowolna', 'stacjonarna' => 'Stacjonarna', 'online' => 'Online'];

function ti_pricing_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    audit_logs_migrate();
    db()->exec((string)file_get_contents(dirname(__DIR__) . '/schema.sql'));
}

function ti_pricing_gr(float|int|string $v): int { return (int)round((float)$v * 100); }
function ti_pricing_zl(int $gr): float { return round($gr / 100, 2); }
function ti_pricing_fmt(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }
/** Kwota/wartość z formularza (≥ 0, max 2 miejsca) → float albo null. */
function ti_pricing_num(string $raw, float $max = 1000000): ?float {
    $s = str_replace([' ', "\u{00A0}"], '', trim($raw));
    if (!preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $s)) return null;
    $v = (float)str_replace(',', '.', $s);
    return $v >= 0 && $v <= $max ? round($v, 2) : null;
}
function ti_pricing_date(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    return $d && $d->format('Y-m-d') === $raw ? $raw : null;
}

final class TiPricingEngine
{
    /** Profil uczestnika do warunków; $ov nadpisuje pola (symulator). */
    public static function profile(?int $client_id, ?int $course_id, array $ov = []): array {
        $statuses = []; $groups = 0; $credit = 0.0; $cont = false;
        if ($client_id) {
            $r = db_one("SELECT statuses FROM ti_pricing_participant_status WHERE participant_id=?", [$client_id]);
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string)($r['statuses'] ?? '')))));
            $groups = (int)(db_one("SELECT COUNT(*) n FROM k30_ti_enrollments WHERE client_id=? AND status='active'", [$client_id])['n'] ?? 0);
            if ($course_id && !db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=? AND status='active'", [$client_id, $course_id])) $groups++;
            // Kontynuacja: zapis w innej grupie, która już się zakończyła albo z której kursant odszedł
            $cont = (bool)db_one(
                "SELECT 1 FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id=e.course_id
                  WHERE e.client_id=? AND e.course_id != ? AND (e.status!='active' OR c.status IN ('archived','cancelled'))",
                [$client_id, (int)$course_id]);
            try { $credit = (float)ti_client_balance($client_id)['credit']; } catch (\Throwable $e) { $credit = 0.0; }
        }
        if ($cont && !in_array('kontynuacja', $statuses, true)) $statuses[] = 'kontynuacja';
        $start = null;
        if ($course_id) {
            $s = db_one("SELECT MIN(lesson_date) d FROM k30_ti_sessions WHERE course_id=? AND status NOT IN ('cancelled','removed','draft','reserved')", [$course_id]);
            $start = $s['d'] ?? null;
        }
        $p = ['statuses' => $statuses, 'groups' => max(1, $groups), 'credit' => round($credit, 2), 'start_date' => $start];
        foreach (['statuses', 'groups', 'credit', 'start_date'] as $k) if (array_key_exists($k, $ov) && $ov[$k] !== null && $ov[$k] !== '') $p[$k] = $ov[$k];
        return $p;
    }

    /** Czy warunek reguły jest spełniony — [bool, powód]. */
    public static function checkCondition(array $rule, array $p, string $date): array {
        $cv = trim((string)($rule['condition_value'] ?? ''));
        switch ($rule['condition_type']) {
            case 'none': return [true, 'bez warunku'];
            case 'participant_status':
                $need = array_values(array_filter(array_map('trim', explode(',', strtolower($cv)))));
                $hit  = array_values(array_intersect($need, array_map('strtolower', $p['statuses'])));
                return $hit ? [true, 'status: ' . implode(', ', $hit)] : [false, 'brak statusu ' . ($cv !== '' ? $cv : '—')];
            case 'early_bird':
                $days = (int)$cv;
                if (empty($p['start_date'])) return [false, 'grupa bez terminu startu'];
                $left = (int)floor((strtotime((string)$p['start_date']) - strtotime($date)) / 86400);
                if ($left < 0) return [false, 'grupa już wystartowała (' . date('d.m.Y', strtotime((string)$p['start_date'])) . ')'];
                return $left >= $days ? [true, "{$left} dni przed startem (min. {$days})"] : [false, "{$left} dni przed startem — wymagane {$days}"];
            case 'bundle_quantity':
                $min = max(1, (int)$cv);
                return (int)$p['groups'] >= $min ? [true, "{$p['groups']} grup (min. {$min})"] : [false, "{$p['groups']} grup — wymagane {$min}"];
            case 'virtual_account_overpayment':
                $min = (float)str_replace(',', '.', $cv);
                return (float)$p['credit'] + 0.001 >= $min && (float)$p['credit'] > 0.005
                    ? [true, 'nadpłata ' . ti_pricing_fmt((float)$p['credit'])] : [false, 'nadpłata ' . ti_pricing_fmt((float)$p['credit']) . ' — wymagane ' . ti_pricing_fmt($min)];
        }
        return [false, 'nieznany warunek'];
    }

    /**
     * Kalkulacja ceny. $ctx: lesson_type_id (wymagane), client_id, course_id, date (Y-m-d), profile (nadpisania).
     * @return array{ok:bool, error?:string, type?:array, base:float, final:float, steps:list<array>, skipped:list<array>, profile:array}
     */
    public static function calculate(array $ctx): array {
        ti_pricing_migrate();
        $type = db_one("SELECT * FROM lesson_types WHERE id=?", [(int)($ctx['lesson_type_id'] ?? 0)]);
        if (!$type) return ['ok' => false, 'error' => 'Wybierz typ zajęć.', 'base' => 0, 'final' => 0, 'steps' => [], 'skipped' => [], 'profile' => []];
        $date = ti_pricing_date($ctx['date'] ?? '') ?? date('Y-m-d');
        $p    = self::profile(!empty($ctx['client_id']) ? (int)$ctx['client_id'] : null, !empty($ctx['course_id']) ? (int)$ctx['course_id'] : null, $ctx['profile'] ?? []);
        $rules = db_all(
            "SELECT * FROM discount_rules WHERE is_active=1 AND (target_lesson_type_id IS NULL OR target_lesson_type_id=?)
               AND (date_from IS NULL OR date_from='' OR date(date_from) <= date(?)) AND (date_to IS NULL OR date_to='' OR date(date_to) >= date(?))
             ORDER BY priority ASC, id ASC", [(int)$type['id'], $date, $date]);
        $cur = ti_pricing_gr($type['base_price']); $steps = []; $skipped = []; $locked = null;
        foreach ($rules as $r) {
            if ($locked) { $skipped[] = ['rule_id' => (int)$r['id'], 'name' => $r['name'], 'why' => 'reguła „' . $locked . '” nie łączy się z innymi']; continue; }
            [$ok, $why] = self::checkCondition($r, $p, $date);
            if (!$ok) { $skipped[] = ['rule_id' => (int)$r['id'], 'name' => $r['name'], 'why' => $why]; continue; }
            if (!(int)$r['stackable'] && $steps) {
                // Reguła wyłączna vs rabaty dotąd: wybieramy korzystniejszą dla uczestnika
                $base_gr = ti_pricing_gr($type['base_price']);
                $alone = max(0, $base_gr - ($r['discount_type'] === 'percentage'
                    ? (int)round($base_gr * min(100, (float)$r['discount_value']) / 100) : ti_pricing_gr($r['discount_value'])));
                if ($alone >= $cur) {
                    $skipped[] = ['rule_id' => (int)$r['id'], 'name' => $r['name'], 'why' => 'nie łączy się — dotychczasowe rabaty są korzystniejsze']; continue;
                }
                foreach ($steps as $st) $skipped[] = ['rule_id' => $st['rule_id'], 'name' => $st['name'], 'why' => 'zastąpiony korzystniejszym rabatem wyłącznym „' . $r['name'] . '”'];
                $steps = []; $cur = $base_gr;
            }
            $before = $cur;
            $cut = $r['discount_type'] === 'percentage'
                ? (int)round($cur * min(100, (float)$r['discount_value']) / 100)
                : ti_pricing_gr($r['discount_value']);
            $cur = max(0, $cur - $cut);
            $steps[] = ['rule_id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['discount_type'], 'value' => (float)$r['discount_value'],
                        'priority' => (int)$r['priority'], 'before' => ti_pricing_zl($before), 'after' => ti_pricing_zl($cur),
                        'amount' => ti_pricing_zl($before - $cur), 'reason' => $why, 'stackable' => (bool)(int)$r['stackable']];
            if (!(int)$r['stackable']) $locked = $r['name'];
        }
        return ['ok' => true, 'type' => $type, 'date' => $date, 'base' => (float)$type['base_price'], 'final' => ti_pricing_zl($cur),
                'steps' => $steps, 'skipped' => $skipped, 'profile' => $p];
    }
}

// ── Zapis: typy zajęć ────────────────────────────────────────────────────────
function ti_pricing_save_type(array $in, string $by, ?int $uid): int|string {
    ti_pricing_migrate();
    $id   = (int)($in['id'] ?? 0);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    if ($slug === '') $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), '-');
    $price = ti_pricing_num((string)($in['base_price'] ?? ''));
    $mode  = array_key_exists((string)($in['mode'] ?? ''), TI_PRICING_MODES) ? (string)$in['mode'] : 'dowolna';
    if ($name === '') return 'Podaj nazwę typu zajęć.';
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $slug)) return 'Identyfikator (slug): małe litery, cyfry i myślniki.';
    if ($price === null) return 'Cena bazowa musi być liczbą ≥ 0 (np. 80,00).';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $dup = db_one("SELECT id FROM lesson_types WHERE slug=? AND id!=?", [$slug, $id]);
        if ($dup) { $pdo->rollBack(); return 'Taki identyfikator (slug) już istnieje.'; }
        $data = ['name' => $name, 'slug' => $slug, 'base_price' => $price, 'mode' => $mode,
                 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 1000), 'is_active' => !empty($in['is_active']) ? 1 : 0];
        if ($id > 0) {
            $old = db_one("SELECT * FROM lesson_types WHERE id=?", [$id]);
            if (!$old) { $pdo->rollBack(); return 'Nie znaleziono typu zajęć.'; }
            db()->prepare("UPDATE lesson_types SET name=?, slug=?, base_price=?, mode=?, description=?, is_active=?, updated_at=datetime('now') WHERE id=?")
                ->execute([...array_values($data), $id]);
            audit_log('pricing.lesson_type_updated', ['lesson_type_id' => $id, 'before' => array_intersect_key($old, $data), 'after' => $data, 'by' => $by], $uid);
        } else {
            $id = db_insert('lesson_types', $data);
            audit_log('pricing.lesson_type_created', ['lesson_type_id' => $id, 'after' => $data, 'by' => $by], $uid);
        }
        $pdo->commit();
        return $id;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd zapisu: ' . $e->getMessage(); }
}

// ── Zapis: reguły rabatowe ───────────────────────────────────────────────────
function ti_pricing_save_rule(array $in, string $by, ?int $uid): int|string {
    ti_pricing_migrate();
    $id    = (int)($in['id'] ?? 0);
    $name  = mb_substr(trim((string)($in['name'] ?? '')), 0, 160);
    $dtype = ($in['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percentage';
    $val   = ti_pricing_num((string)($in['discount_value'] ?? ''), $dtype === 'percentage' ? 100 : 1000000);
    $ctype = array_key_exists((string)($in['condition_type'] ?? ''), TI_PRICING_CONDITIONS) ? (string)$in['condition_type'] : 'none';
    $cval  = trim((string)($in['condition_value'] ?? ''));
    $tid   = (int)($in['target_lesson_type_id'] ?? 0) ?: null;
    $prio  = (int)($in['priority'] ?? 100);
    $from  = ti_pricing_date($in['date_from'] ?? '');
    $to    = ti_pricing_date($in['date_to'] ?? '');
    if ($name === '') return 'Podaj nazwę reguły.';
    if ($val === null) return $dtype === 'percentage' ? 'Rabat procentowy: liczba 0–100.' : 'Rabat kwotowy: liczba ≥ 0.';
    if ($prio < 0 || $prio > 9999) return 'Priorytet: liczba 0–9999.';
    if (trim((string)($in['date_from'] ?? '')) !== '' && !$from || trim((string)($in['date_to'] ?? '')) !== '' && !$to) return 'Nieprawidłowa data obowiązywania.';
    if ($from && $to && $to < $from) return 'Data „do” jest wcześniejsza niż „od”.';
    switch ($ctype) {
        case 'none': $cval = ''; break;
        case 'participant_status':
            $parts = array_values(array_filter(array_map(fn($s) => strtolower(trim($s)), explode(',', $cval))));
            if (!$parts) return 'Podaj co najmniej jeden status uczestnika.';
            foreach ($parts as $s) if (!preg_match('/^[a-z0-9_-]{2,40}$/', $s)) return 'Status „' . $s . '”: małe litery, cyfry, _ lub -.';
            $cval = implode(',', array_unique($parts)); break;
        case 'early_bird':
        case 'bundle_quantity':
            if (!preg_match('/^\d{1,4}$/', $cval) || (int)$cval < ($ctype === 'bundle_quantity' ? 2 : 1)) return $ctype === 'early_bird' ? 'Early bird: liczba dni (≥ 1).' : 'Pakiet: minimalna liczba grup (≥ 2).';
            $cval = (string)(int)$cval; break;
        case 'virtual_account_overpayment':
            $n = ti_pricing_num($cval); if ($n === null) return 'Nadpłata: kwota ≥ 0.'; $cval = (string)$n; break;
    }
    if ($tid && !db_one("SELECT 1 FROM lesson_types WHERE id=?", [$tid])) return 'Nie ma takiego typu zajęć.';
    $data = ['name' => $name, 'discount_type' => $dtype, 'discount_value' => $val, 'target_lesson_type_id' => $tid,
             'condition_type' => $ctype, 'condition_value' => $cval !== '' ? $cval : null, 'date_from' => $from, 'date_to' => $to,
             'priority' => $prio, 'stackable' => !empty($in['stackable']) ? 1 : 0, 'is_active' => !empty($in['is_active']) ? 1 : 0];
    $pdo = db(); $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $old = db_one("SELECT * FROM discount_rules WHERE id=?", [$id]);
            if (!$old) { $pdo->rollBack(); return 'Nie znaleziono reguły.'; }
            $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
            db()->prepare("UPDATE discount_rules SET $set, updated_at=datetime('now') WHERE id=?")->execute([...array_values($data), $id]);
            audit_log('pricing.rule_updated', ['rule_id' => $id, 'before' => array_intersect_key($old, $data), 'after' => $data, 'by' => $by], $uid);
        } else {
            $id = db_insert('discount_rules', $data);
            audit_log('pricing.rule_created', ['rule_id' => $id, 'after' => $data, 'by' => $by], $uid);
        }
        $pdo->commit();
        return $id;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd zapisu: ' . $e->getMessage(); }
}

function ti_pricing_delete_rule(int $id, string $by, ?int $uid): ?string {
    ti_pricing_migrate();
    $pdo = db(); $pdo->beginTransaction();
    try {
        $old = db_one("SELECT * FROM discount_rules WHERE id=?", [$id]);
        if (!$old) { $pdo->rollBack(); return 'Nie znaleziono reguły.'; }
        db()->prepare("DELETE FROM discount_rules WHERE id=?")->execute([$id]);
        audit_log('pricing.rule_deleted', ['rule_id' => $id, 'before' => $old, 'by' => $by], $uid);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

// ── Integracja z TI: typy grup, statusy uczestników, stawka zapisu ───────────
function ti_pricing_set_course_types(int $course_id, ?int $type_id, ?int $online_type_id, string $by, ?int $uid): ?string {
    ti_pricing_migrate();
    if (!db_one("SELECT 1 FROM k30_ti_courses WHERE id=?", [$course_id])) return 'Nie ma takiej grupy.';
    $pdo = db(); $pdo->beginTransaction();
    try {
        db()->prepare("INSERT INTO ti_pricing_course_types (course_id, lesson_type_id, online_lesson_type_id, updated_at) VALUES (?,?,?,datetime('now'))
                       ON CONFLICT(course_id) DO UPDATE SET lesson_type_id=excluded.lesson_type_id, online_lesson_type_id=excluded.online_lesson_type_id, updated_at=excluded.updated_at")
            ->execute([$course_id, $type_id ?: null, $online_type_id ?: null]);
        audit_log('pricing.course_types_set', ['course_id' => $course_id, 'lesson_type_id' => $type_id, 'online_lesson_type_id' => $online_type_id, 'by' => $by], $uid);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

function ti_pricing_set_participant_status(int $client_id, array $statuses, string $by, ?int $uid): ?string {
    ti_pricing_migrate();
    $st = array_values(array_unique(array_filter(array_map(fn($s) => strtolower(trim((string)$s)), $statuses),
                                                 fn($s) => preg_match('/^[a-z0-9_-]{2,40}$/', $s) && $s !== 'kontynuacja')));
    $pdo = db(); $pdo->beginTransaction();
    try {
        $old = db_one("SELECT statuses FROM ti_pricing_participant_status WHERE participant_id=?", [$client_id]);
        db()->prepare("INSERT INTO ti_pricing_participant_status (participant_id, statuses, updated_by, updated_at) VALUES (?,?,?,datetime('now'))
                       ON CONFLICT(participant_id) DO UPDATE SET statuses=excluded.statuses, updated_by=excluded.updated_by, updated_at=excluded.updated_at")
            ->execute([$client_id, implode(',', $st), $by]);
        audit_log('pricing.participant_status_set', ['participant_id' => $client_id, 'before' => $old['statuses'] ?? '', 'after' => implode(',', $st), 'by' => $by], $uid);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

/** Typy zajęć przypisane grupie. */
function ti_pricing_course_types(int $course_id): array {
    ti_pricing_migrate();
    return db_one("SELECT * FROM ti_pricing_course_types WHERE course_id=?", [$course_id]) ?: ['lesson_type_id' => null, 'online_lesson_type_id' => null];
}

/**
 * Zatwierdza cenę z cennika jako stawkę zapisu kursanta (stacjonarną i — gdy grupa
 * ma typ online — online). $override: ['stacjonarna'=>zł, 'online'=>zł] nadpisuje
 * wynik silnika (wymaga uzasadnienia). Transakcja: stawki zapisu + log wyliczeń + audyt.
 * @return array{ok:bool, msg:string}
 */
function ti_pricing_apply_to_enrollment(int $course_id, int $client_id, string $by, ?int $uid, array $override = [], string $reason = ''): array {
    ti_pricing_migrate();
    $enr = db_one("SELECT * FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$course_id, $client_id]);
    if (!$enr) return ['ok' => false, 'msg' => 'Kursant nie jest zapisany do tej grupy.'];
    if ($m = ti_course_closed_guard(['course_id' => $course_id])) return ['ok' => false, 'msg' => $m];
    $ct = ti_pricing_course_types($course_id);
    if (!$ct['lesson_type_id']) return ['ok' => false, 'msg' => 'Grupa nie ma przypisanego typu zajęć (zakładka „Grupy”).'];
    if ($override && mb_strlen(trim($reason)) < 5) return ['ok' => false, 'msg' => 'Nadpisanie ceny wymaga uzasadnienia.'];
    $modes = ['stacjonarna' => (int)$ct['lesson_type_id']];
    if ($ct['online_lesson_type_id']) $modes['online'] = (int)$ct['online_lesson_type_id'];
    $pdo = db(); $pdo->beginTransaction();
    try {
        $set = []; $summary = [];
        foreach ($modes as $mode => $tid) {
            $calc = TiPricingEngine::calculate(['lesson_type_id' => $tid, 'client_id' => $client_id, 'course_id' => $course_id]);
            if (!$calc['ok']) throw new \RuntimeException($calc['error']);
            $final = $calc['final']; $src = 'applied';
            if (isset($override[$mode]) && $override[$mode] !== null) {
                if ($override[$mode] < 0) throw new \RuntimeException('Cena nie może być ujemna.');
                $final = round((float)$override[$mode], 2); $src = 'override';
            }
            db_insert('calculated_prices_log', [
                'participant_id' => $client_id, 'lesson_type_id' => $tid, 'course_id' => $course_id, 'mode' => $mode,
                'base_price' => $calc['base'], 'applied_discounts_json' => json_encode(['steps' => $calc['steps'], 'skipped' => $calc['skipped'], 'engine_final' => $calc['final']], JSON_UNESCAPED_UNICODE),
                'context_json' => json_encode($calc['profile'], JSON_UNESCAPED_UNICODE), 'final_price' => $final, 'source' => $src,
                'note' => $src === 'override' ? trim($reason) : '', 'created_by' => $by,
            ]);
            $set[$mode === 'online' ? 'hourly_rate_online' : 'hourly_rate'] = $final;
            $summary[] = ($mode === 'online' ? 'online ' : 'stacjonarnie ') . ti_pricing_fmt($final) . '/h' . ($src === 'override' ? ' (nadpisano, cennik: ' . ti_pricing_fmt($calc['final']) . ')' : '');
            audit_log($src === 'override' ? 'pricing.override' : 'pricing.applied', [
                'course_id' => $course_id, 'participant_id' => $client_id, 'mode' => $mode, 'lesson_type_id' => $tid,
                'engine_final' => $calc['final'], 'final' => $final, 'previous' => (float)$enr[$mode === 'online' ? 'hourly_rate_online' : 'hourly_rate'],
                'reason' => $src === 'override' ? trim($reason) : '', 'by' => $by], $uid);
        }
        $cols = implode(', ', array_map(fn($k) => "$k=?", array_keys($set)));
        db()->prepare("UPDATE k30_ti_enrollments SET $cols WHERE course_id=? AND client_id=?")->execute([...array_values($set), $course_id, $client_id]);
        $pdo->commit();
        return ['ok' => true, 'msg' => 'Stawka zapisu ustawiona z cennika: ' . implode('; ', $summary) . '.'];
    } catch (\Throwable $e) { $pdo->rollBack(); return ['ok' => false, 'msg' => $e->getMessage()]; }
}
