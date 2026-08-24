<?php
/**
 * crm/api/search.php — wyszukiwanie w całym CRM (kontakty, sprawy, oferty, poczta).
 *
 * Do tej pory każda sekcja miała własną wyszukiwarkę i trzeba było wiedzieć,
 * GDZIE szukać. Pulpit dostaje jedno pole: wpisujesz nazwisko, numer sprawy,
 * temat maila albo numer oferty i dostajesz wynik z każdej sekcji naraz.
 *
 * Wyniki respektują uprawnienia obszarów (includes/crm_perms.php) — sekcja
 * zamknięta dla roli po prostu nie odpowiada.
 *
 * GET ?q=fraza&limit=6 → {ok, groups:[{key,label,icon,items:[{title,sub,url}]}]}
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'groups' => []]); exit; }

$limit = min(10, max(3, (int)($_GET['limit'] ?? 6)));
$like  = '%' . $q . '%';
$digits = preg_replace('/\D/', '', $q);

$groups = [];

/** Dokłada grupę wyników, o ile cokolwiek znaleziono. */
$add = static function (string $key, string $label, string $icon, array $items) use (&$groups): void {
    if ($items) $groups[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'items' => $items];
};

// ── Kontakty ────────────────────────────────────────────────────────────────
if (crm_can('contacts', 'read')) {
    try {
        $rows = db_all(
            "SELECT id, imie_nazwisko, organizacja, email, telefon, type, is_critical
               FROM crm_contacts
              WHERE crm_active=1
                AND (imie_nazwisko LIKE ? OR organizacja LIKE ? OR email LIKE ?
                     OR telefon LIKE ? OR nip LIKE ? OR regon LIKE ?)
           ORDER BY imie_nazwisko LIMIT {$limit}",
            [$like, $like, $like, $like, $like, $like]
        );
        $add('contacts', 'Kontakty', 'bi-people-fill', array_map(static fn($r) => [
            'title' => (string)$r['imie_nazwisko'],
            'sub'   => trim(implode(' · ', array_filter([
                        $r['organizacja'] ?: null, $r['email'] ?: null, $r['telefon'] ?: null]))),
            'flag'  => !empty($r['is_critical']) ? 'krytyczny operacyjnie' : '',
            'url'   => APP_URL . '/crm/contact/view.php?id=' . (int)$r['id'],
        ], $rows));
    } catch (\Throwable $e) {}
}

// ── Sprawy ──────────────────────────────────────────────────────────────────
if (crm_can('cases', 'read')) {
    try {
        $rows = db_all(
            "SELECT c.id, c.title, c.status, c.case_number, ct.imie_nazwisko AS contact_name
               FROM crm_cases c
          LEFT JOIN crm_contacts ct ON ct.id = c.contact_id
              WHERE c.title LIKE ? OR c.description LIKE ? OR c.case_number LIKE ?
                    OR ct.imie_nazwisko LIKE ?
           ORDER BY c.updated_at DESC LIMIT {$limit}",
            [$like, $like, $like, $like]
        );
        $add('cases', 'Sprawy', 'bi-briefcase-fill', array_map(static fn($r) => [
            'title' => (string)$r['title'],
            'sub'   => trim(implode(' · ', array_filter([
                        $r['contact_name'] ?: null, $r['case_number'] ?: null, $r['status'] ?: null]))),
            'flag'  => '',
            'url'   => APP_URL . '/crm/cases/view.php?id=' . (int)$r['id'],
        ], $rows));
    } catch (\Throwable $e) {}
}

// ── Oferty ──────────────────────────────────────────────────────────────────
if (crm_can('offers', 'read')) {
    try {
        // Nazwa klienta leży w migawce (client_snapshot), nie w osobnej kolumnie —
        // dlatego przeszukujemy tytuł, numer i tę migawkę.
        $rows = crm_all(
            "SELECT id, offer_number, title, status, client_snapshot
               FROM crm_offers
              WHERE offer_number LIKE ? OR title LIKE ? OR client_snapshot LIKE ?
           ORDER BY id DESC LIMIT {$limit}",
            [$like, $like, $like]
        );
        $add('offers', 'Oferty', 'bi-file-earmark-text-fill', array_map(static function ($r) {
            $snap = json_decode((string)($r['client_snapshot'] ?? '{}'), true);
            $who  = is_array($snap) ? (string)($snap['name'] ?? '') : '';
            return [
            'title' => (string)($r['offer_number'] ?: $r['title']),
            'sub'   => trim(implode(' · ', array_filter([
                        $r['title'] ?: null, $who ?: null, $r['status'] ?: null]))),
            'flag'  => '',
            'url'   => APP_URL . '/crm/offers/view.php?id=' . (int)$r['id'],
            ];
        }, $rows));
    } catch (\Throwable $e) {}
}

// ── Poczta (skrzynka CRM) ───────────────────────────────────────────────────
if (crm_can('inbox', 'read')) {
    try {
        $params = [$like, $like, $like];
        $extra  = '';
        // Sześciocyfrowy ciąg to prawdopodobnie numer wiadomości — szukamy też po nim
        if (strlen($digits) === 6) { $extra = ' OR msg_no = ?'; $params[] = $digits; }

        $rows = db_all(
            "SELECT id, subject, from_name, from_email, sent_at, msg_no, direction
               FROM crm_communications
              WHERE (subject LIKE ? OR from_name LIKE ? OR from_email LIKE ?{$extra})
           ORDER BY sent_at DESC LIMIT {$limit}", $params
        );
        $add('inbox', 'Poczta', 'bi-envelope-fill', array_map(static fn($r) => [
            'title' => (string)($r['subject'] ?: '(bez tematu)'),
            'sub'   => trim(implode(' · ', array_filter([
                        ($r['direction'] === 'out' ? 'wysłana' : (string)($r['from_name'] ?: $r['from_email'])) ?: null,
                        !empty($r['sent_at']) ? date('d.m.Y', strtotime((string)$r['sent_at'])) : null,
                        !empty($r['msg_no']) ? '#' . $r['msg_no'] : null]))),
            'flag'  => '',
            'url'   => APP_URL . '/crm/inbox.php?view=all&msg=' . (int)$r['id'],
        ], $rows));
    } catch (\Throwable $e) {}
}

echo json_encode(['ok' => true, 'q' => $q, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
