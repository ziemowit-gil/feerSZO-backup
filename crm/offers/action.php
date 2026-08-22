<?php
/**
 * crm/offers/action.php — akcje na ofercie (wyłącznie POST).
 *
 * Wszystkie zmiany stanu oferty przechodzą tędy, żeby reguły biznesowe
 * (limit rabatu, wymagane potwierdzenie osoby fizycznej, dziennik zdarzeń)
 * były w jednym miejscu, a nie rozsypane po widokach.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_offers_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$a   = (string)($_POST['a'] ?? '');
$id  = (int)($_POST['id'] ?? 0);
$uid = (int)(current_user()['id'] ?? 0);
$offer = $id ? crm_offer_get($id) : null;

if (!$offer) { flash_set('danger', 'Oferta nie istnieje.'); header('Location: index.php'); exit; }
if (!crm_offer_can_write() && $a !== 'pdf') {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: view.php?id=' . $id); exit;
}

$back = 'view.php?id=' . $id;

switch ($a) {

// ── Wystawienie faktury z oferty ────────────────────────────────────────────
// Tworzy wyłącznie SZKIC — wystawienie w Fakturowni jest osobną, świadomą
// decyzją na karcie faktury. Powtórne wywołanie prowadzi do istniejącego
// dokumentu (indeks UNIQUE na parze źródło+id), nie tworzy duplikatu.
case 'invoice':
    if (!module_enabled('invoices_enabled')) {
        flash_set('warning', 'Moduł Faktury jest wyłączony.');
        break;
    }
    require_once dirname(dirname(__DIR__)) . '/includes/invoices.php';
    $res = invoice_from_offer($id, $uid);
    if (empty($res['ok'])) {
        flash_set('danger', 'Nie udało się przygotować faktury: ' . (string)$res['error']);
        break;
    }
    flash_set(!empty($res['existing']) ? 'info' : 'success',
        !empty($res['existing'])
            ? 'Ta oferta ma już fakturę — otwieram istniejący dokument.'
            : 'Szkic faktury przygotowany z oferty. Sprawdź dane nabywcy i pozycje, potem wystaw dokument.');
    header('Location: ' . APP_URL . '/crm/invoices/view.php?id=' . (int)$res['id']);
    exit;

// ── FVAT demo z oferty (tylko administrator) ────────────────────────────────
// Jeden krok: szkic → numer TEST/… → PDF. Nie zużywa numeru produkcyjnego,
// nie idzie do Fakturowni ani KSeF i nie blokuje faktury prawdziwej.
case 'invoice_demo':
    if (!is_admin()) {
        flash_set('danger', 'Faktura demo jest dostępna tylko dla administratora.');
        break;
    }
    if (!module_enabled('invoices_enabled')) {
        flash_set('warning', 'Moduł Faktury jest wyłączony.');
        break;
    }
    require_once dirname(dirname(__DIR__)) . '/includes/invoices.php';
    $res = invoice_demo_issue('offer', $id, $uid);
    if (empty($res['ok'])) {
        flash_set('danger', 'Nie udało się wygenerować faktury demo: ' . (string)$res['error']);
        break;
    }
    header('Location: ' . APP_URL . '/crm/invoices/pdf.php?id=' . (int)$res['id']);
    exit;

// ── Wysyłka oferty do klienta ───────────────────────────────────────────────
case 'send':
    if (!in_array($offer['status'], ['szkic', 'wyslana', 'do_zatwierdzenia'], true)) {
        flash_set('warning', 'Ofertę w tym statusie można tylko wysłać ponownie z nowej wersji.');
        break;
    }
    if ($offer['status'] === 'do_zatwierdzenia' && !crm_offer_can_approve_discount()) {
        flash_set('danger', 'Oferta czeka na zatwierdzenie rabatu — nie można jej wysłać.');
        break;
    }
    // ⚠ Ostrzeżenie dla osoby fizycznej musi być świadomie potwierdzone
    if (crm_offer_warning($offer) && empty($_POST['ack'])) {
        flash_set('danger', 'Wysyłka wstrzymana: potwierdź, że wiesz, iż oferta dla osoby fizycznej wymaga potwierdzenia klienta.');
        break;
    }
    $res = crm_offer_send($offer, [
        'to'         => trim((string)($_POST['to'] ?? '')),
        'subject'    => trim((string)($_POST['subject'] ?? '')),
        'message'    => trim((string)($_POST['message'] ?? '')),
        'attach_pdf' => !empty($_POST['attach_pdf']),
    ]);
    if ($res['ok']) {
        flash_set('success', 'Oferta wysłana. Zadanie follow-up zaplanowane na ' . crm_offer_followup_days() . ' dni.');
    } else {
        flash_set('danger', 'Nie udało się wysłać: ' . $res['error']);
    }
    break;

// ── Zmiana statusu ręcznie ──────────────────────────────────────────────────
case 'status':
    $st = (string)($_POST['status'] ?? '');
    if (!isset(CRM_OFFER_STATUSES[$st])) { flash_set('danger', 'Nieznany status.'); break; }
    if ($st === 'zaakceptowana') {
        if ($blk = crm_offer_blocker($offer, 'accept')) { flash_set('danger', $blk); break; }
        $vid = (int)($_POST['variant_id'] ?? 0);
        crm_offer_set_status($id, $st, [
            'event' => 'accepted', 'selected_variant_id' => $vid ?: null, 'recalc' => true,
            'detail' => 'Akceptacja zarejestrowana przez pracownika.',
        ]);
        flash_set('success', 'Oferta oznaczona jako zaakceptowana.');
        break;
    }
    if ($st === 'odrzucona') {
        crm_offer_set_status($id, $st, [
            'event' => 'rejected', 'reject_reason' => trim((string)($_POST['reason'] ?? '')),
            'detail' => trim((string)($_POST['reason'] ?? '')) ?: null,
        ]);
        flash_set('success', 'Oferta oznaczona jako odrzucona.');
        break;
    }
    crm_offer_set_status($id, $st, ['detail' => trim((string)($_POST['reason'] ?? '')) ?: null]);
    flash_set('success', 'Status zmieniony na „' . CRM_OFFER_STATUSES[$st]['label'] . '".');
    break;

// ── Zatwierdzenie rabatu ────────────────────────────────────────────────────
case 'approve_discount':
    if (!crm_offer_can_approve_discount()) { flash_set('danger', 'Nie masz uprawnień do zatwierdzania rabatów.'); break; }
    crm_update('crm_offers', [
        'discount_approved_by' => $uid,
        'discount_approved_at' => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ], $id);
    crm_offer_log($id, 'discount_approved', ['detail' => 'Rabat ' . $offer['discount_pct'] . '% zatwierdzony.']);
    if ($offer['status'] === 'do_zatwierdzenia') {
        crm_offer_set_status($id, 'szkic', ['detail' => 'Rabat zatwierdzony — oferta gotowa do wysłania.']);
    }
    flash_set('success', 'Rabat zatwierdzony.');
    break;

// ── Rejestracja potwierdzenia klienta (poza kanałem online) ─────────────────
case 'confirm':
    $method = (string)($_POST['method'] ?? 'email');
    if (!isset(CRM_OFFER_CONFIRM_METHODS[$method]) || $method === 'online') $method = 'email';
    $name = trim((string)($_POST['confirmed_name'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    if ($name === '') { flash_set('danger', 'Podaj osobę potwierdzającą (imię i nazwisko).'); break; }
    if ($note === '') { flash_set('danger', 'Opisz podstawę potwierdzenia (np. treść i data maila, numer protokołu).'); break; }

    $file = null;
    if (!empty($_FILES['conf_file']['name'])) {
        $file = handle_upload('conf_file', 'crm_offers');
        if (!$file) { flash_set('danger', 'Nie udało się zapisać pliku potwierdzenia.'); break; }
    }
    if ($method === 'skan' && !$file) { flash_set('danger', 'Dla potwierdzenia skanem załącz plik.'); break; }

    crm_offer_record_confirmation($id, [
        'variant_id'      => (int)($_POST['variant_id'] ?? 0) ?: null,
        'method'          => $method,
        'confirmed_name'  => $name,
        'confirmed_email' => trim((string)($_POST['confirmed_email'] ?? '')),
        'note'            => $note,
        'file_path'       => $file,
        'statements'      => ['rejestracja_przez_pracownika' => true],
    ], $uid);
    flash_set('success', 'Potwierdzenie zarejestrowane — można uruchomić realizację.');
    break;

case 'revoke_confirm':
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') { flash_set('danger', 'Podaj powód wycofania potwierdzenia.'); break; }
    crm_offer_revoke_confirmation($id, $reason, $uid);
    flash_set('success', 'Potwierdzenie wycofane.');
    break;

// ── Konwersja 1-kliknięciem ────────────────────────────────────────────────
case 'convert':
    $target = (string)($_POST['target'] ?? 'sprawa');
    if (crm_offer_warning($offer) && empty($_POST['ack'])) {
        flash_set('danger', 'Realizacja wstrzymana — potwierdź ostrzeżenie o wymaganym potwierdzeniu oferty.');
        break;
    }
    if (!in_array($offer['status'], ['zaakceptowana', 'wyslana'], true) && empty($_POST['force'])) {
        flash_set('warning', 'Konwersja dotyczy ofert zaakceptowanych. Najpierw zarejestruj decyzję klienta.');
        break;
    }
    $res = crm_offer_convert($offer, $target);
    if (!$res['ok']) { flash_set('danger', $res['error']); break; }
    flash_set('success', 'Uruchomiono realizację: ' . CRM_OFFER_CONVERT_TARGETS[$target]['label'] . '.');
    header('Location: ' . ($res['url'] ?: $back)); exit;

// ── Nowa wersja / duplikat ─────────────────────────────────────────────────
case 'revision':
case 'duplicate':
    $full = crm_offer_full($id);
    $new = [
        'offer_number'   => crm_offer_next_number(),
        'revision'       => $a === 'revision' ? ((int)$full['revision'] + 1) : 1,
        'parent_offer_id'=> $a === 'revision' ? $id : null,
        'contact_id'     => (int)$full['contact_id'],
        'case_id'        => $full['case_id'] ?: null,
        'owner_id'       => $full['owner_id'] ?: $uid,
        'status'         => 'szkic',
        'title'          => $full['title'],
        'intro'          => $full['intro'],
        'terms'          => $full['terms'],
        'delivery_terms' => $full['delivery_terms'],
        'notes_internal' => $full['notes_internal'],
        'currency'       => $full['currency'],
        'valid_until'    => date('Y-m-d', strtotime('+' . crm_offer_default_validity() . ' days')),
        'payment_terms_days' => (int)$full['payment_terms_days'],
        'discount_pct'   => (float)$full['discount_pct'],
        'discount_reason'=> $full['discount_reason'],
        'funding_source' => $full['funding_source'],
        'objective_id'   => $full['objective_id'] ?: null,
        'statutory_note' => $full['statutory_note'],
        'accounting_note'=> $full['accounting_note'],
        'client_type'    => $full['client_type'],
        'client_snapshot'=> $full['client_snapshot'],
        'requires_confirmation' => (int)$full['requires_confirmation'],
        'access_token'   => crm_offer_token_new(),
        'created_by'     => $uid,
        'created_at'     => date('Y-m-d H:i:s'),
        'updated_at'     => date('Y-m-d H:i:s'),
    ];
    $nid = crm_insert('crm_offers', $new);
    foreach ($full['variants'] as $v) {
        $nvid = crm_insert('crm_offer_variants', [
            'offer_id' => $nid, 'code' => $v['code'], 'name' => $v['name'],
            'description' => $v['description'], 'is_recommended' => (int)$v['is_recommended'],
            'sort_order' => (int)$v['sort_order'], 'created_at' => date('Y-m-d H:i:s'),
        ]);
        foreach ($v['items'] as $it) {
            crm_insert('crm_offer_items', [
                'offer_id' => $nid, 'variant_id' => $nvid, 'catalog_id' => $it['catalog_id'] ?: null,
                'name' => $it['name'], 'description' => $it['description'], 'unit' => $it['unit'],
                'qty' => $it['qty'], 'unit_net' => $it['unit_net'], 'discount_pct' => $it['discount_pct'],
                'vat_rate' => $it['vat_rate'], 'vat_basis' => $it['vat_basis'],
                'objective_id' => $it['objective_id'] ?: null, 'merit_note' => $it['merit_note'],
                'is_optional' => (int)$it['is_optional'], 'sort_order' => (int)$it['sort_order'],
            ]);
        }
    }
    crm_offer_recalc($nid);
    crm_offer_map_objective($nid, $full['objective_id'] ? (int)$full['objective_id'] : null);
    crm_offer_log($nid, $a === 'revision' ? 'revision' : 'created', [
        'detail' => $a === 'revision'
            ? ('Nowa wersja oferty ' . $full['offer_number'] . ' (wersja ' . $new['revision'] . ').')
            : ('Duplikat oferty ' . $full['offer_number'] . '.'),
        'meta' => ['source_offer_id' => $id],
    ]);
    if ($a === 'revision') {
        crm_offer_log($id, 'revision', ['detail' => 'Utworzono nową wersję: ' . $new['offer_number'], 'meta' => ['new_offer_id' => $nid]]);
    }
    flash_set('success', ($a === 'revision' ? 'Utworzono nową wersję oferty: ' : 'Utworzono duplikat: ') . $new['offer_number']);
    header('Location: form.php?id=' . $nid); exit;

// ── Nowy link publiczny (unieważnia poprzedni) ─────────────────────────────
case 'token_reset':
    crm_update('crm_offers', ['access_token' => crm_offer_token_new(), 'updated_at' => date('Y-m-d H:i:s')], $id);
    crm_offer_log($id, 'updated', ['detail' => 'Wygenerowano nowy link publiczny — poprzedni przestał działać.']);
    flash_set('success', 'Nowy link do oferty wygenerowany.');
    break;

// ── Usunięcie (soft delete) ────────────────────────────────────────────────
case 'delete':
    if (!crm_offer_can_delete()) { flash_set('danger', 'Brak uprawnień do usuwania.'); break; }
    crm_update('crm_offers', ['deleted_at' => date('Y-m-d H:i:s')], $id);
    crm_offer_log($id, 'updated', ['detail' => 'Oferta usunięta (soft-delete).']);
    flash_set('success', 'Oferta „' . $offer['offer_number'] . '" została usunięta.');
    header('Location: index.php'); exit;

default:
    flash_set('danger', 'Nieznana akcja.');
}

header('Location: ' . $back);
exit;
