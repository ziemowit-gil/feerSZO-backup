<?php
/**
 * modules/mail_alias/logic/mailAlias.php — aliasy e-mail skrzynek Exchange Online.
 *
 * Graph nie pozwala zmieniać proxyAddresses użytkownika ze skrzynką Exchange (pole tylko
 * do odczytu), więc aliasy idą przez Exchange: Set-Mailbox -EmailAddresses @{Add/Remove}.
 * Połączenie (token aplikacji z Exchange.ManageAsApp + InvokeCommand) jest współdzielone
 * z modułem stopki — konfiguracja i diagnostyka: kreator w modules/stopka (#h-wz).
 *
 * Używane przez: admin/email_aliasy.php (bezpośrednie nadawanie + zatwierdzanie wniosków).
 */
require_once dirname(__DIR__, 2) . '/stopka/logic/stopka.php';
require_once dirname(__DIR__, 2) . '/stopka/logic/stopkaExchange.php';

const MAIL_ALIAS_LOCAL_RE = '/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/';

/** Normalizuje adres; rzuca wyjątek przy złym formacie. */
function mailAliasNormalize(string $addr): string {
    $addr = strtolower(trim($addr));
    $at = strrpos($addr, '@');
    if ($at === false || !preg_match(MAIL_ALIAS_LOCAL_RE, substr($addr, 0, $at)) || strpos($addr, '..') !== false
        || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Nieprawidłowy adres: ' . $addr . '. Dozwolone: małe litery, cyfry, kropka, myślnik, podkreślenie.');
    }
    return $addr;
}

/** Domeny zaakceptowane w Exchange (do listy wyboru); przy błędzie — domena z ustawień M365. */
function mailAliasDomains(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $fallback = [m365_setting('m365_domain') ?: 'feer.org.pl'];
    try {
        $rows = stopkaExInvoke(stopkaExToken(), 'Get-AcceptedDomain');
        $d = [];
        foreach ($rows as $r) {
            $name = strtolower((string)($r['DomainName'] ?? ''));
            if ($name === '' || str_ends_with($name, '.onmicrosoft.com')) continue;
            if (!empty($r['Default'])) array_unshift($d, $name); else $d[] = $name; // domyślna na górze
        }
        return $cache = $d ?: $fallback;
    } catch (Throwable $e) {
        return $cache = $fallback;
    }
}

/**
 * Adresy skrzynki: ['identity','name','primary','aliases'=>[...]] (aliasy bez prefiksu smtp:).
 * $identity: UPN, adres e-mail lub alias skrzynki.
 */
function mailAliasMailbox(string $identity): array {
    $rows = stopkaExInvoke(stopkaExToken(), 'Get-Mailbox', ['Identity' => $identity]);
    if (!$rows) throw new RuntimeException('Nie znaleziono skrzynki: ' . $identity);
    $m = $rows[0];
    $primary = ''; $aliases = [];
    foreach ((array)($m['EmailAddresses'] ?? []) as $a) {
        if (strncmp($a, 'SMTP:', 5) === 0) $primary = strtolower(substr($a, 5));
        elseif (strncasecmp($a, 'smtp:', 5) === 0) $aliases[] = strtolower(substr($a, 5));
    }
    sort($aliases);
    return [
        'identity' => (string)($m['UserPrincipalName'] ?? $identity),
        'name'     => (string)($m['DisplayName'] ?? ''),
        'primary'  => $primary ?: strtolower((string)($m['PrimarySmtpAddress'] ?? '')),
        'aliases'  => $aliases,
    ];
}

/** Czy adres jest już używany przez jakikolwiek obiekt Exchange (skrzynka, grupa, kontakt…). */
function mailAliasInUse(string $addr): bool {
    $addr = mailAliasNormalize($addr);
    $rows = stopkaExInvoke(stopkaExToken(), 'Get-Recipient', ['Filter' => "EmailAddresses -eq 'smtp:" . str_replace("'", "''", $addr) . "'"]);
    return (bool)$rows;
}

/** Dodaje alias do skrzynki. Zwraca stan skrzynki po zmianie. */
function mailAliasAdd(string $identity, string $addr): array {
    $addr = mailAliasNormalize($addr);
    $domain = substr($addr, strrpos($addr, '@') + 1);
    if (!in_array($domain, mailAliasDomains(), true)) {
        throw new RuntimeException('Domena ' . $domain . ' nie jest domeną organizacji w Exchange.');
    }
    $mb = mailAliasMailbox($identity);
    if ($addr === $mb['primary'] || in_array($addr, $mb['aliases'], true)) return $mb; // już jest
    if (mailAliasInUse($addr)) throw new RuntimeException('Adres ' . $addr . ' jest już zajęty w organizacji.');
    stopkaExInvoke(stopkaExToken(), 'Set-Mailbox', ['Identity' => $mb['identity'], 'EmailAddresses' => [
        '@odata.type' => '#Exchange.GenericHashTable', 'Add' => ['smtp:' . $addr],
    ]]);
    $after = mailAliasMailbox($mb['identity']);
    if (!in_array($addr, $after['aliases'], true)) throw new RuntimeException('Exchange przyjął polecenie, ale alias nie pojawił się na skrzynce.');
    return $after;
}

/** Usuwa alias (nigdy adresu głównego). Zwraca stan skrzynki po zmianie. */
function mailAliasRemove(string $identity, string $addr): array {
    $addr = mailAliasNormalize($addr);
    $mb = mailAliasMailbox($identity);
    if ($addr === $mb['primary']) throw new RuntimeException('Nie można usunąć adresu głównego skrzynki.');
    if (!in_array($addr, $mb['aliases'], true)) return $mb;
    stopkaExInvoke(stopkaExToken(), 'Set-Mailbox', ['Identity' => $mb['identity'], 'EmailAddresses' => [
        '@odata.type' => '#Exchange.GenericHashTable', 'Remove' => ['smtp:' . $addr],
    ]]);
    return mailAliasMailbox($mb['identity']);
}

/** Konta SZO powiązane z M365 — podpowiedzi w polu „Skrzynka”. [['login','name'], …] */
function mailAliasKnownMailboxes(): array {
    try {
        return db_all("SELECT COALESCE(NULLIF(m365_login,''), email) AS login, name FROM users
                       WHERE (m365_login IS NOT NULL AND m365_login<>'') OR (microsoft_id IS NOT NULL AND microsoft_id<>'')
                       ORDER BY name");
    } catch (Throwable $e) {
        return [];
    }
}
