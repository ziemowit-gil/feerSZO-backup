<?php
declare(strict_types=1);

/**
 * modules/subdomains/public/index.php — małe subdomeny feer.org.pl obsługiwane bez SZO (bez bazy, sesji i logowania).
 * Katalog modules/subdomains/public jest public_html tych stron na serwerze (symlink, jak szo.feer.org.pl).
 * Polecenia 2026-11-14: „subdomena it.feer.org.pl z przekierowaniem na szo.feer.org.pl/uslugi-it” i „autodiscover dla
 * programów pocztowych”.
 *
 *   it.feer.org.pl          → przekierowanie 302 na https://szo.feer.org.pl/uslugi-it (z zachowaniem ścieżki i zapytania)
 *   autodiscover.feer.org.pl → Outlook: /autodiscover/autodiscover.xml (POX, POST albo GET) i autodiscover.json (AutodiscoverV1)
 *   autoconfig.feer.org.pl   → Thunderbird i inne: /mail/config-v1.1.xml (też /.well-known/autoconfig/mail/config-v1.1.xml)
 *
 * Dane serwerów poczty @feer.org.pl (dhosting / dPoczta) — te same, co w artykule /uslugi-it „Poczta w telefonie, Outlooku
 * i Thunderbirdzie”: IMAP imap.dpoczta.pl:993 SSL, POP3 pop3.dpoczta.pl:995 SSL, SMTP smtp.dpoczta.pl:587 STARTTLS,
 * login = pełny adres e-mail. Hasła nikt tu nie podaje i nic nie jest zapisywane.
 */

const SUB_MAIL = [
    'domain'   => 'feer.org.pl',
    'name'     => 'Poczta Fundacji FEER',
    'short'    => 'FEER',
    'imap'     => ['imap.dpoczta.pl', 993],
    'pop3'     => ['pop3.dpoczta.pl', 995],
    'smtp'     => ['smtp.dpoczta.pl', 587],
    'help'     => 'https://szo.feer.org.pl/uslugi-it/uslugi/moja-poczta/poczta-w-telefonie',
];
const SUB_IT_TARGET = 'https://szo.feer.org.pl/uslugi-it';

$host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = (string)parse_url($uri, PHP_URL_PATH);
$label = explode('.', $host)[0];

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// ── it.feer.org.pl ─────────────────────────────────────────────────────────────────────────
if ($label === 'it') {
    $rest = ltrim($path, '/');
    $query = (string)parse_url($uri, PHP_URL_QUERY);
    $target = SUB_IT_TARGET . ($rest !== '' ? '/' . implode('/', array_map('rawurlencode', array_map('rawurldecode', explode('/', $rest)))) : '')
            . ($query !== '' ? '?' . $query : '');
    header('Cache-Control: no-cache');
    header('Location: ' . $target, true, 302);
    exit;
}

/** Adres e-mail z zapytania (Thunderbird: emailaddress=…) albo z treści żądania Outlooka; '' gdy brak albo niepoprawny. */
function subEmail(string $raw): string
{
    $raw = strtolower(trim($raw));
    return filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : '';
}

function subXml(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ── autoconfig.feer.org.pl (Thunderbird, Evolution, K-9/Thunderbird na Androida) ────────────
if ($label === 'autoconfig' || str_ends_with($path, '/autoconfig/mail/config-v1.1.xml')) {
    if (!preg_match('#/mail/config-v1\.1\.xml$#i', $path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Nie ma tu takiej strony.\n"; exit; }
    $m = SUB_MAIL;
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $srv = fn(string $tag, string $type, array $hp, string $sock) => "    <{$tag} type=\"{$type}\">\n"
        . "      <hostname>{$hp[0]}</hostname>\n      <port>{$hp[1]}</port>\n      <socketType>{$sock}</socketType>\n"
        . "      <authentication>password-cleartext</authentication>\n      <username>%EMAILADDRESS%</username>\n    </{$tag}>\n";
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . "<clientConfig version=\"1.1\">\n"
       . '  <emailProvider id="' . subXml($m['domain']) . "\">\n"
       . '    <domain>' . subXml($m['domain']) . "</domain>\n"
       . '    <displayName>' . subXml($m['name']) . "</displayName>\n"
       . '    <displayShortName>' . subXml($m['short']) . "</displayShortName>\n"
       . $srv('incomingServer', 'imap', $m['imap'], 'SSL')
       . $srv('incomingServer', 'pop3', $m['pop3'], 'SSL')
       . $srv('outgoingServer', 'smtp', $m['smtp'], 'STARTTLS')
       . '    <documentation url="' . subXml($m['help']) . "\">\n"
       . "      <descr lang=\"pl\">Poczta w telefonie, Outlooku i Thunderbirdzie</descr>\n"
       . "    </documentation>\n"
       . "  </emailProvider>\n"
       . "</clientConfig>\n";
    exit;
}

// ── autodiscover.feer.org.pl (Outlook) ──────────────────────────────────────────────────────
if ($label === 'autodiscover') {
    $lp = strtolower($path);
    // Nowy protokół (Outlook najpierw pyta o JSON): wskazujemy adres usługi XML
    if (str_starts_with($lp, '/autodiscover/autodiscover.json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['Protocol' => 'AutodiscoverV1', 'Url' => 'https://autodiscover.' . SUB_MAIL['domain'] . '/autodiscover/autodiscover.xml'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($lp !== '/autodiscover/autodiscover.xml') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Nie ma tu takiej strony.\n"; exit; }

    $body = (string)file_get_contents('php://input', false, null, 0, 65536);
    $email = '';
    if (preg_match('#<EMailAddress>\s*([^<\s]+)\s*</EMailAddress>#i', $body, $mm)) $email = subEmail($mm[1]);
    if ($email === '') $email = subEmail((string)($_GET['email'] ?? $_GET['emailaddress'] ?? ''));
    $m = SUB_MAIL;
    $login = $email !== '' ? '        <LoginName>' . subXml($email) . "</LoginName>\n" : '';
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
       . "<Autodiscover xmlns=\"http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006\">\n"
       . "  <Response xmlns=\"http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a\">\n"
       . "    <Account>\n"
       . "      <AccountType>email</AccountType>\n"
       . "      <Action>settings</Action>\n"
       . "      <Protocol>\n"
       . "        <Type>IMAP</Type>\n"
       . '        <Server>' . $m['imap'][0] . "</Server>\n"
       . '        <Port>' . $m['imap'][1] . "</Port>\n"
       . "        <DomainRequired>off</DomainRequired>\n"
       . $login
       . "        <SPA>off</SPA>\n"
       . "        <SSL>on</SSL>\n"
       . "        <AuthRequired>on</AuthRequired>\n"
       . "      </Protocol>\n"
       . "      <Protocol>\n"
       . "        <Type>SMTP</Type>\n"
       . '        <Server>' . $m['smtp'][0] . "</Server>\n"
       . '        <Port>' . $m['smtp'][1] . "</Port>\n"
       . "        <DomainRequired>off</DomainRequired>\n"
       . $login
       . "        <SPA>off</SPA>\n"
       . "        <Encryption>TLS</Encryption>\n"
       . "        <AuthRequired>on</AuthRequired>\n"
       . "        <UsePOPAuth>off</UsePOPAuth>\n"
       . "        <SMTPLast>off</SMTPLast>\n"
       . "      </Protocol>\n"
       . "    </Account>\n"
       . "  </Response>\n"
       . "</Autodiscover>\n";
    exit;
}

// Inny adres skierowany na ten katalog
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Nie ma tu takiej strony.\n";
