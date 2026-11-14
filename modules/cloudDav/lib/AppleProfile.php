<?php
declare(strict_types=1);

/**
 * modules/cloudDav/lib/AppleProfile.php — profil konfiguracji (.mobileconfig) dla iPhone'a, iPada i Maca:
 * konto CalDAV (kalendarz) i CardDAV (kontakty) w chmurze Nextcloud, z loginem i hasłem aplikacji.
 *
 * Klucze wg dokumentacji Apple (Device Management: CalDAV, CardDAV): com.apple.caldav.account (CalDAVHostName, CalDAVUsername,
 * CalDAVPassword, CalDAVUseSSL, CalDAVPort, CalDAVPrincipalURL, CalDAVAccountDescription) i analogicznie com.apple.carddav.account.
 * Profil nie jest podpisany, więc system pokaże go jako „niezweryfikowany”; użytkownik instaluje go sam. Zawiera hasło aplikacji,
 * dlatego wysyłamy go tylko zalogowanej osobie z jej sesji i z Cache-Control: no-store.
 */
final class AppleProfile
{
    /** @param array{host:string, port:int, ssl:bool, user:string, pass:string, principal:string} $acc */
    public static function build(array $acc, string $org, string $label, string $idBase = 'pl.org.feer.szo.dav'): string
    {
        $uuid = static fn(): string => strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)));
        $id = $idBase . '.' . strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $acc['user'])) . '.' . date('YmdHis');
        $cal = [
            'CalDAVAccountDescription' => $label . ': kalendarz',
            'CalDAVHostName' => $acc['host'], 'CalDAVPort' => $acc['port'], 'CalDAVUseSSL' => $acc['ssl'],
            'CalDAVUsername' => $acc['user'], 'CalDAVPassword' => $acc['pass'], 'CalDAVPrincipalURL' => $acc['principal'],
            'PayloadDescription' => 'Kalendarz z chmury ' . $org, 'PayloadDisplayName' => $label . ': kalendarz (CalDAV)',
            'PayloadIdentifier' => $id . '.caldav', 'PayloadType' => 'com.apple.caldav.account', 'PayloadUUID' => $uuid(), 'PayloadVersion' => 1,
        ];
        $card = [
            'CardDAVAccountDescription' => $label . ': kontakty',
            'CardDAVHostName' => $acc['host'], 'CardDAVPort' => $acc['port'], 'CardDAVUseSSL' => $acc['ssl'],
            'CardDAVUsername' => $acc['user'], 'CardDAVPassword' => $acc['pass'], 'CardDAVPrincipalURL' => $acc['principal'],
            'PayloadDescription' => 'Kontakty z chmury ' . $org, 'PayloadDisplayName' => $label . ': kontakty (CardDAV)',
            'PayloadIdentifier' => $id . '.carddav', 'PayloadType' => 'com.apple.carddav.account', 'PayloadUUID' => $uuid(), 'PayloadVersion' => 1,
        ];
        $top = [
            'PayloadContent' => [$cal, $card],
            'PayloadDescription' => 'Kalendarz i kontakty z chmury ' . $org . ' (' . $acc['host'] . '). Profil zawiera hasło aplikacji; '
                . 'usuniesz je w chmurze w ustawieniach osobistych, w części Bezpieczeństwo.',
            'PayloadDisplayName' => $label . ': kalendarz i kontakty',
            'PayloadIdentifier' => $id, 'PayloadOrganization' => $org, 'PayloadRemovalDisallowed' => false,
            'PayloadType' => 'Configuration', 'PayloadUUID' => $uuid(), 'PayloadVersion' => 1,
        ];
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
             . '<plist version="1.0">' . self::value($top) . "</plist>\n";
    }

    private static function value(mixed $v): string
    {
        if (is_bool($v)) return $v ? '<true/>' : '<false/>';
        if (is_int($v)) return '<integer>' . $v . '</integer>';
        if (is_array($v)) {
            if (array_is_list($v)) return '<array>' . implode('', array_map([self::class, 'value'], $v)) . '</array>';
            $s = '<dict>';
            foreach ($v as $k => $x) $s .= '<key>' . self::esc((string)$k) . '</key>' . self::value($x);
            return $s . '</dict>';
        }
        return '<string>' . self::esc((string)$v) . '</string>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
