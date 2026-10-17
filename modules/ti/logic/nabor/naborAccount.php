<?php
/**
 * modules/ti/logic/nabor/naborAccount.php — konto kandydata (jak IRK): e-mail + hasło.
 *
 * Konto powstaje (albo jest dowiązywane po e-mailu) przy wejściu zgłoszenia do obiegu — wtedy e-mail i telefon są już
 * zweryfikowane (link + kod SMS). Bez hasła konto czeka na jego ustawienie z linku (token 7 dni, e-mail „Ustaw hasło”);
 * reset hasła — token 2 h. Logowanie: 5 błędnych prób → blokada 15 min. Sesja: klucz ti_nabor_acc w sesji SZO
 * (publiczna część — bez dostępu do panelu), wygasa po 2 h bezczynności. W bazie tylko skróty tokenów i hasła.
 */
require_once __DIR__ . '/nabor.php';

const NABOR_ACC_IDLE = 7200;
const NABOR_PASS_MIN = 10;

function naborAccountGet(int $id): ?array {
    naborMigrate();
    return db_one("SELECT * FROM ti_nabor_accounts WHERE id=?", [$id]);
}

function naborAccountByEmail(string $email): ?array {
    naborMigrate();
    return db_one("SELECT * FROM ti_nabor_accounts WHERE email=?", [mb_strtolower(trim($email))]);
}

/** Dowiązuje zgłoszenie do konta opiekuna (zakłada konto, gdy go nie ma) i — gdy konto bez hasła — wysyła link „Ustaw hasło”. */
function naborAccountLinkSubmission(int $sid): ?int {
    try {
        $s = naborSubmissionGet($sid);
        if (!$s || !empty($s['is_test'])) return null;
        $acc = $s['account_id'] ? naborAccountGet((int)$s['account_id']) : naborAccountByEmail($s['guardian_email']);
        if (!$acc) {
            $id = db_insert('ti_nabor_accounts', [
                'email' => mb_strtolower($s['guardian_email']), 'name' => $s['guardian_name'], 'phone' => $s['guardian_phone'],
                'email_verified_at' => gmdate('Y-m-d H:i:s'), 'phone_verified_at' => $s['phone_verified_at'],
            ]);
            $acc = naborAccountGet($id);
        } elseif (!$acc['phone_verified_at'] || $acc['phone'] !== $s['guardian_phone']) {
            if ($s['phone_verified_at']) db_exec("UPDATE ti_nabor_accounts SET phone=?, phone_verified_at=? WHERE id=?", [$s['guardian_phone'], $s['phone_verified_at'], $acc['id']]);
        }
        db_exec("UPDATE ti_nabor_submissions SET account_id=? WHERE id=?", [$acc['id'], $sid]);
        if (!$acc['password_hash']) naborAccountSendSetup($acc, $s);
        return (int)$acc['id'];
    } catch (\Throwable $e) {
        error_log('[ti_nabor] konto #' . $sid . ': ' . $e->getMessage());
        return null;
    }
}

/** Nowy token ustawienia/resetu hasła (zwraca token jawny — tylko do linku). */
function naborAccountIssueToken(int $accId, int $ttl): string {
    $t = bin2hex(random_bytes(24));
    db_exec("UPDATE ti_nabor_accounts SET token_hash=?, token_expires_at=? WHERE id=?", [hash('sha256', $t), gmdate('Y-m-d H:i:s', time() + $ttl), $accId]);
    return $t;
}

function naborAccountByToken(string $t): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
    naborMigrate();
    return db_one("SELECT * FROM ti_nabor_accounts WHERE token_hash=? AND token_expires_at > ?", [hash('sha256', $t), gmdate('Y-m-d H:i:s')]);
}

function naborAccountSendSetup(array $acc, ?array $s = null): void {
    require_once __DIR__ . '/naborNotify.php';
    $t = naborAccountIssueToken((int)$acc['id'], 7 * 86400);
    $url = naborLink('/haslo', ['t' => $t]);
    $body = '<p>Dzień dobry,</p><p>założyliśmy dla Ciebie <strong>konto kandydata</strong>' . ($s ? ' (zgłoszenie ' . h($s['registration_number']) . ')' : '') . '. '
          . 'Na koncie zobaczysz status zgłoszeń, wiadomości od organizatora, prośby o dokumenty i — po przyjęciu — potwierdzenie miejsca.</p>'
          . '<p style="text-align:center;margin:24px 0"><a href="' . h($url) . '" style="background:#1d68f2;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Ustaw hasło</a></p>'
          . '<p style="font-size:13px;color:#5b6472">Login: ' . h($acc['email']) . '. Link jest ważny 7 dni. Adres logowania: ' . h(naborLink('/logowanie')) . '</p>';
    try {
        mail_queue_add($acc['email'], $acc['name'], 'Konto kandydata — ustaw hasło', naborMailHtml('Konto kandydata', $body), '', 'ti_nabor_account', (int)$acc['id']);
    } catch (\Throwable $e) { error_log('[ti_nabor] mail konta: ' . $e->getMessage()); }
}

/** Ustawienie hasła z tokenu. Zwraca błąd albo '' (i loguje kandydata). */
function naborAccountSetPassword(string $token, string $pass, string $pass2): string {
    $acc = naborAccountByToken($token);
    if (!$acc) return 'Link wygasł albo był już użyty. Poproś o nowy na stronie logowania („Nie pamiętam hasła”).';
    if (mb_strlen($pass) < NABOR_PASS_MIN) return 'Hasło musi mieć co najmniej ' . NABOR_PASS_MIN . ' znaków.';
    if ($pass !== $pass2) return 'Hasła nie są takie same.';
    if (strcasecmp($pass, $acc['email']) === 0) return 'Hasło nie może być adresem e-mail.';
    db_exec("UPDATE ti_nabor_accounts SET password_hash=?, token_hash=NULL, token_expires_at=NULL, failed_logins=0, locked_until=NULL,
             email_verified_at=COALESCE(email_verified_at, datetime('now')) WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $acc['id']]);
    naborAccountStartSession((int)$acc['id']);
    return '';
}

/** Logowanie. Zwraca błąd albo ''. Komunikat nie zdradza, czy konto istnieje. */
function naborAccountLogin(string $email, string $pass): string {
    $acc = naborAccountByEmail($email);
    $bad = 'Nieprawidłowy e-mail lub hasło.';
    if (!$acc) { password_verify($pass, password_hash('brak-konta', PASSWORD_DEFAULT)); return $bad; }   // podobny czas odpowiedzi
    if ($acc['locked_until'] && $acc['locked_until'] > gmdate('Y-m-d H:i:s')) return 'Zbyt wiele nieudanych prób. Spróbuj ponownie za kilkanaście minut.';
    if (!$acc['password_hash']) return 'Konto nie ma jeszcze hasła — użyj linku z e-maila albo „Nie pamiętam hasła”.';
    if (!$acc['email_verified_at'] && password_verify($pass, $acc['password_hash'])) {
        naborAccountSendActivation($acc);
        return 'Konto nie jest jeszcze aktywne — wysłaliśmy ponownie link aktywacyjny na ' . $acc['email'] . '.';
    }
    if (!password_verify($pass, $acc['password_hash'])) {
        $f = (int)$acc['failed_logins'] + 1;
        db_exec("UPDATE ti_nabor_accounts SET failed_logins=?, locked_until=? WHERE id=?",
                [$f >= 5 ? 0 : $f, $f >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null, $acc['id']]);
        return $bad;
    }
    if (password_needs_rehash($acc['password_hash'], PASSWORD_DEFAULT))
        db_exec("UPDATE ti_nabor_accounts SET password_hash=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $acc['id']]);
    naborAccountStartSession((int)$acc['id']);
    return '';
}

function naborAccountStartSession(int $id): void {
    auth_start();
    session_regenerate_id(true);
    $_SESSION['ti_nabor_acc'] = ['id' => $id, 'at' => time()];
    db_exec("UPDATE ti_nabor_accounts SET failed_logins=0, locked_until=NULL, last_login_at=datetime('now') WHERE id=?", [$id]);
}

/** Zalogowany kandydat albo null (sesja wygasa po NABOR_ACC_IDLE bezczynności). */
function naborAccountCurrent(): ?array {
    auth_start();
    $s = $_SESSION['ti_nabor_acc'] ?? null;
    if (!$s) return null;
    if (time() - (int)$s['at'] > NABOR_ACC_IDLE) { unset($_SESSION['ti_nabor_acc']); return null; }
    $_SESSION['ti_nabor_acc']['at'] = time();
    return naborAccountGet((int)$s['id']);
}

function naborAccountLogout(): void {
    auth_start();
    unset($_SESSION['ti_nabor_acc']);
    session_regenerate_id(true);
}

/** „Nie pamiętam hasła” — wysyła link resetu (2 h), jeśli konto istnieje; odpowiedź zawsze ta sama. */
function naborAccountRequestReset(string $email): void {
    $acc = naborAccountByEmail($email);
    if (!$acc) return;
    require_once __DIR__ . '/naborNotify.php';
    $t = naborAccountIssueToken((int)$acc['id'], 7200);
    $url = naborLink('/haslo', ['t' => $t]);
    $body = '<p>Dzień dobry,</p><p>otrzymaliśmy prośbę o ustawienie nowego hasła do konta kandydata <strong>' . h($acc['email']) . '</strong>.</p>'
          . '<p style="text-align:center;margin:24px 0"><a href="' . h($url) . '" style="background:#1d68f2;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Ustaw nowe hasło</a></p>'
          . '<p style="font-size:13px;color:#5b6472">Link jest ważny 2 godziny. Jeśli to nie Ty — zignoruj wiadomość, hasło się nie zmieni.</p>';
    try {
        mail_queue_add($acc['email'], $acc['name'], 'Konto kandydata — nowe hasło', naborMailHtml('Nowe hasło', $body), '', 'ti_nabor_account', (int)$acc['id'], '', true);
    } catch (\Throwable $e) { error_log('[ti_nabor] reset: ' . $e->getMessage()); }
}

/** Zgłoszenia konta (najnowsze najpierw) z liczbą nieprzeczytanych wiadomości i dokumentów do wgrania. */
function naborAccountSubmissions(int $accId): array {
    return db_all(
        "SELECT s.id, s.registration_number, s.status, s.participant_name, s.created_at, s.course_id, s.is_custom_schedule,
                s.place_confirmed_at, s.agreement_accepted_at,
                COALESCE(NULLIF(n.public_name,''), NULLIF(c.display_name,''), c.name) AS course_name,
                (SELECT COUNT(*) FROM ti_nabor_messages m WHERE m.submission_id=s.id AND m.author='staff' AND m.read_at IS NULL) AS unread,
                (SELECT COUNT(*) FROM ti_nabor_documents d WHERE d.submission_id=s.id AND d.status IN ('requested','rejected')) AS docs_todo
           FROM ti_nabor_submissions s
      LEFT JOIN k30_ti_courses c ON c.id=s.course_id
      LEFT JOIN ti_nabor_courses n ON n.course_id=s.course_id
          WHERE s.account_id=? AND s.status <> 'unconfirmed'
       ORDER BY s.id DESC", [$accId]);
}

/** Zgłoszenie należące do konta albo null. */
function naborAccountSubmission(int $accId, int $sid): ?array {
    $s = naborSubmissionGet($sid);
    return $s && (int)$s['account_id'] === $accId && $s['status'] !== 'unconfirmed' ? $s : null;
}

/** Kandydat może poprawiać dane do czasu rozpatrzenia. */
function naborCandidateCanEdit(array $s): bool {
    return in_array($s['status'], ['new', 'pending_review', 'custom_schedule', 'waitlist'], true);
}

/**
 * Historia zgłoszenia i komentarze dla kandydata — jedna oś czasu (najstarsze najpierw):
 * złożenie, zmiany statusu (komentarz tylko wpisany przez kierownika — ten sam trafia do opiekuna w e-mailu),
 * komentarze obu stron (wiadomości), prośby o dokumenty, wgrane pliki i ich ocena, potwierdzenie miejsca.
 * Notatki wewnętrzne i wpisy techniczne systemu (EZD, CRM, CLI, powiadomienia) są pomijane.
 * Wpis: ['at', 'kind' (submitted|status|staff|candidate|doc_request|doc_upload|doc_ok|doc_bad|place), 'title', 'text', 'who'].
 */
function naborCandidateTimeline(int $sid): array {
    $s = naborSubmissionGet($sid);
    if (!$s) return [];
    $t = [['at' => $s['created_at'], 'kind' => 'submitted', 'title' => 'Zgłoszenie złożone', 'text' => '', 'who' => '']];
    foreach (db_all("SELECT from_status, to_status, comment, user_id, created_at FROM ti_nabor_history
                      WHERE submission_id=? AND from_status <> to_status AND from_status <> '' ORDER BY id", [$sid]) as $h) {
        $t[] = ['at' => $h['created_at'], 'kind' => 'status', 'title' => naborStatusLabel($h['to_status']),
                'text' => ($h['user_id'] && $h['comment'] !== 'Zmiana w kanbanie') ? $h['comment'] : naborStatusPublicNote($h['from_status'], $h['to_status']),
                'who' => $h['user_id'] ? 'Organizator' : ''];
    }
    foreach (naborMessages($sid) as $m) {
        $staff = $m['author'] === 'staff';
        $t[] = ['at' => $m['created_at'], 'kind' => $staff ? 'staff' : 'candidate', 'title' => $staff ? 'Komentarz organizatora' : 'Twój komentarz',
                'text' => $m['body'], 'who' => $staff ? ($m['author_name'] ?: 'Organizator') : 'Ty'];
    }
    foreach (naborDocuments($sid) as $d) {
        if ($d['requested_by']) $t[] = ['at' => $d['created_at'], 'kind' => 'doc_request', 'title' => 'Prośba o dokument: ' . $d['name'], 'text' => $d['note'], 'who' => 'Organizator'];
        if ($d['uploaded_at']) $t[] = ['at' => $d['uploaded_at'], 'kind' => 'doc_upload', 'title' => 'Wgrano dokument: ' . $d['name'], 'text' => (string)$d['file_name'], 'who' => 'Ty'];
        if ($d['reviewed_at']) $t[] = ['at' => $d['reviewed_at'], 'kind' => $d['status'] === 'accepted' ? 'doc_ok' : 'doc_bad',
                                       'title' => ($d['status'] === 'accepted' ? 'Dokument zaakceptowany: ' : 'Dokument do poprawy: ') . $d['name'], 'text' => $d['review_note'], 'who' => 'Organizator'];
    }
    if ($s['place_confirmed_at']) $t[] = ['at' => $s['place_confirmed_at'], 'kind' => 'place', 'title' => 'Miejsce potwierdzone' . ($s['agreement_accepted_at'] ? ' i warunki zaakceptowane' : ''), 'text' => '', 'who' => 'Ty'];
    usort($t, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at']));
    return $t;
}

/** Opis zmiany statusu dla opiekuna, gdy nie ma komentarza kierownika (wpisy systemowe). */
function naborStatusPublicNote(string $from, string $to): string {
    return match (true) {
        $from === 'unconfirmed' && $to === 'cancelled' => 'Zgłoszenie wygasło — adres e-mail nie został potwierdzony.',
        $from === 'unconfirmed' && $to === 'waitlist'  => 'Zgłoszenie potwierdzone — w grupie nie ma wolnych miejsc, jesteś na liście rezerwowej.',
        $from === 'unconfirmed'                        => 'Zgłoszenie potwierdzone i przekazane do rozpatrzenia.',
        default                                       => '',
    };
}

/* ─────────────── Dokumenty (prośby kierownika, pliki kandydata) ─────────────── */

const NABOR_DOC_MAX = 10 * 1024 * 1024;
const NABOR_DOC_MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
const NABOR_DOC_STATUSES = ['requested' => ['Do wgrania', 'warning'], 'uploaded' => ['Wgrany — do sprawdzenia', 'info'],
                            'accepted' => ['Zaakceptowany', 'success'], 'rejected' => ['Do poprawy', 'danger']];

function naborDocuments(int $sid): array {
    return db_all("SELECT * FROM ti_nabor_documents WHERE submission_id=? ORDER BY id", [$sid]);
}

/** Kierownik prosi o dokument — powiadomienie e-mail do kandydata. */
function naborDocRequest(int $sid, string $name, string $note, int $uid, string $uname): int {
    $name = mb_substr(trim($name), 0, 150);
    if ($name === '') throw new InvalidArgumentException('Podaj nazwę dokumentu.');
    $id = db_insert('ti_nabor_documents', ['submission_id' => $sid, 'name' => $name, 'note' => mb_substr(trim($note), 0, 1000), 'requested_by' => $uid ?: null]);
    naborHistoryNote($sid, 'Prośba o dokument: ' . $name . '.', $uid, $uname);
    naborCandidateNotify($sid, 'Prośba o dokument', 'Organizator prosi o dokument: <strong>' . h($name) . '</strong>.' . ($note !== '' ? '<br>' . nl2br(h($note)) : ''), 'ti_nabor_status');
    return $id;
}

/**
 * Kandydat wgrywa plik (do prośby $docId albo jako „inny dokument”, gdy $docId = 0). PDF/JPG/PNG ≤ 10 MB, typ po zawartości.
 * Plik trafia też do koszulki EZD zgłoszenia (pismo przychodzące). Zwraca błąd albo ''.
 */
function naborDocUpload(int $sid, int $docId, array $file, string $otherName = ''): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 'Wybierz plik.';
    if ((int)$file['size'] > NABOR_DOC_MAX) return 'Plik jest za duży (maks. 10 MB).';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(NABOR_DOC_MIME[$mime])) return 'Dozwolone pliki: PDF, JPG, PNG.';
    if ($docId) {
        $d = db_one("SELECT * FROM ti_nabor_documents WHERE id=? AND submission_id=?", [$docId, $sid]);
        if (!$d || !in_array($d['status'], ['requested', 'rejected', 'uploaded'], true)) return 'Ten dokument nie czeka na plik.';
    } else {
        $name = mb_substr(trim($otherName), 0, 150) ?: 'Inny dokument';
        $docId = db_insert('ti_nabor_documents', ['submission_id' => $sid, 'name' => $name, 'status' => 'uploaded']);
        $d = db_one("SELECT * FROM ti_nabor_documents WHERE id=?", [$docId]);
    }
    $rel = 'ti_nabor/' . $sid . '/docs/';
    $dir = rtrim(UPLOAD_DIR, '/') . '/' . $rel;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $stored = $docId . '_' . bin2hex(random_bytes(6)) . '.' . NABOR_DOC_MIME[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $stored) && !rename($file['tmp_name'], $dir . $stored)) return 'Nie udało się zapisać pliku.';
    $orig = mb_substr(preg_replace('/[^\p{L}\p{N} ._-]/u', '_', (string)$file['name']), 0, 120) ?: $stored;
    db_exec("UPDATE ti_nabor_documents SET status='uploaded', file_path=?, file_name=?, file_size=?, uploaded_at=datetime('now'), review_note='' WHERE id=?",
            [$rel . $stored, $orig, (int)$file['size'], $docId]);
    naborHistoryNote($sid, 'Kandydat wgrał dokument: ' . $d['name'] . ' (' . $orig . ').');
    naborDocToEzd($sid, $d['name'], $dir . $stored, $orig);
    naborStaffNotify($sid, 'Nowy dokument w zgłoszeniu', 'Kandydat wgrał dokument: ' . $d['name'] . '.');
    return '';
}

/** Dokument do koszulki EZD zgłoszenia (pismo przychodzące z plikiem) — najlepszy wysiłek. */
function naborDocToEzd(int $sid, string $title, string $path, string $name): void {
    try {
        $s = naborSubmissionGet($sid);
        if (!$s || !$s['ezd_case_id'] || !module_enabled('ezd_enabled')) return;
        require_once dirname(__DIR__, 4) . '/includes/ezd.php';
        $uid = (int)(db_one("SELECT created_by FROM ezd_sprawy WHERE id=?", [$s['ezd_case_id']])['created_by'] ?? 0);
        if (!$uid) return;
        $pid = ezd_pismo_create(['sprawa_id' => (int)$s['ezd_case_id'], 'kierunek' => 'przychodzace', 'title' => $title . ' — ' . $s['registration_number'],
            'tresc' => 'Dokument wgrany przez kandydata w koncie kandydata.', 'nadawca' => $s['guardian_name'] . ' <' . $s['guardian_email'] . '>',
            'data_pisma' => date('Y-m-d'), 'data_wplywu' => date('Y-m-d'), 'status' => 'nowe', 'rodzaj_medium' => 'email'], $uid);
        ezd_attach_path($path, $name, (int)$s['ezd_case_id'], $pid, $uid);
    } catch (\Throwable $e) { error_log('[ti_nabor] dokument → EZD: ' . $e->getMessage()); }
}

/** Kierownik akceptuje / odrzuca dokument (odrzucenie z uwagą → kandydat poprawia). */
function naborDocReview(int $sid, int $docId, bool $ok, string $note, int $uid, string $uname): void {
    $d = db_one("SELECT * FROM ti_nabor_documents WHERE id=? AND submission_id=?", [$docId, $sid]);
    if (!$d || $d['status'] !== 'uploaded') throw new InvalidArgumentException('Dokument nie czeka na sprawdzenie.');
    db_exec("UPDATE ti_nabor_documents SET status=?, review_note=?, reviewed_at=datetime('now') WHERE id=?", [$ok ? 'accepted' : 'rejected', mb_substr(trim($note), 0, 1000), $docId]);
    naborHistoryNote($sid, 'Dokument „' . $d['name'] . '”: ' . ($ok ? 'zaakceptowany' : 'do poprawy') . ($note !== '' ? ' — ' . $note : '') . '.', $uid, $uname);
    if (!$ok) naborCandidateNotify($sid, 'Dokument do poprawy', 'Dokument <strong>' . h($d['name']) . '</strong> wymaga poprawy.' . ($note !== '' ? '<br>' . nl2br(h($note)) : ''), 'ti_nabor_status');
}

/* ─────────────── Wiadomości ─────────────── */

/** Wiadomości zgłoszenia: $channel 'comment' (oś czasu), 'chat' (czat na żywo) albo null (wszystkie). $afterId — tylko nowsze. */
function naborMessages(int $sid, ?string $channel = 'comment', int $afterId = 0): array {
    $w = 'submission_id=? AND id>?'; $p = [$sid, $afterId];
    if ($channel !== null) { $w .= " AND COALESCE(channel,'comment')=?"; $p[] = $channel; }
    return db_all("SELECT * FROM ti_nabor_messages WHERE $w ORDER BY id", $p);
}

/**
 * Nowa wiadomość. Komentarz ('comment'): 'staff' → kandydat dostaje e-mail + SMS, 'candidate' → e-mail do kierownika.
 * Czat ('chat'): bez SMS; e-mail do drugiej strony najwyżej raz na 15 min na zgłoszenie (i tylko gdy poprzednia wiadomość
 * tej strony w czacie jest nieprzeczytana albo starsza niż 15 min). Zwraca id wiadomości.
 */
function naborMessageAdd(int $sid, string $author, string $body, ?int $uid, string $name, string $channel = 'comment'): int {
    $body = mb_substr(trim($body), 0, 5000);
    if ($body === '') throw new InvalidArgumentException('Wpisz treść wiadomości.');
    $channel = $channel === 'chat' ? 'chat' : 'comment';
    $author  = $author === 'staff' ? 'staff' : 'candidate';
    $prev = $channel === 'chat' ? db_one("SELECT created_at FROM ti_nabor_messages WHERE submission_id=? AND author=? AND channel='chat' ORDER BY id DESC LIMIT 1", [$sid, $author]) : null;
    $id = db_insert('ti_nabor_messages', ['submission_id' => $sid, 'author' => $author, 'user_id' => $uid, 'author_name' => $name, 'body' => $body, 'channel' => $channel]);
    if ($channel === 'chat') {
        if ($prev && strtotime($prev['created_at'] . ' UTC') > time() - 900) return $id;   // rozmowa trwa — bez kolejnego e-maila
        if ($author === 'staff') naborCandidateNotify($sid, 'Nowa wiadomość na czacie', nl2br(h($body)), 'ti_nabor_status', false);
        else naborStaffNotify($sid, 'Nowa wiadomość na czacie od kandydata', $body);
        return $id;
    }
    if ($author === 'staff') {
        naborCandidateNotify($sid, 'Nowy komentarz organizatora', nl2br(h($body)), 'ti_nabor_status', true);
    } else {
        naborStaffNotify($sid, 'Nowy komentarz kandydata', $body);
    }
    return $id;
}

/** Wiadomości czatu do JSON (dla widoku kandydata albo kierownika). */
function naborChatJson(int $sid, int $afterId, string $viewer): array {
    $out = [];
    foreach (naborMessages($sid, 'chat', $afterId) as $m) {
        $mine = ($viewer === 'staff') === ($m['author'] === 'staff');
        $out[] = ['id' => (int)$m['id'], 'mine' => $mine, 'who' => $mine ? 'Ty' : ($m['author'] === 'staff' ? ($m['author_name'] ?: 'Organizator') : ($m['author_name'] ?: 'Opiekun')),
                  'body' => $m['body'], 'at' => naborTime($m['created_at']), 'read' => (bool)$m['read_at']];
    }
    naborMessagesMarkRead($sid, $viewer, 'chat');
    return $out;
}

/** Oznacza wiadomości drugiej strony jako przeczytane. */
function naborMessagesMarkRead(int $sid, string $reader, ?string $channel = null): void {
    db_exec("UPDATE ti_nabor_messages SET read_at=datetime('now') WHERE submission_id=? AND author=? AND read_at IS NULL"
            . ($channel ? " AND COALESCE(channel,'comment')=?" : ''),
            $channel ? [$sid, $reader === 'staff' ? 'candidate' : 'staff', $channel] : [$sid, $reader === 'staff' ? 'candidate' : 'staff']);
}

/* ─────────────── Po przyjęciu: potwierdzenie miejsca i umowa ─────────────── */

/** Treść umowy / deklaracji uczestnictwa z edytora formularza ('' = bez umowy — wystarczy potwierdzenie miejsca). */
function naborAgreementText(): string {
    return trim((string)(naborForm()['texts']['agreement'] ?? ''));
}

/** Kandydat potwierdza miejsce (i akceptuje umowę, jeśli ustawiona). Zwraca błąd albo ''. */
function naborConfirmPlace(int $sid, bool $agreementAccepted): string {
    $s = naborSubmissionGet($sid);
    if (!$s || $s['status'] !== 'accepted') return 'Miejsce można potwierdzić po przyjęciu zgłoszenia.';
    if ($s['place_confirmed_at']) return '';
    $agr = naborAgreementText();
    if ($agr !== '' && !$agreementAccepted) return 'Zaakceptuj warunki uczestnictwa.';
    db_exec("UPDATE ti_nabor_submissions SET place_confirmed_at=datetime('now'), agreement_accepted_at=?, agreement_ip=?, updated_at=datetime('now') WHERE id=?",
            [$agr !== '' ? gmdate('Y-m-d H:i:s') : null, $agr !== '' ? naborClientIp() : '', $sid]);
    naborHistoryNote($sid, 'Opiekun potwierdził miejsce' . ($agr !== '' ? ' i zaakceptował warunki uczestnictwa (IP ' . naborClientIp() . ')' : '') . '.');
    if ($agr !== '') {
        try {
            require_once dirname(__DIR__, 2) . '/lib/naborPdf.php';
            $tmp = tempnam(sys_get_temp_dir(), 'tinagr_');
            file_put_contents($tmp, naborAgreementPdf($sid));
            naborDocToEzd($sid, 'Warunki uczestnictwa — akceptacja', $tmp, 'warunki-' . str_replace('/', '-', $s['registration_number']) . '.pdf');
            @unlink($tmp);
        } catch (\Throwable $e) { error_log('[ti_nabor] umowa PDF: ' . $e->getMessage()); }
    }
    naborStaffNotify($sid, 'Potwierdzenie miejsca', 'Opiekun potwierdził miejsce uczestnika ' . $s['participant_name'] . '.');
    return '';
}

/* ─────────────── Powiadomienia kandydata / kierownika ─────────────── */

/** E-mail (+ opcjonalnie SMS) do opiekuna z odnośnikiem do konta kandydata. */
function naborCandidateNotify(int $sid, string $subject, string $html, string $kind, bool $sms = false): void {
    require_once __DIR__ . '/naborNotify.php';
    $s = naborSubmissionGet($sid);
    if (!$s || !empty($s['is_test'])) return;
    $url = naborLink('/konto/zgloszenia/' . $sid);
    $body = '<p>Dzień dobry,</p><p>' . $html . '</p><p style="text-align:center;margin:24px 0"><a href="' . h($url) . '" style="background:#1d68f2;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Przejdź do konta kandydata</a></p>'
          . '<p style="font-size:13px;color:#5b6472">Zgłoszenie ' . h($s['registration_number']) . ' — ' . h($s['participant_name']) . '</p>';
    try { mail_queue_add($s['guardian_email'], $s['guardian_name'], $subject . ' — ' . $s['registration_number'], naborMailHtml($subject, $body), '', 'ti_nabor', $sid); }
    catch (\Throwable $e) { error_log('[ti_nabor] powiadomienie: ' . $e->getMessage()); }
    if ($sms) {
        require_once dirname(__DIR__, 4) . '/includes/sms.php';
        try { naborSms($s['guardian_phone'], naborOrgName() . ': ' . sms_ascii($subject) . ' w zgloszeniu ' . $s['registration_number'] . '. Zaloguj sie na konto kandydata.', $kind); }
        catch (\Throwable $e) { error_log('[ti_nabor] SMS: ' . $e->getMessage()); }
    }
}

/** E-mail do kierownika naboru (ustawienie ti_nabor_staff_email; puste = bez powiadomienia). */
function naborStaffNotify(int $sid, string $subject, string $text): void {
    $to = trim(org_setting('ti_nabor_staff_email'));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    require_once __DIR__ . '/naborNotify.php';
    $s = naborSubmissionGet($sid);
    if (!$s) return;
    $url = naborPanelUrl('/zgloszenia/' . $sid);
    $body = '<p>' . nl2br(h($text)) . '</p><p><a href="' . h($url) . '">Otwórz zgłoszenie ' . h($s['registration_number']) . ' w panelu dydaktyka</a></p>';
    try { mail_queue_add($to, 'Nabór TI', $subject . ' — ' . $s['registration_number'], naborMailHtml($subject, $body), '', 'ti_nabor', $sid); }
    catch (\Throwable $e) { error_log('[ti_nabor] powiadomienie kierownika: ' . $e->getMessage()); }
}

/* ─────────────── Samodzielne założenie konta (jak IRK: konto najpierw, zgłoszenia później) ─────────────── */

const NABOR_REG_PURPOSE = 'ti_nabor_konto';

/**
 * Rejestracja: imię i nazwisko, e-mail, telefon potwierdzony kodem SMS (w tej sesji), hasło, zgoda RODO.
 * Konto bez potwierdzonego e-maila — link aktywacyjny (48 h). Zwraca [błędy, id konta|null].
 */
function naborAccountRegister(array $p): array {
    require_once dirname(__DIR__, 4) . '/modules/weryfikacja_sms/logic/phoneVerify.php';
    $e = [];
    $name  = mb_substr(trim((string)($p['name'] ?? '')), 0, 200);
    $email = mb_strtolower(trim((string)($p['email'] ?? '')));
    $phone = phoneVerifyNormalize((string)($p['phone'] ?? ''));
    $pass  = (string)($p['password'] ?? '');
    if (mb_strlen($name) < 3) $e[] = 'Podaj imię i nazwisko.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e[] = 'Podaj poprawny adres e-mail.';
    if ($phone === '') $e[] = 'Podaj numer telefonu komórkowego (9 cyfr).';
    elseif (phoneVerifiedInSession(NABOR_REG_PURPOSE) !== $phone) $e[] = 'Potwierdź numer telefonu kodem SMS (albo kodem wysłanym e-mailem).';
    $via = phoneVerifiedVia(NABOR_REG_PURPOSE) ?? 'sms';
    if ($via === 'email' && $email !== phoneVerifiedEmail(NABOR_REG_PURPOSE)) $e[] = 'Adres e-mail różni się od tego, na który wysłaliśmy kod — wyślij kod ponownie.';
    if (mb_strlen($pass) < NABOR_PASS_MIN) $e[] = 'Hasło musi mieć co najmniej ' . NABOR_PASS_MIN . ' znaków.';
    elseif ($pass !== (string)($p['password2'] ?? '')) $e[] = 'Hasła nie są takie same.';
    if (empty($p['gdpr'])) $e[] = 'Zgoda na przetwarzanie danych w celu prowadzenia konta jest wymagana.';
    if (!$e && naborAccountByEmail($email)) $e[] = 'Konto z tym adresem już istnieje — zaloguj się albo użyj „Nie pamiętam hasła”.';
    if ($e) return [$e, null, $via];
    // Kod e-mailem: adres potwierdzony od razu (bez linku aktywacyjnego), telefon niezweryfikowany
    $id = db_insert('ti_nabor_accounts', ['email' => $email, 'name' => $name, 'phone' => $phone,
                                          'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                                          'phone_verified_at' => $via === 'email' ? null : gmdate('Y-m-d H:i:s'),
                                          'email_verified_at' => $via === 'email' ? gmdate('Y-m-d H:i:s') : null]);
    phoneVerifyForget(NABOR_REG_PURPOSE);
    if ($via === 'email') naborAccountStartSession($id);
    else naborAccountSendActivation(naborAccountGet($id));
    return [[], $id, $via];
}

function naborAccountSendActivation(array $acc): void {
    require_once __DIR__ . '/naborNotify.php';
    $t = naborAccountIssueToken((int)$acc['id'], 2 * 86400);
    $url = naborLink('/aktywuj', ['t' => $t]);
    $body = '<p>Dzień dobry,</p><p>dziękujemy za założenie <strong>konta kandydata</strong>. Kliknij, żeby potwierdzić adres e-mail i aktywować konto:</p>'
          . '<p style="text-align:center;margin:24px 0"><a href="' . h($url) . '" style="background:#0b5ea8;color:#fcc634;padding:12px 22px;text-decoration:none;font-weight:bold">Aktywuj konto</a></p>'
          . '<p style="font-size:13px;color:#5b6472">Link jest ważny 48 godzin. Jeśli to nie Ty zakładałeś(-aś) konto — zignoruj wiadomość.</p>';
    try { mail_queue_add($acc['email'], $acc['name'], 'Aktywuj konto kandydata', naborMailHtml('Aktywuj konto kandydata', $body), '', 'ti_nabor_account', (int)$acc['id'], '', true); }
    catch (\Throwable $e) { error_log('[ti_nabor] aktywacja: ' . $e->getMessage()); }
}

/** Aktywacja z linku: potwierdza e-mail i loguje. Zwraca błąd albo ''. */
function naborAccountActivate(string $token): string {
    $acc = naborAccountByToken($token);
    if (!$acc) return 'Link aktywacyjny wygasł albo był już użyty.';
    db_exec("UPDATE ti_nabor_accounts SET email_verified_at=COALESCE(email_verified_at, datetime('now')), token_hash=NULL, token_expires_at=NULL WHERE id=?", [$acc['id']]);
    naborAccountStartSession((int)$acc['id']);
    return '';
}
