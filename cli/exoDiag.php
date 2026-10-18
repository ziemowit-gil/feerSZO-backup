#!/usr/bin/env php
<?php
/**
 * cli/exoDiag.php — diagnostyka „Exchange zwrócił HTTP 403 bez JSON” (stopka e-mail, aliasy).
 *
 * Pobiera token aplikacji certyfikatem SZO (jak stopkaExToken) i wywołuje TYLKO DO ODCZYTU
 * Get-AcceptedDomain w kilku wariantach adresu i nagłówków, wypisując kod HTTP i nagłówki diagnostyczne
 * każdej próby. Niczego nie zmienia w Exchange ani w bazie.
 *
 * Użycie (na serwerze, w katalogu aplikacji):
 *   php cli/exoDiag.php
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('SKIP_CONSENT_CHECK', true);
$root = dirname(__DIR__);
require $root . '/config.php';
require $root . '/includes/db.php';
require $root . '/includes/functions.php';
require_once $root . '/includes/m365.php';
require_once $root . '/modules/stopka/logic/stopkaExchange.php';

$tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? MS_TENANT_ID : '');
echo "Dzierżawa: $tenant\n";

try {
    [$tok, $c] = stopkaExAppToken('https://outlook.office365.com', true);
} catch (Throwable $e) {
    exit("Token: BŁĄD — " . $e->getMessage() . "\n");
}
echo "Token: aud={$c['aud']} appid=" . ($c['appid'] ?? '?') . ' appidacr=' . ($c['appidacr'] ?? '?')
   . ' tid=' . ($c['tid'] ?? '?') . "\n  roles=" . implode(',', (array)($c['roles'] ?? [])) . "\n  wids=" . implode(',', (array)($c['wids'] ?? [])) . "\n\n";

$doms = stopkaExAnchorDomains($tenant);
$initial = '';
foreach ($doms as $d) if (str_ends_with($d, '.onmicrosoft.com')) { $initial = $d; break; }

/** Jedno żądanie: zwraca [kod, nagłówki, początek treści]. */
$call = function (string $url, array $extra) use ($tok): array {
    $hdrs = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => json_encode(['CmdletInput' => ['CmdletName' => 'Get-AcceptedDomain', 'Parameters' => (object)[]]]),
        CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $tok, 'Content-Type: application/json',
            'Accept: application/json;odata.metadata=minimal', 'X-ResponseFormat: json', 'X-CmdletName: Get-AcceptedDomain'], $extra),
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdrs) {
            if (strpos($l, ':') !== false) { [$k, $v] = explode(':', $l, 2); $hdrs[strtolower(trim($k))] = trim($v); }
            return strlen($l);
        }]);
    $out = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $hdrs, trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($out)), 0, 160))];
};

$sys = 'SystemMailbox{bb558c35-97f1-4cb9-8ff7-d53741dc928c}@';
$variants = [];
foreach (array_unique(array_filter([$tenant, $initial])) as $t) {
    foreach (['APP', 'UPN'] as $p) foreach ($doms as $d) {
        $variants[] = ["/adminapi/beta/$t", ["X-AnchorMailbox: $p:$sys$d", 'X-ClientApplication: ExoManagementModule']];
    }
    $variants[] = ["/adminapi/beta/$t", ['X-ClientApplication: ExoManagementModule']];                    // bez kotwicy
    $variants[] = ["/adminapi/beta/$t", ["X-AnchorMailbox: UPN:$sys" . ($initial ?: $doms[0] ?? '')]];   // bez X-ClientApplication
}

$okAny = false;
foreach ($variants as [$path, $extra]) {
    [$code, $h, $body] = $call('https://outlook.office365.com' . $path . '/InvokeCommand', $extra);
    $okAny = $okAny || $code === 200;
    printf("%-3s %s  %s\n", $code, $path, implode(' | ', array_map(fn($x) => preg_replace('/^X-AnchorMailbox: /', 'kotwica ', $x), $extra)));
    foreach (['x-ms-diagnostics', 'www-authenticate', 'x-feserver', 'x-besku', 'request-id', 'x-calculatedbetarget'] as $k) {
        if (!empty($h[$k])) echo "      $k: {$h[$k]}\n";
    }
    if ($body !== '') echo "      treść: $body\n";
    if ($code === 200) { echo "      → DZIAŁA. Ten wariant trzeba ustawić w SZO.\n"; break; }
}
echo $okAny ? "\nWynik: znaleziono działający wariant (wyżej).\n"
            : "\nWynik: wszystkie warianty odrzucone. Prześlij powyższy wydruk — nagłówki x-ms-diagnostics / x-feserver wskażą przyczynę.\n";
