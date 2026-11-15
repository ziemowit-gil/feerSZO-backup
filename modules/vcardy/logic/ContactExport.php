<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/cloudDav/lib/GraphPim.php';
require_once dirname(__DIR__, 2) . '/cloudDav/lib/PimVcard.php';
require_once dirname(__DIR__, 2) . '/cloudDav/logic/PimImport.php';

/**
 * modules/vcardy/logic/ContactExport.php — kontakty z Outlooka (Microsoft 365) do pliku VCF (vCard 3.0) albo CSV (nagłówki Outlooka).
 *
 * Źródło 1: Graph app-only przez GraphPim (Contacts.Read, ten sam token co przeniesienie do chmury w modules/cloudDav).
 * Źródło 2 (gdy Microsoft 365 nie odpowiada, np. po wyłączeniu 20.11.2026): zaszyfrowana kopia contacts.vcf zrobiona przez PimImport
 * (uploads/pim_m365/…). Z kopii dajemy VCF, a CSV składamy z kart vCard (prosty parser poniżej).
 * Konto Microsoft: tylko własne (PimImport::upnsFor: users.m365_login albo adres w domenie M365). Plik nigdy nie trafia na dysk.
 */
final class ContactExport
{
    /** Bezpiecznik: 300 stron po 100 kontaktów na folder. */
    private const MAX_PAGES = 300;

    /** Nagłówki CSV w formacie Outlooka. Rozpoznają je dPoczta (Roundcube), Gmail, Outlook.com i Thunderbird. */
    public const CSV_HEAD = ['First Name', 'Middle Name', 'Last Name', 'Title', 'Nickname', 'E-mail Address', 'E-mail 2 Address', 'E-mail 3 Address',
        'Mobile Phone', 'Business Phone', 'Business Phone 2', 'Home Phone', 'Home Phone 2', 'Company', 'Department', 'Job Title',
        'Business Street', 'Business City', 'Business State', 'Business Postal Code', 'Business Country/Region',
        'Home Street', 'Home City', 'Home State', 'Home Postal Code', 'Home Country/Region',
        'Other Street', 'Other City', 'Other State', 'Other Postal Code', 'Other Country/Region',
        'Birthday', 'Web Page', 'Notes', 'Categories'];

    /** Konta Microsoft 365 zalogowanej osoby. @return list<string> */
    public static function upns(array $me): array
    {
        $u = db_one("SELECT id, email, m365_login FROM users WHERE id=?", [(int)($me['id'] ?? 0)]) ?: [];
        return PimImport::upnsFor($u);
    }

    public static function graphReady(): bool
    {
        return GraphPim::configured();
    }

    /** Foldery kontaktów ('' = Kontakty, folder domyślny). @return list<array{id:string,name:string}> */
    public static function folders(string $upn): array
    {
        return (new GraphPim())->contactFolders($upn);
    }

    /**
     * Kontakty z Graph: wszystkie foldery albo jeden ($folderId; '' = domyślny). Null w $folderId = wszystkie.
     * @return list<array{c:array, folder:string}>
     */
    public static function fetch(string $upn, ?string $folderId = null): array
    {
        $g = new GraphPim();
        $folders = $g->contactFolders($upn);
        if ($folderId !== null) $folders = array_values(array_filter($folders, fn($f) => $f['id'] === $folderId));
        $out = [];
        foreach ($folders as $f) {
            $next = null;
            $guard = 0;
            do {
                $page = $g->contacts($upn, $f['id'], $next);
                foreach ($page['items'] as $c) $out[] = ['c' => $c, 'folder' => (string)$f['name']];
                $next = $page['next'];
            } while ($next && ++$guard < self::MAX_PAGES);
        }
        return $out;
    }

    /** @param list<array{c:array, folder:string}> $rows */
    public static function vcf(array $rows): string
    {
        $out = '';
        foreach ($rows as $r) $out .= PimVcard::card($r['c'], $r['folder']);
        return $out;
    }

    /** CSV (UTF-8 z BOM, przecinki, CRLF) z kontaktów Graph. @param list<array{c:array, folder:string}> $rows */
    public static function csv(array $rows): string
    {
        $lines = [self::CSV_HEAD];
        foreach ($rows as $r) $lines[] = self::csvRow($r['c'], $r['folder']);
        return self::csvEncode($lines);
    }

    /** Jeden wiersz CSV z kontaktu Graph. @return list<string> */
    public static function csvRow(array $c, string $folder = ''): array
    {
        $s = static fn($v): string => trim((string)($v ?? ''));
        $emails = [];
        foreach ((array)($c['emailAddresses'] ?? []) as $m) {
            $a = $s($m['address'] ?? '');
            if ($a !== '' && !in_array(strtolower($a), array_map('strtolower', $emails), true)) $emails[] = $a;
        }
        $bp = array_values(array_filter(array_map($s, (array)($c['businessPhones'] ?? []))));
        $hp = array_values(array_filter(array_map($s, (array)($c['homePhones'] ?? []))));
        $adr = static function (string $k) use ($c, $s): array {
            $a = (array)($c[$k] ?? []);
            return [$s($a['street'] ?? ''), $s($a['city'] ?? ''), $s($a['state'] ?? ''), $s($a['postalCode'] ?? ''), $s($a['countryOrRegion'] ?? '')];
        };
        $bday = '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s($c['birthday'] ?? ''), $m) && (int)$m[1] > 1700) $bday = "{$m[1]}-{$m[2]}-{$m[3]}";
        $cats = array_values(array_filter(array_map($s, (array)($c['categories'] ?? []))));
        if ($folder !== '') $cats[] = $folder;
        $given = $s($c['givenName'] ?? '');
        $sur = $s($c['surname'] ?? '');
        // kontakt bez imienia i nazwiska (np. sama firma albo sam adres): nazwa wyświetlana jako imię, żeby nie zginął przy imporcie
        if ($given === '' && $sur === '') $given = $s($c['displayName'] ?? '') ?: $s($c['companyName'] ?? '') ?: ($emails[0] ?? '');
        return [
            $given, $s($c['middleName'] ?? ''), $sur, $s($c['title'] ?? ''), $s($c['nickName'] ?? ''),
            $emails[0] ?? '', $emails[1] ?? '', $emails[2] ?? '',
            $s($c['mobilePhone'] ?? ''), $bp[0] ?? '', $bp[1] ?? '', $hp[0] ?? '', $hp[1] ?? '',
            $s($c['companyName'] ?? ''), $s($c['department'] ?? ''), $s($c['jobTitle'] ?? ''),
            ...$adr('businessAddress'), ...$adr('homeAddress'), ...$adr('otherAddress'),
            $bday, $s($c['businessHomePage'] ?? ''), mb_substr($s($c['personalNotes'] ?? ''), 0, 10000), implode(';', array_unique($cats)),
        ];
    }

    /** @param list<list<string>> $lines */
    public static function csvEncode(array $lines): string
    {
        $out = "\xEF\xBB\xBF";
        foreach ($lines as $row) {
            $cells = [];
            foreach ($row as $v) {
                $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
                // arkusz nie potraktuje komórki jako formuły (=, @; + i - tylko gdy nie zaczynają numeru telefonu)
                if ($v !== '' && (in_array($v[0], ['=', '@', "\t"], true) || (in_array($v[0], ['+', '-'], true) && !preg_match('/^[+-][\d\s(]/', $v)))) $v = "'" . $v;
                $cells[] = '"' . str_replace('"', '""', $v) . '"';
            }
            $out .= implode(',', $cells) . "\r\n";
        }
        return $out;
    }

    // ── kopia z przeniesienia do chmury (PimImport) ─────────────────────────────

    /** Kopia kontaktów (tekst VCF) zrobiona przy przenoszeniu do chmury albo null. */
    public static function copyVcf(string $upn): ?string
    {
        try {
            $job = PimImport::forUpn($upn);
            if (!$job) return null;
            $files = PimImport::exportFiles($job);
            $v = (string)($files['contacts.vcf'] ?? '');
            return $v !== '' ? $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Data kopii (Y-m-d H:i) albo ''. */
    public static function copyDate(string $upn): string
    {
        try { $j = PimImport::forUpn($upn); } catch (\Throwable $e) { return ''; }
        return $j && !empty($j['exported_at']) ? substr((string)$j['exported_at'], 0, 16) : '';
    }

    /** Kontakty już przeniesione do chmury przez PimImport (książka „Kontakty z Outlooka”): ['contacts'=>n, 'date'=>Y-m-d] albo null. */
    public static function inCloud(string $upn): ?array
    {
        try { $j = PimImport::forUpn($upn); } catch (\Throwable $e) { return null; }
        if (!$j || ($j['status'] ?? '') !== 'done' || (int)($j['contacts'] ?? 0) < 1) return null;
        return ['contacts' => (int)$j['contacts'], 'date' => substr((string)($j['imported_at'] ?? ''), 0, 10)];
    }

    public static function countCards(string $vcf): int
    {
        return preg_match_all('/^BEGIN:VCARD\r?$/mi', $vcf);
    }

    /** CSV z tekstu VCF (karty z PimVcard; obsługuje to, co PimVcard zapisuje). */
    public static function csvFromVcf(string $vcf): string
    {
        $lines = [self::CSV_HEAD];
        $unfolded = (string)preg_replace("/\r?\n[ \t]/", '', $vcf);
        $un = static fn(string $s): string => str_replace(['\\n', '\\N', '\\;', '\\,', '\\\\'], ["\n", "\n", ';', ',', '\\'], $s);
        $split = static fn(string $s, string $sep): array => array_map($un, preg_split('/(?<!\\\\)' . preg_quote($sep, '/') . '/', $s) ?: []);
        foreach (preg_split('/^BEGIN:VCARD\r?$/mi', $unfolded) ?: [] as $block) {
            if (!preg_match('/^END:VCARD/mi', $block)) continue;
            $c = ['emailAddresses' => [], 'businessPhones' => [], 'homePhones' => [], 'categories' => []];
            foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
                if (!str_contains($line, ':')) continue;
                [$k, $v] = explode(':', $line, 2);
                $params = strtoupper($k);
                $name = explode(';', $params)[0];
                switch ($name) {
                    case 'FN': $c['displayName'] = $un($v); break;
                    case 'N': $n = $split($v, ';') + ['', '', '', '']; $c['surname'] = $n[0]; $c['givenName'] = $n[1]; $c['middleName'] = $n[2]; $c['title'] = $n[3]; break;
                    case 'NICKNAME': $c['nickName'] = $un($v); break;
                    case 'ORG': $o = $split($v, ';'); $c['companyName'] = $o[0] ?? ''; $c['department'] = $o[1] ?? ''; break;
                    case 'TITLE': $c['jobTitle'] = $un($v); break;
                    case 'EMAIL': $c['emailAddresses'][] = ['address' => $un($v)]; break;
                    case 'TEL':
                        if (str_contains($params, 'CELL')) $c['mobilePhone'] = $un($v);
                        elseif (str_contains($params, 'HOME')) $c['homePhones'][] = $un($v);
                        else $c['businessPhones'][] = $un($v);
                        break;
                    case 'ADR':
                        $p = $split($v, ';') + array_fill(0, 7, '');
                        $key = str_contains($params, 'HOME') ? 'homeAddress' : (str_contains($params, 'WORK') ? 'businessAddress' : 'otherAddress');
                        $c[$key] = ['street' => $p[2], 'city' => $p[3], 'state' => $p[4], 'postalCode' => $p[5], 'countryOrRegion' => $p[6]];
                        break;
                    case 'BDAY': $c['birthday'] = $v; break;
                    case 'URL': $c['businessHomePage'] = $v; break;
                    case 'NOTE': $c['personalNotes'] = $un($v); break;
                    case 'CATEGORIES': $c['categories'] = $split($v, ','); break;
                }
            }
            if (($c['givenName'] ?? '') === '' && ($c['surname'] ?? '') === '') $c['givenName'] = $c['displayName'] ?? '';
            $lines[] = self::csvRow($c);
        }
        return self::csvEncode($lines);
    }
}
