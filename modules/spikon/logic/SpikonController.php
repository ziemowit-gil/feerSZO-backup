<?php
/**
 * modules/spikon/logic/SpikonController.php — ustawienia telefonii Spikon w CRM, książki telefoniczne i wybieranie numeru.
 * Ustawienia / książki / konta: uprawnienie CRM „Ustawienia” (zapis). Wybieranie numeru: „Kontakty” (odczyt) + dostęp do kontaktu.
 */
require_once dirname(__DIR__, 3) . '/includes/crm.php';
require_once __DIR__ . '/SpikonService.php';

final class SpikonController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string
    {
        crm_migrate();
        SpikonService::migrate();
        $configured = SpikonService::isConfigured();
        $remoteError = null; $accounts = []; $balance = null;
        if ($configured) {
            try {
                $accounts = SpikonService::client()->phoneAccounts();
                SpikonService::cacheAccounts($accounts);
            } catch (\Throwable $e) {
                $remoteError = $e->getMessage();
            }
        }
        $users = db_all("SELECT id, name FROM users WHERE COALESCE(is_active,1)=1 AND role IN ('admin','editor','crm_user') ORDER BY name COLLATE NOCASE");
        $map = [];
        foreach (db_all("SELECT user_id, phone_account_id FROM spikon_user_accounts") as $r) $map[(int)$r['user_id']] = (int)$r['phone_account_id'];
        return $this->page('CRM — Telefonia Spikon', Router::view(self::VIEW . 'index.php', [
            'enabled' => SpikonService::setting('spikon_enabled') === '1',
            'configured' => $configured,
            'base' => SpikonService::setting('spikon_api_base'),
            'defaultAccount' => (int)SpikonService::setting('spikon_default_account_id'),
            'accounts' => $accounts, 'remoteError' => $remoteError,
            'users' => $users, 'map' => $map,
            'books' => SpikonService::books(),
            'groups' => SpikonService::unlinkedGroups(),
        ]));
    }

    public function saveSettings(): ?string
    {
        SpikonService::migrate();
        $key = trim((string)($_POST['spikon_api_key'] ?? ''));
        if ($key !== '') SpikonService::settingSet('spikon_api_key', $key);   // puste pole = zostaw dotychczasowy klucz
        if (!empty($_POST['clear_key'])) SpikonService::settingSet('spikon_api_key', '');
        SpikonService::settingSet('spikon_enabled', !empty($_POST['spikon_enabled']) ? '1' : '0');
        $base = rtrim(trim((string)($_POST['spikon_api_base'] ?? '')), '/');
        if ($base !== '' && !preg_match('#^https?://[^\s/]+$#i', $base)) {
            flash_set('danger', 'Adres API musi mieć postać https://host (bez ścieżki).');
            return Router::redirect(route('spikon.index'));
        }
        SpikonService::settingSet('spikon_api_base', $base);
        SpikonService::settingSet('spikon_default_account_id', (string)max(0, (int)($_POST['spikon_default_account_id'] ?? 0)));
        audit_log('spikon.settings_save', ['enabled' => !empty($_POST['spikon_enabled']), 'key_changed' => $key !== '']);
        flash_set('success', 'Ustawienia Spikon zapisane.');
        return Router::redirect(route('spikon.index'));
    }

    /** Sprawdza klucz, białą listę IP i dostępność API: saldo + liczba kont telefonicznych. */
    public function test(): ?string
    {
        try {
            $c = SpikonService::client();
            $balance = $c->balance();
            $n = count($c->phoneAccounts());
            flash_set('success', 'Połączenie działa. Saldo: ' . number_format($balance, 2, ',', ' ') . ' zł, kont telefonicznych: ' . $n . '.');
        } catch (\Throwable $e) {
            flash_set('danger', 'Test nieudany: ' . $e->getMessage());
        }
        return Router::redirect(route('spikon.index'));
    }

    public function saveAccounts(): ?string
    {
        $map = [];
        foreach ((array)($_POST['account'] ?? []) as $uid => $acc) $map[(int)$uid] = (int)$acc;
        SpikonService::saveAccountMap($map);
        audit_log('spikon.accounts_save', ['users' => count(array_filter($map))]);
        flash_set('success', 'Przypisania kont telefonicznych zapisane.');
        return Router::redirect(route('spikon.index'));
    }

    public function linkGroup(): ?string
    {
        try {
            $id = SpikonService::linkGroup((int)($_POST['group_id'] ?? 0), (int)current_user()['id']);
            $r = SpikonService::syncBook($id);
            flash_set($r['errors'] ? 'warning' : 'success', 'Książka założona. ' . $this->syncSummary($r));
        } catch (\Throwable $e) {
            flash_set('danger', 'Nie udało się założyć książki: ' . $e->getMessage());
        }
        return Router::redirect(route('spikon.index'));
    }

    public function sync(int $id): ?string
    {
        try {
            $r = SpikonService::syncBook($id);
            flash_set($r['errors'] ? 'warning' : 'success', $this->syncSummary($r));
        } catch (\Throwable $e) {
            flash_set('danger', 'Synchronizacja nieudana: ' . $e->getMessage());
        }
        return Router::redirect(route('spikon.index'));
    }

    public function unlink(int $id): ?string
    {
        try {
            SpikonService::unlinkBook($id, !empty($_POST['delete_remote']));
            flash_set('success', 'Książka odpięta od grupy' . (!empty($_POST['delete_remote']) ? ' i skasowana w Spikon.' : ' (w Spikon została).'));
        } catch (\Throwable $e) {
            flash_set('danger', 'Nie udało się odpiąć: ' . $e->getMessage());
        }
        return Router::redirect(route('spikon.index'));
    }

    /** POST /zadzwon (JSON): contact_id [+ account_id = numer VoIP] → dzwoni numer VoIP, po odebraniu łączy z kontaktem. */
    public function call(): array
    {
        $cid = (int)($_POST['contact_id'] ?? 0);
        if (!$cid || !crm_can_access_contact($cid)) { http_response_code(403); return ['ok' => false, 'error' => 'Brak dostępu do tego kontaktu.']; }
        try {
            $from = SpikonService::dial((int)current_user()['id'], $cid, ((int)($_POST['account_id'] ?? 0)) ?: null);
            return ['ok' => true, 'message' => 'Dzwonię z numeru ' . $from . ' — odbierz, a połączę Cię z kontaktem. Połączenie zapisano w historii.',
                    'history' => 'Z numeru: ' . $from . "\nNa numer: " . trim((string)(crm_one("SELECT telefon FROM crm_contacts WHERE id=?", [$cid])['telefon'] ?? ''))];
        } catch (\Throwable $e) {
            http_response_code($e instanceof SpikonException ? 502 : 422);   // 502 = zawiódł Spikon, 422 = brak numeru / konta / telefonii
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function syncSummary(array $r): string
    {
        $s = 'Dodano wpisów: ' . $r['added'] . ', usunięto: ' . $r['removed'] . '.';
        if ($r['remaining'] > 0) $s .= ' Zostało do zrobienia: ' . $r['remaining'] . ' — uruchom synchronizację ponownie.';
        if ($r['errors']) $s .= ' Błędy: ' . implode(' | ', array_slice($r['errors'], 0, 2));
        return $s;
    }

    private function page(string $title, string $body): string
    {
        $PAGE_TITLE = $title;
        global $_uri;   // header_crm.php: _crm_nav_active() czyta globalne $_uri, a tu include działa w zakresie metody
        ob_start();
        include dirname(__DIR__, 3) . '/crm/includes/header_crm.php';
        require_once dirname(__DIR__, 3) . '/crm/settings/_nav.php';
        echo $body;
        require_once dirname(__DIR__, 3) . '/crm/settings/_nav_end.php';
        include dirname(__DIR__, 3) . '/crm/includes/footer_crm.php';
        return (string)ob_get_clean();
    }
}
