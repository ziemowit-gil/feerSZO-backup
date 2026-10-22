<?php
/**
 * modules/noclegi/lib/noclegiFinance.php — filar 3: budżety / granty, limity kwotowe, obieg faktur i potwierdzeń.
 *
 * Budżet (nocl_budgets) = projekt albo grant z limitem całkowitym i limitem za osobę za noc. Koszty zgłoszeń
 * (noce × cena pokoju) liczą się od statusu „W trakcie”. Potwierdzenie rezerwacji przypisanej do budżetu wymaga
 * akceptacji Koordynatora Projektu (noclApproveBudget) i mieści się w limitach (noclBudgetCheck).
 * Faktury: oczekuje → otrzymana → zatwierdzona (Koordynator budżetu / Logistyk) → zapłacona (Logistyk) lub odrzucona.
 */
require_once __DIR__ . '/noclegiRequests.php';

function noclBudgetList(bool $onlyActive = false): array {
    return db_all("SELECT * FROM nocl_budgets " . ($onlyActive ? 'WHERE is_active = 1 ' : '') . "ORDER BY is_active DESC, code");
}
function noclBudgetGet(int $id): ?array { return db_one("SELECT * FROM nocl_budgets WHERE id = ?", [$id]) ?: null; }

function noclBudgetValidate(array $in, int $id = 0): array {
    $d = ['code' => trim((string)($in['code'] ?? '')), 'name' => trim((string)($in['name'] ?? '')),
          'limit_total' => (string)noclMoney((string)($in['limit_total'] ?? '0')), 'limit_per_night' => (string)noclMoney((string)($in['limit_per_night'] ?? '0')),
          'valid_from' => (string)($in['valid_from'] ?? ''), 'valid_to' => (string)($in['valid_to'] ?? ''), 'note' => trim((string)($in['note'] ?? '')),
          'is_active' => !empty($in['is_active']) ? 1 : 0];
    $e = [];
    if ($d['code'] === '') $e['code'] = 'Podaj kod budżetu (np. numer projektu).';
    elseif (db_one("SELECT 1 FROM nocl_budgets WHERE code = ? AND id != ?", [$d['code'], $id])) $e['code'] = 'Budżet o takim kodzie już istnieje.';
    if ($d['name'] === '') $e['name'] = 'Podaj nazwę projektu lub grantu.';
    if ((float)$d['limit_total'] < 0 || (float)$d['limit_per_night'] < 0) $e['limit_total'] = 'Limity nie mogą być ujemne (0 = bez limitu).';
    foreach (['valid_from', 'valid_to'] as $k) if ($d[$k] !== '' && !noclDateOk($d[$k])) $e[$k] = 'Data w formacie RRRR-MM-DD.';
    if (!$e && $d['valid_from'] !== '' && $d['valid_to'] !== '' && $d['valid_to'] < $d['valid_from']) $e['valid_to'] = 'Koniec okresu przed jego początkiem.';
    return [$d, $e];
}
function noclBudgetSave(array $d, int $id = 0): int {
    $now = noclNow();
    if ($id) { $d['updated_at'] = $now; db_update('nocl_budgets', $d, $id); noclLog(null, 'budzet', 'zmieniono: ' . $d['code'], 'budget'); return $id; }
    $id = db_insert('nocl_budgets', $d + ['created_at' => $now, 'updated_at' => $now]);
    noclLog(null, 'budzet', 'dodano: ' . $d['code'], 'budget');
    return $id;
}

/** Podsumowanie budżetu: zobowiązania (koszty zgłoszeń), faktury, saldo względem limitu. */
function noclBudgetSummary(int $id): array {
    $b = noclBudgetGet($id) ?: [];
    $commit = (float)(db_one("SELECT COALESCE(SUM(CAST(cost_total AS REAL)),0) AS s FROM nocl_requests WHERE budget_id = ? AND status IN ('w_trakcie','potwierdzone','zrealizowane')", [$id])['s'] ?? 0);
    $inv = db_one("SELECT COALESCE(SUM(CASE WHEN status != 'odrzucona' THEN CAST(amount AS REAL) END),0) AS s,
                          COALESCE(SUM(CASE WHEN status = 'zaplacona' THEN CAST(amount AS REAL) END),0) AS paid FROM nocl_invoices WHERE budget_id = ?", [$id]);
    $limit = (float)($b['limit_total'] ?? 0);
    return ['budget' => $b, 'committed' => $commit, 'invoiced' => (float)$inv['s'], 'paid' => (float)$inv['paid'],
            'limit' => $limit, 'left' => $limit > 0 ? $limit - max($commit, (float)$inv['s']) : null];
}

/**
 * Kontrola limitów i okresu budżetu dla zgłoszenia ($r z room_id i date_*). Komunikat błędu albo null.
 * $confirming = true przy potwierdzaniu (te same reguły — osobny parametr zostawia miejsce na łagodniejsze ostrzeżenia).
 */
function noclBudgetCheck(array $r, bool $confirming): ?string {
    $bid = (int)($r['budget_id'] ?? 0);
    if (!$bid) return null;
    $b = noclBudgetGet($bid);
    if (!$b || !(int)$b['is_active']) return 'Budżet zgłoszenia jest zamknięty albo nie istnieje.';
    if ($b['valid_from'] !== '' && $r['date_from'] < $b['valid_from']) return 'Pobyt zaczyna się przed okresem budżetu ' . $b['code'] . ' (od ' . date('d.m.Y', strtotime($b['valid_from'])) . ').';
    if ($b['valid_to'] !== '' && $r['date_to'] > $b['valid_to']) return 'Pobyt kończy się po okresie budżetu ' . $b['code'] . ' (do ' . date('d.m.Y', strtotime($b['valid_to'])) . ').';
    $cost = (float)($r['cost_total'] ?? 0);
    if (!$cost && (int)($r['room_id'] ?? 0)) $cost = noclRequestCost($r);
    $nights = max(1, noclNights($r['date_from'], $r['date_to']));
    $lpn = (float)$b['limit_per_night'];
    if ($lpn > 0 && $cost / $nights > $lpn + 0.005) return 'Cena za noc (' . noclMoneyFmt($cost / $nights) . ') przekracza limit budżetu ' . $b['code'] . ' (' . noclMoneyFmt($lpn) . ').';
    $lt = (float)$b['limit_total'];
    if ($lt > 0) {
        $other = (float)(db_one("SELECT COALESCE(SUM(CAST(cost_total AS REAL)),0) AS s FROM nocl_requests WHERE budget_id = ? AND id != ? AND status IN ('w_trakcie','potwierdzone','zrealizowane')", [$bid, (int)($r['id'] ?? 0)])['s'] ?? 0);
        if ($other + $cost > $lt + 0.005) return 'Przekroczony limit całkowity budżetu ' . $b['code'] . ': zobowiązania ' . noclMoneyFmt($other) . ' + to zgłoszenie ' . noclMoneyFmt($cost) . ' > limit ' . noclMoneyFmt($lt) . '.';
    }
    return null;
}

/** Akceptacja kosztu przez Koordynatora budżetu (lub Logistyka). */
function noclApproveBudget(int $requestId, int $by, bool $approve = true): ?string {
    $r = noclRequestGet($requestId);
    if (!$r) return 'Nie znaleziono zgłoszenia.';
    if (!(int)$r['budget_id']) return 'Zgłoszenie nie jest przypisane do budżetu.';
    $role = noclRole($by);
    if (!($role === 'logistyk' || ($role === 'koordynator' && noclCoordCovers($by, (int)$r['budget_id'])))) return 'Akceptuje Koordynator Projektu tego budżetu.';
    if (in_array($r['status'], ['zrealizowane', 'anulowane'], true)) return 'Zgłoszenie jest zamknięte.';
    if ($approve && ($e = noclBudgetCheck($r, false))) return $e;
    db()->prepare("UPDATE nocl_requests SET budget_approved=?, budget_approved_by=?, budget_approved_at=?, updated_at=? WHERE id=?")
        ->execute([$approve ? 1 : 0, $approve ? $by : null, $approve ? noclNow() : null, noclNow(), $requestId]);
    noclLog($requestId, 'budzet', $approve ? 'zaakceptowano koszt w budżecie ' . $r['budget_code'] : 'cofnięto akceptację kosztu', 'request', $by);
    return null;
}
function noclSetBudget(int $requestId, ?int $budgetId, int $by): ?string {
    $r = noclRequestGet($requestId);
    if (!$r) return 'Nie znaleziono zgłoszenia.';
    if (!noclIsLogistyk($by) && !((int)$r['user_id'] === $by && in_array($r['status'], ['wniosek', 'w_trakcie'], true))) return 'Brak uprawnień do zmiany budżetu.';
    if ($budgetId && !noclBudgetGet($budgetId)) return 'Nie znaleziono budżetu.';
    db()->prepare("UPDATE nocl_requests SET budget_id=?, budget_approved=0, budget_approved_by=NULL, budget_approved_at=NULL, updated_at=? WHERE id=?")->execute([$budgetId ?: null, noclNow(), $requestId]);
    noclLog($requestId, 'budzet', $budgetId ? 'przypisano budżet ' . (noclBudgetGet($budgetId)['code'] ?? '') : 'odpięto budżet', 'request', $by);
    return null;
}

// ── Faktury ────────────────────────────────────────────────────────────────────

const NOCL_FAKTURA_PRZEJSCIA = ['oczekuje' => ['otrzymana', 'odrzucona'], 'otrzymana' => ['zatwierdzona', 'odrzucona'], 'zatwierdzona' => ['zaplacona', 'otrzymana'], 'zaplacona' => [], 'odrzucona' => ['oczekuje']];

function noclInvoiceList(array $f = [], ?array $budgets = null): array {
    $w = ['1=1']; $p = [];
    if ($budgets !== null) { if (!$budgets) return []; $w[] = 'i.budget_id IN (' . implode(',', array_map('intval', $budgets)) . ')'; }
    if (!empty($f['budget_id'])) { $w[] = 'i.budget_id = ?'; $p[] = (int)$f['budget_id']; }
    if (!empty($f['status'])) { $w[] = 'i.status = ?'; $p[] = (string)$f['status']; }
    return db_all("SELECT i.*, b.code AS budget_code, fa.name AS facility_name, r.person_name
                     FROM nocl_invoices i LEFT JOIN nocl_budgets b ON b.id = i.budget_id LEFT JOIN nocl_facilities fa ON fa.id = i.facility_id
                     LEFT JOIN nocl_requests r ON r.id = i.request_id WHERE " . implode(' AND ', $w) . " ORDER BY i.id DESC LIMIT 500", $p);
}
function noclInvoiceGet(int $id): ?array { return db_one("SELECT * FROM nocl_invoices WHERE id = ?", [$id]) ?: null; }

function noclInvoiceValidate(array $in): array {
    $d = ['number' => trim((string)($in['number'] ?? '')), 'category' => (string)($in['category'] ?? 'nocleg'),
          'issue_date' => (string)($in['issue_date'] ?? ''), 'due_date' => (string)($in['due_date'] ?? ''),
          'amount' => (string)noclMoney((string)($in['amount'] ?? '0')), 'note' => trim((string)($in['note'] ?? '')),
          'budget_id' => (int)($in['budget_id'] ?? 0) ?: null, 'request_id' => (int)($in['request_id'] ?? 0) ?: null, 'facility_id' => (int)($in['facility_id'] ?? 0) ?: null];
    $e = [];
    if ($d['number'] === '') $e['number'] = 'Podaj numer faktury lub potwierdzenia.';
    if (!noclDictAllowedKey($d['category'], noclKategorieKosztu())) $e['category'] = 'Wybierz kategorię kosztu.';
    if ((float)$d['amount'] <= 0) $e['amount'] = 'Kwota musi być większa od zera.';
    foreach (['issue_date', 'due_date'] as $k) if ($d[$k] !== '' && !noclDateOk($d[$k])) $e[$k] = 'Data w formacie RRRR-MM-DD.';
    if ($d['request_id']) {
        $rq = noclRequestGet($d['request_id']);
        if (!$rq) $e['request_id'] = 'Nie znaleziono zgłoszenia o tym numerze.';
        else { if (!$d['budget_id']) $d['budget_id'] = (int)$rq['budget_id'] ?: null; if (!$d['facility_id']) $d['facility_id'] = (int)$rq['facility_id'] ?: null; }
    }
    return [$d, $e];
}
function noclInvoiceCreate(array $d, int $by, array $file = []): int {
    $now = noclNow();
    $id = db_insert('nocl_invoices', $d + $file + ['status' => 'otrzymana', 'created_by' => $by ?: null, 'created_at' => $now, 'updated_at' => $now]);
    noclLog($d['request_id'] ?? null, 'faktura', $d['number'] . ', ' . noclMoneyFmt((float)$d['amount']), 'invoice', $by);
    return $id;
}
function noclInvoiceTransition(int $id, string $to, int $by): ?string {
    $i = noclInvoiceGet($id);
    if (!$i) return 'Nie znaleziono faktury.';
    if (!in_array($to, NOCL_FAKTURA_PRZEJSCIA[$i['status']] ?? [], true)) return 'Niedozwolona zmiana statusu faktury.';
    $role = noclRole($by);
    $coord = $role === 'koordynator' && noclCoordCovers($by, (int)$i['budget_id'] ?: null);
    if ($to === 'zatwierdzona' && !($coord || $role === 'logistyk')) return 'Fakturę zatwierdza Koordynator Projektu budżetu albo Logistyk.';
    if ($to !== 'zatwierdzona' && $role !== 'logistyk' && !($to === 'odrzucona' && $coord)) return 'Tę zmianę wykonuje Logistyk.';
    $set = 'status = ?, updated_at = ?'; $p = [$to, noclNow()];
    if ($to === 'zatwierdzona') { $set .= ', approved_by = ?, approved_at = ?'; array_push($p, $by, noclNow()); }
    $p[] = $id;
    db()->prepare("UPDATE nocl_invoices SET $set WHERE id = ?")->execute($p);
    noclLog($i['request_id'] ? (int)$i['request_id'] : null, 'faktura', $i['number'] . ': ' . $i['status'] . ' → ' . $to, 'invoice', $by);
    return null;
}
