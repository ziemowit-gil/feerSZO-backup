<?php
declare(strict_types=1);

/**
 * modules/gosposia/logic/GosposiaController.php — ekrany /pomocne/gosposia (zalogowani użytkownicy SZO).
 *
 * GET  /pomocne/gosposia                    — formularz połączenia skrzynki albo panel sprzątania;
 * POST /pomocne/gosposia/polacz             — sprawdzenie loginu na imap.dpoczta.pl i zapis poświadczeń (PRG);
 * POST /pomocne/gosposia/rozlacz            — zapomnij skrzynkę (sesja + baza) (PRG);
 * POST /pomocne/gosposia/przeglad           — JSON: nadawcy do sprzątania;
 * POST /pomocne/gosposia/biala-lista        — JSON: dodaj adres albo domenę;  …/biala-lista/usun — JSON: usuń własny wpis;
 * POST /pomocne/gosposia/kosz               — JSON: przenieś zatwierdzone UID-y do Kosza (po potwierdzeniu w oknie).
 * Żądania fetch wysyłają FormData z polem _csrf (Router + csrf_check). Hasła nigdy nie wracają do widoku ani do dzienników.
 */
require_once __DIR__ . '/Gosposia.php';

final class GosposiaController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string
    {
        $u = current_user();
        $uid = (int)$u['id'];
        $cred = Gosposia::credentials($uid);
        return Router::view(self::VIEW . 'index.php', [
            'user'      => $u,
            'cred'      => $cred ? ['email' => $cred['email'], 'remembered' => $cred['remembered']] : null,
            'whitelist' => GosposiaStore::whitelist($uid),
            'aiReady'   => GosposiaClassifier::apiKey() !== '',
            'limit'     => Gosposia::scanLimit(),
        ]);
    }

    public function connect(): ?string
    {
        $uid = (int)current_user()['id'];
        try {
            Gosposia::connect($uid, (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), !empty($_POST['remember']));
            flash_set('success', 'Skrzynka połączona. Kliknij „Przejrzyj skrzynkę”.');
        } catch (\RuntimeException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('gosposia.index'));
    }

    public function disconnect(): ?string
    {
        Gosposia::disconnect((int)current_user()['id']);
        flash_set('info', 'Gosposia zapomniała Twoją skrzynkę i hasło.');
        return Router::redirect(route('gosposia.index'));
    }

    public function scan(): array
    {
        @set_time_limit(300);
        $uid = (int)current_user()['id'];
        $cred = Gosposia::credentials($uid);
        if (!$cred) return $this->fail('Najpierw połącz skrzynkę.', 409);
        try {
            return ['ok' => true] + Gosposia::scan($uid, $cred);
        } catch (GosposiaImapAuthFailed $e) {
            return $this->fail($e->getMessage() . ' Połącz skrzynkę ponownie.', 401);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 502);
        }
    }

    public function whitelistAdd(): array
    {
        $uid = (int)current_user()['id'];
        try {
            $p = GosposiaStore::whitelistAdd($uid, (string)($_POST['pattern'] ?? ''));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Gosposia::forgetWhitelisted($uid);
        $own = GosposiaStore::whitelist($uid)['own'];
        return ['ok' => true, 'pattern' => $p, 'own' => $own];
    }

    public function whitelistRemove(): array
    {
        $uid = (int)current_user()['id'];
        GosposiaStore::whitelistRemove($uid, (int)($_POST['id'] ?? 0));
        return ['ok' => true, 'own' => GosposiaStore::whitelist($uid)['own']];
    }

    public function trash(): array
    {
        @set_time_limit(180);
        $uid = (int)current_user()['id'];
        $cred = Gosposia::credentials($uid);
        if (!$cred) return $this->fail('Najpierw połącz skrzynkę.', 409);
        $sel = json_decode((string)($_POST['selection'] ?? ''), true);
        if (!is_array($sel)) return $this->fail('Nie zaznaczono żadnej wiadomości.', 422);
        try {
            return ['ok' => true] + Gosposia::moveToTrash($uid, $cred, $sel);
        } catch (GosposiaImapAuthFailed $e) {
            return $this->fail($e->getMessage(), 401);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    private function fail(string $msg, int $code): array
    {
        http_response_code($code);
        return ['ok' => false, 'error' => $msg];
    }
}
