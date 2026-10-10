<?php
/**
 * includes/ti_student_onboarding.php — mail onboardingowy dla kursanta, per konto.
 * Hasła nie wysyłamy (system go nie przechowuje w jawnej postaci) — w mailu jest login
 * i informacja, skąd wziąć hasło (od prowadzącego).
 */
require_once dirname(__DIR__) . '/includes/mail_queue.php';

/** Konta kursantów z danymi kontaktowymi klienta (do listy i wysyłki). */
function ti_student_accounts_for_onboarding(): array {
    return db_all(
        "SELECT a.id, a.login, a.is_active, a.client_id, c.name AS client_name, c.email AS client_email,
                c.phone AS client_phone, c.consent AS client_consent,
                (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.client_id = a.client_id AND e.status = 'active') AS n_grup
           FROM k30_ti_student_accounts a
           JOIN k30_clients c ON c.id = a.client_id
          ORDER BY c.name"
    );
}

/** Treść maila onboardingowego dla jednego konta. */
/** Aktywne grupy klienta: numer (group_code, a gdy go nie ma — numer kursu) i nazwa. */
function ti_student_onboarding_grupy(int $client_id): array {
    // Link online: najpierw link zapisu kursanta, a gdy go brak — stały link grupy (jak w ti_online.php).
    return db_all(
        "SELECT c.id, c.name, c.group_code,
                CASE WHEN e.zoom_meeting_url != '' THEN e.zoom_meeting_url ELSE c.default_meeting_url END AS online_url,
                u.name AS instructor_name, u.email AS instructor_email
           FROM k30_ti_enrollments e
           JOIN k30_ti_courses c ON c.id = e.course_id
           LEFT JOIN users u ON u.id = c.instructor_id
          WHERE e.client_id = ? AND e.status = 'active'
            AND c.group_code <> ''   -- grupy bez numeru to dawne (# numer kursu) — poza mailingiem
          ORDER BY c.name",
        [$client_id]
    );
}

function ti_student_onboarding_mail(string $name, string $login, array $grupy = []): array {
    $login_url = 'https://kursant.feer.org.pl/login.php';
    $org = function_exists('org_setting') && org_setting('org_name') !== '' ? org_setting('org_name') : (defined('ORG_NAME') ? ORG_NAME : '');
    // Powitanie tylko imieniem (pierwszy wyraz nazwy klienta).
    $imie = trim((string)preg_split('/\s+/u', trim($name))[0]);
    $txt = ($org !== '' ? "Instytucja: {$org}\n\n" : '')
         . "Dzień dobry, {$imie},\n\n"
         . ($grupy ? "Twoje grupy:\n" . implode("\n", array_map(function ($g) {
               $linia = '- ' . ($g['group_code'] !== '' ? $g['group_code'] : '#' . (int)$g['id']) . ' — ' . $g['name'];
               if (trim((string)($g['instructor_name'] ?? '')) !== '') {
                   $linia .= "\n  Prowadzący: " . trim((string)$g['instructor_name'])
                           . (trim((string)($g['instructor_email'] ?? '')) !== '' ? ' (' . trim((string)$g['instructor_email']) . ')' : '');
               }
               if (trim((string)($g['online_url'] ?? '')) !== '') $linia .= "\n  Zajęcia online (link): " . trim((string)$g['online_url']);
               return $linia;
           }, $grupy)) . "\n\n" : '')
         . "Twoje konto w panelu kursanta jest gotowe. Tam zobaczysz swoje zajęcia, plan i rozliczenia.\n\n"
         . "Jak zacząć:\n"
         . "1. Zaloguj się: {$login_url}\n"
         . "   Login: {$login}\n"
         . "   Hasło dostałeś od prowadzącego. Jeśli go nie masz albo nie działa, poproś prowadzącego o nowe.\n"
         . "2. Po zalogowaniu zobaczysz swój plan i zajęcia.\n\n"
         . "Hasła nie wysyłamy mailem. Nie przekazuj go innym osobom.\n\n"
         . "Jeśli nie uzupełniłeś zgody na przetwarzanie danych, zrób to po zalogowaniu w ustawieniach.\n"
         . "\n"
         . "Problemy z logowaniem zgłoś kierownikowi jednostki. Pozostałe pytania kieruj do prowadzącego swojej grupy.\n\n"
         . "--\nWiadomość automatyczna. Prosimy na nią nie odpowiadać.";
    $html = '<p>' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</p>';
    return [$txt, $html];
}

/**
 * Wysyła mail onboardingowy na adres klienta konta. Zwraca 'sent', 'no_email' albo 'failed'.
 */
function ti_student_onboarding_send(int $account_id): string {
    $a = db_one(
        "SELECT a.id, a.login, a.client_id, c.name AS client_name, c.email AS client_email
           FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id = a.client_id
          WHERE a.id = ? AND a.is_active = 1",
        [$account_id]
    );
    if (!$a) return 'failed';
    $email = trim((string)$a['client_email']);
    if ($email === '') return 'no_email';

    [$txt, $html] = ti_student_onboarding_mail((string)$a['client_name'], (string)$a['login'],
        ti_student_onboarding_grupy((int)$a['client_id']));

    // Adresy spoza @feer.org.pl: wysyłka tylko w godzinach 7:00–22:00; poza nimi — na 7:00 (następnego dnia, gdy po 22:00).
    $scheduled = '';
    if (!str_ends_with(strtolower($email), '@feer.org.pl')) {
        $h = (int)date('G');
        if ($h < 7 || $h >= 22) {
            // Kolejka porównuje czas z datetime('now') (UTC), więc 7:00 czasu polskiego zapisujemy jako UTC.
            $pl = new DateTime($h >= 22 ? 'tomorrow 07:00' : 'today 07:00', new DateTimeZone('Europe/Warsaw'));
            $pl->setTimezone(new DateTimeZone('UTC'));
            $scheduled = $pl->format('Y-m-d H:i:s');
        }
    }

    $mail_id = mail_queue_add($email, (string)$a['client_name'],
        'Witamy w panelu kursanta – jak zacząć', $html, $txt, 'ti_student_onboarding', $account_id,
        $scheduled, $scheduled === '');
    if ($scheduled !== '') return 'scheduled';
    $status = (string)db_one("SELECT status FROM mail_queue WHERE id = ?", [$mail_id])['status'];
    return $status === 'sent' ? 'sent' : 'failed';
}
