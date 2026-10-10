<?php
/**
 * includes/ti_cert_issue.php — wystawianie i wysyłka certyfikatu prowadzącego.
 *
 * Plik .p12 idzie do prowadzącego mailem jako załącznik, hasło do pliku SMS-em (rodzaj
 * 'document_code'). Hasło nie trafia do maila ani do bazy. Plik tymczasowy jest usuwany
 * zaraz po wysłaniu maila; jeśli wysyłka maila się nie uda, mail jest anulowany.
 * Jeśli SMS się nie uda, hasło pokazuje się osobie wystawiającej jednorazowo.
 */
require_once dirname(__DIR__) . '/includes/x509_login.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';

/** Katalog tymczasowy na plik .p12 (w uploads/, ale z zakazem dostępu przez WWW). */
function ti_cert_tmp_dir(): string {
    $dir = rtrim(UPLOAD_DIR, '/') . '/ti_certs_tmp/';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $ht = $dir . '.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    return $dir;
}

/** Data, od której można wystawiać certyfikaty do podpisu (RRRR-MM-DD). */
const TI_CERT_GENERATION_FROM = '2026-10-11';

/** Czy wystawianie certyfikatów jest już otwarte. */
function ti_cert_generation_open(): bool {
    return date('Y-m-d') >= TI_CERT_GENERATION_FROM;
}

/** Komunikat o terminie otwarcia (np. „od 11.10.2026”). */
function ti_cert_generation_info(): string {
    return 'Wystawianie certyfikatów będzie możliwe od ' . date('d.m.Y', strtotime(TI_CERT_GENERATION_FROM)) . '.';
}

/** Osoby, które mogą dostać certyfikat: prowadzący przypisani do kursów, aktywne konta. */
function ti_cert_instructors(): array {
    return db_all(
        "SELECT DISTINCT u.id, u.name, u.email, u.phone_number
           FROM users u JOIN k30_ti_courses c ON c.instructor_id = u.id
          WHERE u.is_active = 1
          ORDER BY u.name"
    );
}

/**
 * Info dla prowadzących o zmianach przy zatwierdzaniu protokołu: czym jest certyfikat, po co i co trzeba zrobić.
 * Wysyłane przez kierownika jednostki (przycisk na stronie certyfikatów).
 */
function ti_cert_info_mail(string $name): array {
    $txt = "Dzień dobry, {$name},\n\n"
         . "od teraz protokół miesięczny zatwierdza się elektronicznie. Poniżej wyjaśniam, co to oznacza.\n\n"
         . "Czym jest certyfikat?\n"
         . "To elektroniczny odpowiednik podpisu. Plik .p12 zawiera klucz, którym podpisujesz protokół. "
         . "Dzięki temu widać, kto zatwierdził protokół i czy ktoś zmienił godziny po zatwierdzeniu.\n\n"
         . "Dlaczego tak?\n"
         . "- Protokół jest podstawą do rozliczenia Twoich godzin. Musi być wiarygodny i łatwy do sprawdzenia.\n"
         . "- Podpis elektroniczny zastępuje wydruk i ręczny podpis, więc nie trzeba niczego drukować ani skanować.\n"
         . "- Kod z SMS-a dodaje drugie zabezpieczenie: nawet jeśli ktoś pozna plik certyfikatu, bez Twojego telefonu nie zatwierdzi protokołu.\n\n"
         . "Co trzeba zrobić?\n"
         . "1. Przy zatwierdzaniu protokołu wyślij kod SMS i podpisz się certyfikatem (plik .p12 i hasło) albo kluczem YubiKey.\n"
         . "2. Certyfikat wygenerujesz sam w „Mój certyfikat”. Hasło ustawiasz sam: co najmniej 16 znaków, w tym co najmniej 3 rodzaje znaków (małe i wielkie litery, cyfry, znaki specjalne).\n"
         . "3. Certyfikaty wystawione przed 10.10.2026, godz. 21:32, są nieważne. Wygeneruj nowy.\n"
         . "4. Samodzielne wystawianie działa od 11.10.2026.\n\n"
         . "Rachunki (umowy zlecenia) są przesyłane tak jak dotychczas. Zmiana dotyczy tylko zatwierdzania protokołów.\n\n"
         . "Jeśli w ostatnim czasie dostałeś testowe maile albo SMS-y o certyfikatach, zignoruj je.\n\n"
         . "W razie pytań pisz do kierownika jednostki.\n\n"
         . "--\nWiadomość automatyczna. Prosimy na nią nie odpowiadać.";
    $html = '<p>' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</p>';
    return [$txt, $html];
}

/** Krótki SMS z informacją o zmianach (odsyła do maila). */
function ti_cert_info_sms(): string {
    return 'FEER: od teraz protokoly zatwierdza sie certyfikatem i kodem SMS. Certyfikat wygenerujesz w panelu dydaktyka (Mój certyfikat). Szczegoly w mailu.';
}

/** SMS dotyczący certyfikatu. Zwraca true, gdy bramka przyjęła wiadomość; błąd nie przerywa procesu. */
function ti_cert_sms(string $phone, string $text, string $kind): bool {
    if (preg_replace('/\D/', '', $phone) === '') return false;
    try {
        return sms_send($phone, $text, $kind) !== '';
    } catch (\Throwable $e) {
        error_log('[ti_cert_sms] ' . $e->getMessage());
        return false;
    }
}

/**
 * Prośba o wygenerowanie certyfikatu do podpisu protokołów — wysyłana przez kierownika do wszystkich
 * prowadzących bez certyfikatu. Wyjaśnia, do czego jest certyfikat i jak go wygenerować samemu.
 */
function ti_cert_request_mail(string $name, string $issuer): array {
    $link = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/moj_certyfikat.php';
    $txt = "Dzień dobry, {$name},\n\n"
         . "od teraz protokoły miesięczne TI podpisuje się certyfikatem cyfrowym. "
         . "Zatwierdzenie protokołu nie będzie możliwe bez certyfikatu, dlatego prosimy o jego wygenerowanie.\n\n"
         . "Jak to zrobić:\n"
         . "1. Zaloguj się do panelu dydaktyka i wejdź w sekcję „Certyfikat” (albo bezpośrednio: {$link}).\n"
         . "2. Kliknij „Wygeneruj certyfikat”. Plik .p12 przyjdzie na Twój e-mail, a hasło do niego SMS-em na telefon z konta "
         . "(możesz też sam ustawić hasło na tej samej stronie).\n"
         . "3. Przy zatwierdzaniu protokołu wskaż plik i wpisz hasło. Nic z tym nie trzeba instalować.\n\n"
         . "Jeśli nie możesz wejść do panelu albo coś nie działa, odezwij się do kierownika.\n\n"
         . "Pozdrawiam,\n{$issuer}\nFundacja FEER\n\n"
         . "--\nTo jest wiadomość automatyczna, wysłana przez system. Prosimy na nią nie odpowiadać.";
    $html = '<p>' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</p>';
    return [$txt, $html];
}

/** Treść maila z instrukcją (bez hasła). */
function ti_cert_mail_body(string $name, string $issuer, bool $zmiana_hasla = false, string $numer = ''): array {
    $co = $zmiana_hasla
        ? "w załączniku przesyłamy certyfikat (plik .p12) z nowym hasłem. Zmienione zostało hasło do pliku."
        : "w załączniku przesyłamy certyfikat cyfrowy (plik .p12), którym będzie Pan/Pani podpisywać protokoły miesięczne w module Dydaktyka.";
    $txt = "Dzień dobry, {$name},\n\n"
         . ($numer !== '' ? "Numer certyfikatu: {$numer}.\n\n" : '')
         . "{$co}\n\n"
         . "Hasło do pliku przyjdzie w osobnej wiadomości SMS na Pana/Pani numer telefonu.\n\n"
         . "Do czego służy?\n"
         . "Zatwierdzając protokół, podpisuje Pan/Pani go swoim certyfikatem. Podpis potwierdza, że to Pan/Pani "
         . "zatwierdził(a) ewidencję godzin i że jej treść nie została zmieniona po podpisaniu. "
         . "Bez certyfikatu nie da się zatwierdzić protokołu.\n\n"
         . "Co zrobić:\n"
         . "1. Zapisz plik w bezpiecznym miejscu. Nie przesyłaj go dalej i nie zostawiaj na wspólnym komputerze.\n"
         . "2. Przy zatwierdzaniu protokołu (Protokoły → Zamknij) wskaż ten plik i wpisz hasło z SMS-a.\n"
         . "3. Plik i hasło są używane tylko do tego podpisu i nie są zapisywane w systemie.\n\n"
         . "Certyfikat jest ważny 2 lata. Jeśli plik się zgubi albo hasła nie ma, poproś o ponowne wystawienie.\n\n"
         . "Pozdrawiam,\n{$issuer}\nFundacja FEER\n\n"
         . "--\nTo jest wiadomość automatyczna, wysłana przez system. Prosimy na nią nie odpowiadać.";
    $html = '<p>' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</p>';
    return [$txt, $html];
}

/**
 * Wysyła plik .p12 mailem (załącznik) i hasło SMS-em. Zwraca:
 *   ok (bool), error (string), sms (bool), password (string — tylko gdy SMS się nie udał).
 */
function ti_cert_deliver(int $uid, string $name, string $email, string $phone, string $p12, string $password, string $issuer, bool $zmiana_hasla = false, bool $sms = true, string $numer = ''): array {
    $dir  = ti_cert_tmp_dir();
    $file = bin2hex(random_bytes(12)) . '.p12';
    if (file_put_contents($dir . $file, $p12) === false) return ['ok' => false, 'error' => 'Nie udało się zapisać pliku certyfikatu.'];

    [$txt, $html] = ti_cert_mail_body($name, $issuer, $zmiana_hasla, $numer);
    $mail_id = mail_queue_add(
        $email, $name,
        'Certyfikat do podpisywania protokołów TI — Fundacja FEER',
        $html, $txt, 'ti_cert', $uid, '', true,
        [['path' => 'ti_certs_tmp/' . $file, 'name' => 'certyfikat-' . $uid . '.p12', 'mime' => 'application/x-pkcs12', 'size' => strlen($p12)]]
    );
    $status = (string)db_one("SELECT status FROM mail_queue WHERE id = ?", [$mail_id])['status'];
    @unlink($dir . $file);  // plik nie może zostać na serwerze po wysyłce

    if ($status !== 'sent') {
        // Bez pliku wiadomość poszłaby bez załącznika — anuluj ją w kolejce.
        mail_queue_cancel($mail_id);
        return ['ok' => false, 'error' => 'Mail z certyfikatem nie został wysłany. Spróbuj ponownie później.'];
    }

    // Hasło wybrane przez prowadzącego nie idzie nigdzie — zna je tylko on. Informujemy go SMS-em o wystawieniu.
    if (!$sms) {
        ti_cert_sms($phone, 'FEER: wystawiono certyfikat ' . $numer . ' do podpisu protokołów. Plik na e-mailu. Hasło ustawiłeś sam.', 'ti_dydaktyka_notice');
        return ['ok' => true, 'error' => '', 'sms' => false, 'password' => ''];
    }

    $sms_ok = ti_cert_sms($phone, 'FEER: certyfikat ' . $numer . '. Haslo do pliku: ' . $password . '. Nie udostepniaj.', 'document_code');
    return $sms_ok
        ? ['ok' => true, 'error' => '', 'sms' => true, 'password' => '']
        : ['ok' => true, 'error' => '', 'sms' => false, 'password' => $password];
}

/**
 * Wystawia nowy certyfikat prowadzącemu i wysyła go. Zwraca jak ti_cert_deliver().
 * Wymaga aktywnego konta z e-mailem i telefonem.
 */
function ti_cert_issue_for_instructor(int $target_id, string $issuer_name): array {
    // Kierownik wystawia niezależnie od terminu otwarcia (blokada dotyczy tylko samodzielnego generowania).
    $u = db_one("SELECT id, name, email, phone_number FROM users WHERE id = ? AND is_active = 1", [$target_id]);
    if (!$u) return ['ok' => false, 'error' => 'Nie ma takiego aktywnego konta.'];
    if (trim((string)$u['email']) === '') return ['ok' => false, 'error' => 'Prowadzący nie ma adresu e-mail w koncie.'];
    if (preg_replace('/\D/', '', (string)$u['phone_number']) === '') return ['ok' => false, 'error' => 'Prowadzący nie ma numeru telefonu w koncie (potrzebny do hasła SMS-em).'];

    $password = bin2hex(random_bytes(6));  // 12 znaków, pokazywane tylko w razie błędu SMS
    $gen = x509_generate_for_user($target_id, $password, (string)$u['name'], 'Fundacja FEER');
    $p12 = (string)($gen['p12_data'] ?? '');
    if ($p12 === '') return ['ok' => false, 'error' => 'Nie udało się wygenerować certyfikatu.'];

    $numer = x509_assign_number($target_id);
    return ti_cert_deliver($target_id, (string)$u['name'], (string)$u['email'], (string)$u['phone_number'], $p12, $password, $issuer_name, false, true, $numer);
}

/**
 * Zmiana hasła do pliku .p12: ten sam certyfikat i klucz, nowe hasło. Wymaga aktualnego pliku
 * i obecnego hasła (klucz prywatny nie jest przechowywany na serwerze). Zwraca jak ti_cert_deliver()
 * albo ok=false z błędem.
 */
/**
 * Siła hasła do certyfikatu: co najmniej 16 znaków i co najmniej 3 z 4 rodzajów znaków
 * (mała litera, wielka litera, cyfra, znak specjalny). Zwraca komunikat błędu albo null.
 */
function ti_cert_password_error(string $p): ?string {
    if (mb_strlen($p) < 16) return 'Hasło musi mieć co najmniej 16 znaków.';
    $klasy = (preg_match('/[a-zęóąśłżźćń]/u', $p) ? 1 : 0) + (preg_match('/[A-ZĘÓĄŚŁŻŹĆŃ]/u', $p) ? 1 : 0)
           + (preg_match('/\d/', $p) ? 1 : 0) + (preg_match('/[^\p{L}\d]/u', $p) ? 1 : 0);
    if ($klasy < 3) return 'Hasło musi zawierać co najmniej 3 rodzaje znaków: małe litery, wielkie litery, cyfry, znaki specjalne.';
    return null;
}

function ti_cert_set_own_password(int $uid, string $new_password): array {
    if (!ti_cert_generation_open()) return ['ok' => false, 'error' => ti_cert_generation_info()];
    $u = db_one("SELECT id, name, email, phone_number FROM users WHERE id = ? AND is_active = 1", [$uid]);
    if (!$u) return ['ok' => false, 'error' => 'Nie ma takiego aktywnego konta.'];
    if (trim((string)$u['email']) === '') return ['ok' => false, 'error' => 'W koncie brakuje adresu e-mail.'];
    if ($blad = ti_cert_password_error($new_password)) return ['ok' => false, 'error' => $blad];

    // Hasło wybiera prowadzący, więc wystawiamy nowy certyfikat z tym hasłem. Stare odwołujemy —
    // stary plik przestaje działać, nawet jeśli ktoś ma jego hasło.
    // Najpierw odwołanie starych, potem wystawienie nowego — nowy nigdy nie trafi na listę odwołanych.
    foreach (x509_list_for_user($uid) as $c) {
        if (empty($c['revoked_at'])) x509_revoke((int)$c['id']);
    }
    $gen = x509_generate_for_user($uid, $new_password, (string)$u['name'], 'Fundacja FEER');
    $p12 = (string)($gen['p12_data'] ?? '');
    if ($p12 === '') return ['ok' => false, 'error' => 'Nie udało się wygenerować certyfikatu. Stare certyfikaty zostały odwołane — wystaw nowy.'];

    // Plik idzie mailem; hasło zna tylko prowadzący, więc nigdzie go nie wysyłamy.
    $numer = x509_assign_number($uid);
    return ti_cert_deliver($uid, (string)$u['name'], (string)$u['email'], (string)$u['phone_number'], $p12, '', (string)$u['name'], true, false, $numer);
}
