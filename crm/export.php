<?php
/**
 * crm/export.php — Eksport danych CRM do CSV / XLSX.
 *
 * ?what=contacts (domyślnie) — kartoteki; filtry jak w crm/index.php (q, q_all,
 *   status, type, tag, group, wojewodztwo, powiat, gmina, source, branza,
 *   created_*, last_*, stale_days, has_email, has_phone) + cols[] i format.
 * ?what=cases — sprawy; filtry jak w crm/cases/index.php (q, status, priority,
 *   contact_id, type_id, mine, noowner, overdue).
 * ?what=comms — korespondencja; filtry: direction, mailbox_id, contact_id,
 *   date_from, date_to, q.
 *
 * Sprawy i wysyłki dało się dotąd zobaczyć wyłącznie na ekranie, po dwadzieścia
 * pozycji na stronę — zestawienie do sprawozdania trzeba było klikać ręcznie.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_perms.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
crm_require('export', 'read');
if (!can_read('crm_eksport') && !is_admin()) {
    http_response_code(403); die('Brak uprawnień do eksportu kontaktów.');
}
crm_migrate();

$format = in_array($_GET['format'] ?? 'csv', ['csv', 'xlsx'], true) ? $_GET['format'] : 'csv';
$what   = in_array($_GET['what']   ?? 'contacts', ['contacts', 'cases', 'comms'], true)
        ? $_GET['what'] : 'contacts';

/**
 * Wypisuje gotowe zestawienie i kończy działanie skryptu.
 *
 * @param string[]        $headers nagłówki kolumn
 * @param iterable<array> $data    wiersze (już jako wartości, nie rekordy)
 */
function crm_export_emit(array $headers, iterable $data, string $sheet, string $basename, string $format): never
{
    if ($format === 'xlsx') {
        require_once dirname(__DIR__) . '/includes/xlsx.php';
        $x = new XlsxWriter();
        $x->addSheet($sheet);
        $x->writeRow($headers, ['header']);
        foreach ($data as $row) $x->writeRow($row);
        $x->output($basename . '-' . date('Y-m-d') . '.xlsx');
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $basename . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                 // BOM — inaczej Excel psuje polskie znaki
    fputcsv($out, $headers, ';', '"', '\\');
    foreach ($data as $row) fputcsv($out, $row, ';', '"', '\\');
    fclose($out);
    exit;
}

// ══ Sprawy ═══════════════════════════════════════════════════════════════
if ($what === 'cases') {
    crm_require('cases', 'read');
    require_once dirname(__DIR__) . '/includes/crm_case_extras.php';

    $where = '1=1'; $params = [];
    $uid   = (int)(current_user()['id'] ?? 0);
    if ($q = trim($_GET['q'] ?? '')) {
        $where .= " AND (c.title LIKE ? OR ct.imie_nazwisko LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%";
    }
    foreach (['status' => 'c.status', 'priority' => 'c.priority'] as $g => $col) {
        if ($v = trim($_GET[$g] ?? '')) { $where .= " AND $col=?"; $params[] = $v; }
    }
    if ($cid = (int)($_GET['contact_id'] ?? 0)) { $where .= " AND c.contact_id=?"; $params[] = $cid; }
    if ($tid = (int)($_GET['type_id']    ?? 0)) { $where .= " AND c.type_id=?";    $params[] = $tid; }
    if (!empty($_GET['mine']))    { $where .= " AND c.owner_id=?"; $params[] = $uid; }
    if (!empty($_GET['noowner'])) { $where .= " AND (c.owner_id IS NULL OR c.owner_id=0)"; }
    if (!empty($_GET['overdue'])) {
        $where .= " AND c.due_date IS NOT NULL AND c.due_date <> '' AND date(c.due_date) < date('now')"
                . " AND c.status IN ('open','in_progress')";
    }

    $rows = db_all(
        "SELECT c.*, ct.imie_nazwisko AS contact_name, u.name AS owner_name, t.name AS type_name,
                (SELECT COUNT(*) FROM crm_case_notes n WHERE n.case_id=c.id) AS notes_count
           FROM crm_cases c
      LEFT JOIN crm_contacts ct      ON ct.id = c.contact_id
      LEFT JOIN users u              ON u.id  = c.owner_id
      LEFT JOIN crm_case_types t     ON t.id  = c.type_id
          WHERE $where ORDER BY c.updated_at DESC LIMIT 20000", $params
    );

    // Etykiety statusów i priorytetów są w CRM zdefiniowane w widoku listy spraw;
    // powtarzamy je tu, żeby plik nie zawierał kodów zrozumiałych tylko w bazie.
    $statuses = [
        'open' => 'Otwarta', 'in_progress' => 'W toku',
        'closed' => 'Zamknięta', 'cancelled' => 'Anulowana',
    ];
    $prios = ['low' => 'Niski', 'medium' => 'Średni', 'high' => 'Wysoki'];
    $headers  = ['ID', 'Numer', 'Tytuł', 'Kontakt', 'Status', 'Priorytet', 'Typ', 'Opiekun',
                 'Termin', 'Utworzono', 'Zamknięto', 'Powód zamknięcia', 'Notatek'];
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            (int)$r['id'],
            (string)($r['case_number'] ?? ''),
            (string)$r['title'],
            (string)($r['contact_name'] ?? ''),
            (string)($statuses[$r['status']] ?? $r['status']),
            (string)($prios[$r['priority'] ?? ''] ?? $r['priority'] ?? ''),
            (string)($r['type_name'] ?? ''),
            (string)($r['owner_name'] ?? ''),
            $r['due_date']   ? substr((string)$r['due_date'], 0, 10)   : '',
            substr((string)$r['created_at'], 0, 16),
            $r['closed_at']  ? substr((string)$r['closed_at'], 0, 16)  : '',
            (string)($r['closed_reason'] ?? ''),
            (int)($r['notes_count'] ?? 0),
        ];
    }
    crm_export_emit($headers, $data, 'Sprawy CRM', 'crm-sprawy', $format);
}

// ══ Korespondencja ═══════════════════════════════════════════════════════
if ($what === 'comms') {
    crm_require('inbox', 'read');
    require_once dirname(__DIR__) . '/includes/crm_mailbox.php';

    // Zakres skrzynek zgodny z uprawnieniami do poczty — eksport nie może
    // pokazać wiadomości, których użytkownik nie zobaczy w skrzynce.
    $where = [poczta_scope_sql('c.mailbox_id')];
    $params = [];
    if (($d = $_GET['direction'] ?? '') && in_array($d, ['in', 'out'], true)) {
        $where[] = 'c.direction=?'; $params[] = $d;
    }
    if ($mb  = (int)($_GET['mailbox_id'] ?? 0)) { $where[] = 'c.mailbox_id=?'; $params[] = $mb; }
    if ($cid = (int)($_GET['contact_id'] ?? 0)) { $where[] = 'c.contact_id=?'; $params[] = $cid; }
    if ($df  = trim($_GET['date_from'] ?? ''))  { $where[] = "date(c.sent_at) >= date(?)"; $params[] = $df; }
    if ($dt  = trim($_GET['date_to']   ?? ''))  { $where[] = "date(c.sent_at) <= date(?)"; $params[] = $dt; }
    if ($q   = trim($_GET['q']         ?? '')) {
        $where[] = '(c.subject LIKE ? OR c.from_email LIKE ? OR c.from_name LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%");
    }

    $rows = db_all(
        "SELECT c.id, c.msg_no, c.direction, c.channel, c.subject, c.status, c.sent_at,
                c.from_name, c.from_email, c.inbox_status, c.has_attachments,
                ct.imie_nazwisko AS contact_name, u.name AS assigned_name, m.mailbox AS mailbox_name
           FROM crm_communications c
      LEFT JOIN crm_contacts ct    ON ct.id = c.contact_id
      LEFT JOIN users u            ON u.id  = c.assigned_to
      LEFT JOIN poczta_mailboxes m ON m.id  = c.mailbox_id
          WHERE " . implode(' AND ', $where) . "
       ORDER BY c.sent_at DESC LIMIT 20000", $params
    );

    $headers = ['Nr', 'Kierunek', 'Kanał', 'Data', 'Skrzynka', 'Nadawca', 'Adres nadawcy',
                'Kontakt', 'Temat', 'Status', 'Stan w skrzynce', 'Prowadzi', 'Załącznik'];
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            crm_msg_no((int)$r['id'], $r['msg_no'] ?? null),
            $r['direction'] === 'out' ? 'wychodząca' : 'przychodząca',
            (string)($r['channel'] ?? ''),
            substr((string)$r['sent_at'], 0, 16),
            (string)($r['mailbox_name'] ?? ''),
            (string)($r['from_name'] ?? ''),
            (string)($r['from_email'] ?? ''),
            (string)($r['contact_name'] ?? ''),
            (string)($r['subject'] ?? ''),
            (string)($r['status'] ?? ''),
            (string)($r['inbox_status'] ?? ''),
            (string)($r['assigned_name'] ?? ''),
            (int)$r['has_attachments'] ? 'tak' : '',
        ];
    }
    crm_export_emit($headers, $data, 'Korespondencja CRM', 'crm-korespondencja', $format);
}

// ══ Kontakty (domyślnie) ═════════════════════════════════════════════════
$filters = [
    'q'            => trim($_GET['q']            ?? ''),
    'q_all'        => trim($_GET['q_all']        ?? ''),
    'status'       => trim($_GET['status']       ?? ''),
    'type'         => trim($_GET['type']         ?? ''),
    'tag'          => trim($_GET['tag']          ?? ''),
    'group'        => (int)($_GET['group']       ?? 0) ?: '',
    'wojewodztwo'  => trim($_GET['wojewodztwo']  ?? ''),
    'powiat'       => trim($_GET['powiat']       ?? ''),
    'gmina'        => trim($_GET['gmina']        ?? ''),
    'source'       => trim($_GET['source']       ?? ''),
    'branza'       => trim($_GET['branza']       ?? ''),
    'action_id'    => (int)($_GET['action_id']   ?? 0) ?: '',
    'created_from' => trim($_GET['created_from'] ?? ''),
    'created_to'   => trim($_GET['created_to']   ?? ''),
    'last_from'    => trim($_GET['last_from']    ?? ''),
    'last_to'      => trim($_GET['last_to']      ?? ''),
    'stale_days'   => (int)($_GET['stale_days']  ?? 0) ?: '',
    'has_email'    => !empty($_GET['has_email']) ? '1' : '',
    'has_phone'    => !empty($_GET['has_phone']) ? '1' : '',
];

// Eksport tylko zaznaczonych kontaktów (po ID z paska bulk)
$only_ids = array_values(array_filter(array_map('intval', (array)($_GET['ids'] ?? []))));

// ── Definicja dostępnych kolumn: kod => [etykieta, funkcja wartości] ──────────
$COLUMNS = [
    'id'            => ['ID',                fn($r) => $r['id']],
    'type'          => ['Typ',               fn($r) => CRM_CONTACT_TYPES[$r['type']]['label'] ?? ucfirst($r['type'])],
    'imie_nazwisko' => ['Imię i nazwisko',   fn($r) => $r['imie_nazwisko']],
    'email'         => ['E-mail',            fn($r) => $r['email'] ?? ''],
    'telefon'       => ['Telefon',           fn($r) => $r['telefon'] ?? ''],
    'organizacja'   => ['Organizacja',       fn($r) => $r['organizacja'] ?? ''],
    'stanowisko'    => ['Stanowisko',        fn($r) => $r['stanowisko'] ?? ''],
    'status'        => ['Status',            fn($r) => crm_statuses()[$r['status']]['label'] ?? $r['status']],
    'adres'         => ['Adres',             fn($r) => $r['adres'] ?? ''],
    'nip'           => ['NIP',               fn($r) => $r['nip'] ?? ''],
    'krs'           => ['KRS',               fn($r) => $r['krs'] ?? ''],
    'regon'         => ['REGON',             fn($r) => $r['regon'] ?? ''],
    'pesel'         => ['PESEL',             fn($r) => $r['pesel'] ?? ''],
    'branza'        => ['Branża',            fn($r) => $r['branza'] ?? ''],
    'strona_www'    => ['Strona WWW',        fn($r) => $r['strona_www'] ?? ''],
    'wojewodztwo'   => ['Województwo',       fn($r) => $r['wojewodztwo'] ?? ''],
    'powiat'        => ['Powiat',            fn($r) => $r['powiat'] ?? ''],
    'gmina'         => ['Gmina',             fn($r) => $r['gmina'] ?? ''],
    'tags'          => ['Tagi',              fn($r) => $r['tags_csv'] ?? ''],
    'notatka'       => ['Notatka',           fn($r) => $r['notatka'] ?? ''],
    'source'        => ['Źródło',            fn($r) => $r['source'] ?? 'manual'],
    'last_comm'     => ['Ostatni kontakt',   fn($r) => $r['last_comm_at'] ? substr($r['last_comm_at'], 0, 16) : ''],
    'created'       => ['Dodano',            fn($r) => substr((string)$r['created_at'], 0, 10)],
];

// Wybór kolumn — z parametru cols[]; domyślnie wszystkie (kolejność jak w mapie)
$requested = (array)($_GET['cols'] ?? []);
$requested = array_values(array_filter($requested, fn($c) => isset($COLUMNS[$c])));
$selected  = $requested ?: array_keys($COLUMNS);

// Pola zamknięte dla roli nie mogą wyjść bocznymi drzwiami przez eksport —
// odsiewamy je z listy kolumn, zanim ktokolwiek zobaczy plik.
$selected = array_values(array_filter($selected, static fn($c) => crm_field_can_view($c)));
if (!$selected) {
    http_response_code(403);
    die('Twoja rola nie ma podglądu żadnego z wybranych pól.');
}

$headers   = array_map(fn($c) => $COLUMNS[$c][0], $selected);
$build_row = function (array $r) use ($COLUMNS, $selected) {
    $r   = crm_mask_contact($r);
    $out = [];
    foreach ($selected as $c) {
        $out[] = ($COLUMNS[$c][1])($r);
    }
    return $out;
};

// Pobierz kontakty (bez paginacji — wszystkie pasujące do filtra)
$result = CrmManager::getContacts($filters, 1, 99999);
$rows   = $result['rows'];
if ($only_ids) {
    $idset = array_flip($only_ids);
    $rows  = array_values(array_filter($rows, fn($r) => isset($idset[(int)$r['id']])));
}

$data = [];
foreach ($rows as $r) $data[] = $build_row($r);
crm_export_emit($headers, $data, 'Kontakty CRM', 'crm-kontakty', $format);
