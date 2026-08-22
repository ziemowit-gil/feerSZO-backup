<?php
/**
 * Skrypt cron: pilnowanie cyklu życia ofert CRM.
 *
 *  1) wygasza oferty po terminie ważności (status → wygasła),
 *  2) przypomina opiekunowi o ofertach kończących ważność w ciągu 2 dni,
 *  3) uzupełnia brakujące zadania follow-up,
 *  4) przypomina o ofertach dla OSÓB FIZYCZNYCH bez potwierdzenia klienta —
 *     bo taka oferta nie może przejść do realizacji.
 *
 * Uruchamiany przez cron/dispatcher.php (agent 'crm_offers'), raz dziennie.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/auth.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_offers.php';
require_once $base_dir . '/includes/mail_queue.php';

crm_offers_migrate();
echo '[' . date('Y-m-d H:i:s') . "] Start: crm_offers\n";

// ── 1. Wygaszanie ofert po terminie ─────────────────────────────────────────
$expired = crm_offer_expire_due();
echo "  wygaszone oferty: {$expired}\n";

// ── 2. Follow-up, których zabrakło (np. wysyłka poza UI) ────────────────────
$fups = crm_offer_followups_due();
echo "  utworzone zadania follow-up: {$fups}\n";

// ── 3. Ostrzeżenie o kończącej się ważności (raz na ofertę) ─────────────────
$soon = crm_all(
    "SELECT * FROM crm_offers
     WHERE deleted_at IS NULL AND status='wyslana' AND expire_notified_at IS NULL
       AND valid_until IS NOT NULL AND valid_until BETWEEN ? AND ?",
    [date('Y-m-d'), date('Y-m-d', strtotime('+2 days'))]
);
$notified = 0;
foreach ($soon as $o) {
    $owner_id = (int)($o['owner_id'] ?? 0);
    $mail = $owner_id ? (db_one("SELECT email FROM users WHERE id=?", [$owner_id])['email'] ?? '') : '';
    if ($mail) {
        $c = crm_one("SELECT imie_nazwisko FROM crm_contacts WHERE id=?", [(int)$o['contact_id']]);
        $body = '<p>Oferta <strong>' . h((string)$o['offer_number']) . '</strong> dla '
              . h((string)($c['imie_nazwisko'] ?? '')) . ' traci ważność <strong>'
              . h(date('d.m.Y', strtotime((string)$o['valid_until']))) . '</strong>.</p>'
              . '<p>Wartość: ' . h(crm_offer_money((float)$o['total_gross'], (string)$o['currency'])) . ' brutto.</p>';
        mail_queue_add($mail, '', 'Oferta ' . $o['offer_number'] . ' — kończy się ważność',
            _feer_email_tpl($body, 'Oferta traci ważność',
                APP_URL . '/crm/offers/view.php?id=' . (int)$o['id'], 'Otwórz ofertę'),
            '', 'crm_offer', (int)$o['id'], '', false);
        $notified++;
    }
    crm_update('crm_offers', ['expire_notified_at' => date('Y-m-d H:i:s')], (int)$o['id']);
}
echo "  powiadomienia o kończącej się ważności: {$notified}\n";

// ── 4. Oferty osób fizycznych bez potwierdzenia ─────────────────────────────
// Zadanie w CRM dla opiekuna — bez potwierdzenia nie wolno uruchomić realizacji.
$noconf = crm_all(
    "SELECT * FROM crm_offers
     WHERE deleted_at IS NULL AND requires_confirmation=1 AND confirmation_id IS NULL
       AND status IN ('wyslana','zaakceptowana')
       AND sent_at IS NOT NULL AND sent_at <= ?",
    [date('Y-m-d H:i:s', time() - 5 * 86400)]
);
$tasks = 0;
foreach ($noconf as $o) {
    // Nie duplikuj — jedno zadanie na ofertę
    $exists = crm_one(
        "SELECT id FROM crm_activities WHERE contact_id=? AND title=? AND status='planned'",
        [(int)$o['contact_id'], 'Brak potwierdzenia oferty ' . $o['offer_number']]
    );
    if ($exists) continue;
    crm_insert('crm_activities', [
        'contact_id'   => (int)$o['contact_id'],
        'type'         => 'task',
        'title'        => 'Brak potwierdzenia oferty ' . $o['offer_number'],
        'description'  => "Oferta dla osoby fizycznej nie ma potwierdzenia klienta.\n"
                        . "Bez potwierdzenia nie wolno uruchomić realizacji — przypomnij klientowi o linku "
                        . "albo zarejestruj potwierdzenie (e-mail, skan, protokół).",
        'scheduled_at' => date('Y-m-d H:i:s', strtotime('tomorrow 09:00')),
        'status'       => 'planned',
        'assigned_to'  => (int)($o['owner_id'] ?? 0) ?: null,
        'created_by'   => (int)($o['owner_id'] ?? 0) ?: null,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
    crm_offer_log((int)$o['id'], 'followup_created', [
        'actor_id' => null,
        'detail'   => 'Zadanie: brak potwierdzenia klienta (osoba fizyczna).',
    ]);
    $tasks++;
}
echo "  zadania o braku potwierdzenia: {$tasks}\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_offers\n";
