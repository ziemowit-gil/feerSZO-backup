<?php
/**
 * modules/ti/logic/nabor/NaborCandidateController.php — konto kandydata (IRK): logowanie, hasło, lista zgłoszeń, karta zgłoszenia.
 *
 * Karta zgłoszenia: oś czasu statusów, poprawa danych do czasu rozpatrzenia, dokumenty (prośby kierownika i własne pliki),
 * wiadomości z kierownikiem, po przyjęciu — potwierdzenie miejsca (+ akceptacja warunków) i płatność (portal płatności).
 * Trasy w modules/ti/router.php (grupa /nabor, middleware cand). Sesja: klucz ti_nabor_acc (naborAccount.php).
 */
require_once __DIR__ . '/naborAccount.php';

final class NaborCandidateController
{
    private const VIEW = __DIR__ . '/../../view/nabor/public/';

    private function org(): string { return org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'); }

    private function view(string $file, array $data): string
    {
        return Router::view(self::VIEW . $file, $data + ['org' => $this->org(), 'acc' => naborAccountCurrent(), 'form' => naborForm()]);
    }

    public function loginForm(): string
    {
        if (naborAccountCurrent()) { Router::redirect(route('nabor.account')); return ''; }
        return $this->view('logowanie.php', ['error' => '', 'email' => '', 'next' => (string)($_GET['next'] ?? '')]);
    }

    public function login(): ?string
    {
        $email = trim((string)($_POST['email'] ?? ''));
        $err = naborAccountLogin($email, (string)($_POST['password'] ?? ''));
        if ($err !== '') return $this->view('logowanie.php', ['error' => $err, 'email' => $email, 'next' => (string)($_POST['next'] ?? '')]);
        $next = (string)($_POST['next'] ?? '');
        return Router::redirect(preg_match('#^/(konto(/zgloszenia/\d+)?|zapisy)$#', $next) ? naborUrl($next) : route('nabor.account'));
    }

    public function logout(): ?string
    {
        naborAccountLogout();
        return Router::redirect(route('nabor.login'));
    }

    public function registerForm(array $errors = [], array $old = []): string
    {
        if (naborAccountCurrent()) { Router::redirect(route('nabor.account')); return ''; }
        require_once dirname(__DIR__, 4) . '/modules/weryfikacja_sms/logic/phoneVerify.php';
        return $this->view('rejestracja.php', ['errors' => $errors, 'old' => $old, 'done' => false,
            'verified' => phoneVerifiedInSession(NABOR_REG_PURPOSE), 'smsUrl' => route('nabor.register.sms'), 'csrf' => Router::csrfToken()]);
    }

    public function register(): string
    {
        if (trim((string)($_POST['www'] ?? '')) !== '') return $this->view('rejestracja.php', ['errors' => [], 'old' => [], 'done' => true, 'verified' => null, 'smsUrl' => '', 'csrf' => '']);
        [$errors, $id, $via] = naborAccountRegister($_POST);
        if ($errors) return $this->registerForm($errors, $_POST);
        if ($via === 'email') {
            flash_set('success', 'Konto aktywne — adres e-mail potwierdzony kodem. Numer telefonu potwierdzisz później.');
            Router::redirect(route('nabor.account'));
            return '';
        }
        return $this->view('rejestracja.php', ['errors' => [], 'old' => ['email' => (string)$_POST['email']], 'done' => true, 'verified' => null, 'smsUrl' => '', 'csrf' => '']);
    }

    /** AJAX kodu SMS przy rejestracji (działa także przy zamkniętym naborze). */
    public function registerSms(): array
    {
        require_once dirname(__DIR__, 4) . '/modules/weryfikacja_sms/logic/phoneVerify.php';
        header('Cache-Control: no-store');
        $phone = (string)($_POST['phone'] ?? '');
        $err = match ((string)($_POST['action'] ?? '')) {
            'send'   => phoneVerifySend(NABOR_REG_PURPOSE, $phone, 'zakladania konta kandydata', naborSmsProvider()),
            'send_email' => phoneVerifySendEmail(NABOR_REG_PURPOSE, $phone, (string)($_POST['email'] ?? ''), 'zakładania konta kandydata'),
            'verify' => phoneVerifyCheck(NABOR_REG_PURPOSE, $phone, (string)($_POST['code'] ?? '')),
            default  => 'Nieznana operacja.',
        };
        return ['ok' => $err === '', 'error' => $err];
    }

    public function activate(): ?string
    {
        header('Referrer-Policy: no-referrer');
        $err = naborAccountActivate((string)($_GET['t'] ?? ''));
        if ($err !== '') return $this->view('haslo.php', ['mode' => 'invalid', 'error' => $err, 'email' => '', 'sent' => false]);
        flash_set('success', 'Konto aktywne — jesteś zalogowany(-a). Możesz złożyć zgłoszenie na zajęcia.');
        return Router::redirect(route('nabor.account'));
    }

    public function passwordForm(): string
    {
        header('Referrer-Policy: no-referrer');
        $acc = naborAccountByToken((string)($_GET['t'] ?? ''));
        return $this->view('haslo.php', ['mode' => $acc ? 'set' : 'invalid', 'error' => '', 'email' => $acc['email'] ?? '', 'sent' => false]);
    }

    public function passwordSave(): ?string
    {
        header('Referrer-Policy: no-referrer');
        $t = (string)($_GET['t'] ?? '');
        $err = naborAccountSetPassword($t, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        if ($err !== '') {
            $acc = naborAccountByToken($t);
            return $this->view('haslo.php', ['mode' => $acc ? 'set' : 'invalid', 'error' => $err, 'email' => $acc['email'] ?? '', 'sent' => false]);
        }
        flash_set('success', 'Hasło ustawione — jesteś zalogowany(-a) na koncie kandydata.');
        return Router::redirect(route('nabor.account'));
    }

    public function forgotForm(): string
    {
        return $this->view('haslo.php', ['mode' => 'forgot', 'error' => '', 'email' => '', 'sent' => false]);
    }

    public function forgot(): string
    {
        $email = trim((string)($_POST['email'] ?? ''));
        if (time() - (int)($_SESSION['ti_nabor_reset_at'] ?? 0) >= 60 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['ti_nabor_reset_at'] = time();
            naborAccountRequestReset($email);
        }
        return $this->view('haslo.php', ['mode' => 'forgot', 'error' => '', 'email' => $email, 'sent' => true]);
    }

    public function dashboard(): string
    {
        $acc = naborAccountCurrent();
        return $this->view('konto.php', ['rows' => naborAccountSubmissions((int)$acc['id'])]);
    }

    public function show(int $id): string
    {
        $acc = naborAccountCurrent();
        $s = naborAccountSubmission((int)$acc['id'], $id);
        if (!$s) { http_response_code(404); return $this->view('konto.php', ['rows' => naborAccountSubmissions((int)$acc['id']), 'notFound' => true]); }
        naborMessagesMarkRead($id, 'candidate', 'comment');
        $pay = null;
        if ($s['status'] === 'accepted' && $s['place_confirmed_at'] && $s['client_id']) {
            try {
                require_once dirname(__DIR__, 3) . '/payment_portal/logic/paymentPortal.php';
                $u = pp_user((int)$s['client_id']);
                $pay = ['nrb' => $u ? (pp_payment_account($u)['nrb'] ?? null) : null];
            } catch (\Throwable $e) { $pay = ['nrb' => null]; }
        }
        return $this->view('zgloszenie.php', [
            's' => $s, 'timeline' => naborCandidateTimeline($id), 'docs' => naborDocuments($id), 'msgs' => naborMessages($id),
            'canEdit' => naborCandidateCanEdit($s), 'agreement' => naborAgreementText(), 'pay' => $pay,
        ]);
    }

    /** Operacje z karty zgłoszenia (pole _op): edit | message | upload | confirm_place | pay. */
    public function update(int $id): ?string
    {
        $acc = naborAccountCurrent();
        $s = naborAccountSubmission((int)$acc['id'], $id);
        if (!$s) { http_response_code(404); return 'Nie znaleziono zgłoszenia.'; }
        $back = route('nabor.account.show', ['id' => $id]);
        try {
            switch ($_POST['_op'] ?? '') {
                case 'edit':
                    if (!naborCandidateCanEdit($s)) throw new InvalidArgumentException('Zgłoszenie jest już rozpatrzone — zmiany zgłoś w wiadomości.');
                    // Kandydat nie zmienia e-maila ani telefonu (zweryfikowane) — tylko pozostałe dane
                    $post = ['guardian_email' => $s['guardian_email'], 'guardian_phone' => $s['guardian_phone']] + $_POST;
                    foreach (array_keys(NABOR_EDITABLE) as $k) {                 // pola spoza formularza — bez zmian
                        if (!array_key_exists($k, $post)) $post[$k] = $k === 'equipment' ? (json_decode($s[$k] ?? '[]', true) ?: []) : $s[$k];
                    }
                    $ch = naborUpdateData($id, $post, 0, 'opiekun (konto kandydata)');
                    flash_set($ch ? 'success' : 'info', $ch ? 'Zapisano zmiany.' : 'Bez zmian.');
                    if ($ch) naborStaffNotify($id, 'Opiekun poprawił dane zgłoszenia', 'Zmienione pola: ' . implode(', ', $ch) . '.');
                    break;
                case 'message':
                    naborMessageAdd($id, 'candidate', (string)($_POST['body'] ?? ''), null, $s['guardian_name']);
                    flash_set('success', 'Komentarz dodany — organizator dostał powiadomienie.');
                    $back .= '#historia';
                    break;
                case 'upload':
                    $err = naborDocUpload($id, (int)($_POST['doc_id'] ?? 0), $_FILES['file'] ?? [], (string)($_POST['doc_name'] ?? ''));
                    if ($err !== '') throw new InvalidArgumentException($err);
                    flash_set('success', 'Dokument wgrany — organizator go sprawdzi.');
                    $back .= '#dokumenty';
                    break;
                case 'confirm_place':
                    $err = naborConfirmPlace($id, !empty($_POST['agreement']));
                    if ($err !== '') throw new InvalidArgumentException($err);
                    flash_set('success', 'Dziękujemy — miejsce potwierdzone.');
                    break;
                case 'pay':
                    if (!$s['place_confirmed_at'] || !$s['client_id']) throw new InvalidArgumentException('Płatność będzie dostępna po potwierdzeniu miejsca.');
                    require_once dirname(__DIR__, 3) . '/payment_portal/logic/paymentPortal.php';
                    $u = pp_user_ensure((int)$s['client_id'], 'nabór — konto kandydata', null);
                    if (is_string($u)) throw new InvalidArgumentException('Portal płatności jest chwilowo niedostępny.');
                    return Router::redirect(pp_issue_link((int)$s['client_id'], 'nabór — konto kandydata', null));
            }
        } catch (InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect($back);
    }

    /** Czat (JSON): GET — nowe wiadomości po ?after=, POST body — wysyłka. */
    public function chat(int $id): array
    {
        $acc = naborAccountCurrent();
        if (!naborAccountSubmission((int)$acc['id'], $id)) { http_response_code(404); return ['ok' => false]; }
        return ['ok' => true, 'msgs' => naborChatJson($id, (int)($_GET['after'] ?? 0), 'candidate')];
    }

    public function chatPost(int $id): array
    {
        $acc = naborAccountCurrent();
        $s = naborAccountSubmission((int)$acc['id'], $id);
        if (!$s) { http_response_code(404); return ['ok' => false]; }
        try { naborMessageAdd($id, 'candidate', (string)($_POST['body'] ?? ''), null, $s['guardian_name'], 'chat'); }
        catch (InvalidArgumentException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        return ['ok' => true, 'msgs' => naborChatJson($id, (int)($_POST['after'] ?? 0), 'candidate')];
    }

    /** Pobranie własnego wgranego dokumentu. */
    public function document(int $id, int $doc): ?string
    {
        $acc = naborAccountCurrent();
        if (!naborAccountSubmission((int)$acc['id'], $id)) { http_response_code(404); return 'Nie znaleziono.'; }
        return naborSendDocument($id, $doc);
    }
}

/** Wysyła plik dokumentu zgłoszenia (kontrola ścieżki w UPLOAD_DIR). */
function naborSendDocument(int $sid, int $docId): ?string {
    $d = db_one("SELECT * FROM ti_nabor_documents WHERE id=? AND submission_id=? AND file_path IS NOT NULL", [$docId, $sid]);
    $base = realpath(rtrim(UPLOAD_DIR, '/'));
    $path = $d ? realpath(rtrim(UPLOAD_DIR, '/') . '/' . $d['file_path']) : false;
    if (!$d || !$path || !$base || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) { http_response_code(404); return 'Nie znaleziono pliku.'; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($mime === 'application/pdf' ? 'inline' : 'attachment') . '; filename="' . rawurlencode((string)$d['file_name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    return null;
}
