<?php
declare(strict_types=1);

/**
 * modules/gosposia/logic/GosposiaNotify.php — powiadomienia e-mail dla osoby korzystającej z Gosposi (polecenie 2026-11-14).
 *
 * Trzy wiadomości przez kolejkę poczty (includes/mail_queue.php, context_type 'gosposia') na adres konta SZO:
 *   started()  — Gosposia zaczęła przegląd poczty (raz na POST /przeglad, nie przy GET /postep);
 *   scanned()  — przegląd skończony: ilu nadawców i ile wiadomości znalazła;
 *   trashed()  — posprzątane: ile wiadomości i od ilu nadawców trafiło do Kosza + jak je odzyskać.
 * Tylko liczby: bez tematów, adresów nadawców i treści wiadomości. Błąd wysyłki nigdy nie przerywa pracy Gosposi.
 */
final class GosposiaNotify
{
    public static function started(array $user, string $mailbox): void
    {
        self::send($user, 'Gosposia sprawdza Twoją pocztę',
            '<p>Gosposia zaczęła sprawdzać skrzynkę <strong>' . h($mailbox) . '</strong>.</p>'
          . '<p>Szuka reklam i newsletterów. Niczego sama nie usuwa: na końcu pokaże listę, a Ty zdecydujesz, co przenieść do Kosza.</p>'
          . '<p>Gdy skończy, dostaniesz krótkie podsumowanie.</p>');
    }

    public static function scanned(array $user, string $mailbox, array $res): void
    {
        $senders = count($res['senders'] ?? []);
        $msgs = array_sum(array_map(fn($s) => (int)($s['count'] ?? 0), $res['senders'] ?? []));
        $checked = (int)($res['stats']['scanned'] ?? 0);
        $body = '<p>Gosposia skończyła sprawdzać skrzynkę <strong>' . h($mailbox) . '</strong>.</p>'
              . '<p>Przejrzała ' . self::n($checked, 'wiadomość', 'wiadomości', 'wiadomości') . '.</p>';
        $body .= $senders > 0
            ? '<p>Znalazła <strong>' . self::n($senders, 'nadawcę', 'nadawców', 'nadawców') . '</strong> reklam lub newsletterów, razem <strong>'
              . self::n($msgs, 'wiadomość', 'wiadomości', 'wiadomości') . '</strong>.</p>'
              . '<p>Wejdź do Gosposi, sprawdź listę i zdecyduj, co przenieść do Kosza.</p>'
            : '<p>Nie znalazła reklam ani newsletterów do sprzątania. Twoja poczta jest w porządku.</p>';
        self::send($user, 'Gosposia skończyła przegląd poczty', $body);
    }

    public static function trashed(array $user, string $mailbox, array $res): void
    {
        $moved = (int)($res['moved'] ?? 0);
        $senders = (int)($res['senders'] ?? 0);
        $folder = trim((string)($res['folder'] ?? '')) ?: 'Kosz';
        self::send($user, 'Gosposia posprzątała Twoją pocztę',
            '<p>Gosposia przeniosła do folderu <strong>' . h($folder) . '</strong> '
          . self::n($moved, 'wiadomość', 'wiadomości', 'wiadomości') . ' od '
          . self::n($senders, 'nadawcy', 'nadawców', 'nadawców') . ' ze skrzynki <strong>' . h($mailbox) . '</strong>.</p>'
          . '<p><strong>Nic nie zostało usunięte na zawsze.</strong> Jeśli potrzebujesz którejś wiadomości, otwórz folder '
          . h($folder) . ' w poczcie i przenieś ją z powrotem do Odebranych. Pamiętaj, że Kosz może być co jakiś czas opróżniany.</p>');
    }

    private static function send(array $user, string $subject, string $body): void
    {
        $to = trim((string)($user['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        $link = rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . '/pomocne/gosposia';
        $html = '<div style="font:15px/1.5 Arial,sans-serif;color:#1f2328">'
              . '<p>Dzień dobry' . (($n = trim((string)($user['name'] ?? ''))) !== '' ? ', ' . h($n) : '') . ',</p>'
              . $body
              . '<p><a href="' . h($link) . '">Otwórz Gosposię</a></p>'
              . '<p style="color:#555;font-size:13px">To automatyczna wiadomość z SZO. Nie zawiera tematów ani treści Twoich e-maili, tylko liczby.</p>'
              . '</div>';
        try {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            mail_queue_add($to, (string)($user['name'] ?? ''), $subject, $html, '', 'gosposia', (int)($user['id'] ?? 0) ?: null);
        } catch (\Throwable $e) {
            error_log('gosposia notify: ' . $e->getMessage());
        }
    }

    /** Liczba z polską odmianą: 1 wiadomość, 2–4 wiadomości, 5 wiadomości. */
    private static function n(int $n, string $one, string $few, string $many): string
    {
        $m10 = $n % 10; $m100 = $n % 100;
        $w = $n === 1 ? $one : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? $few : $many);
        return $n . ' ' . $w;
    }
}
