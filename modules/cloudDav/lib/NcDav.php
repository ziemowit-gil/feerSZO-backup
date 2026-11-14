<?php
declare(strict_types=1);

require_once __DIR__ . '/NcLoginFlow.php';

/**
 * modules/cloudDav/lib/NcDav.php — odczyt kalendarzy (CalDAV) i książek adresowych (CardDAV) użytkownika w Nextcloud.
 *
 * Tylko PROPFIND (odczyt), uwierzytelnione loginem i hasłem aplikacji z Login Flow v2:
 *   principal()     — PROPFIND /remote.php/dav/ current-user-principal → uid w chmurze (login z hasła aplikacji bywa adresem e-mail);
 *   calendars($uid) — /remote.php/dav/calendars/{uid}/, Depth 1: kolekcje z typem cal:calendar (bez skrzynek zaproszeń, kosza,
 *                     subskrypcji); własne i udostępnione (oc:owner-principal innej osoby);
 *   addressbooks($uid) — /remote.php/dav/addressbooks/users/{uid}/, Depth 1: kolekcje card:addressbook (też książka wszystkich
 *                     kont chmury, gdy serwer ją udostępnia).
 * Adresy zwracane w pełnej postaci, gotowe do wklejenia w program (dPoczta, Thunderbird, DAVx5).
 */
final class NcDav
{
    private const NS = ['d' => 'DAV:', 'cal' => 'urn:ietf:params:xml:ns:caldav', 'card' => 'urn:ietf:params:xml:ns:carddav',
                        'oc' => 'http://owncloud.org/ns', 'ap' => 'http://apple.com/ns/ical/'];

    private string $base;

    public function __construct(string $base, private string $login, private string $pass)
    {
        $this->base = rtrim($base, '/');
    }

    public function davRoot(): string { return $this->base . '/remote.php/dav/'; }
    public function principalUrl(string $uid): string { return $this->davRoot() . 'principals/users/' . rawurlencode($uid) . '/'; }
    public function calendarHome(string $uid): string { return $this->davRoot() . 'calendars/' . rawurlencode($uid) . '/'; }
    public function addressbookHome(string $uid): string { return $this->davRoot() . 'addressbooks/users/' . rawurlencode($uid) . '/'; }

    /** uid w chmurze z current-user-principal (np. /remote.php/dav/principals/users/jan.kowalski/). */
    public function principal(): string
    {
        $xml = $this->propfind($this->davRoot(), 0, '<d:current-user-principal/>');
        foreach ($this->responses($xml) as $r) {
            $href = $this->text($r, './/d:prop/d:current-user-principal/d:href');
            if ($href !== '' && preg_match('~/principals/users/([^/]+)/?$~', $href, $m)) return rawurldecode($m[1]);
        }
        throw new RuntimeException('Chmura nie podała loginu konta (current-user-principal).');
    }

    /** @return list<array{name:string, url:string, color:string, shared:bool, events:bool}> */
    public function calendars(string $uid): array
    {
        $home = $this->calendarHome($uid);
        $xml = $this->propfind($home, 1, '<d:displayname/><d:resourcetype/><cal:supported-calendar-component-set/><ap:calendar-color/><oc:owner-principal/>');
        $out = [];
        foreach ($this->responses($xml) as $r) {
            if (!$this->has($r, './/d:prop/d:resourcetype/cal:calendar')) continue;
            $comps = [];
            foreach ($this->nodes($r, './/d:prop/cal:supported-calendar-component-set/cal:comp') as $c) $comps[] = strtoupper((string)$c->getAttribute('name'));
            $owner = $this->text($r, './/d:prop/oc:owner-principal');
            $url = $this->absolute($this->text($r, './d:href'));
            $out[] = [
                'name'   => $this->text($r, './/d:prop/d:displayname') ?: basename(rtrim(parse_url($url, PHP_URL_PATH) ?: '', '/')),
                'url'    => $url,
                'color'  => (string)preg_replace('/[^#0-9A-Fa-f]/', '', $this->text($r, './/d:prop/ap:calendar-color')),
                // Nextcloud podaje właściciela jako „principals/users/{uid}” (bez ukośnika na początku)
                'shared' => $owner !== '' && !preg_match('~(^|/)principals/users/(' . preg_quote(rawurlencode($uid), '~') . '|' . preg_quote($uid, '~') . ')/?$~i', $owner),
                'events' => !$comps || in_array('VEVENT', $comps, true),
            ];
        }
        usort($out, fn($a, $b) => [$a['shared'], !$a['events'], mb_strtolower($a['name'])] <=> [$b['shared'], !$b['events'], mb_strtolower($b['name'])]);
        return $out;
    }

    /** @return list<array{name:string, url:string}> */
    public function addressbooks(string $uid): array
    {
        $xml = $this->propfind($this->addressbookHome($uid), 1, '<d:displayname/><d:resourcetype/>');
        $out = [];
        foreach ($this->responses($xml) as $r) {
            if (!$this->has($r, './/d:prop/d:resourcetype/card:addressbook')) continue;
            $url = $this->absolute($this->text($r, './d:href'));
            $out[] = ['name' => $this->text($r, './/d:prop/d:displayname') ?: basename(rtrim(parse_url($url, PHP_URL_PATH) ?: '', '/')), 'url' => $url];
        }
        return $out;
    }

    private function propfind(string $url, int $depth, string $props): \DOMXPath
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" '
              . 'xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:oc="http://owncloud.org/ns" xmlns:ap="http://apple.com/ns/ical/"><d:prop>'
              . $props . '</d:prop></d:propfind>';
        [$code, $raw] = NcLoginFlow::http('PROPFIND', $url, ['Depth: ' . $depth, 'Content-Type: application/xml; charset=utf-8'], $body, [$this->login, $this->pass]);
        if ($code === 401 || $code === 403) throw new RuntimeException('Chmura odrzuciła hasło aplikacji (HTTP ' . $code . ').');
        if ($code !== 207) throw new RuntimeException('Chmura nie podała listy (HTTP ' . $code . ').');
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) throw new RuntimeException('Nieczytelna odpowiedź chmury.');
        $xp = new \DOMXPath($dom);
        foreach (self::NS as $p => $ns) $xp->registerNamespace($p, $ns);
        return $xp;
    }

    /** @return list<\DOMElement> */
    private function responses(\DOMXPath $xp): array
    {
        $out = [];
        foreach ($xp->query('/d:multistatus/d:response') ?: [] as $n) {
            if (!$n instanceof \DOMElement) continue;
            // tylko właściwości z kodem 200 (Nextcloud zwraca brakujące w osobnym propstat 404)
            $ok = false;
            foreach ($xp->query('./d:propstat', $n) ?: [] as $ps) {
                $st = trim((string)($xp->query('./d:status', $ps)->item(0)?->textContent ?? ''));
                if (str_contains($st, ' 200 ')) { $ok = true; break; }
            }
            if ($ok) $out[] = $n;
        }
        $this->xp = $xp;
        return $out;
    }

    private ?\DOMXPath $xp = null;

    private function nodes(\DOMElement $ctx, string $q): array
    {
        $out = [];
        foreach ($this->xp?->query($q, $ctx) ?: [] as $n) {
            // właściwość liczy się tylko w propstat z kodem 200
            $ps = $n;
            while ($ps && !($ps instanceof \DOMElement && $ps->localName === 'propstat')) $ps = $ps->parentNode;
            if ($ps instanceof \DOMElement) {
                $st = trim((string)($this->xp->query('./d:status', $ps)->item(0)?->textContent ?? ''));
                if (!str_contains($st, ' 200 ')) continue;
            }
            if ($n instanceof \DOMElement) $out[] = $n;
        }
        return $out;
    }

    private function has(\DOMElement $ctx, string $q): bool { return (bool)$this->nodes($ctx, $q); }

    private function text(\DOMElement $ctx, string $q): string
    {
        if (str_starts_with($q, './d:href')) return trim((string)($this->xp?->query($q, $ctx)->item(0)?->textContent ?? ''));
        $n = $this->nodes($ctx, $q);
        return $n ? trim((string)$n[0]->textContent) : '';
    }

    private function absolute(string $href): string
    {
        if (preg_match('~^https?://~i', $href)) return $href;
        $p = parse_url($this->base);
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . ltrim($href, '/');
    }
}
