<?php
/**
 * includes/hostAliases.php — jedno źródło prawdy o hostach aliasów SZO (CRM, EZD).
 *
 * Wcześniej te same listy siedziały osobno w crm_alias_base_url() i ezd_alias_base_url() (includes/auth.php),
 * a rejestracja adresów powrotu w Logto potrzebowała trzeciej. Nowy alias dopisz TUTAJ.
 * Wartość: true = host sam serwuje strony (tu ląduje /sso/powrot), false = tylko przekierowanie na host feer.org.pl
 * (Traefik, docker/docker-compose.prod.yml), więc logowania na nim nie ma.
 */
function host_aliases(?string $kind = null): array {
    static $all = [
        'crm' => ['crm.feer.org.pl' => true, 'crm.ngosystem.pl' => false],
        'ezd' => ['ezd.feer.org.pl' => true, 'ezd.ngosystem.pl' => false, 'ezd-prod-srv.feer.org.pl' => true],
    ];
    return $kind === null ? $all : ($all[$kind] ?? []);
}

/** Hosty aliasów, które serwują strony (bez samych przekierowań). */
function host_alias_serving_hosts(): array {
    $out = [];
    foreach (host_aliases() as $hosts) {
        foreach ($hosts as $host => $serves) if ($serves) $out[] = $host;
    }
    return $out;
}

/** „schemat://host”, gdy żądanie przyszło na alias danego rodzaju ('crm' | 'ezd'), inaczej null. */
function host_alias_origin(string $kind): ?string {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    if (!isset(host_aliases($kind)[$host])) return null;
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . $host;
}
