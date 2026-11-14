<?php
declare(strict_types=1);

namespace Feer\Szo\LoginSentry;

/**
 * modules/login_sentry/lib/IpTools.php — adresy IP i przeglądarki dla LoginSentry („Staszek”).
 *
 *   network()      → sieć, którą traktujemy jako „to samo miejsce”: IPv4 /24, IPv6 /48.
 *   inCidr()       → czy adres należy do zakresu CIDR (IPv4 i IPv6).
 *   usable()       → czy adres mówi coś o osobie: odrzuca puste, prywatne, pętlę zwrotną i serwery pośredniczące Cloudflare
 *                    (gdy SZO stoi za proxy bez mod_remoteip, w login_log lądują adresy Cloudflare zamiast prawdziwych).
 *   uaFamily()     → „Chrome / Windows”, „Safari / iOS” itd. Wersji nie bierzemy, bo zmieniają się co kilka tygodni.
 */
final class IpTools
{
    /** Zakresy Cloudflare (https://www.cloudflare.com/ips/, stan 2026-11). */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function valid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** '' gdy adres jest bezużyteczny; inaczej powód odrzucenia albo 'ok'. */
    public static function usable(string $ip): string
    {
        if (!self::valid($ip) || $ip === '0.0.0.0') return 'invalid';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return 'private';
        foreach (self::CLOUDFLARE as $c) if (self::inCidr($ip, $c)) return 'cloudflare';
        return 'ok';
    }

    public static function network(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return '';
        if (strlen($bin) === 4) {
            $p = explode('.', $ip);
            return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0/24';
        }
        $net = substr($bin, 0, 6) . str_repeat("\0", 10);
        return inet_ntop($net) . '/48';
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') return false;
        [$net, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
        $max = strlen($a) * 8;
        $bits = $bits === null ? $max : (int)$bits;
        if ($bits < 0 || $bits > $max) return false;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** @param list<string> $cidrs */
    public static function inAny(string $ip, array $cidrs): ?string
    {
        foreach ($cidrs as $c) if (self::inCidr($ip, $c)) return $c;
        return null;
    }

    public static function validCidr(string $cidr): bool
    {
        [$net, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $b = @inet_pton($net);
        if ($b === false) return false;
        if ($bits === null) return true;
        return ctype_digit($bits) && (int)$bits <= strlen($b) * 8 && (int)$bits >= (strlen($b) === 4 ? 8 : 16);
    }

    public static function uaFamily(string $ua): string
    {
        if (trim($ua) === '') return '';
        $browser = match (true) {
            (bool)preg_match('~Edg(e|A|iOS)?/~', $ua) => 'Edge',
            (bool)preg_match('~(OPR|Opera)/~', $ua) => 'Opera',
            (bool)preg_match('~Firefox/|FxiOS/~', $ua) => 'Firefox',
            (bool)preg_match('~SamsungBrowser/~', $ua) => 'Samsung',
            (bool)preg_match('~Chrome/|CriOS/~', $ua) => 'Chrome',
            (bool)preg_match('~Safari/~', $ua) => 'Safari',
            (bool)preg_match('~curl|python|Go-http|okhttp|Java/|wget|libwww|HeadlessChrome~i', $ua) => 'Skrypt',
            default => 'Inna',
        };
        $os = match (true) {
            (bool)preg_match('~iPhone|iPad|iPod~', $ua) => 'iOS',
            (bool)preg_match('~Android~', $ua) => 'Android',
            (bool)preg_match('~Windows~', $ua) => 'Windows',
            (bool)preg_match('~Mac OS X|Macintosh~', $ua) => 'macOS',
            (bool)preg_match('~CrOS~', $ua) => 'ChromeOS',
            (bool)preg_match('~Linux~', $ua) => 'Linux',
            default => 'inny system',
        };
        return $browser . ' / ' . $os;
    }
}
