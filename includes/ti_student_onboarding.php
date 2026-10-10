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
        "SELECT a.id, a.login, a.is_active, a.client_id, c.name AS client_name, c.email AS client_email
           FROM k30_ti_student_accounts a
           JOIN k30_clients c ON c.id = a.client_id
          ORDER BY c.name"
    );
}

/** Treść maila onboardingowego dla jednego konta. */
function ti_student_onboarding_mail(string $name, string $login): array {
    $login_url = rtrim(APP_URL, '/') . '/karty30/ti/login.php?tab=kursant';
    $start_url = rtrim(APP_URL, '/') . '/karty30/ti/kursant/onboarding.php';
    $txt = "Dzień dobry, {$name},\n\n"
         . "Twoje konto w panelu kursanta jest gotowe. Tam zobaczysz swoje zajęcia, plan i rozliczenia.\n\n"
         . "Jak zacząć:\n"
         . "1. Zaloguj się: {$login_url}\n"
         . "   Login: {$login}\n"
         . "   Hasło dostałeś od prowadzącego. Jeśli go nie masz albo nie działa, poproś prowadzącego o nowe.\n"
         . "2. Po zalogowaniu otwórz listę startową: {$start_url}\n"
         . "   Pokaże, czy masz uzupełnione dane kontaktowe, zgodę i zapis na grupę.\n\n"
         . "Hasła nie wysyłamy mailem. Nie przekazuj go innym osobom.\n\n"
         . "Pytania kieruj do prowadzącego swojej grupy.\n\n"
         . "--\nWiadomość automatyczna. Prosimy na nią nie odpowiadać.";
    $html = '<p>' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</p>';
    return [$txt, $html];
}

/**
 * Wysyła mail onboardingowy na adres klienta konta. Zwraca 'sent', 'no_email' albo 'failed'.
 */
function ti_student_onboarding_send(int $account_id): string {
    $a = db_one(
        "SELECT a.id, a.login, c.name AS client_name, c.email AS client_email
           FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id = a.client_id
          WHERE a.id = ? AND a.is_active = 1",
        [$account_id]
    );
    if (!$a) return 'failed';
    $email = trim((string)$a['client_email']);
    if ($email === '') return 'no_email';

    [$txt, $html] = ti_student_onboarding_mail((string)$a['client_name'], (string)$a['login']);
    $mail_id = mail_queue_add($email, (string)$a['client_name'],
        'Witamy w panelu kursanta – jak zacząć', $html, $txt, 'ti_student_onboarding', $account_id, '', true);
    $status = (string)db_one("SELECT status FROM mail_queue WHERE id = ?", [$mail_id])['status'];
    return $status === 'sent' ? 'sent' : 'failed';
}
