<?php
declare(strict_types=1);

namespace Feer\Szo\LoginSentry;

/**
 * modules/login_sentry/lib/LoginSentryTemplates.php — teksty LoginSentry („Staszek”): ogłoszenie dla zespołu
 * i powiadomienia dla administratora (HTML + tekst). SMS-ów nie ma (decyzja 2026-11-15).
 *
 * Zmienne powiadomień: {{name}}, {{email}}, {{score}}, {{reasons}} (lista „- …”), {{ips}}, {{when}} (Europe/Warsaw),
 * {{outcome}} (lista „- …”), {{panel_url}}. Teksty przeszły przez humanizer: bez myślników, bez urzędowych ozdobników.
 * Ogłoszenie leży też w docs/login_sentry/mail_do_zespolu.md, żeby dało się je wkleić do zwykłej poczty.
 */
final class LoginSentryTemplates
{
    public const HELP_URL = 'https://feer.org.pl/uslugi-it';
    public const SIGNATURE = "Ziemowit Gil\nZespół IT Fundacji FEER";

    public const ANNOUNCE_SUBJECT = 'Poznajcie Staszka, który pilnuje naszych logowań';
    public const ANNOUNCE_BODY = <<<'TXT'
Cześć,

do ekipy IT dołączył Staszek. To skrypt, który co 6 godzin przegląda logowania do SZO i do Systemu Tożsamość (Logto) i sprawdza, czy ktoś obcy nie wszedł na czyjeś konto.

Po co nam Staszek?

Jedno konto FEER otwiera dziś wiele drzwi: SZO, Nextcloud, pocztę i inne usługi. Jeśli ktoś pozna Twoje hasło, na przykład z wycieku z innego serwisu, może zalogować się spokojnie z drugiego końca kraju, a my dowiemy się o tym za późno. Część ataków zatrzymuje Cloudflare, który stoi przed naszymi stronami. Staszek patrzy na to, co przeszło dalej, czyli na udane logowania.

Jak to działa?

- Co 6 godzin Staszek sprawdza adresy IP i przeglądarki, z których ktoś się zalogował.
- Porównuje je z tym, skąd zwykle się logujesz. Każda osoba ma swój wzorzec, który Staszek sam buduje z historii logowań.
- Za nietypowe rzeczy przyznaje punkty: seria błędnych haseł tuż przed udanym logowaniem, nowa sieć i nowa przeglądarka naraz, logowania z kilku miejsc w krótkim czasie, logowanie przez skrypt zamiast przeglądarki.
- Gdy punktów jest trochę, pisze do administratora i nic więcej nie robi.
- Gdy punktów jest dużo, zawiesza konto w SZO i w Systemie Tożsamość i od razu daje znać administratorowi. Administrator sprawdza sprawę i jednym kliknięciem przywraca dostęp, jeśli to był fałszywy alarm.

Samo logowanie z nowego miejsca, na przykład z hotelu albo z telefonu u rodziny, nie wystarczy do blokady.

Staszek ma ludzkie imię tak jak Mietek, Bronek i Gosposia. Łatwiej powiedzieć „Staszek zablokował mi konto” niż podawać nazwę skryptu, a od razu wiadomo, do kogo z IT pisać. Każdy z nich robi jedną rzecz i robi ją po cichu w tle.

Czy musisz coś zrobić?

Nie. Loguj się tak jak zawsze. Jeśli kiedyś nie wpuści Cię SZO ani Nextcloud, a hasło na pewno jest dobre, napisz do nas. Sprawdzimy, co zobaczył Staszek, i odblokujemy konto.

Dobrze też mieć hasło, którego nie używasz nigdzie indziej. Jak je zmienić, przeczytasz w portalu pomocy IT: https://feer.org.pl/uslugi-it

Pozdrawiam
Ziemowit Gil
Zespół IT Fundacji FEER
TXT;

    public const SUSPEND_SUBJECT = 'Staszek zawiesił konto {{email}}: podejrzane logowanie';
    public const SUSPEND_BODY = <<<'TXT'
Cześć,

Staszek zawiesił konto {{name}} ({{email}}). Logowanie z ostatnich godzin wygląda tak, jakby ktoś inny znał hasło tej osoby. Wynik: {{score}} pkt.

Co zauważył:
{{reasons}}

Adresy IP: {{ips}}
Sprawdzone: {{when}}

Co zrobił:
{{outcome}}

Co dalej:
- Zadzwoń albo napisz do tej osoby innym kanałem niż poczta FEER i zapytaj, czy to ona się logowała.
- Jeśli tak, kliknij w panelu „To była ta osoba, cofnij”. Konto wróci, a te sieci Staszek zapamięta jako zaufane.
- Jeśli nie, zostaw blokadę, ustaw nowe hasło w Systemie Tożsamość i sprawdź, co zmieniło się na koncie.

Panel: {{panel_url}}

Ziemowit Gil
Zespół IT Fundacji FEER
TXT;

    public const ESCALATE_SUBJECT = 'Staszek zawiesił konto {{email}}: dziś już drugie podejrzane logowanie';
    public const ESCALATE_INTRO = "Cześć,\n\nwcześniej dziś Staszek pisał o nietypowym logowaniu na konto {{name}} ({{email}}). Doszły nowe sygnały, więc konto jest teraz zawieszone. Wynik: {{score}} pkt.";

    public const WARN_SUBJECT = 'Staszek: nietypowe logowanie na konto {{email}}';
    public const WARN_BODY = <<<'TXT'
Cześć,

na koncie {{name}} ({{email}}) Staszek zauważył logowanie, które odbiega od zwykłych. To za mało na blokadę ({{score}} pkt), więc konto działa normalnie.

Co zauważył:
{{reasons}}

Adresy IP: {{ips}}
Sprawdzone: {{when}}

Jeśli coś Ci tu nie pasuje, zapytaj tę osobę, czy to ona. Gdy wszystko jest w porządku, kliknij w panelu „To była ta osoba”, a Staszek zapamięta tę sieć i przeglądarkę. Jeśli coś jest nie tak, konto zawiesisz w panelu Systemu Tożsamość.

Panel: {{panel_url}}

Ziemowit Gil
Zespół IT Fundacji FEER
TXT;

    /** Krótki opis powodu do raportu, panelu i maila. */
    public static function reasonLabel(string $key, mixed $v): string
    {
        $v = is_scalar($v) ? (string)$v : '';
        return match ($key) {
            'blocked_net' => 'adres z sieci oznaczonej jako podejrzana (' . $v . ')',
            'fail_burst' => $v . ' błędnych haseł tuż przed udanym logowaniem',
            'foreign_country' => 'logowanie z kraju ' . $v,
            'country_hop' => 'zmiana kraju w ciągu 2 godzin (' . $v . ')',
            'many_networks' => 'logowania z ' . $v . ' różnych sieci w ciągu kilku godzin',
            'new_network' => 'sieć, z której ta osoba wcześniej się nie logowała (' . $v . ')',
            'new_browser' => 'nowa przeglądarka lub urządzenie (' . $v . ')',
            'script_client' => 'logowanie przez skrypt, nie przez przeglądarkę (' . $v . ')',
            'night' => 'logowanie w nocy (' . $v . ')',
            'trusted_net' => 'zaufana sieć ' . $v,
            'ip_cloudflare' => 'adres Cloudflare zamiast prawdziwego (' . $v . ')',
            'ip_private', 'ip_invalid' => 'adres lokalny albo pusty (' . $v . ')',
            'admin_protected' => 'konto administratora: tylko powiadomienie, bez blokady',
            'suspend_limit' => 'limit ' . $v . ' blokad na przebieg wyczerpany, tylko powiadomienie',
            default => $key . ($v !== '' ? ' (' . $v . ')' : ''),
        };
    }

    public static function reasonLines(array $reasons): string
    {
        $lines = [];
        foreach ($reasons as $k => $v) $lines[] = '- ' . self::reasonLabel((string)$k, $v);
        return $lines ? implode("\n", $lines) : '- brak szczegółów';
    }

    /** @param array{logto:?bool, szo:?bool, errors:list<string>} $o */
    public static function outcomeLines(array $o): string
    {
        $l = [];
        if ($o['logto'] === true) $l[] = '- zawiesił konto w Systemie Tożsamość (Logto)';
        if ($o['logto'] === false) $l[] = '- NIE udało się zawiesić konta w Logto, zrób to ręcznie';
        if ($o['logto'] === null) $l[] = '- konto nie ma powiązania z Logto, tam nic nie zmienił';
        if ($o['szo'] === true) $l[] = '- zablokował konto w SZO i wylogował wszystkie sesje';
        if ($o['szo'] === false) $l[] = '- NIE udało się zablokować konta w SZO, zrób to ręcznie';
        foreach ($o['errors'] as $e) $l[] = '- błąd: ' . $e;
        return implode("\n", $l);
    }

    /** @return array{subject:string, text:string, html:string} */
    public static function adminAlert(string $level, array $vars, bool $escalate = false): array
    {
        if ($level === 'suspend') {
            $subject = $escalate ? self::ESCALATE_SUBJECT : self::SUSPEND_SUBJECT;
            $body = self::SUSPEND_BODY;
            if ($escalate) $body = self::ESCALATE_INTRO . substr($body, (int)strpos($body, "\n\nCo zauważył:"));
        } else {
            $subject = self::WARN_SUBJECT;
            $body = self::WARN_BODY;
        }
        $text = self::fill($body, $vars);
        return ['subject' => self::fill($subject, $vars), 'text' => $text, 'html' => self::html($text)];
    }

    /** @return array{subject:string, text:string, html:string} */
    public static function announcement(): array
    {
        return ['subject' => self::ANNOUNCE_SUBJECT, 'text' => self::ANNOUNCE_BODY, 'html' => self::html(self::ANNOUNCE_BODY)];
    }

    public static function fill(string $tpl, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) $map['{{' . $k . '}}'] = (string)$v;
        return strtr($tpl, $map);
    }

    /** HTML z tekstu: akapity, listy „- ”, adresy jako linki, wszystko escapowane (jak WardenTemplates::html). */
    public static function html(string $text): string
    {
        $paras = preg_split("/\n{2,}/", trim($text)) ?: [];
        $out = '';
        foreach ($paras as $p) {
            $lines = explode("\n", $p);
            $items = array_values(array_filter($lines, fn($l) => str_starts_with($l, '- ')));
            if ($items && count($items) >= count($lines) - 1 && (count($items) === count($lines) || !str_starts_with($lines[0], '- '))) {
                $head = str_starts_with($lines[0], '- ') ? '' : $lines[0];
                if ($head !== '') $out .= '<p style="margin:0 0 6px">' . self::inline($head) . "</p>\n";
                $out .= "<ul style=\"margin:0 0 14px;padding-left:22px\">\n";
                foreach ($items as $l) $out .= '<li>' . self::inline(substr($l, 2)) . "</li>\n";
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
