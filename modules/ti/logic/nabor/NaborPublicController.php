<?php
/**
 * modules/ti/logic/nabor/NaborPublicController.php — część publiczna (bez logowania).
 *
 * form/submit: pięciokrokowy formularz; serwer waliduje ponownie (naborValidate), wymaga numeru potwierdzonego
 *   kodem SMS (modules/weryfikacja_sms), zapisuje zgłoszenie „unconfirmed” i wysyła link e-mail.
 * sms: AJAX — wysyłka / sprawdzenie kodu (JSON).
 * confirmForm/confirm: GET pokazuje przycisk (skanery linków w poczcie nie potwierdzają za człowieka),
 *   POST potwierdza → obieg, PDF, EZD, CRM.
 * Zabezpieczenia: CSRF (Router), honeypot, limit 5 zgłoszeń z IP na dobę, formularz zamknięty, gdy zapisy wyłączone.
 */
require_once __DIR__ . '/nabor.php';
require_once dirname(__DIR__, 4) . '/modules/weryfikacja_sms/logic/phoneVerify.php';
require_once dirname(__DIR__, 4) . '/includes/branding.php';
require_once dirname(__DIR__, 4) . '/modules/gdpr_clauses/logic/gdpr_clauses.php';

final class NaborPublicController
{
    private const VIEW = __DIR__ . '/../../view/nabor/public/';

    public function form(array $errors = [], array $old = []): string
    {
        // Podgląd kierownika (edytor formularza): token dnia pozwala zobaczyć formularz także przy zamkniętym naborze
        $preview = naborPreviewValid((string)($_GET['podglad'] ?? ''));
        if (!$preview) $this->requireOpen();
        auth_start();
        $done = isset($_GET['done']) ? ($_SESSION['ti_nabor_done'] ?? null) : null;
        $brand = branding_load();
        return Router::view(self::VIEW . 'zapisy.php', [
            'org'      => $brand['org_name'] ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'),
            'logo'     => $brand['logo_url'] ?? '',
            'clause'   => gdpr_clause_resolve(null, 'ti_nabor'),
            'terms'    => trim(org_setting('ti_nabor_terms_url')),
            'errors'   => $errors,
            'done'     => $done,
            'doneMail' => $_SESSION['ti_nabor_done_email'] ?? '',
            'init'     => $done ? [] : $this->initState($errors, $old, $preview),
            'form'     => naborForm(),
            'preview'  => $preview,
        ]);
    }

    public function submit(): ?string
    {
        $this->requireOpen();
        if (trim((string)($_POST['www'] ?? '')) !== '') {          // honeypot — udaj sukces bez zapisu
            $_SESSION['ti_nabor_done'] = 'REK/' . date('Y/m') . '/0000';
            return Router::redirect(route('nabor.form', ['done' => 1]));
        }
        $ip = naborClientIp();
        [$data, $errors] = naborValidate($_POST, phoneVerifiedInSession(NABOR_PHONE_PURPOSE));
        $n = (int)db_one("SELECT COUNT(*) n FROM ti_nabor_submissions WHERE ip_address=? AND created_at>?", [$ip, gmdate('Y-m-d H:i:s', time() - 86400)])['n'];
        if ($ip !== '' && $n >= 5) $errors = ['Przekroczono dzienny limit zgłoszeń z tego urządzenia. Spróbuj jutro lub napisz do nas.'];
        if (!$errors) {
            try {
                $res = naborSubmissionCreate($data);
                if ($clause = gdpr_clause_resolve(null, 'ti_nabor')) {
                    gdpr_clause_accept_from_post($clause['clause']['slug'], 'ti_nabor',
                        ['name' => $data['guardian_name'], 'email' => $data['guardian_email']], ['ti_nabor', $res['id']]);
                }
                phoneVerifyForget(NABOR_PHONE_PURPOSE);
                $_SESSION['ti_nabor_done'] = $res['registration_number'];
                $_SESSION['ti_nabor_done_email'] = $data['guardian_email'];
                return Router::redirect(route('nabor.form', ['done' => 1]));
            } catch (\Throwable $e) {
                error_log('[ti_nabor] zapis: ' . $e->getMessage());
                $errors[] = 'Nie udało się zapisać zgłoszenia. Spróbuj ponownie za chwilę.';
            }
        }
        unset($_GET['done']);
        return $this->form($errors, $_POST);
    }

    /** AJAX kodu SMS: action=send|verify. */
    public function sms(): array
    {
        header('Cache-Control: no-store');
        if (!naborPublicOpen()) return ['ok' => false, 'error' => 'Zapisy są zamknięte.'];
        $phone = (string)($_POST['phone'] ?? '');
        $err = match ((string)($_POST['action'] ?? '')) {
            'send'   => phoneVerifySend(NABOR_PHONE_PURPOSE, $phone, 'potwierdzajacy zgloszenie na zajecia', naborSmsProvider()),
            'verify' => phoneVerifyCheck(NABOR_PHONE_PURPOSE, $phone, (string)($_POST['code'] ?? '')),
            default  => 'Nieznana operacja.',
        };
        return ['ok' => $err === '', 'error' => $err];
    }

    public function confirmForm(): string
    {
        header('Referrer-Policy: no-referrer');
        naborExpireUnconfirmed();
        $token = (string)($_GET['t'] ?? '');
        $row = preg_match('/^[a-f0-9]{48}$/', $token)
            ? db_one("SELECT registration_number, status FROM ti_nabor_submissions WHERE email_token_hash=?", [hash('sha256', $token)]) : null;
        $state = !$row ? 'invalid' : ($row['status'] !== 'unconfirmed' ? 'done' : 'ask');
        return $this->confirmView($state, $row);
    }

    public function confirm(): string
    {
        header('Referrer-Policy: no-referrer');
        $sub = naborConfirmEmail((string)($_GET['t'] ?? ''));
        return $this->confirmView($sub ? 'done' : 'invalid', $sub);
    }

    private function confirmView(string $state, ?array $sub): string
    {
        return Router::view(self::VIEW . 'potwierdz.php', [
            'state' => $state, 'sub' => $sub,
            'org'   => org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'),
        ]);
    }

    private function requireOpen(): void
    {
        if (!naborPublicOpen()) { http_response_code(404); exit('Nabór do grup jest obecnie zamknięty.'); }
    }

    /** Warunki pól zależnych dla przeglądarki: klucz pola albo 'q:<id>' => warunek. */
    private function conds(): object
    {
        $c = [];
        foreach (naborForm()['fields'] as $k => $f) if (!empty($f['cond'])) $c[$k] = $f['cond'];
        foreach (naborForm()['custom'] as $q) if (!empty($q['cond'])) $c['q:' . $q['id']] = $q['cond'];
        return (object)$c;
    }

    /** Stan początkowy Alpine (po błędzie walidacji — wartości z POST, krok 5). */
    private function initState(array $errors, array $old, bool $preview = false): array
    {
        $s = fn($k) => (string)($old[$k] ?? '');
        $b = fn($k) => !empty($old[$k]);
        $a = fn($k) => array_values((array)($old[$k] ?? []));
        return [
            'step'     => $errors ? 5 : 1,
            'csrf'     => csrf_token(),
            'smsUrl'   => route('nabor.sms'),
            'courses'  => array_map(fn($c) => [
                'id' => (int)$c['id'], 'name' => $c['title'], 'category' => $c['category'], 'location' => 'zajęcia online',
                'first' => $c['first_label'], 'planned' => (int)$c['planned'],
                'age' => $c['age_group'], 'schedule' => $c['schedule'], 'price' => $c['price_info'],
                'max' => (int)$c['max_students'], 'free' => $c['free'],
            ], naborCourses(true)),
            'days' => NABOR_DAYS, 'slots' => NABOR_SLOTS, 'equipment' => naborEquipmentOptions(), 'payment' => NABOR_PAYMENT,
            'preview' => $preview,
            'steps'   => naborForm()['steps'],
            'req'     => array_map(fn($f) => $f['visible'] && $f['required'] ? $f['label'] : '', naborForm()['fields']),
            'vis'     => array_map(fn($f) => (bool)$f['visible'], naborForm()['fields']),
            'custom'  => array_map(fn($q) => ['id' => $q['id'], 'step' => (int)$q['step'], 'label' => $q['label'], 'required' => (bool)$q['required']], naborForm()['custom']),
            'conds'   => $this->conds(),
            'verified' => phoneVerifiedInSession(NABOR_PHONE_PURPOSE),
            'f' => [
                'course_id' => $s('course_id'), 'is_custom_schedule' => $b('is_custom_schedule'),
                'preferred_days' => $a('preferred_days'), 'preferred_time_slots' => $a('preferred_time_slots'),
                'notes_availability' => $s('notes_availability'),
                'participant_name' => $s('participant_name'), 'participant_birth_date_or_pesel' => $s('participant_birth_date_or_pesel'),
                'participant_school_class' => $s('participant_school_class'), 'participant_medical_notes' => $s('participant_medical_notes'),
                'guardian_name' => $s('guardian_name'), 'guardian_phone' => $s('guardian_phone'), 'guardian_email' => $s('guardian_email'),
                'guardian_street' => $s('guardian_street'), 'guardian_postal_code' => $s('guardian_postal_code'), 'guardian_city' => $s('guardian_city'),
                'equipment' => $a('equipment'), 'equipment_notes' => $s('equipment_notes'),
                'terms_accepted' => $b('terms_accepted'), 'gdpr_accepted' => $b('gdpr_accepted'),
                'image_consent' => $b('image_consent'), 'marketing_consent' => $b('marketing_consent'),
                'payment_method' => $s('payment_method'),
                'extra' => (object)array_map('strval', (array)($old['extra'] ?? [])),
            ],
        ];
    }
}
