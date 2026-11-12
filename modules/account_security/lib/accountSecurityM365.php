<?php
/**
 * modules/account_security/lib/accountSecurityM365.php — stan konta Microsoft 365 / Entra ID (tylko odczyt) dla Tożsamości.
 *
 * Odczyt na żądanie (przycisk w sekcji Usługi), wynik w sesji — zwykłe wejście na stronę nie czeka na Graph.
 * Konto wiązane przez users.microsoft_id (główny tenant, M365Graph z ustawień). Nic nie zmienia w Microsoft.
 * Graph: GET /users/{id} (profil), /licenseDetails, /memberOf, signInActivity (wymaga AuditLog.Read.All — bywa niedostępne).
 */

final class AccountSecurityM365
{
    public const SESSION_KEY = 'account_security_m365';
    /** Wynik w sesji pokazujemy najwyżej przez tyle sekund (potem trzeba sprawdzić ponownie). */
    public const TTL = 1800;

    /** Fabryka klienta Graph (testy hermetyczne podstawiają atrapę); null = M365Graph z ustawień. */
    public static ?\Closure $graphFactory = null;

    /** Czytelna nazwa licencji (skuPartNumber); nieznana → oryginalny kod. */
    public static function skuLabel(string $part): string
    {
        static $map = [
            'STANDARDWOFFPACK_STUDENT'   => 'Office 365 A1 (uczniowie)',
            'STANDARDWOFFPACK_FACULTY'   => 'Office 365 A1 (kadra)',
            'STANDARDWOFFPACK_IW_STUDENT' => 'Office 365 A1 Plus (uczniowie)',
            'STANDARDWOFFPACK_IW_FACULTY' => 'Office 365 A1 Plus (kadra)',
            'M365EDU_A3_STUDENT'         => 'Microsoft 365 A3 (uczniowie)',
            'M365EDU_A3_FACULTY'         => 'Microsoft 365 A3 (kadra)',
            'M365EDU_A5_STUDENT'         => 'Microsoft 365 A5 (uczniowie)',
            'M365EDU_A5_FACULTY'         => 'Microsoft 365 A5 (kadra)',
            'ENTERPRISEPACK_STUDENT'     => 'Office 365 A3 (uczniowie)',
            'ENTERPRISEPACK_FACULTY'     => 'Office 365 A3 (kadra)',
            'O365_BUSINESS_ESSENTIALS'   => 'Microsoft 365 Business Basic',
            'O365_BUSINESS_PREMIUM'      => 'Microsoft 365 Business Standard',
            'O365_BUSINESS'              => 'Microsoft 365 Apps for business',
            'SMB_BUSINESS_ESSENTIALS'    => 'Microsoft 365 Business Basic',
            'SMB_BUSINESS_PREMIUM'       => 'Microsoft 365 Business Standard',
            'SMB_BUSINESS'               => 'Microsoft 365 Apps for business',
            'SPB'                        => 'Microsoft 365 Business Premium',
            'OFFICESUBSCRIPTION'         => 'Microsoft 365 Apps for enterprise',
            'STANDARDPACK'               => 'Office 365 E1',
            'ENTERPRISEPACK'             => 'Office 365 E3',
            'ENTERPRISEPREMIUM'          => 'Office 365 E5',
            'SPE_E3'                     => 'Microsoft 365 E3',
            'SPE_E5'                     => 'Microsoft 365 E5',
            'SPE_F1'                     => 'Microsoft 365 F3',
            'DESKLESSPACK'               => 'Office 365 F3',
            'EXCHANGESTANDARD'           => 'Exchange Online (plan 1)',
            'EXCHANGEENTERPRISE'         => 'Exchange Online (plan 2)',
            'EXCHANGEDESKLESS'           => 'Exchange Online Kiosk',
            'AAD_PREMIUM'                => 'Entra ID P1',
            'AAD_PREMIUM_P2'             => 'Entra ID P2',
            'EMS'                        => 'Enterprise Mobility + Security E3',
            'POWER_BI_STANDARD'          => 'Power BI (bezpłatna)',
            'POWER_BI_PRO'               => 'Power BI Pro',
            'FLOW_FREE'                  => 'Power Automate (bezpłatna)',
            'TEAMS_EXPLORATORY'          => 'Teams Exploratory',
            'Microsoft_Teams_Exploratory' => 'Teams Exploratory',
            'VISIOCLIENT'                => 'Visio (plan 2)',
            'PROJECTPROFESSIONAL'        => 'Project (plan 3)',
            'WINDOWS_STORE'              => 'Microsoft Store dla firm',
        ];
        $clean = (string)preg_replace('/^[A-Za-z0-9]+:/', '', $part);
        return $map[$clean] ?? ($clean !== '' ? $clean : 'licencja bez nazwy');
    }

    /** Czas z Graph (ISO 8601, UTC) w strefie aplikacji; null/'' → null. */
    public static function localTime(?string $iso, string $format = 'd.m.Y H:i'): ?string
    {
        if ($iso === null || $iso === '') return null;
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function graph(): ?object
    {
        if (self::$graphFactory) return (self::$graphFactory)();
        require_once dirname(__DIR__, 3) . '/includes/m365.php';
        if (m365_setting('m365_enabled') !== '1') return null;
        $g = new M365Graph();
        return $g->is_configured() ? $g : null;
    }

    private static function ok(object $g): bool
    {
        $st = (int)$g->last_status();
        return $st >= 200 && $st < 300;
    }

    /**
     * Odczyt stanu konta z Graph.
     * @return array{state:string, at:int, oid?:string, msg?:string, profile?:array, licenses?:?list<string>, groups?:?list<string>,
     *               roles?:list<string>, last_signin?:?string}
     *   state: ok | none (brak powiązanego konta) | skip (integracja wyłączona) | missing (konta nie ma w Entra) | err
     */
    public static function fetch(array $dbUser): array
    {
        $oid = trim((string)($dbUser['microsoft_id'] ?? ''));
        $at  = time();
        if ($oid === '') return ['state' => 'none', 'at' => $at];
        try {
            $g = self::graph();
            if (!$g) return ['state' => 'skip', 'at' => $at, 'oid' => $oid, 'msg' => 'Integracja Microsoft 365 jest wyłączona albo nieskonfigurowana.'];

            $p = $g->user_profile($oid);
            if (!self::ok($g) || isset($p['error'])) {
                $st  = (int)$g->last_status();
                $err = is_array($p['error'] ?? null) ? $p['error'] : (array)$g->last_error();
                if ($st === 404 || ($err['code'] ?? '') === 'Request_ResourceNotFound') {
                    return ['state' => 'missing', 'at' => $at, 'oid' => $oid,
                            'msg' => 'Konta o tym identyfikatorze nie ma w Entra ID — mogło zostać usunięte. Skontaktuj się z administratorem.'];
                }
                $m = trim((string)($err['message'] ?? $err['code'] ?? ''));
                return ['state' => 'err', 'at' => $at, 'oid' => $oid,
                        'msg' => 'Microsoft 365 nie udostępnił danych konta' . ($st > 0 ? ' (HTTP ' . $st . ')' : '') . ': '
                               . ($m !== '' ? $m : ($st === 0 ? 'brak odpowiedzi Graph API' : 'brak szczegółów'))];
            }

            // Licencje: nazwy z licenseDetails; gdy odczyt się nie uda — null (nieznane), a nie „brak”
            $licenses = null;
            $ld = $g->user_license_details($oid);
            if (self::ok($g)) {
                $licenses = array_values(array_unique(array_map(fn($l) => self::skuLabel((string)($l['skuPartNumber'] ?? '')), $ld)));
                sort($licenses, SORT_NATURAL | SORT_FLAG_CASE);
            }

            $groups = null; $roles = [];
            $mo = $g->user_member_of($oid);
            if (self::ok($g)) {
                $groups = [];
                foreach ($mo as $m) {
                    $name = trim((string)($m['displayName'] ?? ''));
                    if ($name === '') continue;
                    if (($m['@odata.type'] ?? '') === '#microsoft.graph.directoryRole') $roles[] = $name;
                    else $groups[] = $name;
                }
                sort($groups, SORT_NATURAL | SORT_FLAG_CASE);
                sort($roles, SORT_NATURAL | SORT_FLAG_CASE);
            }

            $last = $g->user_last_signin($oid);   // null, gdy brak uprawnienia AuditLog.Read.All albo danych

            return [
                'state'   => 'ok', 'at' => $at, 'oid' => $oid,
                'profile' => [
                    'enabled'         => !empty($p['accountEnabled']),
                    'upn'             => (string)($p['userPrincipalName'] ?? ''),
                    'mail'            => (string)($p['mail'] ?? ''),
                    'display'         => (string)($p['displayName'] ?? ''),
                    'created'         => self::localTime($p['createdDateTime'] ?? null, 'd.m.Y'),
                    'pwd_changed'     => self::localTime($p['lastPasswordChangeDateTime'] ?? null),
                    'pwd_never_exp'   => str_contains((string)($p['passwordPolicies'] ?? ''), 'DisablePasswordExpiration'),
                    'usage_location'  => (string)($p['usageLocation'] ?? ''),
                    'license_count'   => count((array)($p['assignedLicenses'] ?? [])),
                ],
                'licenses'    => $licenses,
                'groups'      => $groups,
                'roles'       => $roles,
                'last_signin' => self::localTime(is_string($last) ? $last : null),
            ];
        } catch (\Throwable $e) {
            return ['state' => 'err', 'at' => $at, 'oid' => $oid, 'msg' => $e->getMessage()];
        }
    }

    /** Zapamiętuje wynik w sesji (dla tego konta). */
    public static function store(int $uid, array $res): void
    {
        auth_start();
        $_SESSION[self::SESSION_KEY] = ['uid' => $uid, 'res' => $res];
    }

    /** Ostatni wynik z sesji dla tego konta i identyfikatora Microsoft (nie starszy niż TTL); null = trzeba sprawdzić. */
    public static function cached(int $uid, string $oid): ?array
    {
        $c = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($c) || (int)($c['uid'] ?? 0) !== $uid) return null;
        $r = $c['res'] ?? null;
        if (!is_array($r) || (string)($r['oid'] ?? '') !== $oid || (int)($r['at'] ?? 0) < time() - self::TTL) return null;
        return $r;
    }
}
