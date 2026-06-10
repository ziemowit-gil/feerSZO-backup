<?php
/**
 * correspondence/dispatch_save.php
 * AJAX POST — rejestracja korespondencji wychodzącej z koperty.
 *
 * Wejście (POST JSON lub formdata):
 *   csrf_token, contract_type, contract_id,
 *   carrier, dispatch_date, subject, description,
 *   correspondent (imię i nazwisko adresata),
 *   tracking_number (opcjonalnie — uzupełniane po nadaniu lub przez Apaczka),
 *   order_apaczka   (1 = złóż zamówienie przez Apaczka API),
 *   weight, dim1, dim2, dim3, service_id (dla Apaczka),
 *   pickup_type (SELF|COURIER)
 *
 * Odpowiedź JSON:
 *   {ok, corr_id, number, s10, tracking_number, apaczka_order_id, tracking_url, msg}
 */

if (!defined('APP_INSTALLED')) require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/correspondence.php';
require_once dirname(__DIR__) . '/includes/postal.php';

header('Content-Type: application/json; charset=utf-8');

function _ds_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

require_login();
if (!can_write('correspondence') && !can_edit()) {
    _ds_err('Brak uprawnień.', 403);
}

// Obsługa zarówno JSON body jak i normalnego POST
$raw = file_get_contents('php://input');
if ($raw && ($json = json_decode($raw, true)) !== null) {
    $post = $json;
} else {
    $post = $_POST;
}

csrf_check($post['csrf_token'] ?? '');

$carrier         = trim($post['carrier']        ?? '');
$contract_type   = trim($post['contract_type']  ?? '');
$contract_id     = (int)($post['contract_id']   ?? 0);
$correspondent   = trim($post['correspondent']  ?? '');
$subject         = trim($post['subject']        ?? 'Korespondencja wychodząca');
$description     = trim($post['description']    ?? '');
$dispatch_date   = trim($post['dispatch_date']  ?? date('Y-m-d'));
$tracking_manual = trim($post['tracking_number'] ?? '');
$order_apaczka   = !empty($post['order_apaczka']);

if (!$carrier) _ds_err('Wybierz przewoźnika.');
if (!array_key_exists($carrier, carrier_labels())) _ds_err('Nieprawidłowy przewoźnik.');
if (!$dispatch_date || !strtotime($dispatch_date)) $dispatch_date = date('Y-m-d');

$uid = (int)current_user()['id'];

// ── 1. Utwórz rekord korespondencji ──────────────────────────────────────────
$result = corr_create_dispatch([
    'carrier'        => $carrier,
    'contract_type'  => $contract_type,
    'contract_id'    => $contract_id,
    'correspondent'  => $correspondent,
    'subject'        => $subject,
    'description'    => $description,
    'dispatch_date'  => $dispatch_date,
    'tracking_number'=> $tracking_manual,
], $uid);

$corr_id        = $result['corr_id'];
$corr_number    = $result['number'];
$s10            = $result['s10'];
$tracking_number = $tracking_manual;
$apaczka_order_id = '';
$tracking_url     = '';
$msg              = 'Zarejestrowano korespondencję nr ' . $corr_number . '.';

// ── 2. Opcjonalnie: zlecenie Apaczka ─────────────────────────────────────────
if ($order_apaczka && carrier_uses_apaczka($carrier)) {
    try {
        require_once dirname(__DIR__) . '/includes/apaczka.php';
        $api = new ApaczkaClient();

        if (!$api->is_configured()) {
            $msg .= ' Apaczka nie jest skonfigurowana — dodaj dane dostępowe w Ustawieniach.';
        } else {
            // Pobierz dane konraktu do adresu odbiorcy
            $vol_row = null;
            if ($contract_type === 'wolontariat' && $contract_id) {
                $vol_row = db_one("SELECT * FROM umowy_wolontariat WHERE id=?", [$contract_id]);
            }

            $sender  = apaczka_sender_defaults();
            $rec_name  = $correspondent ?: ($vol_row['imie_nazwisko'] ?? '');
            $rec_line1 = '';
            $rec_postal = '';
            $rec_city   = '';
            $rec_email  = '';
            $rec_phone  = '';

            if ($vol_row) {
                // Preferuj adres korespondencyjny, fallback na główny
                if (!empty($vol_row['adres_linia1'])) {
                    $rec_line1  = $vol_row['adres_linia1'];
                    $rec_postal = preg_replace('/\s.*$/', '', $vol_row['adres_linia2'] ?? '');
                    $rec_city   = preg_replace('/^\S+\s*/', '', $vol_row['adres_linia2'] ?? '');
                } else {
                    $rec_line1  = trim(($vol_row['addr_street'] ?? '') . ' ' . ($vol_row['addr_house'] ?? '') . ($vol_row['addr_flat'] ? '/' . $vol_row['addr_flat'] : ''));
                    $rec_postal = $vol_row['addr_postal'] ?? '';
                    $rec_city   = $vol_row['addr_city']   ?? '';
                }
                $rec_email = $vol_row['email']   ?? '';
                $rec_phone = preg_replace('/\D/', '', $vol_row['telefon'] ?? '');
            }

            // Paczkomat: potrzebny point_id
            $rec_point_id   = trim($post['paczkomat_id'] ?? '');
            $rec_point_type = $carrier === 'inpost_paczkomat' ? 'APM' : '';

            $ship_data = [
                'contract_type' => $contract_type,
                'contract_id'   => $contract_id ?: null,
                'direction'     => 'out',
                'purpose'       => 'documents',
                'status'        => 'draft',
                'service_id'    => (int)($post['service_id'] ?? 0),
                'weight'        => (float)($post['weight']   ?? 0.5),
                'dimension1'    => (int)($post['dim1']       ?? 25),
                'dimension2'    => (int)($post['dim2']       ?? 20),
                'dimension3'    => (int)($post['dim3']       ?? 5),
                'content'       => $subject,
                'comment'       => $description,
                'pickup_type'   => trim($post['pickup_type'] ?? 'SELF'),
                'sender_name'          => $sender['name'],
                'sender_line1'         => $sender['line1'],
                'sender_line2'         => $sender['line2'],
                'sender_postal_code'   => $sender['postal_code'],
                'sender_city'          => $sender['city'],
                'sender_email'         => $sender['email'],
                'sender_phone'         => $sender['phone'],
                'receiver_name'        => $rec_name,
                'receiver_line1'       => $rec_line1,
                'receiver_line2'       => '',
                'receiver_postal_code' => $rec_postal,
                'receiver_city'        => $rec_city,
                'receiver_email'       => $rec_email,
                'receiver_phone'       => $rec_phone,
                'receiver_point_id'    => $rec_point_id,
                'receiver_point_type'  => $rec_point_type,
                'created_by'    => $uid,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ];

            $ship_id = db_insert('shipments', $ship_data);

            // Zaktualizuj status + place order
            try {
                $order_result  = $api->place_order($ship_id);
                $apaczka_order_id = $order_result['apaczka_id']    ?? '';
                $tracking_number  = $order_result['waybill_number'] ?? $tracking_manual;
                $tracking_url     = $order_result['tracking_url']    ?? '';

                db()->prepare(
                    "UPDATE correspondence SET tracking_number=?, apaczka_shipment_id=?, updated_at=? WHERE id=?"
                )->execute([$tracking_number, $ship_id, date('Y-m-d H:i:s'), $corr_id]);

                $msg .= ' Przesyłka ' . $tracking_number . ' zlecona przez Apaczka.pl.';
            } catch (Throwable $ex) {
                // Zamówienie nieudane — zachowaj rekord korespondencji, zgłoś błąd
                $msg .= ' Błąd Apaczka: ' . $ex->getMessage();
            }
        }
    } catch (Throwable $ex) {
        $msg .= ' Błąd integracji: ' . $ex->getMessage();
    }
}

// ── 3. Log na umowie (jeśli powiązana) ───────────────────────────────────────
if ($contract_id && $contract_type) {
    try {
        $label = carrier_labels()[$carrier] ?? $carrier;
        log_contract_action(
            $contract_type, $contract_id, $uid, 'note',
            'Zarejestrowano wysyłkę wychodząca: ' . $corr_number . ' — ' . $label .
            ($tracking_number ? ' — nr: ' . $tracking_number : '')
        );
    } catch (Throwable $e) { /* nie blokuj */ }
}

echo json_encode([
    'ok'               => true,
    'corr_id'          => $corr_id,
    'number'           => $corr_number,
    's10'              => $s10,
    'tracking_number'  => $tracking_number,
    'tracking_url'     => $tracking_url,
    'apaczka_order_id' => $apaczka_order_id,
    'msg'              => $msg,
]);
