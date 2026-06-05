<?php
/**
 * api/strategy_webhook.php — Webhook n8n dla modułu Strategii Rozwoju NGO.
 *
 * Obsługiwane zdarzenia (JSON body):
 *   payment_added    {objective_id, amount, note}
 *   progress_update  {objective_id, wartosc_realizowana, budzet_wydany, budzet_przypisany, note}
 *   contract_linked  {objective_id, contract_type, contract_id}
 *   grant_linked     {objective_id, grant_id}
 *   snapshot_all     {}
 *
 * Autoryzacja: Bearer token z ustawień (klucz: strategy_webhook_token)
 *
 * Przykład n8n HTTP Request:
 *   POST https://domena.pl/api/strategy_webhook.php
 *   Authorization: Bearer TWOJ_TOKEN
 *   Content-Type: application/json
 *   Body: { "event": "progress_update", "objective_id": 3, "wartosc_realizowana": 75, ... }
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/strategy.php';

header('Content-Type: application/json; charset=utf-8');

function wh_ok(array $data = []): never {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function wh_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

// ── Tylko POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wh_err('Method Not Allowed', 405);
}

// ── Autoryzacja Bearer token ──────────────────────────────────────────────────
$token_setting = db_one("SELECT value FROM settings WHERE key_='strategy_webhook_token'");
$expected_token = $token_setting['value'] ?? '';

if ($expected_token) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
        wh_err('Brak tokenu autoryzacyjnego.', 401);
    }
    if (!hash_equals($expected_token, trim($m[1]))) {
        wh_err('Nieprawidłowy token.', 401);
    }
}

// ── Parsowanie JSON ───────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    wh_err('Nieprawidłowy JSON.');
}

$event = $payload['event'] ?? '';
if (!$event) wh_err('Brakuje pola "event".');

// ── Audit log helper ──────────────────────────────────────────────────────────
function strat_log(string $event, int $obj_id, string $detail): void {
    try {
        db_insert('contract_audit_log', [
            'contract_type' => 'strategy',
            'contract_id'   => $obj_id,
            'user_id'       => 0,
            'user_snapshot' => 'n8n webhook',
            'action'        => 'webhook_' . $event,
            'note'          => $detail,
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {}
}

// ── Obsługa zdarzeń ───────────────────────────────────────────────────────────

switch ($event) {

    // Nowa płatność — zwiększa budzet_wydany w ostatnim snapshot lub tworzy nowy
    case 'payment_added': {
        $obj_id = (int)($payload['objective_id'] ?? 0);
        $amount = (float)($payload['amount'] ?? 0);
        $note   = $payload['note'] ?? "Płatność +{$amount} PLN z n8n";

        if (!$obj_id || $amount <= 0) wh_err('Wymagane: objective_id (int), amount (float > 0).');

        $obj = db_one("SELECT id FROM strategy_objectives WHERE id=? AND status='aktywny'", [$obj_id]);
        if (!$obj) wh_err("Cel #{$obj_id} nie istnieje lub jest nieaktywny.", 404);

        // Pobierz ostatni snapshot tego dnia lub utwórz nowy
        $today    = date('Y-m-d');
        $existing = db_one(
            "SELECT * FROM strategy_progress WHERE objective_id=? AND snapshot_date=? ORDER BY id DESC LIMIT 1",
            [$obj_id, $today]
        );

        if ($existing) {
            db()->prepare("UPDATE strategy_progress SET budzet_wydany = budzet_wydany + ?, notatka=? WHERE id=?")
               ->execute([$amount, $note, $existing['id']]);
        } else {
            // Wez dane z poprzedniego snapshotu jako baze
            $prev = db_one(
                "SELECT * FROM strategy_progress WHERE objective_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1",
                [$obj_id]
            );
            db_insert('strategy_progress', [
                'objective_id'        => $obj_id,
                'wartosc_realizowana' => $prev['wartosc_realizowana'] ?? 0,
                'budzet_wydany'       => ($prev['budzet_wydany'] ?? 0) + $amount,
                'budzet_przypisany'   => $prev['budzet_przypisany'] ?? 0,
                'snapshot_date'       => $today,
                'source'              => 'n8n',
                'notatka'             => $note,
            ]);
        }
        strat_log($event, $obj_id, "Płatność +{$amount} PLN");
        wh_ok(['msg' => "Płatność {$amount} PLN zapisana dla celu #{$obj_id}."]);
    }

    // Aktualizacja postępu z n8n
    case 'progress_update': {
        $obj_id = (int)($payload['objective_id'] ?? 0);
        if (!$obj_id) wh_err('Wymagane: objective_id.');

        $obj = db_one("SELECT id FROM strategy_objectives WHERE id=?", [$obj_id]);
        if (!$obj) wh_err("Cel #{$obj_id} nie istnieje.", 404);

        $insert_id = db_insert('strategy_progress', [
            'objective_id'        => $obj_id,
            'wartosc_realizowana' => (float)($payload['wartosc_realizowana'] ?? 0),
            'budzet_wydany'       => (float)($payload['budzet_wydany'] ?? 0),
            'budzet_przypisany'   => (float)($payload['budzet_przypisany'] ?? 0),
            'snapshot_date'       => $payload['snapshot_date'] ?? date('Y-m-d'),
            'source'              => 'n8n',
            'notatka'             => $payload['note'] ?? 'Aktualizacja z n8n',
        ]);
        strat_log($event, $obj_id, "Postęp: {$payload['wartosc_realizowana']}, budżet: {$payload['budzet_wydany']}");
        wh_ok(['progress_id' => $insert_id, 'msg' => "Postęp zapisany dla celu #{$obj_id}."]);
    }

    // Powiązanie umowy z celem
    case 'contract_linked': {
        $obj_id    = (int)($payload['objective_id'] ?? 0);
        $c_type    = trim($payload['contract_type'] ?? '');
        $c_id      = (int)($payload['contract_id'] ?? 0);
        $valid_types = ['wolontariat','zlecenie','dzielo','uslugi','praca','inne'];

        if (!$obj_id || !$c_id || !in_array($c_type, $valid_types, true)) {
            wh_err('Wymagane: objective_id, contract_type (wolontariat|zlecenie|dzielo|uslugi|praca|inne), contract_id.');
        }

        try {
            db_insert('strategy_mapping', [
                'objective_id'        => $obj_id,
                'entity_type'         => 'contract',
                'entity_id'           => $c_id,
                'contract_type'       => $c_type,
                'contribution_weight' => (float)($payload['weight'] ?? 100),
                'note'                => $payload['note'] ?? 'Powiązane przez n8n',
                'added_by'            => 0,
            ]);
            strat_log($event, $obj_id, "Umowa {$c_type}#{$c_id} powiązana");
            wh_ok(['msg' => "Umowa {$c_type}#{$c_id} powiązana z celem #{$obj_id}."]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                wh_ok(['msg' => 'Powiązanie już istnieje.']);
            }
            wh_err('Błąd zapisu: ' . $e->getMessage());
        }
    }

    // Powiązanie grantu z celem
    case 'grant_linked': {
        $obj_id  = (int)($payload['objective_id'] ?? 0);
        $grant_id = (int)($payload['grant_id'] ?? 0);
        if (!$obj_id || !$grant_id) wh_err('Wymagane: objective_id, grant_id.');

        try {
            db_insert('strategy_mapping', [
                'objective_id'        => $obj_id,
                'entity_type'         => 'grant',
                'entity_id'           => $grant_id,
                'contract_type'       => null,
                'contribution_weight' => (float)($payload['weight'] ?? 100),
                'note'                => $payload['note'] ?? 'Powiązane przez n8n',
                'added_by'            => 0,
            ]);
            strat_log($event, $obj_id, "Grant #{$grant_id} powiązany");
            wh_ok(['msg' => "Grant #{$grant_id} powiązany z celem #{$obj_id}."]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) wh_ok(['msg' => 'Powiązanie już istnieje.']);
            wh_err('Błąd zapisu: ' . $e->getMessage());
        }
    }

    // Snapshot dla wszystkich aktywnych celów
    case 'snapshot_all': {
        try {
            $objs = db_all("SELECT id, nazwa FROM strategy_objectives WHERE status='aktywny'");
        } catch (\Throwable $e) {
            wh_err('Błąd pobierania celów: ' . $e->getMessage());
        }
        $done = 0;
        foreach ($objs as $o) {
            strategy_add_progress_snapshot((int)$o['id']);
            $done++;
        }
        strat_log($event, 0, "Snapshoty dla {$done} celów");
        wh_ok(['snapshots_created' => $done, 'msg' => "Snapshoty dodane dla {$done} aktywnych celów."]);
    }

    default:
        wh_err('Nieznane zdarzenie: "' . $event . '". Obsługiwane: payment_added, progress_update, contract_linked, grant_linked, snapshot_all.');
}
