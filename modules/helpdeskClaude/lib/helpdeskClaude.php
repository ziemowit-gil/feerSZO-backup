<?php
/**
 * modules/helpdeskClaude/lib/helpdeskClaude.php — kreator zgłoszeń „Dostęp do Claude” w Helpdesku (helpdesk/claude.php).
 *
 * Polecenie 2026-11-15: formularz problemów z dostępem do Claude, kreator po wyborze typu zgłoszenia; zgłaszającym jest często
 * kursant, problem dotyczy migracji kont @edu → @stud. Decyzje użytkownika: zgłasza WYŁĄCZNIE administrator (w imieniu osoby),
 * tożsamość nie jest weryfikowana (sprawdza operator), cztery typy, kategoria „Dostęp do Claude” z prefiksem CLA, po przyjęciu
 * potwierdzenie SMS i e-mailem do zgłaszającego.
 *
 * Zgłoszenie: requester_id = NULL (jak KUR: odpowiedzi idą na e-mail zgłaszającego przez track.php), źródło `kreator_claude`,
 * pierwsza wiadomość = opis z odpowiedzi kreatora, notatka wewnętrzna = kto założył, brak weryfikacji, dodatkowe dane.
 * SMS idzie rodzajem 'helpdesk' (modules/sms_senders); poza 7–22 czeka w kolejce (inc/smsQuietHours).
 * Testy: define('HDC_DRY_NOTIFY', true) — żadnych potwierdzeń; define('HDC_DRY_SMS', true) — tylko bez SMS-a (e-mail trafia do kolejki).
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';

const HDC_CATEGORY = 'it_claude';

/** Typy zgłoszeń: klucz => etykieta, opis, ikona, domyślny priorytet, pola szczegółów. */
function hdcTypes(): array {
    $mailbox = ['Mam skrzynkę @stud i umiem się do niej zalogować', 'Skrzynka @stud istnieje, ale nie mam hasła', 'Nie wiem, czy mam skrzynkę @stud'];
    return [
        'migracja' => [
            'label' => 'Migracja konta @edu na @stud', 'icon' => 'bi-arrow-left-right', 'priority' => 'wysoki',
            'desc' => 'Claude był założony na adres @edu.feer.org.pl, a konta @edu zostają wyłączone 20.11.2026.',
            'fields' => [
                ['key' => 'edu_email', 'label' => 'Stary adres @edu', 'type' => 'email', 'required' => true, 'placeholder' => 'imie.nazwisko@edu.feer.org.pl'],
                ['key' => 'stud_email', 'label' => 'Nowy adres @stud', 'type' => 'email', 'required' => true, 'placeholder' => 'inicjal.nazwisko@stud.feer.org.pl', 'domain' => '@stud.feer.org.pl'],
                ['key' => 'stud_mailbox', 'label' => 'Skrzynka @stud', 'type' => 'select', 'required' => true, 'options' => $mailbox],
                ['key' => 'symptom', 'label' => 'Co się dzieje', 'type' => 'select', 'required' => true, 'options' => [
                    'Claude prosi o stary adres @edu i nie mogę się zalogować',
                    'Nie mogę założyć konta Claude na adres @stud',
                    'Konto na @stud działa, ale nie mam licencji',
                    'Zniknęły rozmowy i projekty ze starego konta',
                    'Inny problem']],
                ['key' => 'details', 'label' => 'Dodatkowy opis i treść komunikatu', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'logowanie' => [
            'label' => 'Nie mogę się zalogować do Claude', 'icon' => 'bi-box-arrow-in-right', 'priority' => 'normalny',
            'desc' => 'Błędny login lub hasło, nie przychodzi kod, konto zablokowane, komunikat przy logowaniu.',
            'fields' => [
                ['key' => 'claude_email', 'label' => 'Adres, na który logujesz się do Claude', 'type' => 'email', 'required' => true],
                ['key' => 'method', 'label' => 'Jak próbujesz się zalogować', 'type' => 'select', 'required' => true, 'options' => ['Kod wysłany e-mailem', 'Hasło', 'Konto Google', 'Nie wiem']],
                ['key' => 'since', 'label' => 'Od kiedy to trwa', 'type' => 'select', 'required' => true, 'options' => ['Od dzisiaj', 'Od kilku dni', 'Od dawna', 'Nigdy nie działało']],
                ['key' => 'details', 'label' => 'Treść komunikatu albo opis', 'type' => 'textarea', 'required' => true],
            ],
        ],
        'brak_dostepu' => [
            'label' => 'Nie mam dostępu albo licencji', 'icon' => 'bi-key', 'priority' => 'normalny',
            'desc' => 'Powinienem(-nam) mieć Claude, ale go nie widzę albo nie dostałem(-am) zaproszenia.',
            'fields' => [
                ['key' => 'claude_email', 'label' => 'Adres, na który ma być konto Claude', 'type' => 'email', 'required' => true],
                ['key' => 'invited', 'label' => 'Zaproszenie', 'type' => 'select', 'required' => true, 'options' => ['Dotarło, ale nie działa', 'Nie dotarło', 'Nie wiem']],
                ['key' => 'application', 'label' => 'Wniosek o dostęp do Claude (ANT1)', 'type' => 'select', 'required' => true, 'options' => ['Tak, jest podpisany', 'Nie składałem(-am)', 'Nie wiem']],
                ['key' => 'details', 'label' => 'Dodatkowy opis', 'type' => 'textarea', 'required' => false],
            ],
        ],
        'dzialanie' => [
            'label' => 'Claude działa błędnie lub ma limit', 'icon' => 'bi-exclamation-triangle', 'priority' => 'normalny',
            'desc' => 'Komunikaty o limicie, błędy przy wysyłaniu wiadomości, wolne działanie, problemy w aplikacji.',
            'fields' => [
                ['key' => 'claude_email', 'label' => 'Adres konta Claude', 'type' => 'email', 'required' => true],
                ['key' => 'where', 'label' => 'Gdzie używasz Claude', 'type' => 'select', 'required' => true, 'options' => ['W przeglądarce', 'W aplikacji na komputerze', 'W aplikacji na telefonie']],
                ['key' => 'problem', 'label' => 'Rodzaj problemu', 'type' => 'select', 'required' => true, 'options' => ['Komunikat o limicie', 'Błąd przy wysyłaniu wiadomości', 'Działa bardzo wolno', 'Inny problem']],
                ['key' => 'details', 'label' => 'Opis i treść komunikatu', 'type' => 'textarea', 'required' => true],
            ],
        ],
    ];
}

function hdcRoles(): array { return ['Kursant', 'Rodzic lub opiekun kursanta', 'Inna osoba']; }

/**
 * Sprawdza odpowiedzi kreatora. Zwraca [błędy, oczyszczone dane]. Wymagane: typ, pola typu, dane zgłaszającego.
 * @return array{0:list<string>, 1:array}
 */
function hdcValidate(array $in): array {
    $errors = [];
    $types = hdcTypes();
    $type = (string)($in['type'] ?? '');
    if (!isset($types[$type])) return [['Wybierz typ zgłoszenia.'], []];
    $d = ['type' => $type, 'answers' => []];
    foreach ($types[$type]['fields'] as $f) {
        $v = trim((string)($in['f'][$f['key']] ?? ''));
        if ($f['type'] === 'textarea') $v = mb_substr($v, 0, 4000);
        else $v = mb_substr($v, 0, 300);
        if ($f['required'] && $v === '') { $errors[] = 'Uzupełnij pole: ' . $f['label'] . '.'; continue; }
        if ($v === '') continue;
        if ($f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[] = $f['label'] . ': podaj poprawny adres e-mail.'; continue; }
        if (!empty($f['domain']) && !str_ends_with(strtolower($v), $f['domain'])) { $errors[] = $f['label'] . ' musi kończyć się na ' . $f['domain'] . '.'; continue; }
        if ($f['type'] === 'select' && !in_array($v, $f['options'], true)) { $errors[] = 'Wybierz odpowiedź w polu: ' . $f['label'] . '.'; continue; }
        $d['answers'][$f['key']] = ['label' => $f['label'], 'value' => $v];
    }
    $d['name']  = trim(mb_substr((string)($in['req_name'] ?? ''), 0, 120));
    $d['email'] = trim((string)($in['req_email'] ?? ''));
    $d['phone'] = preg_replace('/\D/', '', (string)($in['req_phone'] ?? ''));
    $d['role']  = (string)($in['req_role'] ?? '');
    $d['group'] = trim(mb_substr((string)($in['req_group'] ?? ''), 0, 120));
    $d['client_id'] = max(0, (int)($in['client_id'] ?? 0));   // kursant z kartoteki (opcjonalnie) — dane sprawdzamy w bazie przy zapisie
    $d['priority'] = (string)($in['priority'] ?? $types[$type]['priority']);
    if (!isset(HD_PRIORITIES[$d['priority']])) $d['priority'] = $types[$type]['priority'];
    if ($d['name'] === '') $errors[] = 'Podaj imię i nazwisko zgłaszającego.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Podaj poprawny e-mail do kontaktu.';
    elseif (str_ends_with(strtolower($d['email']), '@edu.feer.org.pl')) $errors[] = 'E-mail do kontaktu nie może być adresem @edu, bo te konta są wyłączane 20.11.2026. Podaj prywatny e-mail.';
    if (strlen($d['phone']) < 9) $errors[] = 'Podaj numer telefonu (min. 9 cyfr), na który wyślemy SMS z potwierdzeniem.';
    if (!in_array($d['role'], hdcRoles(), true)) $errors[] = 'Wskaż, kim jest zgłaszający.';
    return [$errors, $d];
}

/** Adres konta Claude / @stud użyty w temacie zgłoszenia. */
function hdcPrimaryAddress(array $d): string {
    foreach (['stud_email', 'claude_email', 'edu_email'] as $k) if (!empty($d['answers'][$k]['value'])) return $d['answers'][$k]['value'];
    return '';
}

function hdcTitle(array $d): string {
    $t = hdcTypes()[$d['type']]['label'];
    $a = hdcPrimaryAddress($d);
    return mb_substr('Claude: ' . $t . ($a !== '' ? ' (' . $a . ')' : ''), 0, 200);
}

/** Treść zgłoszenia: lista odpowiedzi kreatora (widoczna dla zgłaszającego w mikropanelu). */
function hdcDescription(array $d): string {
    $lines = ['Typ problemu: ' . hdcTypes()[$d['type']]['label']];
    foreach ($d['answers'] as $a) $lines[] = $a['label'] . ': ' . $a['value'];
    return implode("\n", $lines);
}

/**
 * Zakłada zgłoszenie, notatkę wewnętrzną, załączniki, powiadamia operatorów i wysyła potwierdzenie SMS + e-mail.
 * @param array $admin  zalogowany administrator (users)
 * @param array $files  $_FILES['attachments'] albo []
 * @return array{id:int, number:string, ack:array{sms:string, email:string}}
 */
function hdcCreate(array $d, array $admin, array $files = []): array {
    helpdesk_migrate();
    $category = HDC_CATEGORY;
    $title = hdcTitle($d);
    $description = hdcDescription($d);
    $number = hd_next_number(hd_number_prefix_for($category), hd_number_pattern_for($category));
    $id = db_insert('helpdesk_tickets', [
        'number' => $number, 'title' => $title, 'description' => $description, 'category' => $category,
        'priority' => $d['priority'], 'status' => 'nowe', 'requester_id' => null,
        'requester_name' => $d['name'], 'requester_email' => $d['email'], 'requester_phone' => $d['phone'],
        'source' => 'kreator_claude',
    ]);
    db_insert('helpdesk_messages', ['ticket_id' => $id, 'user_id' => null, 'user_name' => $d['name'], 'body' => $description, 'is_internal' => 0]);
    $note = ['Zgłoszenie założone w kreatorze „Dostęp do Claude” przez administratora: ' . ($admin['name'] ?? '') . '.',
             'Tożsamość zgłaszającego NIE jest zweryfikowana. Przed zmianą dostępu potwierdź ją, np. przez kod SMS albo dane z kartoteki kursanta.',
             'Zgłaszający: ' . $d['role'] . ($d['group'] !== '' ? '. Grupa lub kurs: ' . $d['group'] : '') . '.'];
    if (!empty($d['client_id']) && ($card = hdcClientCard((int)$d['client_id']))) {
        $note[] = '';
        $note[] = 'Kursant w kartotece TI: nr ' . $card['id'] . ', ' . $card['name'] . ($card['groups'] !== '' ? ' (' . $card['groups'] . ')' : '') . '.';
        foreach ($card['lines'] as [$label, $text]) $note[] = $label . ': ' . $text;
        if ($card['link'] !== '') $note[] = 'Karta w Ewidencji Uczestników: ' . $card['link'];
    }
    db_insert('helpdesk_messages', ['ticket_id' => $id, 'user_id' => (int)($admin['id'] ?? 0) ?: null, 'user_name' => $admin['name'] ?? '', 'body' => implode("\n", $note), 'is_internal' => 1]);

    try { hd_redmine_sync_ticket($id); } catch (\Throwable $e) {}
    hd_after_create($id);
    hdcSaveAttachments($id, $files, (int)($admin['id'] ?? 0));
    $ticket = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$id]) ?? ['id' => $id, 'number' => $number, 'title' => $title, 'requester_phone' => $d['phone'], 'requester_email' => $d['email'], 'requester_name' => $d['name']];
    hdcNotifyOperators($ticket, $d);
    $ack = hdcSendAck($ticket, $d);
    db_insert('helpdesk_messages', ['ticket_id' => $id, 'user_id' => null, 'user_name' => 'System', 'is_internal' => 1,
        'body' => 'Potwierdzenie przyjęcia zgłoszenia. SMS: ' . $ack['sms'] . '. E-mail: ' . $ack['email'] . '.']);
    return ['id' => $id, 'number' => $number, 'ack' => $ack];
}

function hdcSaveAttachments(int $ticketId, array $files, int $uid): void {
    if (empty($files['name'][0])) return;
    $dir = UPLOAD_DIR . 'helpdesk/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $allow = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'txt', 'csv'];
    foreach ($files['name'] as $i => $orig) {
        if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo((string)$orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allow, true)) continue;
        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@move_uploaded_file($files['tmp_name'][$i], $dir . $stored)) continue;
        $att = db_insert('helpdesk_attachments', ['ticket_id' => $ticketId, 'original_name' => $orig, 'stored_path' => 'helpdesk/' . $stored,
            'file_size' => $files['size'][$i], 'uploaded_by' => $uid ?: null]);
        try { require_once dirname(__DIR__, 2) . '/doc_registry/lib/DocRegistry.php'; DocRegistry::assign('helpdesk_attachment', $att); } catch (\Throwable $e) {}
    }
}

/** Powiadomienie operatorów Helpdesku o nowym zgłoszeniu (tak jak helpdesk/new.php). */
function hdcNotifyOperators(array $ticket, array $d): void {
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $org = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
        $url = APP_URL . '/helpdesk/view.php?id=' . (int)$ticket['id'];
        $ops = db_all("SELECT email, name FROM users WHERE helpdesk_operator=1 AND is_active=1 AND email IS NOT NULL AND email != ''");
        foreach ($ops as $op) {
            if (!hdNotifyAllows($op['email'], 'new_ticket')) continue;
            mail_queue_add($op['email'], $op['name'] ?? '', '[' . $ticket['number'] . '] Nowe zgłoszenie: ' . $ticket['title'], hd_mail_layout([
                'org' => $org, 'title' => 'Nowe zgłoszenie dotyczące Claude', 'preheader' => $ticket['number'] . ': ' . $ticket['title'],
                'facts' => [['Numer', $ticket['number']], ['Typ', hdcTypes()[$d['type']]['label']], ['Zgłaszający', $d['name'] . ' (' . $d['role'] . ')'],
                            ['Priorytet', HD_PRIORITIES[$d['priority']]['label'] ?? $d['priority']]],
                'quote' => ['label' => 'Opis zgłoszenia', 'html' => hdMailText(hdcDescription($d), 600)],
                'cta' => ['label' => 'Przypisz i odpowiedz', 'url' => $url],
            ]));
        }
    } catch (\Throwable $e) { error_log('[helpdeskClaude] powiadomienie operatorów: ' . $e->getMessage()); }
}

/** SMS i e-mail z potwierdzeniem przyjęcia. Błąd jednego kanału nie blokuje drugiego ani zgłoszenia. */
function hdcSendAck(array $ticket, array $d): array {
    $out = ['sms' => 'pominięto', 'email' => 'pominięto'];
    if (defined('HDC_DRY_NOTIFY')) return ['sms' => 'pominięto (tryb testowy)', 'email' => 'pominięto (tryb testowy)'];
    $org = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $track = hd_track_url($ticket);
    $number = (string)$ticket['number'];
    try {
        if (defined('HDC_DRY_SMS')) throw new RuntimeException('pominięto (tryb testowy)');
        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        $res = sms_send((string)$d['phone'], "[{$org}] Zgłoszenie {$number} zostało przyjęte. Odpowiemy na e-mail. Status: {$track}", 'helpdesk');
        $out['sms'] = $res === 'odlozony' ? 'odłożony do 7:00' : 'wysłany';
    } catch (\Throwable $e) { $out['sms'] = defined('HDC_DRY_SMS') ? 'pominięto (tryb testowy)' : 'błąd (' . mb_substr($e->getMessage(), 0, 120) . ')'; }
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $next = $d['type'] === 'migracja'
            ? '<p style="margin:10px 0 0">Konta @edu są wyłączane 20.11.2026, dlatego zgłoszenia o migracji na @stud obsługujemy w pierwszej kolejności.</p>' : '';
        $html = hd_mail_layout([
            'org' => $org, 'title' => 'Zgłoszenie zostało przyjęte', 'preheader' => $number . ': ' . $ticket['title'],
            'greeting' => 'Dzień dobry, ' . $d['name'] . ',',
            'intro' => '<p style="margin:0">Przyjęliśmy zgłoszenie dotyczące dostępu do Claude. Odpowiemy na ten adres e-mail. Operator może poprosić o potwierdzenie tożsamości, zanim zmieni dostęp do konta.</p>' . $next,
            'facts' => [['Numer', $number], ['Temat', $ticket['title']], ['Priorytet', HD_PRIORITIES[$d['priority']]['label'] ?? $d['priority']]],
            'quote' => ['label' => 'Twoje odpowiedzi', 'html' => hdMailText(hdcDescription($d))],
            'cta' => ['label' => 'Śledź status zgłoszenia', 'url' => $track],
            'footnote' => 'Możesz też odpowiedzieć na tę wiadomość, zachowując numer [' . h($number) . '] w temacie.',
        ]);
        mail_queue_add($d['email'], $d['name'], "[{$number}] Zgłoszenie przyjęte", $html);
        $out['email'] = 'w kolejce wysyłki';
    } catch (\Throwable $e) { $out['email'] = 'błąd (' . mb_substr($e->getMessage(), 0, 120) . ')'; }
    return $out;
}


// ── Kartoteka kursanta TI (k30_clients) ─────────────────────────────────────────────────

/** Grupy aktywnych zapisów: client_id => „GRUPA1, GRUPA2”. */
function hdcClientGroups(array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        foreach (db_all("SELECT e.client_id, GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(c.group_code),''), c.name)) AS g
                           FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id
                          WHERE e.client_id IN ($in) AND e.status = 'active' AND c.closed_at IS NULL GROUP BY e.client_id", $ids) as $r) {
            $out[(int)$r['client_id']] = (string)$r['g'];
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** Małe litery bez polskich znaków diakrytycznych — do porównań w wyszukiwaniu („Łukasiewicz” = „lukasiewicz”). */
function hdcFold(string $s): string {
    return strtr(mb_strtolower($s), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
}

/**
 * Wyszukiwanie kursanta w kartotece po fragmentach imienia i nazwiska (wszystkie słowa), e-mailu albo telefonie.
 * Filtrowanie w PHP (hdcFold: małe litery, bez polskich znaków), bo LOWER()/LIKE w SQLite nie radzą sobie z polskimi wielkimi literami. Konta wirtualne (testowe) pomijamy.
 * @return list<array{id:int,name:string,email:string,phone:string,groups:string}>
 */
function hdcClientSearch(string $q, int $limit = 12): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $tokens = array_values(array_filter(preg_split('/\s+/u', hdcFold($q))));
    $digits = preg_replace('/\D/', '', $q);
    $rows = db_all("SELECT cl.id, cl.name, cl.email, cl.phone FROM k30_clients cl
                     WHERE NOT EXISTS (SELECT 1 FROM k30_ti_student_accounts sa WHERE sa.client_id = cl.id AND COALESCE(sa.is_virtual,0) = 1)");
    $hits = [];
    foreach ($rows as $r) {
        $hay = hdcFold((string)$r['name'] . ' ' . (string)$r['email']);
        $ok = true;
        foreach ($tokens as $t) if (mb_strpos($hay, $t) === false) { $ok = false; break; }
        if (!$ok && strlen($digits) >= 6 && str_contains(preg_replace('/\D/', '', (string)$r['phone']), $digits)) $ok = true;
        if ($ok) $hits[] = $r;
    }
    $groups = hdcClientGroups(array_column(array_slice($hits, 0, 60), 'id'));
    $out = [];
    foreach ($hits as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => trim((string)$r['name']), 'email' => trim((string)($r['email'] ?? '')),
                  'phone' => trim((string)($r['phone'] ?? '')), 'groups' => $groups[(int)$r['id']] ?? ''];
    }
    usort($out, fn($a, $b) => [$a['groups'] === '' ? 1 : 0, hdcFold($a['name'])] <=> [$b['groups'] === '' ? 1 : 0, hdcFold($b['name'])]);
    return array_slice($out, 0, $limit);
}

/**
 * Karta kursanta do kreatora: konta (@edu, @stud), licencja Claude, dane opiekuna oraz propozycja uzupełnień formularza.
 * Źródła: k30_clients, ClaudeBilling::eduAccount/assignment, PocztaStud::get, k30_ti_student_accounts (małoletni, opiekun).
 * @return array{id:int,name:string,groups:string,minor:bool,lines:list<array{0:string,1:string}>,link:string,prefill:array}|null
 */
function hdcClientCard(int $cid): ?array {
    $c = db_one("SELECT id, name, email, phone FROM k30_clients WHERE id=?", [$cid]);
    if (!$c) return null;
    $sa = db_one("SELECT id, is_minor, guardian_name, guardian_phone, guardian_email FROM k30_ti_student_accounts WHERE client_id=?", [$cid]) ?: [];
    $groups = hdcClientGroups([$cid])[$cid] ?? '';
    $minor = !empty($sa['is_minor']);
    $edu = $stud = null; $lic = null; $studAcc = null;
    try { $f = dirname(__DIR__, 2) . '/claude_billing/logic/claudeBilling.php'; if (is_file($f)) { require_once $f; $edu = ClaudeBilling::eduAccount($cid); $lic = ClaudeBilling::assignment($cid, 'claude'); } } catch (\Throwable $e) {}
    try { $f = dirname(__DIR__, 2) . '/ti_poczta_stud/logic/PocztaStud.php'; if (is_file($f)) { require_once $f; $studAcc = PocztaStud::get($cid); $stud = $studAcc['login'] ?? null; } } catch (\Throwable $e) {}

    $lines = [];
    $lines[] = ['Konto @edu', $edu ?: 'brak w kartotece'];
    $lines[] = ['Poczta @stud', $stud ? $stud . ($studAcc ? ' (' . PocztaStud::staffStatus($studAcc) . ')' : '') : 'brak skrzynki wpisanej przez kadrę'];
    $lines[] = ['Licencja Claude', $lic ? ($lic['license_name'] . ($lic['login'] ? ', konto ' . $lic['login'] : '') . ($lic['expires_at'] ? ', ważna do ' . $lic['expires_at'] : '')) : 'brak aktywnej licencji'];
    if ($minor) $lines[] = ['Małoletni', 'tak' . (!empty($sa['guardian_name']) ? ', opiekun: ' . $sa['guardian_name'] : '') . (!empty($sa['guardian_phone']) ? ', tel. ' . $sa['guardian_phone'] : '')];

    $ok = fn($v) => $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) && !preg_match('/@(edu|stud)\.feer\.org\.pl$/i', $v);
    $clientEmail = trim((string)($c['email'] ?? '')); $guardEmail = trim((string)($sa['guardian_email'] ?? ''));
    $contactEmail = $minor && $ok($guardEmail) ? $guardEmail : ($ok($clientEmail) ? $clientEmail : ($ok($guardEmail) ? $guardEmail : ''));
    $phone = preg_replace('/\D/', '', $minor && !empty($sa['guardian_phone']) ? (string)$sa['guardian_phone'] : (string)($c['phone'] ?? ''));
    $name = $minor && !empty($sa['guardian_name']) ? (string)$sa['guardian_name'] : trim((string)$c['name']);
    return [
        'id' => (int)$c['id'], 'name' => trim((string)$c['name']), 'groups' => $groups, 'minor' => $minor, 'lines' => $lines,
        'link' => !empty($sa['id']) ? APP_URL . '/karty30/ti/dydaktyk/konta.php?selected=' . (int)$sa['id'] : '',
        'prefill' => [
            'edu_email' => (string)$edu, 'stud_email' => (string)$stud, 'claude_email' => (string)($lic['login'] ?? ($stud ?: $edu ?: '')),
            'req_name' => $name, 'req_email' => $contactEmail, 'req_phone' => $phone,
            'req_role' => $minor ? 'Rodzic lub opiekun kursanta' : 'Kursant', 'req_group' => $groups,
        ],
    ];
}
