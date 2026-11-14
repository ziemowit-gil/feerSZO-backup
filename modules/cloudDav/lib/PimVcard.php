<?php
declare(strict_types=1);

require_once __DIR__ . '/PimIcal.php';

/**
 * modules/cloudDav/lib/PimVcard.php — kontakty z Microsoft Graph → vCard 3.0 do książki adresowej w Nextcloud.
 *
 * UID = „outlook-” + sha1(id kontaktu w Graph), więc ponowny import nadpisuje ten sam kontakt (adres karty w chmurze = UID.vcf).
 * Folder kontaktów z Outlooka trafia do CATEGORIES (książka adresowa w chmurze jest jedna). Zdjęć nie przenosimy.
 */
final class PimVcard
{
    public static function uid(array $c): string
    {
        return 'outlook-' . sha1((string)($c['id'] ?? json_encode($c)));
    }

    public static function card(array $c, string $folder = ''): string
    {
        $e = [PimIcal::class, 'esc'];
        $comp = static fn(string $s): string => str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $s);
        $given = trim((string)($c['givenName'] ?? ''));
        $sur = trim((string)($c['surname'] ?? ''));
        $mid = trim((string)($c['middleName'] ?? ''));
        $emails = [];
        foreach ((array)($c['emailAddresses'] ?? []) as $m) {
            $a = trim((string)($m['address'] ?? ''));
            if ($a !== '' && !in_array(strtolower($a), array_map('strtolower', $emails), true)) $emails[] = $a;
        }
        $fn = trim((string)($c['displayName'] ?? '')) ?: trim($given . ' ' . $sur) ?: trim((string)($c['companyName'] ?? '')) ?: ($emails[0] ?? '(bez nazwy)');
        $l = ['BEGIN:VCARD', 'VERSION:3.0', 'PRODID:-//FEER SZO//Import z Microsoft 365//PL', 'UID:' . self::uid($c), 'FN:' . $e($fn),
              'N:' . $comp($sur) . ';' . $comp($given) . ';' . $comp($mid) . ';' . $comp(trim((string)($c['title'] ?? ''))) . ';'];
        if (($nick = trim((string)($c['nickName'] ?? ''))) !== '') $l[] = 'NICKNAME:' . $e($nick);
        $org = trim((string)($c['companyName'] ?? ''));
        $dep = trim((string)($c['department'] ?? ''));
        if ($org !== '' || $dep !== '') $l[] = 'ORG:' . $comp($org) . ($dep !== '' ? ';' . $comp($dep) : '');
        if (($job = trim((string)($c['jobTitle'] ?? ''))) !== '') $l[] = 'TITLE:' . $e($job);
        foreach ($emails as $i => $a) $l[] = 'EMAIL;TYPE=INTERNET' . ($i === 0 ? ',PREF' : '') . ':' . $e($a);
        $tel = static function (string $num, string $type) use (&$l, $e): void { $num = trim($num); if ($num !== '') $l[] = 'TEL;TYPE=' . $type . ':' . $e($num); };
        $tel((string)($c['mobilePhone'] ?? ''), 'CELL');
        foreach ((array)($c['businessPhones'] ?? []) as $p) $tel((string)$p, 'WORK,VOICE');
        foreach ((array)($c['homePhones'] ?? []) as $p) $tel((string)$p, 'HOME,VOICE');
        foreach (['businessAddress' => 'WORK', 'homeAddress' => 'HOME', 'otherAddress' => 'OTHER'] as $k => $type) {
            $a = (array)($c[$k] ?? []);
            $parts = array_map(fn($f) => trim((string)($a[$f] ?? '')), ['street', 'city', 'state', 'postalCode', 'countryOrRegion']);
            if (implode('', $parts) === '') continue;
            $l[] = 'ADR;TYPE=' . $type . ':;;' . implode(';', array_map($comp, $parts));
        }
        $bday = (string)($c['birthday'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $bday, $m) && (int)$m[1] > 1700) $l[] = 'BDAY:' . $m[1] . '-' . $m[2] . '-' . $m[3];
        if (($url = trim((string)($c['businessHomePage'] ?? ''))) !== '' && preg_match('~^https?://\S+$~i', $url)) $l[] = 'URL:' . $url;
        if (($note = trim((string)($c['personalNotes'] ?? ''))) !== '') $l[] = 'NOTE:' . $e(mb_substr($note, 0, 10000));
        $cats = array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)($c['categories'] ?? []))));
        if ($folder !== '') $cats[] = $folder;
        if ($cats) $l[] = 'CATEGORIES:' . implode(',', array_map($e, array_values(array_unique($cats))));
        if (!empty($c['lastModifiedDateTime'])) {
            try { $l[] = 'REV:' . (new \DateTimeImmutable((string)preg_replace('/\.\d+/', '', (string)$c['lastModifiedDateTime'])))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'); } catch (\Throwable $x) {}
        }
        $l[] = 'END:VCARD';
        return PimIcal::lines($l);
    }
}
