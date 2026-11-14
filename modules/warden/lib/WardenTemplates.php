<?php
declare(strict_types=1);

namespace Feer\Szo\Warden;

/**
 * modules/warden/lib/WardenTemplates.php — treść wiadomości WARDEN (2x e-mail + 1x SMS).
 *
 * Zmienne: {{first_name}}, {{deadline_date}} (d.m.Y, czas Europe/Warsaw), {{login_url}}, {{inactivity}} (np. „dwóch miesięcy”),
 * {{inactivity_days}} (liczba dni progu). Próg ustawia administrator (panel, domyślnie 30 dni; decyzja 2026-11-15).
 * Treść e-maili przeszła przez humanizer (polecenie 2026-11-14): bez myślników, bez urzędowych ozdobników.
 * SMS: tylko ASCII, najwyżej 160 znaków (pilnuje AsciiSms::prepare()).
 */
final class WardenTemplates
{
    public const HELP_URL = 'https://feer.org.pl/uslugi-it';

    public const EMAIL_1_SUBJECT = 'Brak aktywności na Twoim koncie w Systemie Tożsamość (IAM) Fundacji FEER';
    public const EMAIL_1_BODY = <<<'TXT'
Cześć {{first_name}},

od ponad {{inactivity}} nie logowałeś/aś się do Systemu Tożsamość (IAM) Fundacji FEER. Przez to konto wchodzisz m.in. do SZO i do Nextclouda.

Jeśli nadal potrzebujesz dostępu, zaloguj się do {{deadline_date}}:
{{login_url}}

Nieużywane konta wyłączamy ze względów bezpieczeństwa i żeby utrzymać porządek w naszych zasobach.

Nie pamiętasz hasła? Zresetujesz je na stronie logowania. Instrukcje znajdziesz też w portalu pomocy IT: https://feer.org.pl/uslugi-it

Jeśli Twój udział w projektach Fundacji już się zakończył i konto nie jest Ci potrzebne, nic nie rób. Po {{deadline_date}} wyłączymy je automatycznie.

Masz pytania albo kłopot z logowaniem? Odpowiedz na tę wiadomość.

Pozdrawiam
Ziemowit Gil
Zespół IT Fundacji FEER
TXT;

    public const EMAIL_2_SUBJECT = 'Za 3 dni wyłączymy Twoje konto w Systemie Tożsamość (IAM) Fundacji FEER';
    public const EMAIL_2_BODY = <<<'TXT'
Cześć {{first_name}},

kilka dni temu pisaliśmy, że od ponad {{inactivity}} nie logowałeś/aś się do Systemu Tożsamość (IAM) Fundacji FEER. Od tamtej pory nie widzimy logowania ani w SZO, ani w innych usługach Fundacji.

Jeśli nie zalogujesz się do {{deadline_date}}, wyłączymy konto, a z nim dostęp do SZO i Nextclouda. Wystarczy jedno logowanie:
{{login_url}}

Hasło zresetujesz na stronie logowania, a instrukcje są w portalu pomocy IT: https://feer.org.pl/uslugi-it

Jeśli konto nie jest Ci już potrzebne, nic nie rób.

Masz pytania? Odpowiedz na tę wiadomość.

Pozdrawiam
Ziemowit Gil
Zespół IT Fundacji FEER
TXT;

    /** ASCII, bez polskich znaków. Z adresem logowania do ~30 znaków mieści się w 160. */
    public const SMS_BODY = 'FEER IT: jutro wylaczymy Twoje konto IAM (brak logowania od {{inactivity_days}} dni). Aby je zachowac, zaloguj sie do {{deadline_date}}: {{login_url}}';

    public const ANNOUNCE_SUBJECT = 'Poznajcie Mietka, nowego strażnika kont w naszym systemie';
    public const ANNOUNCE_BODY = <<<'TXT'
Cześć,

uruchomiliśmy skrypt o imieniu Mietek. Pilnuje nieużywanych kont, żeby w systemie był porządek, a dane Fundacji były bezpieczne.

Po co nam Mietek?

Od kiedy nasze narzędzia działają na własnej infrastrukturze, jedno konto otwiera wiele miejsc, od SZO po pliki w Nextcloudzie. Po zakończeniu umowy konto i tak wyłącza się samo. Zdarza się jednak, że ktoś ma jeszcze ważną umowę, a od dawna z tych narzędzi nie korzysta.

Kłopot w tym, że zapomniane konto łatwo przejąć. Wystarczy proste hasło albo hasło, które wyciekło z innego serwisu, i ktoś obcy może przeglądać nasze dokumenty, a nikt tego nie zauważy. Mietek pilnuje, żeby do zasobów FEER miały dostęp tylko osoby, które z nich korzystają. Przy okazji pomaga nam spełniać wymogi RODO, bo nie musimy co kilka tygodni ręcznie przeglądać listy użytkowników.

Jak to działa?

Raz dziennie Mietek sprawdza, kiedy ostatnio logowałeś/aś się do SZO albo przez System Tożsamość (np. do Nextclouda). Wystarczy jedno z tych miejsc.

Jeśli przez {{inactivity_days}} dni nie zalogujesz się nigdzie, Mietek zacznie siedmiodniowe odliczanie. W tym czasie dostaniesz:
- pierwszego dnia e-mail z przypomnieniem,
- czwartego dnia drugi e-mail (3 dni przed blokadą),
- szóstego dnia SMS, gdyby e-maile zginęły w skrzynce.

Jeśli w tym tygodniu zalogujesz się do SZO albo Nextclouda, odliczanie się kończy i konto zostaje aktywne. Jeśli logowania nie będzie, konto zostanie tymczasowo zawieszone.

Czy musisz coś zrobić?

Jeśli regularnie zaglądasz do SZO albo Nextclouda, nie. Mietek działa w tle.

Jeśli wrócisz do nas po dłuższej przerwie i konto będzie zablokowane, napisz do nas, a odblokujemy je od ręki.

Pomoc przy logowaniu znajdziesz na https://feer.org.pl/uslugi-it

Pozdrawiamy
FEER
TXT;

    /** Opis progu w treści e-maila: 30 → „miesiąca”, 60 → „dwóch miesięcy”, 90 → „trzech miesięcy”, inaczej „N dni”. */
    public static function inactivityLabel(int $days): string
    {
        return match ($days) { 30 => 'miesiąca', 60 => 'dwóch miesięcy', 90 => 'trzech miesięcy', default => $days . ' dni' };
    }

    /** @return array{subject:string, text:string, html:string} */
    public static function announcement(array $vars): array
    {
        $text = self::fill(self::ANNOUNCE_BODY, $vars);
        return ['subject' => self::ANNOUNCE_SUBJECT, 'text' => $text, 'html' => self::html($text)];
    }

    /** @return array{subject:string, text:string, html:string} */
    public static function email(int $stage, array $vars): array
    {
        [$subject, $body] = match ($stage) {
            1 => [self::EMAIL_1_SUBJECT, self::EMAIL_1_BODY],
            2 => [self::EMAIL_2_SUBJECT, self::EMAIL_2_BODY],
            default => throw new \InvalidArgumentException('Nieznany etap e-maila: ' . $stage),
        };
        $text = self::fill($body, $vars);
        return ['subject' => self::fill($subject, $vars), 'text' => $text, 'html' => self::html($text)];
    }

    public static function sms(array $vars): string
    {
        return self::fill(self::SMS_BODY, $vars);
    }

    public static function fill(string $tpl, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) $map['{{' . $k . '}}'] = (string)$v;
        return strtr($tpl, $map);
    }

    /** Wersja HTML z tekstu: akapity, adresy jako linki, wszystko escapowane. */
    public static function html(string $text): string
    {
        $paras = preg_split("/\n{2,}/", trim($text)) ?: [];
        $out = '';
        foreach ($paras as $p) {
            $lines = explode("\n", $p);
            $isList = count($lines) > 1 && count(array_filter(array_slice($lines, 1), fn($l) => str_starts_with($l, '- '))) === count($lines) - 1;
            if ($isList) {
                $out .= '<p style="margin:0 0 6px">' . self::inline($lines[0]) . "</p>\n<ul style=\"margin:0 0 14px;padding-left:22px\">\n";
                foreach (array_slice($lines, 1) as $l) $out .= '<li>' . self::inline(substr($l, 2)) . "</li>\n";
                $out .= "</ul>\n";
                continue;
            }
            $out .= '<p style="margin:0 0 14px">' . nl2br(self::inline($p), false) . "</p>\n";
        }
        return '<div lang="pl" style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1f2937">' . "\n" . $out . '</div>';
    }

    private static function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string)preg_replace('~https?://[^\s<]+~u', '<a href="$0">$0</a>', $s);
    }
}
