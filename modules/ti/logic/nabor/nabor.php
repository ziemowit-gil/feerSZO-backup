<?php
/**
 * modules/ti/logic/nabor/nabor.php — TI › Nabór do grup: rdzeń (zgłoszenia nowych uczestników przez rodziców/opiekunów).
 *
 * Obieg zgłoszenia:
 *   formularz (numer opiekuna potwierdzony kodem SMS) → status „unconfirmed” + e-mail z linkiem
 *   → kliknięcie linku (naborConfirmEmail) → „new” (albo „waitlist”, gdy grupa pełna; „custom_schedule”
 *   przy terminie do ustalenia) → PDF zgłoszenia → koszulka w EZD (NaborEzdService) → kontakt opiekuna w CRM
 *   → obsługa w panelu dydaktyka (naborChangeStatus: historia + powiadomienie e-mail/SMS + aktywność w CRM)
 *   → przyjęcie: uczestnik TI (k30_clients) + aktywny zapis do grupy (k30_ti_enrollments) — naborEnroll().
 * Grupy = grupy TI (k30_ti_courses); w formularzu tylko te, które kierownik otworzył (ti_nabor_courses.is_open).
 * Niepotwierdzone zgłoszenia wygasają po NABOR_CONFIRM_HOURS (naborExpireUnconfirmed — wołane leniwie).
 * Integracje (EZD, CRM, powiadomienia) są „najlepszym wysiłkiem”: błąd nigdy nie cofa zapisu zgłoszenia.
 */
require_once __DIR__ . '/naborSchema.php';
require_once dirname(__DIR__, 4) . '/includes/functions.php';

const NABOR_CONFIRM_HOURS = 72;
/** Publiczne wejście modułu TI (router.php na wzór Laravela) — adresy: NABOR_ENTRY + ścieżka trasy. */
const NABOR_ENTRY = '/modules/ti/router.php';
/** Wejście panelu dydaktyka (ta sama tablica tras, sesja panelu, trasy w ?_route=). */
const NABOR_PANEL_ENTRY = '/karty30/ti/dydaktyk/nabor.php';
/** Weryfikacja numeru opiekuna: cel w modules/weryfikacja_sms. */
const NABOR_PHONE_PURPOSE = 'ti_nabor';

const NABOR_STATUSES = [
    'unconfirmed'     => ['Oczekuje na potwierdzenie e-mail', 'secondary'],
    'new'             => ['Nowe',                             'primary'],
    'pending_review'  => ['W weryfikacji',                    'info'],
    'custom_schedule' => ['Termin do ustalenia',              'warning'],
    'accepted'        => ['Przyjęte',                         'success'],
    'waitlist'        => ['Lista rezerwowa',                  'warning'],
    'rejected'        => ['Odrzucone',                        'danger'],
    'cancelled'       => ['Anulowane',                        'dark'],
    'archived'        => ['Zarchiwizowane',                   'light'],
];
/** Statusy, które operator może ustawić ręcznie (unconfirmed nadaje tylko formularz). */
const NABOR_MANUAL_STATUSES = ['new', 'pending_review', 'custom_schedule', 'accepted', 'waitlist', 'rejected', 'cancelled', 'archived'];
/** Kolumny kanbanu w panelu. */
const NABOR_KANBAN = ['new', 'pending_review', 'custom_schedule', 'waitlist', 'accepted'];

const NABOR_PICKUP = [
    'independent'        => 'Uczestnik wraca samodzielnie',
    'guardians_only'     => 'Odbiór wyłącznie przez rodziców / opiekunów prawnych',
    'authorized_persons' => 'Odbiór także przez osoby upoważnione',
];
/** Zajęcia online — sprzęt i dostęp uczestnika (zamiast zasad odbioru). */
const NABOR_EQUIPMENT = [
    'komputer' => 'Komputer lub laptop',
    'kamera'   => 'Kamera internetowa',
    'mikrofon' => 'Mikrofon / słuchawki',
    'internet' => 'Stabilne łącze internetowe',
    'czytnik'  => 'Czytnik ekranu lub program powiększający',
    'pomoc'    => 'Potrzebna pomoc w konfiguracji sprzętu',
];
/** Lista sprzętu (JSON z bazy) jako tekst. */
function naborEquipmentLabel(?string $json): string {
    $o = function_exists('naborEquipmentOptions') ? naborEquipmentOptions() + NABOR_EQUIPMENT : NABOR_EQUIPMENT;
    return implode(', ', array_map(fn($k) => $o[$k] ?? $k, json_decode($json ?: '[]', true) ?: []));
}

const NABOR_DAYS  = ['pn' => 'poniedziałek', 'wt' => 'wtorek', 'sr' => 'środa', 'cz' => 'czwartek', 'pt' => 'piątek', 'sb' => 'sobota'];
const NABOR_SLOTS = ['rano' => 'przed południem (8–12)', 'popoludnie' => 'po południu (12–16)', 'wieczor' => 'późne popołudnie (16–19)'];
const NABOR_PAYMENT = ['transfer' => 'Przelew na rachunek organizacji', 'cash' => 'Gotówka na pierwszych zajęciach', 'exempt' => 'Wnioskuję o zwolnienie z opłaty'];

function naborStatusLabel(string $s): string { return NABOR_STATUSES[$s][0] ?? $s; }
function naborStatusBadge(string $s): string {
    $c = NABOR_STATUSES[$s][1] ?? 'secondary';
    return '<span class="badge text-bg-' . $c . '">' . h(naborStatusLabel($s)) . '</span>';
}

/* ─────────────────────────── Grupy TI ─────────────────────────── */

/**
 * Aktywne grupy TI z ustawieniami naboru i licznikami: taken (aktywne zapisy), queued (zgłoszenia nowe/w weryfikacji/rezerwa),
 * free (null = bez limitu), title (nazwa dla rodziców), schedule (opis terminów), is_open.
 * $publicOnly — tylko grupy otwarte w formularzu publicznym.
 */
function naborCourses(bool $publicOnly = false): array {
    naborMigrate();
    $rows = db_all(
        "SELECT c.id, c.name, c.display_name, c.location, c.day_of_week, c.time_from, c.time_to, c.max_students, c.instructor_id,
                c.status, u.name AS instructor_name,
                COALESCE(n.is_open, 0) AS is_open, COALESCE(n.public_name, '') AS public_name, COALESCE(n.category, '') AS category,
                COALESCE(n.age_group, '') AS age_group, COALESCE(n.schedule, '') AS schedule_note, COALESCE(n.price_info, '') AS price_info,
                COALESCE(n.sort_order, 0) AS sort_order, (n.course_id IS NOT NULL) AS configured,
                (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS taken,
                (SELECT COUNT(*) FROM ti_nabor_submissions s WHERE s.course_id=c.id AND s.status IN ('new','pending_review','waitlist')) AS queued,
                (SELECT COUNT(*) FROM k30_ti_sessions ss WHERE ss.course_id=c.id AND ss.draft_id IS NULL AND ss.lesson_date >= date('now')
                    AND ss.status NOT IN ('draft','reserved','cancelled')) AS planned,
                (SELECT MIN(ss.lesson_date || ' ' || COALESCE(ss.time_from,'')) FROM k30_ti_sessions ss WHERE ss.course_id=c.id AND ss.draft_id IS NULL
                    AND ss.lesson_date >= date('now') AND ss.status NOT IN ('draft','reserved','cancelled')) AS first_lesson
           FROM k30_ti_courses c
      LEFT JOIN ti_nabor_courses n ON n.course_id=c.id
      LEFT JOIN users u ON u.id=c.instructor_id
          WHERE c.status NOT IN ('cancelled','archived') AND c.is_active=1 AND c.closed_at IS NULL
                " . ($publicOnly ? 'AND n.is_open=1' : '') . "
       ORDER BY COALESCE(n.is_open,0) DESC, COALESCE(n.sort_order,0), c.name");
    // Formularz publiczny: tylko grupy z zaplanowanymi przyszłymi lekcjami (plan zajęć ustala kierownik przed naborem)
    if ($publicOnly) $rows = array_values(array_filter($rows, fn($r) => (int)$r['planned'] > 0));
    foreach ($rows as &$r) {
        $r['title']    = $r['public_name'] !== '' ? $r['public_name'] : (trim((string)$r['display_name']) !== '' ? $r['display_name'] : $r['name']);
        $r['schedule'] = $r['schedule_note'] !== '' ? $r['schedule_note'] : naborCourseDefaultSchedule($r);
        $r['first_label'] = naborFirstLessonLabel((string)($r['first_lesson'] ?? ''));
        $r['free']     = (int)$r['max_students'] > 0 ? max(0, (int)$r['max_students'] - (int)$r['taken']) : null;
    }
    return $rows;
}

/** „Wtorek 15:00–16:30” z dnia i godzin grupy ('' gdy brak). */
function naborCourseDefaultSchedule(array $c): string {
    if ($c['day_of_week'] === null || $c['day_of_week'] === '' || !isset(K30_TI_DAYS[(int)$c['day_of_week']])) return '';
    return K30_TI_DAYS[(int)$c['day_of_week']] . ($c['time_from'] ? ' ' . substr((string)$c['time_from'], 0, 5) . ($c['time_to'] ? '–' . substr((string)$c['time_to'], 0, 5) : '') : '');
}

/** „wt 4.11.2026, 15:00” z „2026-11-04 15:00” ('' gdy brak). */
function naborFirstLessonLabel(string $v): string {
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s*(\d{2}:\d{2})?/', $v, $m)) return '';
    $d = strtotime($m[1]);
    return ['nd', 'pn', 'wt', 'śr', 'cz', 'pt', 'sb'][(int)date('w', $d)] . ' ' . date('j.m.Y', $d) . (!empty($m[2]) ? ', ' . $m[2] : '');
}

function naborCourseGet(int $id): ?array {
    foreach (naborCourses() as $c) if ((int)$c['id'] === $id) return $c;
    return null;
}

/** Ustawienia naboru dla grupy TI (upsert). */
function naborCourseSettingsSave(int $courseId, array $d): void {
    naborMigrate();
    $c = naborCourseGet($courseId);
    if (!$c) throw new InvalidArgumentException('Grupa nie istnieje albo jest nieaktywna.');
    if (!empty($d['is_open']) && (int)$c['planned'] === 0)
        throw new InvalidArgumentException('Grupa „' . $c['title'] . '” nie ma zaplanowanych przyszłych zajęć — najpierw ustal plan zajęć grupy, potem otwórz nabór.');
    $t = fn($k, $max = 300) => mb_substr(trim((string)($d[$k] ?? '')), 0, $max);
    db_exec("INSERT INTO ti_nabor_courses (course_id, is_open, public_name, category, age_group, schedule, price_info, sort_order, updated_at)
             VALUES (?,?,?,?,?,?,?,?,datetime('now'))
             ON CONFLICT(course_id) DO UPDATE SET is_open=excluded.is_open, public_name=excluded.public_name, category=excluded.category,
                age_group=excluded.age_group, schedule=excluded.schedule, price_info=excluded.price_info, sort_order=excluded.sort_order,
                updated_at=excluded.updated_at",
            [$courseId, !empty($d['is_open']) ? 1 : 0, $t('public_name'), $t('category', 100), $t('age_group', 100),
             $t('schedule'), $t('price_info', 200), (int)($d['sort_order'] ?? 0)]);
}

/* ─────────────────────────── Zgłoszenia ─────────────────────────── */

function naborSubmissionGet(int $id): ?array {
    naborMigrate();
    $s = db_one(
        "SELECT s.*, c.name AS course_raw_name, c.display_name AS course_display_name, c.location AS course_location,
                c.instructor_id AS course_instructor_id, c.day_of_week, c.time_from, c.time_to,
                n.public_name, n.schedule AS schedule_note
           FROM ti_nabor_submissions s
      LEFT JOIN k30_ti_courses c ON c.id=s.course_id
      LEFT JOIN ti_nabor_courses n ON n.course_id=s.course_id
          WHERE s.id=?", [$id]);
    if ($s && $s['course_id']) {
        $s['course_name']     = ($s['public_name'] ?? '') !== '' ? $s['public_name'] : (trim((string)$s['course_display_name']) !== '' ? $s['course_display_name'] : $s['course_raw_name']);
        $s['course_schedule'] = ($s['schedule_note'] ?? '') !== '' ? $s['schedule_note'] : naborCourseDefaultSchedule($s);
    } elseif ($s) {
        $s['course_name'] = null; $s['course_schedule'] = '';
    }
    return $s;
}

/**
 * Przyjęcie: uczestnik TI (k30_clients — istniejący po PESEL albo nowy) + aktywny zapis do grupy.
 * Kontakt uczestnika = telefon i e-mail opiekuna (małoletni). Zwraca client_id.
 */
function naborEnroll(int $sid, int $uid, string $byName = ''): int {
    $s = naborSubmissionGet($sid);
    if (!$s || !$s['course_id']) throw new InvalidArgumentException('Zgłoszenie bez grupy.');
    $idv   = trim((string)$s['participant_birth_date_or_pesel']);
    $pesel = preg_match('/^\d{11}$/', $idv) ? $idv : '';
    $dob   = $pesel === '' ? naborDateIso($idv) : naborPeselBirthDate($pesel);
    $cid   = (int)($s['client_id'] ?? 0);
    if (!$cid && $pesel !== '') $cid = (int)(db_one("SELECT id FROM k30_clients WHERE pesel=? ORDER BY id LIMIT 1", [$pesel])['id'] ?? 0);
    if (!$cid) {
        $notes = 'Zgłoszenie z naboru ' . $s['registration_number'] . '. Opiekun: ' . $s['guardian_name'] . ', +' . $s['guardian_phone'] . ', ' . $s['guardian_email']
               . "\nZajęcia online — sprzęt: " . (naborEquipmentLabel($s['equipment'] ?? '[]') ?: 'nie podano') . (($s['equipment_notes'] ?? '') !== '' ? ' — ' . $s['equipment_notes'] : '')
               . ($s['participant_school_class'] !== '' ? "\nSzkoła/klasa: " . $s['participant_school_class'] : '');
        $cid = db_insert('k30_clients', [
            'name'          => $s['participant_name'],
            'email'         => $s['guardian_email'],
            'phone'         => '+' . $s['guardian_phone'],
            'pesel'         => $pesel,
            'date_of_birth' => $dob,
            'address'       => trim($s['guardian_street'] . ', ' . trim($s['guardian_postal_code'] . ' ' . $s['guardian_city']), ', '),
            'problem'       => $s['participant_medical_notes'],
            'notes'         => $notes,
            'status'        => 'enrolled',
            'created_by'    => $uid ?: null,
        ]);
    }
    $e = db_one("SELECT id, status FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$s['course_id'], $cid]);
    if ($e) {
        if ($e['status'] !== 'active') db_exec("UPDATE k30_ti_enrollments SET status='active', start_date=? WHERE id=?", [date('Y-m-d'), $e['id']]);
    } else {
        db_insert('k30_ti_enrollments', ['course_id' => (int)$s['course_id'], 'client_id' => $cid, 'start_date' => date('Y-m-d'), 'status' => 'active',
                                         'notes' => 'Nabór ' . $s['registration_number']]);
    }
    db_exec("UPDATE ti_nabor_submissions SET client_id=?, updated_at=datetime('now') WHERE id=?", [$cid, $sid]);
    try { ti_course_log((int)$s['course_id'], 'create', 'Zapis z naboru ' . $s['registration_number'] . ': ' . $s['participant_name'], $uid, $byName); } catch (\Throwable $e) {}
    return $cid;
}

/** Wycofanie przyjęcia: zapis do grupy → inactive (uczestnik zostaje w ewidencji). */
function naborUnenroll(array $s): void {
    if (!$s['course_id'] || !$s['client_id']) return;
    db_exec("UPDATE k30_ti_enrollments SET status='inactive', end_date=? WHERE course_id=? AND client_id=? AND status='active'",
            [date('Y-m-d'), $s['course_id'], $s['client_id']]);
}

function naborDateIso(string $v): ?string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v)) return $v;
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $v, $m)) return "$m[3]-$m[2]-$m[1]";
    return null;
}

/** Data urodzenia z PESEL (miesiąc zakodowany ze stuleciem). */
function naborPeselBirthDate(string $p): ?string {
    $y = (int)substr($p, 0, 2); $m = (int)substr($p, 2, 2); $d = (int)substr($p, 4, 2);
    $c = intdiv($m, 20); $m %= 20;
    $y += [1900, 2000, 2100, 2200, 1800][$c] ?? 1900;
    return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
}

function naborClientIp(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }

/** Kolejny numer REK/RRRR/MM/NNNN — numeracja roczna. */
function naborNextRegistrationNumber(): string {
    $y = date('Y');
    $last = db_one("SELECT registration_number FROM ti_nabor_submissions WHERE registration_number LIKE ? ORDER BY id DESC LIMIT 1", ["REK/$y/%"]);
    $n = $last && preg_match('#/(\d+)$#', $last['registration_number'], $m) ? (int)$m[1] + 1 : 1;
    return sprintf('REK/%s/%s/%04d', $y, date('m'), $n);
}

/** PESEL z sumą kontrolną albo data RRRR-MM-DD / DD.MM.RRRR. */
function naborValidPeselOrDate(string $v): bool {
    $v = trim($v);
    if (preg_match('/^\d{11}$/', $v)) {
        $w = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $sum = 0;
        for ($i = 0; $i < 10; $i++) $sum += $w[$i] * (int)$v[$i];
        return (10 - $sum % 10) % 10 === (int)$v[10];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $v, $m)) return checkdate((int)$m[2], (int)$m[1], (int)$m[3]);
    return false;
}

/**
 * Walidacja danych z formularza. Zwraca [dane_znormalizowane, błędy].
 * $verifiedPhone — numer potwierdzony kodem SMS w tej sesji (phoneVerifiedInSession(NABOR_PHONE_PURPOSE)).
 */
function naborValidate(array $p, ?string $verifiedPhone): array {
    $e = [];
    $t = fn($k, $max = 500) => mb_substr(trim((string)($p[$k] ?? '')), 0, $max);
    $custom = !empty($p['is_custom_schedule']);
    $d = [
        'course_id'                       => $custom ? null : ((int)($p['course_id'] ?? 0) ?: null),
        'is_custom_schedule'              => $custom ? 1 : 0,
        'preferred_days'                  => $custom ? array_values(array_intersect(array_keys(NABOR_DAYS), (array)($p['preferred_days'] ?? []))) : [],
        'preferred_time_slots'            => $custom ? array_values(array_intersect(array_keys(NABOR_SLOTS), (array)($p['preferred_time_slots'] ?? []))) : [],
        'notes_availability'              => $custom ? $t('notes_availability', 2000) : '',
        'participant_name'                => $t('participant_name', 200),
        'participant_birth_date_or_pesel' => $t('participant_birth_date_or_pesel', 20),
        'participant_school_class'        => $t('participant_school_class', 50),
        'participant_medical_notes'       => $t('participant_medical_notes', 3000),
        'guardian_name'                   => $t('guardian_name', 200),
        'guardian_phone'                  => $t('guardian_phone', 30),
        'guardian_email'                  => mb_strtolower($t('guardian_email', 200)),
        'guardian_street'                 => $t('guardian_street', 200),
        'guardian_postal_code'            => $t('guardian_postal_code', 10),
        'guardian_city'                   => $t('guardian_city', 100),
        'equipment'                       => array_values(array_intersect(array_keys(naborEquipmentOptions()), (array)($p['equipment'] ?? []))),
        'equipment_notes'                 => $t('equipment_notes', 1000),
        'payment_method'                  => $custom ? '' : (array_key_exists($p['payment_method'] ?? '', NABOR_PAYMENT) ? $p['payment_method'] : ''),
        'terms_accepted'                  => !empty($p['terms_accepted']),
        'gdpr_accepted'                   => !empty($p['gdpr_accepted']),
        'image_consent'                   => !empty($p['image_consent']),
        'marketing_consent'               => !empty($p['marketing_consent']),
    ];
    // Konfiguracja formularza (edytor kierownika): pola ukryte są puste, wymagane — sprawdzane; zgody ukryte = brak zgody
    $form = naborForm(); $fl = $form['fields'];
    $vis = fn($k) => !empty($fl[$k]['visible']) && naborCondMet($fl[$k]['cond'] ?? null, $p);   // ukryte albo niespełniony warunek
    $req = fn($k) => $vis($k) && !empty($fl[$k]['required']);
    foreach (['participant_school_class', 'participant_medical_notes', 'equipment_notes'] as $k) if (!$vis($k)) $d[$k] = '';
    if (!$vis('equipment')) $d['equipment'] = [];
    if (!$vis('guardian_address')) { $d['guardian_street'] = $d['guardian_postal_code'] = $d['guardian_city'] = ''; }
    if (!$vis('payment_method')) $d['payment_method'] = '';
    if (empty($form['consents']['image']['visible'])) $d['image_consent'] = false;
    if (empty($form['consents']['marketing']['visible'])) $d['marketing_consent'] = false;
    foreach (['participant_school_class', 'participant_medical_notes', 'equipment_notes'] as $k)
        if ($req($k) && $d[$k] === '') $e[] = 'Uzupełnij: „' . $fl[$k]['label'] . '”.';
    if ($req('equipment') && !$d['equipment']) $e[] = 'Zaznacz sprzęt do zajęć online.';
    [$d['extra'], $ee] = naborFormValidateExtra($p);
    array_push($e, ...$ee);

    if ($custom) {
        if (!$d['preferred_days'] && !$d['preferred_time_slots'] && $d['notes_availability'] === '')
            $e[] = 'Przy terminie do ustalenia zaznacz preferowane dni lub pory albo opisz dyspozycyjność.';
    } else {
        $c = $d['course_id'] ? naborCourseGet($d['course_id']) : null;
        if (!$c || !$c['is_open']) $e[] = 'Wybierz zajęcia z listy albo opcję „Termin do ustalenia”.';
        if ($req('payment_method') && $d['payment_method'] === '') $e[] = 'Wybierz formę płatności.';
    }
    if (mb_strlen($d['participant_name']) < 3)                    $e[] = 'Podaj imię i nazwisko uczestnika.';
    if (!$vis('participant_birth_date_or_pesel')) $d['participant_birth_date_or_pesel'] = '';
    elseif (($req('participant_birth_date_or_pesel') || $d['participant_birth_date_or_pesel'] !== '') && !naborValidPeselOrDate($d['participant_birth_date_or_pesel']))
        $e[] = 'Podaj poprawny PESEL albo datę urodzenia uczestnika (RRRR-MM-DD).';
    if (mb_strlen($d['guardian_name']) < 3)                       $e[] = 'Podaj imię i nazwisko opiekuna.';
    if (!filter_var($d['guardian_email'], FILTER_VALIDATE_EMAIL)) $e[] = 'Podaj poprawny adres e-mail opiekuna.';
    $phone = preg_replace('/\D/', '', $d['guardian_phone']);
    if (strlen($phone) === 9) $phone = '48' . $phone;
    if (!preg_match('/^48\d{9}$/', $phone))                       $e[] = 'Podaj polski numer telefonu opiekuna (9 cyfr).';
    elseif ($verifiedPhone !== $phone)                            $e[] = 'Potwierdź numer telefonu opiekuna kodem SMS.';
    $d['guardian_phone'] = $phone;
    if ($req('guardian_address') && ($d['guardian_street'] === '' || $d['guardian_city'] === '')) $e[] = 'Podaj adres zamieszkania (ulica, miejscowość).';
    if ($vis('guardian_address') && ($req('guardian_address') || $d['guardian_postal_code'] !== '') && !preg_match('/^\d{2}-\d{3}$/', $d['guardian_postal_code']))
        $e[] = 'Podaj kod pocztowy w formacie 00-000.';
    if (!$d['terms_accepted']) $e[] = 'Akceptacja regulaminu jest wymagana.';
    if (!$d['gdpr_accepted'])  $e[] = 'Zgoda na przetwarzanie danych osobowych jest wymagana.';
    return [$d, $e];
}

/**
 * Zapis zgłoszenia (status unconfirmed) + zgody + historia; wysyła e-mail z linkiem potwierdzającym.
 * Zwraca ['id','registration_number'].
 */
function naborSubmissionCreate(array $d): array {
    naborMigrate();
    $token = bin2hex(random_bytes(24));
    $ip    = naborClientIp();
    $pdo   = db();
    $pdo->beginTransaction();
    try {
        $nr = naborNextRegistrationNumber();
        $id = db_insert('ti_nabor_submissions', [
            'registration_number'             => $nr,
            'course_id'                       => $d['course_id'],
            'status'                          => 'unconfirmed',
            'is_custom_schedule'              => $d['is_custom_schedule'],
            'preferred_days'                  => json_encode($d['preferred_days']),
            'preferred_time_slots'            => json_encode($d['preferred_time_slots']),
            'notes_availability'              => $d['notes_availability'],
            'participant_name'                => $d['participant_name'],
            'participant_birth_date_or_pesel' => $d['participant_birth_date_or_pesel'],
            'participant_school_class'        => $d['participant_school_class'],
            'participant_medical_notes'       => $d['participant_medical_notes'],
            'guardian_name'                   => $d['guardian_name'],
            'guardian_phone'                  => $d['guardian_phone'],
            'guardian_email'                  => $d['guardian_email'],
            'guardian_street'                 => $d['guardian_street'],
            'guardian_postal_code'            => $d['guardian_postal_code'],
            'guardian_city'                   => $d['guardian_city'],
            'pickup_type'                     => '',
            'equipment'                       => json_encode($d['equipment']),
            'equipment_notes'                 => $d['equipment_notes'],
            'extra'                           => json_encode($d['extra'] ?: new stdClass(), JSON_UNESCAPED_UNICODE),
            'payment_method'                  => $d['payment_method'],
            'phone_verified_at'               => gmdate('Y-m-d H:i:s'),
            'email_token_hash'                => hash('sha256', $token),
            'ip_address'                      => $ip,
        ]);
        db_insert('ti_nabor_consents', [
            'submission_id'     => $id,
            'ip_address'        => $ip,
            'user_agent'        => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
            'terms_accepted'    => $d['terms_accepted'] ? 1 : 0,
            'gdpr_accepted'     => $d['gdpr_accepted'] ? 1 : 0,
            'image_consent'     => $d['image_consent'] ? 1 : 0,
            'marketing_consent' => $d['marketing_consent'] ? 1 : 0,
        ]);
        naborHistoryAdd($id, null, '', 'unconfirmed', 'Zgłoszenie z formularza; numer telefonu potwierdzony kodem SMS. IP: ' . $ip);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    require_once __DIR__ . '/naborNotify.php';
    naborNotifyConfirmEmail(naborSubmissionGet($id), $token);
    return ['id' => $id, 'registration_number' => $nr];
}

function naborHistoryAdd(int $sid, ?int $userId, string $from, string $to, string $comment = '', string $userName = ''): void {
    db_insert('ti_nabor_history', [
        'submission_id' => $sid, 'user_id' => $userId ?: null, 'user_name' => $userName,
        'from_status' => $from, 'to_status' => $to, 'comment' => mb_substr($comment, 0, 2000),
    ]);
}

function naborHistory(int $sid): array {
    return db_all("SELECT h.*, COALESCE(NULLIF(h.user_name,''), u.name) AS user_name FROM ti_nabor_history h
                   LEFT JOIN users u ON u.id=h.user_id WHERE h.submission_id=? ORDER BY h.id", [$sid]);
}

function naborConsents(int $sid): ?array {
    return db_one("SELECT * FROM ti_nabor_consents WHERE submission_id=? ORDER BY id DESC LIMIT 1", [$sid]);
}

/**
 * Potwierdzenie adresu e-mail z linku. Zwraca zgłoszenie po potwierdzeniu albo null (zły / wygasły token).
 * Idempotentne: ponowne kliknięcie zwraca to samo zgłoszenie bez ponownej rejestracji w EZD.
 */
function naborConfirmEmail(string $token): ?array {
    naborMigrate();
    naborExpireUnconfirmed();
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $s = db_one("SELECT id, status FROM ti_nabor_submissions WHERE email_token_hash=?", [hash('sha256', $token)]);
    if (!$s) return null;
    if ($s['status'] !== 'unconfirmed') return naborSubmissionGet((int)$s['id']);

    $sub = naborSubmissionGet((int)$s['id']);
    if ($sub['is_custom_schedule']) {
        $to = 'custom_schedule';
    } else {
        $c  = naborCourseGet((int)$sub['course_id']);
        $to = ($c && $c['free'] === 0) ? 'waitlist' : 'new';
    }
    // Warunkowy UPDATE — dwa równoległe kliknięcia nie zarejestrują sprawy dwa razy
    $st = db()->prepare("UPDATE ti_nabor_submissions SET status=?, email_confirmed_at=datetime('now'), updated_at=datetime('now')
                          WHERE id=? AND status='unconfirmed'");
    $st->execute([$to, $sub['id']]);
    if ($st->rowCount() !== 1) return naborSubmissionGet((int)$sub['id']);
    naborHistoryAdd((int)$sub['id'], null, 'unconfirmed', $to,
        'Adres e-mail potwierdzony przez opiekuna' . ($to === 'waitlist' ? ' — brak wolnych miejsc, lista rezerwowa.' : '.'));

    naborAfterConfirm((int)$sub['id']);
    return naborSubmissionGet((int)$sub['id']);
}

/** Po potwierdzeniu: EZD (PDF jako dokument główny), CRM, e-mail z PDF. Każdy krok osobno, bez przerywania. */
function naborAfterConfirm(int $sid): void {
    require_once __DIR__ . '/NaborEzdService.php';
    require_once __DIR__ . '/naborCrm.php';
    require_once __DIR__ . '/naborNotify.php';
    try { (new NaborEzdService())->registerIncomingCase($sid); }
    catch (\Throwable $e) { error_log('[ti_nabor] EZD #' . $sid . ': ' . $e->getMessage()); naborHistoryNote($sid, 'Nie udało się zarejestrować sprawy w EZD: ' . $e->getMessage()); }
    naborCrmSync($sid, 'Zgłoszenie potwierdzone przez opiekuna');
    naborNotifyReceived(naborSubmissionGet($sid));
}

/** Wpis informacyjny w dzienniku (bez zmiany statusu). */
function naborHistoryNote(int $sid, string $comment, ?int $userId = null, string $userName = ''): void {
    $s = db_one("SELECT status FROM ti_nabor_submissions WHERE id=?", [$sid]);
    if ($s) naborHistoryAdd($sid, $userId, $s['status'], $s['status'], $comment, $userName);
}

/**
 * Zmiana statusu przez operatora: historia, zapis do grupy TI (przyjęcie) albo jego wycofanie, CRM, powiadomienie opiekuna.
 * Zwraca true, gdy status faktycznie się zmienił.
 */
function naborChangeStatus(int $sid, string $to, int $userId, string $comment = '', bool $notify = true, string $userName = ''): bool {
    if (!in_array($to, NABOR_MANUAL_STATUSES, true)) throw new InvalidArgumentException('Nieznany status.');
    $s = naborSubmissionGet($sid);
    if (!$s) throw new InvalidArgumentException('Zgłoszenie nie istnieje.');
    if ($s['status'] === $to) return false;
    if ($s['status'] === 'unconfirmed') throw new InvalidArgumentException('Zgłoszenie ' . $s['registration_number'] . ' nie zostało jeszcze potwierdzone przez opiekuna.');
    if ($to === 'accepted' && !$s['course_id']) throw new InvalidArgumentException('Przed przyjęciem przypisz zgłoszenie ' . $s['registration_number'] . ' do grupy.');
    if ($to === 'accepted') {
        $c = naborCourseGet((int)$s['course_id']);
        if (!$c) throw new InvalidArgumentException('Grupa zgłoszenia ' . $s['registration_number'] . ' jest nieaktywna.');
        if ($c['free'] === 0) throw new InvalidArgumentException('Grupa „' . $c['title'] . '” nie ma wolnych miejsc (' . $s['registration_number'] . ').');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec("UPDATE ti_nabor_submissions SET status=?, updated_at=datetime('now') WHERE id=?", [$to, $sid]);
        naborHistoryAdd($sid, $userId, $s['status'], $to, $comment, $userName);
        if (!empty($s['is_test'])) {
            if ($to === 'accepted') naborHistoryNote($sid, 'Zgłoszenie testowe — bez zakładania uczestnika i zapisu do grupy.', $userId, $userName);
        } elseif ($to === 'accepted') {
            $cid = naborEnroll($sid, $userId, $userName);
            naborHistoryNote($sid, 'Uczestnik TI #' . $cid . ' zapisany do grupy.', $userId, $userName);
        } elseif ($s['status'] === 'accepted') {
            naborUnenroll($s);
            naborHistoryNote($sid, 'Zapis do grupy wycofany (uczestnik zostaje w ewidencji).', $userId, $userName);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (!empty($s['is_test'])) return true;          // test: bez kont, CRM i powiadomień
    if ($to === 'accepted') {
        // Konto panelu uczestnika + dostęp rodzica; hasła jednorazowo dla kierownika (karta zgłoszenia), SMS/e-mail do opiekuna
        require_once __DIR__ . '/naborAccounts.php';
        try {
            $acc = naborCreateAccounts($sid, $userId);
            if ($acc['child'] || $acc['parent']) {
                if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['nabor_creds'][$sid] = $acc;
                $sent = $notify ? naborSendAccounts(naborSubmissionGet($sid), $acc) : 'bez wysyłki — przekaż dane ręcznie';
                naborHistoryNote($sid, 'Założono dostęp: ' . implode(', ', array_filter([
                    $acc['child'] ? 'panel uczestnika (' . $acc['child']['login'] . ')' : '',
                    $acc['parent'] ? 'panel rodzica (' . $acc['parent']['login'] . ')' : '',
                ])) . ' — ' . $sent . '.', $userId, $userName);
            }
            foreach ($acc['notes'] as $n) naborHistoryNote($sid, $n, $userId, $userName);
        } catch (\Throwable $e) {
            naborHistoryNote($sid, 'Nie udało się założyć konta panelu: ' . $e->getMessage(), $userId, $userName);
        }
    }
    require_once __DIR__ . '/naborCrm.php';
    naborCrmSync($sid, 'Status: ' . naborStatusLabel($s['status']) . ' → ' . naborStatusLabel($to) . ($comment !== '' ? ' — ' . $comment : ''));
    if ($notify) {
        require_once __DIR__ . '/naborNotify.php';
        naborNotifyStatus(naborSubmissionGet($sid), $comment);
    }
    return true;
}

/** Przypisanie zgłoszenia (np. z terminu do ustalenia) do grupy TI. Przyjętego najpierw trzeba wycofać ze statusu „Przyjęte”. */
function naborAssignCourse(int $sid, ?int $courseId, int $userId, string $userName = ''): void {
    $s = naborSubmissionGet($sid);
    if (!$s) return;
    if ($s['status'] === 'accepted') throw new InvalidArgumentException('Zgłoszenie jest przyjęte — zmień najpierw status, potem grupę.');
    $c = $courseId ? naborCourseGet($courseId) : null;
    db_exec("UPDATE ti_nabor_submissions SET course_id=?, updated_at=datetime('now') WHERE id=?", [$c ? (int)$c['id'] : null, $sid]);
    naborHistoryNote($sid, 'Przypisano do grupy: ' . ($c['title'] ?? '— brak —'), $userId, $userName);
}

/**
 * Zgłoszenie testowe z panelu (przycisk „Utwórz zgłoszenie testowe”): status „new” od razu, dane fikcyjne,
 * znacznik is_test. Bez SMS/e-maili, EZD, CRM; przyjęcie nie zakłada uczestnika ani zapisu do grupy. Zwraca id.
 */
function naborCreateTest(int $userId, string $userName = ''): int {
    naborMigrate();
    $open = array_values(array_filter(naborCourses(), fn($c) => $c['is_open']));
    $c    = $open ? $open[array_rand($open)] : null;
    $n    = random_int(100, 999);
    $kids = ['Ola', 'Kuba', 'Zosia', 'Antek', 'Hania', 'Staś', 'Lena', 'Franek'];
    $pdo  = db();
    $pdo->beginTransaction();
    try {
        $nr = naborNextRegistrationNumber();
        $id = db_insert('ti_nabor_submissions', [
            'registration_number'             => $nr,
            'course_id'                       => $c ? (int)$c['id'] : null,
            'status'                          => $c ? 'new' : 'custom_schedule',
            'is_test'                         => 1,
            'is_custom_schedule'              => $c ? 0 : 1,
            'preferred_days'                  => json_encode($c ? [] : ['wt', 'cz']),
            'preferred_time_slots'            => json_encode($c ? [] : ['popoludnie']),
            'notes_availability'              => $c ? '' : 'Zgłoszenie testowe — dowolne popołudnie.',
            'participant_name'                => '[TEST] ' . $kids[array_rand($kids)] . ' Testowy ' . $n,
            'participant_birth_date_or_pesel' => date('Y-m-d', strtotime('-' . random_int(8, 15) . ' years')),
            'participant_school_class'        => 'SP ' . random_int(1, 50) . ', kl. ' . random_int(1, 8) . 'a',
            'participant_medical_notes'       => 'Dane testowe.',
            'guardian_name'                   => '[TEST] Opiekun Testowy ' . $n,
            'guardian_phone'                  => '48000000' . $n,
            'guardian_email'                  => 'test' . $n . '@example.invalid',
            'guardian_street'                 => 'Testowa ' . $n,
            'guardian_postal_code'            => '00-000',
            'guardian_city'                   => 'Testowo',
            'pickup_type'                     => '',
            'equipment'                       => json_encode(['komputer', 'mikrofon', 'internet']),
            'payment_method'                  => $c ? 'transfer' : '',
            'phone_verified_at'               => gmdate('Y-m-d H:i:s'),
            'email_confirmed_at'              => gmdate('Y-m-d H:i:s'),
            'ip_address'                      => naborClientIp(),
        ]);
        db_insert('ti_nabor_consents', ['submission_id' => $id, 'ip_address' => naborClientIp(), 'user_agent' => 'zgłoszenie testowe z panelu',
                                        'terms_accepted' => 1, 'gdpr_accepted' => 1]);
        naborHistoryAdd($id, $userId, '', $c ? 'new' : 'custom_schedule', 'Zgłoszenie testowe utworzone w panelu (bez SMS/e-maili, EZD i CRM).', $userName);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

/** Masowa zmiana statusu. Zwraca ['changed'=>n, 'errors'=>[...]]. */
function naborBulkStatus(array $ids, string $to, int $userId, string $comment, bool $notify, string $userName = ''): array {
    $out = ['changed' => 0, 'errors' => []];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        try { if (naborChangeStatus($id, $to, $userId, $comment, $notify, $userName)) $out['changed']++; }
        catch (InvalidArgumentException $e) { $out['errors'][] = $e->getMessage(); }
    }
    return $out;
}

/** Niepotwierdzone po NABOR_CONFIRM_HOURS → cancelled (bez powiadomień). */
function naborExpireUnconfirmed(): void {
    naborMigrate();
    $old = db_all("SELECT id FROM ti_nabor_submissions WHERE status='unconfirmed' AND created_at < ?",
                  [gmdate('Y-m-d H:i:s', time() - NABOR_CONFIRM_HOURS * 3600)]);
    foreach ($old as $r) {
        db_exec("UPDATE ti_nabor_submissions SET status='cancelled', email_token_hash=NULL, updated_at=datetime('now') WHERE id=? AND status='unconfirmed'", [$r['id']]);
        naborHistoryAdd((int)$r['id'], null, 'unconfirmed', 'cancelled', 'Brak potwierdzenia adresu e-mail w ciągu ' . NABOR_CONFIRM_HOURS . ' h.');
    }
}

/** Lista do panelu z filtrami: status, course (id | 'custom'), q. */
function naborSubmissionsList(array $f = []): array {
    naborMigrate();
    $w = []; $p = [];
    if (($f['status'] ?? '') !== '' && isset(NABOR_STATUSES[$f['status']])) { $w[] = 's.status=?'; $p[] = $f['status']; }
    elseif (empty($f['all'])) $w[] = "s.status NOT IN ('archived','cancelled','unconfirmed')";
    if (($f['course'] ?? '') === 'custom') $w[] = 's.is_custom_schedule=1';
    elseif ((int)($f['course'] ?? 0)) { $w[] = 's.course_id=?'; $p[] = (int)$f['course']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') {
        $w[] = '(s.registration_number LIKE ? OR s.participant_name LIKE ? OR s.guardian_name LIKE ? OR s.guardian_email LIKE ? OR s.guardian_phone LIKE ?)';
        array_push($p, ...array_fill(0, 5, '%' . $q . '%'));
    }
    $ezd = (bool)db_one("SELECT 1 FROM sqlite_master WHERE type='table' AND name='ezd_sprawy'");
    return db_all(
        "SELECT s.id, s.registration_number, s.status, s.participant_name, s.participant_school_class, s.guardian_name,
                s.guardian_phone, s.guardian_email, s.is_custom_schedule, s.ezd_case_id, s.created_at, s.course_id, s.is_test,
                COALESCE(NULLIF(n.public_name,''), NULLIF(c.display_name,''), c.name) AS course_name, " . ($ezd ? 'e.znak_sprawy' : 'NULL AS znak_sprawy') . "
           FROM ti_nabor_submissions s
      LEFT JOIN k30_ti_courses c ON c.id=s.course_id
      LEFT JOIN ti_nabor_courses n ON n.course_id=s.course_id
      " . ($ezd ? 'LEFT JOIN ezd_sprawy e ON e.id=s.ezd_case_id' : '') . "
          " . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . "
       ORDER BY s.id DESC LIMIT 500", $p);
}

/** Znacznik czasu z bazy (UTC, jak datetime('now')) → czas lokalny „RRRR-MM-DD GG:MM[:SS]” ('' gdy brak). */
function naborTime(?string $utc, bool $seconds = false): string {
    if (!$utc) return '';
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $d->format($seconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i');
    } catch (\Throwable $e) {
        return (string)$utc;
    }
}

/** Pola edytowalne w panelu: klucz => etykieta (do walidacji i wpisu w historii). */
const NABOR_EDITABLE = [
    'participant_name' => 'Imię i nazwisko uczestnika', 'participant_birth_date_or_pesel' => 'PESEL / data urodzenia',
    'participant_school_class' => 'Szkoła / klasa', 'participant_medical_notes' => 'Uwagi medyczne',
    'guardian_name' => 'Opiekun', 'guardian_phone' => 'Telefon opiekuna', 'guardian_email' => 'E-mail opiekuna',
    'guardian_street' => 'Ulica', 'guardian_postal_code' => 'Kod pocztowy', 'guardian_city' => 'Miejscowość',
    'equipment' => 'Sprzęt', 'equipment_notes' => 'Uwagi o sprzęcie',
    'notes_availability' => 'Dyspozycyjność', 'payment_method' => 'Płatność',
];

/**
 * Edycja danych zgłoszenia przez kierownika. Zmiana telefonu / e-maila kasuje znacznik weryfikacji (dane wpisane
 * w panelu, nie potwierdzone przez opiekuna). Uczestnika TI (k30_clients) już założonego NIE zmienia — wpis w historii
 * przypomina o poprawie w Ewidencji Uczestników. Zwraca listę zmienionych pól (etykiety).
 */
function naborUpdateData(int $sid, array $p, int $userId, string $userName = ''): array {
    $s = naborSubmissionGet($sid);
    if (!$s) throw new InvalidArgumentException('Zgłoszenie nie istnieje.');
    $t = fn($k, $max = 500) => mb_substr(trim((string)($p[$k] ?? '')), 0, $max);
    $d = [
        'participant_name' => $t('participant_name', 200), 'participant_birth_date_or_pesel' => $t('participant_birth_date_or_pesel', 20),
        'participant_school_class' => $t('participant_school_class', 50), 'participant_medical_notes' => $t('participant_medical_notes', 3000),
        'guardian_name' => $t('guardian_name', 200), 'guardian_phone' => phoneDigits48($t('guardian_phone', 30)),
        'guardian_email' => mb_strtolower($t('guardian_email', 200)), 'guardian_street' => $t('guardian_street', 200),
        'guardian_postal_code' => $t('guardian_postal_code', 10), 'guardian_city' => $t('guardian_city', 100),
        'equipment' => json_encode(array_values(array_intersect(array_keys(naborEquipmentOptions() + NABOR_EQUIPMENT), (array)($p['equipment'] ?? [])))),
        'equipment_notes' => $t('equipment_notes', 1000),
        'notes_availability' => $t('notes_availability', 2000),
        'payment_method' => array_key_exists($p['payment_method'] ?? '', NABOR_PAYMENT) ? $p['payment_method'] : '',
    ];
    $e = [];
    if (mb_strlen($d['participant_name']) < 3) $e[] = 'imię i nazwisko uczestnika';
    if ($d['participant_birth_date_or_pesel'] !== '' && !naborValidPeselOrDate($d['participant_birth_date_or_pesel'])) $e[] = 'PESEL albo data urodzenia (RRRR-MM-DD)';
    if (mb_strlen($d['guardian_name']) < 3) $e[] = 'imię i nazwisko opiekuna';
    if (!preg_match('/^48\d{9}$/', $d['guardian_phone'])) $e[] = 'telefon opiekuna (9 cyfr)';
    if (!filter_var($d['guardian_email'], FILTER_VALIDATE_EMAIL)) $e[] = 'e-mail opiekuna';
    if ($d['guardian_postal_code'] !== '' && !preg_match('/^\d{2}-\d{3}$/', $d['guardian_postal_code'])) $e[] = 'kod pocztowy 00-000';
    if ($e) throw new InvalidArgumentException('Popraw: ' . implode(', ', $e) . '.');

    $changed = [];
    foreach ($d as $k => $v) if ((string)$s[$k] !== (string)$v) $changed[] = $k;
    if (!$changed) return [];
    $set = $d;
    if (in_array('guardian_phone', $changed, true)) $set['phone_verified_at'] = null;
    if (in_array('guardian_email', $changed, true)) $set['email_confirmed_at'] = null;
    $cols = implode(', ', array_map(fn($k) => "$k=?", array_keys($set)));
    db_exec("UPDATE ti_nabor_submissions SET $cols, updated_at=datetime('now') WHERE id=?", [...array_values($set), $sid]);

    $labels = array_map(fn($k) => NABOR_EDITABLE[$k], $changed);
    $note = 'Edycja danych w panelu: ' . implode(', ', $labels) . '.';
    if (array_intersect($changed, ['guardian_phone', 'guardian_email'])) $note .= ' Nowy telefon / e-mail nie jest zweryfikowany przez opiekuna.';
    if ($s['client_id']) $note .= ' Uczestnik TI #' . $s['client_id'] . ' już istnieje — popraw jego dane w Ewidencji Uczestników.';
    naborHistoryNote($sid, $note, $userId, $userName);
    return $labels;
}

/** Numer w formacie 48XXXXXXXXX (9 cyfr → z prefiksem), inne pozostawia jako same cyfry. */
function phoneDigits48(string $v): string {
    $p = preg_replace('/\D/', '', $v);
    return strlen($p) === 9 ? '48' . $p : $p;
}

/** Publiczny adres naboru poza kontekstem routera (e-maile, panel): naborUrl('/potwierdz') → …/router.php/nabor/potwierdz. */
function naborUrl(string $path = '/', array $q = []): string {
    return rtrim(APP_URL, '/') . NABOR_ENTRY . '/nabor/' . ltrim($path, '/') . ($q ? '?' . http_build_query($q) : '');
}

function naborEzdUrl(?int $caseId): ?string {
    return $caseId ? rtrim(APP_URL, '/') . '/ezd/sprawy/view.php?id=' . $caseId : null;
}

/** Dostawca SMS naboru (kody, statusy, dane logowania): ustawienie ti_nabor_sms_provider, domyślnie Sendly. */
function naborSmsProvider(): string {
    $p = trim(org_setting('ti_nabor_sms_provider'));
    return $p !== '' ? ($p === 'chain' ? '' : $p) : 'sendly';
}

/** SMS naboru: preferowany dostawca (Sendly), przy błędzie łańcuch systemowy. Zwraca klucz dostawcy. */
function naborSms(string $phone, string $message, string $kind): string {
    require_once dirname(__DIR__, 4) . '/modules/weryfikacja_sms/logic/phoneVerify.php';
    return smsSendPreferred(naborSmsProvider(), $phone, $message, $kind);
}

/** Publiczny formularz otwarty — przełącznik w panelu (ustawienie ti_nabor_enabled, domyślnie zamknięty). */
function naborPublicOpen(): bool {
    return org_setting('ti_nabor_enabled') === '1';
}

require_once __DIR__ . '/naborForm.php';   // konfiguracja formularza (edytor kierownika) — po stałych, z których korzysta
