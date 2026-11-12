<?php
/**
 * modules/account_security/logic/AccountSecurityController.php — akcje modułu bezpieczeństwa konta (System Tożsamości).
 *
 * Każda akcja dotyczy WYŁĄCZNIE konta zalogowanej osoby (user_id z sesji; id wierszy z formularza są sprawdzane
 * względem właściciela). CSRF sprawdza Router. Po akcji powrót do sekcji Tożsamości z komunikatem (flash).
 */

require_once dirname(__DIR__) . '/lib/accountSecurityDevices.php';
require_once dirname(__DIR__) . '/lib/accountSecurityM365.php';
require_once dirname(__DIR__) . '/lib/accountSecurityNotify.php';

final class AccountSecurityController
{
    /** Fabryka klienta Graph (testy hermetyczne podstawiają atrapę); null = M365Graph z ustawień. */
    public static ?\Closure $graphFactory = null;

    private static function back(string $hash, array $query = []): string
    {
        return APP_URL . '/tozsamosc/index.php' . ($query ? '?' . http_build_query($query) : '') . '#' . $hash;
    }

    private static function me(): array
    {
        $u = current_user();
        $row = db_one("SELECT * FROM users WHERE id=?", [(int)($u['id'] ?? 0)]);
        if (!$row) { http_response_code(403); exit('Brak konta.'); }
        return $row;
    }

    /**
     * Unieważnia sesje logowania w Microsoft 365 (Entra ID: revokeSignInSessions) dla konta powiązanego przez microsoft_id.
     * @return array{state:string, msg?:string} state: ok | err | skip (integracja wyłączona) | none (brak konta Microsoft)
     */
    public static function revokeMicrosoftSessions(array $dbUser): array
    {
        $oid = trim((string)($dbUser['microsoft_id'] ?? ''));
        if ($oid === '') return ['state' => 'none'];
        try {
            if (self::$graphFactory) {
                $g = (self::$graphFactory)();
            } else {
                require_once dirname(__DIR__, 3) . '/includes/m365.php';
                if (m365_setting('m365_enabled') !== '1') return ['state' => 'skip', 'msg' => 'integracja Microsoft 365 jest wyłączona'];
                $g = new M365Graph();
                if (!$g->is_configured()) return ['state' => 'skip', 'msg' => 'integracja Microsoft 365 nie jest skonfigurowana'];
            }
            $g->revoke_sessions($oid);   // wyjątek, gdy Graph nie zwróci 2xx
            return ['state' => 'ok'];
        } catch (\Throwable $e) {
            return ['state' => 'err', 'msg' => $e->getMessage()];
        }
    }

    /** Zdanie o wyniku unieważnienia sesji Microsoft 365 (do komunikatu i dziennika); '' gdy nie dotyczy. */
    private static function m365Sentence(array $r): string
    {
        return match ($r['state']) {
            'ok'    => ' Wylogowano też wszystkie sesje Microsoft 365 (Outlook, Teams, OneDrive — także na telefonie).',
            'err'   => ' UWAGA: nie udało się wylogować sesji Microsoft 365: ' . $r['msg'],
            'skip'  => ' Sesji Microsoft 365 nie wylogowano (' . $r['msg'] . ').',
            default => '',
        };
    }

    /** POST /sesje/zakoncz — kończy jedną sesję (nie bieżącą). */
    public function endSession(): ?string
    {
        $me = self::me();
        $ok = AccountSecurityDevices::endSession((int)$me['id'], (int)($_POST['id'] ?? 0));
        if ($ok) {
            authlog_write((int)$me['id'], 'sessions_ended', (string)$me['email'], 'Zakończono jedną sesję (Tożsamość)');
            flash_set('success', 'Sesja została zakończona — to urządzenie zostanie wylogowane przy najbliższym działaniu.');
        } else {
            flash_set('warning', 'Nie znaleziono tej sesji (mogła już wygasnąć). Bieżącej sesji nie można zakończyć tutaj — użyj „Wyloguj”.');
        }
        header('Location: ' . self::back('urzadzenia')); return null;
    }

    /** POST /sesje/zakoncz-pozostale — kończy wszystkie inne sesje; opcjonalnie także sesje Microsoft 365. */
    public function endOtherSessions(): ?string
    {
        $me = self::me();
        $n = AccountSecurityDevices::endOtherSessions((int)$me['id']);
        $m = !empty($_POST['m365']) ? self::revokeMicrosoftSessions($me) : ['state' => 'none'];
        $msg = ($n > 0 ? 'Wylogowano pozostałe urządzenia (' . $n . ').' : 'Nie było innych aktywnych sesji w SZO.') . self::m365Sentence($m);
        authlog_write((int)$me['id'], 'sessions_ended', (string)$me['email'], trim('Wylogowano pozostałe sesje SZO: ' . $n . '.' . self::m365Sentence($m)));
        flash_set($m['state'] === 'err' ? 'warning' : 'success', $msg);
        header('Location: ' . self::back('urzadzenia')); return null;
    }

    /** POST /urzadzenia/zapomnij — zapomina znane urządzenie (kolejne logowanie z niego trzeba będzie potwierdzić). */
    public function forgetDevice(): ?string
    {
        $me = self::me();
        if (AccountSecurityDevices::forgetDevice((int)$me['id'], (int)($_POST['id'] ?? 0))) {
            authlog_write((int)$me['id'], 'device_forgotten', (string)$me['email'], 'Zapomniano znane urządzenie (Tożsamość)');
            flash_set('success', 'Urządzenie zapomniane. Następne logowanie z niego trzeba będzie potwierdzić.');
        } else {
            flash_set('warning', 'Nie znaleziono tego urządzenia.');
        }
        header('Location: ' . self::back('urzadzenia')); return null;
    }

    /** POST /powiadomienia — zapis preferencji powiadomień o bezpieczeństwie (n[zdarzenie][email|sms]). */
    public function saveNotify(): ?string
    {
        $me = self::me();
        AccountSecurityNotify::savePrefs((int)$me['id'], (array)($_POST['n'] ?? []));
        flash_set('success', 'Zapisano ustawienia powiadomień o bezpieczeństwie.');
        header('Location: ' . self::back('bezpieczenstwo', ['sec' => 'powiadomienia'])); return null;
    }

    /** POST /powiadomienia/test — wiadomość testowa na włączone kanały (najwyżej raz na minutę). */
    public function testNotify(): ?string
    {
        $me = self::me();
        $back = self::back('bezpieczenstwo', ['sec' => 'powiadomienia']);
        if ((int)($_SESSION['account_security_test_at'] ?? 0) > time() - 60) {
            flash_set('warning', 'Powiadomienie testowe można wysłać raz na minutę — spróbuj za chwilę.');
            header('Location: ' . $back); return null;
        }
        $_SESSION['account_security_test_at'] = time();
        $r = accountSecurityNotify((int)$me['id'], 'test');
        $parts = [];
        $parts[] = match ($r['email']) {
            true    => 'e-mail wysłany na ' . $me['email'],
            false   => 'e-mail NIE został wysłany (błąd poczty)',
            default => 'e-mail pominięty (login nie jest adresem e-mail)',
        };
        if ($r['sms'] !== null) $parts[] = $r['sms'] ? 'SMS wysłany' : 'SMS NIE został wysłany';
        flash_set(in_array(false, $r, true) || $r['email'] === null ? 'warning' : 'success', 'Powiadomienie testowe: ' . implode(', ', $parts) . '.');
        header('Location: ' . $back); return null;
    }

    /** POST /m365/sprawdz — odczyt stanu konta Microsoft 365 z Entra ID (tylko odczyt); wynik w sesji, pokazuje sekcja Usługi. */
    public function checkM365(): ?string
    {
        $me = self::me();
        AccountSecurityM365::store((int)$me['id'], AccountSecurityM365::fetch($me));
        header('Location: ' . self::back('uslugi')); return null;
    }

    /**
     * POST /to-nie-ja — „Nie rozpoznaję tej aktywności”: wylogowuje inne sesje SZO i Microsoft 365, zapomina inne urządzenia
     * i adresy, zapisuje zgłoszenie w historii i prowadzi do zmiany hasła.
     */
    public function lockdown(): ?string
    {
        $me  = self::me();
        $uid = (int)$me['id'];
        $n   = AccountSecurityDevices::endOtherSessions($uid);
        $d   = AccountSecurityDevices::forgetOtherDevices($uid);
        $m   = self::revokeMicrosoftSessions($me);
        authlog_write($uid, 'security_lockdown', (string)$me['email'],
            'Zgłoszono nierozpoznaną aktywność: wylogowano sesje SZO (' . $n . '), zapomniano urządzenia i adresy (' . $d . ').' . self::m365Sentence($m));
        accountSecurityNotify($uid, 'lockdown', ['sessions' => $n]);   // potwierdzenie e-mailem (zawsze)
        flash_set($m['state'] === 'err' ? 'warning' : 'success',
            'Konto zabezpieczone: wylogowano inne urządzenia (' . $n . ') i zapomniano nieznane miejsca logowania.'
            . self::m365Sentence($m) . ' Teraz ustaw nowe hasło.');
        header('Location: ' . self::back('bezpieczenstwo', ['sec' => 'haslo'])); return null;
    }
}
