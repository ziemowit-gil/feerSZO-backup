<?php
/**
 * cli/seedNaborZgloszenie.php — przykładowe zgłoszenie z naboru do grup TI (pełny obieg, jak po potwierdzeniu e-maila).
 *
 *   php cli/seedNaborZgloszenie.php --email=adres@example.com                 # podgląd (nic nie zapisuje)
 *   php cli/seedNaborZgloszenie.php --email=adres@example.com --apply         # zapis + obieg
 *   php cli/seedNaborZgloszenie.php --email=… --phone=600100200 --apply      # z numerem do SMS-ów statusów
 *   php cli/seedNaborZgloszenie.php --email=… --course=12 --apply            # konkretna grupa TI (domyślnie: pierwsza otwarta w naborze)
 *
 * Po --apply zgłoszenie wchodzi do obiegu (status „Nowe” albo „Termin do ustalenia”, gdy żadna grupa nie jest otwarta):
 * konto kandydata (e-mail „Ustaw hasło”, jeśli konto nie ma hasła), PDF, koszulka EZD, kontakt w CRM, e-mail z PDF.
 * Numer telefonu traktujemy jak potwierdzony (dane przykładowe) — bez --phone SMS-y statusów pójdą na numer zastępczy.
 * Każde uruchomienie z --apply tworzy NOWE zgłoszenie (numer REK/…), w historii oznaczone jako przykładowe.
 *
 * Usuwanie przykładowych zgłoszeń (tylko utworzonych tym seederem; naborDeleteSubmission — sprząta konto, pliki, CRM,
 * uczestnika TI bez historii; koszulkę EZD zamyka i ukrywa):
 *   php cli/seedNaborZgloszenie.php --delete [--email=…]            # lista do usunięcia (podgląd)
 *   php cli/seedNaborZgloszenie.php --delete [--email=…] --apply    # usunięcie
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Tylko z CLI.'); }
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/ti/logic/nabor/nabor.php';
require_once dirname(__DIR__) . '/modules/ti/logic/nabor/naborAccount.php';

$o     = getopt('', ['email:', 'phone:', 'course:', 'apply', 'delete']);
$email = mb_strtolower(trim((string)($o['email'] ?? '')));

if (array_key_exists('delete', $o)) {
    require_once dirname(__DIR__) . '/modules/ti/logic/nabor/naborDelete.php';
    naborMigrate();
    $rows = db_all("SELECT s.id, s.registration_number, s.status, s.guardian_email, s.participant_name FROM ti_nabor_submissions s
                     WHERE EXISTS (SELECT 1 FROM ti_nabor_history h WHERE h.submission_id=s.id AND h.comment LIKE 'Zgłoszenie przykładowe utworzone z CLI%')
                       " . ($email !== '' ? 'AND LOWER(s.guardian_email)=?' : '') . " ORDER BY s.id", $email !== '' ? [$email] : []);
    if (!$rows) { echo "Brak przykładowych zgłoszeń do usunięcia.\n"; exit(0); }
    foreach ($rows as $r) echo "  {$r['registration_number']} (#{$r['id']}) — {$r['participant_name']}, {$r['guardian_email']}, " . naborStatusLabel($r['status']) . "\n";
    if (!array_key_exists('apply', $o)) { echo "\nPodgląd — nic nie usunięto. Dodaj --apply.\n"; exit(0); }
    foreach ($rows as $r) {
        $rep = naborDeleteSubmission((int)$r['id'], 0, 'cli/seedNaborZgloszenie.php', 'przykładowe zgłoszenie (seeder)');
        echo "\n{$r['registration_number']}:\n  - " . implode("\n  - ", $rep) . "\n";
    }
    exit(0);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Podaj poprawny --email=adres\n"); exit(1); }
$phone = preg_replace('/\D/', '', (string)($o['phone'] ?? ''));
if (strlen($phone) === 9) $phone = '48' . $phone;
if ($phone !== '' && !preg_match('/^48\d{9}$/', $phone)) { fwrite(STDERR, "Numer telefonu: 9 cyfr (polski komórkowy)\n"); exit(1); }
$phone = $phone ?: '48000000000';

naborMigrate();
$open   = array_values(array_filter(naborCourses(true), fn($c) => (int)$c['planned'] > 0));
$course = null;
if (!empty($o['course'])) {
    $course = naborCourseGet((int)$o['course']);
    if (!$course) { fwrite(STDERR, "Grupa #{$o['course']} nie istnieje albo jest nieaktywna.\n"); exit(1); }
} elseif ($open) {
    $course = $open[0];
}

$form  = naborForm();
$equip = array_slice(array_keys(naborEquipmentOptions()), 0, 2);
$data  = [
    'course_id' => $course ? (int)$course['id'] : null,
    'is_custom_schedule' => $course ? 0 : 1,
    'preferred_days' => $course ? [] : ['wt', 'cz'], 'preferred_time_slots' => $course ? [] : ['popoludnie'],
    'notes_availability' => $course ? '' : 'Przykładowe zgłoszenie — dowolne popołudnie.',
    'participant_name' => 'Zofia Przykładowa',
    'participant_birth_date_or_pesel' => date('Y-m-d', strtotime('-11 years')),
    'participant_school_class' => 'SP 12, kl. 5a',
    'participant_medical_notes' => 'Słabowidząca — korzysta z powiększenia ekranu (zgłoszenie przykładowe).',
    'guardian_name' => 'Anna Przykładowa',
    'guardian_phone' => $phone,
    'guardian_email' => $email,
    'guardian_street' => 'Przykładowa 1', 'guardian_postal_code' => '00-001', 'guardian_city' => 'Warszawa',
    'equipment' => $equip, 'equipment_notes' => 'Laptop z NVDA',
    'payment_method' => $course ? 'transfer' : '',
    'extra' => [],
    'terms_accepted' => true, 'gdpr_accepted' => true, 'image_consent' => false, 'marketing_consent' => false,
];
// Pytania dodatkowe z edytora: pierwsza opcja listy / „tak” / krótki tekst, żeby zgłoszenie było kompletne
foreach ($form['custom'] as $q) {
    if (!$q['required']) continue;
    $data['extra'][$q['id']] = match ($q['type']) { 'select' => $q['options'][0] ?? '', 'checkbox' => 'tak', default => 'Odpowiedź przykładowa' };
}

echo "Przykładowe zgłoszenie z naboru TI\n";
echo "  opiekun:   {$data['guardian_name']} <{$email}>, +{$phone}" . (isset($o['phone']) ? '' : ' (numer zastępczy)') . "\n";
echo "  uczestnik: {$data['participant_name']}\n";
echo '  grupa:     ' . ($course ? $course['title'] . ' (#' . $course['id'] . ')' : 'termin do ustalenia (brak grup otwartych w naborze)') . "\n";
$acc = naborAccountByEmail($email);
echo '  konto:     ' . ($acc ? 'istnieje' . ($acc['password_hash'] ? ' (z hasłem)' : ' (bez hasła — przyjdzie e-mail „Ustaw hasło”)') : 'zostanie założone — przyjdzie e-mail „Ustaw hasło”') . "\n";
echo '  EZD: ' . (module_enabled('ezd_enabled') && org_setting('ti_nabor_ezd_auto') !== '0' ? 'tak' : 'nie') . ', CRM: ' . (module_enabled('crm_enabled') ? 'tak' : 'nie') . "\n";
if (!array_key_exists('apply', $o)) { echo "\nPodgląd — nic nie zapisano. Dodaj --apply.\n"; exit(0); }

$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'cli/seedNaborZgloszenie.php';
// Zgłoszenie bez linku e-mail (jak z konta kandydata), potem pełny obieg naborConfirmSubmission()
$res = naborSubmissionCreate($data, null, false);
db_exec("UPDATE ti_nabor_submissions SET email_token_hash=NULL, email_confirmed_at=datetime('now') WHERE id=?", [$res['id']]);
naborHistoryNote($res['id'], 'Zgłoszenie przykładowe utworzone z CLI (cli/seedNaborZgloszenie.php).');
$s = naborConfirmSubmission($res['id'], 'Zgłoszenie przykładowe — wprowadzone do obiegu z CLI');
echo "\nUtworzono {$res['registration_number']} (#{$res['id']}) — status: " . naborStatusLabel((string)($s['status'] ?? '?'))
   . ($s['ezd_case_id'] ?? null ? ', EZD #' . $s['ezd_case_id'] : '') . ($s['crm_contact_id'] ?? null ? ', CRM #' . $s['crm_contact_id'] : '') . "\n";
echo "Panel: " . rtrim(APP_URL, '/') . NABOR_PANEL_ENTRY . '?_route=/zgloszenia/' . $res['id'] . "\n";
echo "Konto kandydata: " . naborUrl('/logowanie') . "\n";
