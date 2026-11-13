<?php
declare(strict_types=1);

/**
 * modules/sso_logto/lib/CloudGroups.php — grupy w chmurze plików (Nextcloud / ownCloud) przez OCS Provisioning API v2.
 *
 * Osobny plik (nie CloudApi.php), korzysta z publicznego CloudApi::ocs(). Używa go synchronizacja zespołów SZO
 * (logic/CloudTeamsSync.php). Nazwa wyświetlana grupy (displayname) działa w Nextcloud; ownCloud jej nie zna — wtedy
 * zostaje sam identyfikator (błąd przy nazwie nie przerywa pracy).
 */
require_once __DIR__ . '/CloudApi.php';

final class CloudGroups
{
    public function __construct(private CloudApi $api) {}

    /**
     * Grupy, których identyfikator zaczyna się od $prefix: [gid => nazwa wyświetlana]. Najpierw /groups/details (Nextcloud),
     * gdy go nie ma — /groups (ownCloud, nazwa = identyfikator). Wyszukiwanie OCS dopasowuje fragment, więc filtrujemy jeszcze raz.
     */
    public function listByPrefix(string $prefix): array
    {
        $out = [];
        try {
            $d = $this->api->ocs('GET', 'groups/details', ['search' => $prefix, 'limit' => 1000]);
            foreach ((array)($d['groups'] ?? []) as $g) {
                $gid = (string)($g['id'] ?? '');
                if ($gid !== '' && str_starts_with($gid, $prefix)) $out[$gid] = (string)($g['displayname'] ?? $gid);
            }
            return $out;
        } catch (CloudUnavailable | CloudDenied $e) {
            throw $e;
        } catch (CloudApiException $e) {
            // brak /groups/details (ownCloud) — zwykła lista identyfikatorów
        }
        $d = $this->api->ocs('GET', 'groups', ['search' => $prefix, 'limit' => 1000]);
        foreach ((array)($d['groups'] ?? []) as $gid) {
            $gid = (string)$gid;
            if ($gid !== '' && str_starts_with($gid, $prefix)) $out[$gid] = $gid;
        }
        return $out;
    }

    /** Zakłada grupę (z nazwą wyświetlaną, jeśli chmura ją obsługuje). */
    public function create(string $gid, string $displayName): void
    {
        try {
            $this->api->ocs('POST', 'groups', ['groupid' => $gid, 'displayname' => $displayName]);
        } catch (CloudUnavailable | CloudDenied $e) {
            throw $e;
        } catch (CloudApiException $e) {
            // starsza chmura bez parametru displayname — sam identyfikator, nazwę spróbujemy ustawić osobno
            $this->api->ocs('POST', 'groups', ['groupid' => $gid]);
            $this->rename($gid, $displayName);
        }
    }

    /** Zmienia nazwę wyświetlaną. Zwraca false, gdy chmura tego nie obsługuje (ownCloud). */
    public function rename(string $gid, string $displayName): bool
    {
        try {
            $this->api->ocs('PUT', 'groups/' . rawurlencode($gid), ['key' => 'displayname', 'value' => $displayName]);
            return true;
        } catch (CloudUnavailable | CloudDenied $e) {
            throw $e;
        } catch (CloudApiException $e) {
            return false;
        }
    }

    /** Identyfikatory użytkowników w grupie. */
    public function members(string $gid): array
    {
        $d = $this->api->ocs('GET', 'groups/' . rawurlencode($gid) . '/users');
        $users = $d['users'] ?? $d;
        return array_values(array_map('strval', array_filter((array)$users, fn($u) => is_scalar($u) && (string)$u !== '')));
    }

    public function addMember(string $uid, string $gid): void
    {
        $this->api->ocs('POST', 'users/' . rawurlencode($uid) . '/groups', ['groupid' => $gid]);
    }

    public function removeMember(string $uid, string $gid): void
    {
        $this->api->ocs('DELETE', 'users/' . rawurlencode($uid) . '/groups', ['groupid' => $gid]);
    }
}
