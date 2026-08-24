<?php
/**
 * includes/crm_domains.php — grupowanie kontaktów CRM po domenie e-mail.
 *
 * Problem, który to rozwiązuje: auto-kartoteka ze Skrzynki CRM, import CSV i sync
 * Outlooka zakładają OSOBNĄ kartotekę każdemu nadawcy. Po roku pracy z jednym
 * urzędem w bazie leży dwadzieścia osób z adresami w tej samej domenie i ani
 * jednego rekordu samego urzędu. Ten moduł składa je z powrotem: domena →
 * podmiot, a poszczególne osoby stają się jego osobami kontaktowymi
 * (crm_contact_persons), zachowując ślad w `linked_contact_id`.
 *
 * KLUCZOWE ZASTRZEŻENIE: domeny darmowej poczty (gmail.com, wp.pl…) NIE są
 * organizacjami. Grupowanie po nich zlepiłoby przypadkowych ludzi w jeden
 * podmiot, więc są twardo odfiltrowane i nie da się ich przypiąć.
 */

declare(strict_types=1);

require_once __DIR__ . '/crm.php';

/**
 * Domeny darmowej poczty — nigdy nie reprezentują podmiotu.
 *
 * Lista celowo obejmuje polski rynek, bo tam leży większość kontaktów.
 */
const CRM_PUBLIC_MAIL_DOMAINS = [
    'gmail.com', 'googlemail.com', 'wp.pl', 'o2.pl', 'op.pl', 'onet.pl',
    'onet.eu', 'poczta.onet.pl', 'interia.pl', 'interia.eu', 'gazeta.pl',
    'tlen.pl', 'go2.pl', 'poczta.fm', 'buziaczek.pl', 'vp.pl',
    'yahoo.com', 'yahoo.pl', 'outlook.com', 'hotmail.com', 'hotmail.pl',
    'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com',
    'proton.me', 'protonmail.com', 'pm.me', 'aol.com', 'gmx.com', 'gmx.de',
    'mail.ru', 'yandex.ru', 'zoho.com', 'fastmail.com', 'hush.com',
];

/** Normalizacja domeny: małe litery, bez „www.”, bez kropki na końcu. */
function crm_domain_normalize(string $domain): string
{
    $d = strtolower(trim($domain));
    $d = preg_replace('~^https?://~', '', $d) ?? $d;
    $d = explode('/', $d)[0];
    $d = explode('?', $d)[0];
    $d = preg_replace('~^www\.~', '', $d) ?? $d;
    return rtrim($d, '. ');
}

/** Domena z adresu e-mail („jan@um.krakow.pl” → „um.krakow.pl”). */
function crm_email_domain(?string $email): string
{
    $email = trim((string)$email);
    $at    = strrpos($email, '@');
    if ($at === false) return '';
    return crm_domain_normalize(substr($email, $at + 1));
}

/**
 * Domena ze strony WWW podmiotu.
 *
 * Pole `strona_www` bywa wpisane na wszystkie sposoby („um.krakow.pl”,
 * „https://www.um.krakow.pl/aktualnosci”, „WWW.UM.KRAKOW.PL ”), więc
 * sprowadzamy je do samego hosta.
 */
function crm_domain_from_url(?string $url): string
{
    $u = trim((string)$url);
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) $u = 'http://' . $u;
    $host = parse_url($u, PHP_URL_HOST);
    return $host ? crm_domain_normalize((string)$host) : '';
}

/** Czy to domena darmowej poczty (a więc NIE podmiot)? */
function crm_domain_is_public(string $domain): bool
{
    return in_array(crm_domain_normalize($domain), CRM_PUBLIC_MAIL_DOMAINS, true);
}

/**
 * Domena podmiotu — najpierw strona WWW, w drugiej kolejności własny e-mail.
 *
 * WWW jest pewniejsze: adres e-mail podmiotu bywa skrzynką na cudzym serwerze
 * (biuro.fundacja@gmail.com), a strona prawie zawsze stoi we własnej domenie.
 */
function crm_contact_domain(array $contact): string
{
    $d = crm_domain_from_url($contact['strona_www'] ?? '');
    if ($d !== '' && !crm_domain_is_public($d)) return $d;
    $d = crm_email_domain($contact['email'] ?? '');
    return crm_domain_is_public($d) ? '' : $d;
}

/**
 * Zestawienie domen występujących w kartotece.
 *
 * @param bool $include_public czy dołączyć domeny darmowej poczty (do statystyk)
 * @return array<int,array{domain:string,n:int,is_public:bool,orgs:int}>
 */
function crm_domain_overview(bool $include_public = false, int $min = 2): array
{
    crm_migrate();
    $rows = db_all(
        "SELECT lower(substr(email, instr(email,'@')+1)) AS domain, COUNT(*) AS n
           FROM crm_contacts
          WHERE crm_active=1 AND email IS NOT NULL AND email LIKE '%@%'
          GROUP BY domain
          ORDER BY n DESC, domain"
    );

    $out = [];
    foreach ($rows as $r) {
        $d = crm_domain_normalize((string)$r['domain']);
        if ($d === '') continue;
        $pub = crm_domain_is_public($d);
        if ($pub && !$include_public) continue;
        if ((int)$r['n'] < $min && !$pub) {
            // Pojedynczy adres w domenie to jeszcze nie grupa — chyba że istnieje
            // już podmiot o tej domenie, wtedy warto pokazać go do przypięcia.
            if (!crm_domain_orgs($d)) continue;
        }
        $out[] = [
            'domain'    => $d,
            'n'         => (int)$r['n'],
            'is_public' => $pub,
            'orgs'      => count(crm_domain_orgs($d)),
        ];
    }
    return $out;
}

/** Kontakty (osoby) z adresem w danej domenie — kandydaci do przypięcia. */
function crm_domain_contacts(string $domain, bool $only_persons = true): array
{
    $d = crm_domain_normalize($domain);
    if ($d === '') return [];
    $rows = db_all(
        "SELECT id, type, status, imie_nazwisko, email, telefon, stanowisko, organizacja,
                strona_www, owner_id, created_at
           FROM crm_contacts
          WHERE crm_active=1 AND lower(email) LIKE ?
          ORDER BY imie_nazwisko",
        ['%@' . $d]
    );
    if (!$only_persons) return $rows;
    return array_values(array_filter($rows, fn($r) => empty(CRM_CONTACT_TYPES[$r['type']]['org_like'])));
}

/**
 * Podmioty pasujące do domeny — po stronie WWW albo po własnym adresie e-mail.
 * To jest „wykrywanie adresu na podstawie strony WWW podmiotu” od strony domeny.
 */
function crm_domain_orgs(string $domain): array
{
    static $cache = [];
    $d = crm_domain_normalize($domain);
    if ($d === '') return [];
    if (isset($cache[$d])) return $cache[$d];

    $rows = db_all(
        "SELECT id, type, imie_nazwisko, email, strona_www, nip, krs
           FROM crm_contacts
          WHERE crm_active=1 AND (strona_www IS NOT NULL OR email IS NOT NULL)"
    );
    $hits = [];
    foreach ($rows as $r) {
        if (empty(CRM_CONTACT_TYPES[$r['type']]['org_like'])) continue;
        if (crm_contact_domain($r) === $d) $hits[] = $r;
    }
    return $cache[$d] = $hits;
}

/**
 * Propozycje od strony podmiotów: każdy podmiot z własną domeną i liczbą
 * niepodpiętych osób, które mają w niej adres.
 *
 * @return array<int,array{org:array,domain:string,candidates:array}>
 */
function crm_domain_org_suggestions(): array
{
    crm_migrate();
    $orgs = db_all(
        "SELECT id, type, imie_nazwisko, email, strona_www, nip, krs
           FROM crm_contacts
          WHERE crm_active=1 AND (strona_www IS NOT NULL AND strona_www<>'' OR email IS NOT NULL AND email<>'')
          ORDER BY imie_nazwisko"
    );
    $out = [];
    foreach ($orgs as $o) {
        if (empty(CRM_CONTACT_TYPES[$o['type']]['org_like'])) continue;
        $d = crm_contact_domain($o);
        if ($d === '') continue;
        $cands = array_values(array_filter(
            crm_domain_contacts($d),
            fn($c) => (int)$c['id'] !== (int)$o['id']
        ));
        if (!$cands) continue;
        $out[] = ['org' => $o, 'domain' => $d, 'candidates' => $cands];
    }
    usort($out, fn($a, $b) => count($b['candidates']) <=> count($a['candidates']));
    return $out;
}
