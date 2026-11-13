<?php
declare(strict_types=1);

/**
 * modules/gosposia/logic/GosposiaController.php — ekrany /pomocne/gosposia (zalogowani użytkownicy SZO).
 *
 * GET  /pomocne/gosposia                    — samodzielna strona (bez menu SZO): połączenie poczty albo sprzątanie;
 * Dostęp: administrator i współpracownicy (osoby z umową) — middleware gosposia.access w router.php;
 * POST /pomocne/gosposia/polacz             — sprawdzenie loginu na imap.dpoczta.pl i zapis poświadczeń (PRG);
 * POST /pomocne/gosposia/rozlacz            — zapomnij skrzynkę (sesja + baza) (PRG);
 * POST /pomocne/gosposia/przeglad           — JSON: nadawcy do sprzątania;
 * POST /pomocne/gosposia/biala-lista        — JSON: dodaj adres albo domenę;  …/biala-lista/usun — JSON: usuń własny wpis;
 * POST /pomocne/gosposia/kosz               — JSON: przenieś zatwierdzone UID-y do Kosza (po potwierdzeniu w oknie).
 * Powiadomienia e-mail (start przeglądu, podsumowanie przeglądu, podsumowanie sprzątania): GosposiaNotify.
 * Żądania fetch wysyłają FormData z polem _csrf (Router + csrf_check). Hasła nigdy nie wracają do widoku ani do dzienników.
 */
require_once __DIR__ . '/Gosposia.php';
require_once __DIR__ . '/GosposiaNotify.php';

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
            'flash'     => flash_get(),
        ]);
    }

    public function connect(): ?string
    {
        $uid = (int)current_user()['id'];
        try {
            Gosposia::connect($uid, (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), !empty($_POST['remember']));
            flash_set('success', 'Poczta połączona. Kliknij „Sprawdź pocztę”.');
        } catch (\RuntimeException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('gosposia.index'));
    }

    public function disconnect(): ?string
    {
        Gosposia::disconnect((int)current_user()['id']);
        flash_set('info', 'Poczta odłączona. Gosposia nie pamięta już hasła.');
        return Router::redirect(route('gosposia.index'));
    }

    public function scan(): array
    {
        @set_time_limit(300);
        $uid = (int)current_user()['id'];
        $cred = Gosposia::credentials($uid);
        if (!$cred) return $this->fail('Najpierw połącz pocztę.', 409);
        // Przegląd już trwa (np. podwójne kliknięcie) — bez drugiej pary maili.
        $running = !in_array(Gosposia::progressRead($uid)['stage'], ['idle', 'done', 'error'], true);
        // Sesja zamknięta na czas przeglądu, żeby równoległe GET /postep nie czekały na jej blokadę.
        Gosposia::progressWrite($uid, ['stage' => 'start', 'done' => 0, 'total' => 0]);
        session_write_close();
        $user = current_user();
        if (!$running) GosposiaNotify::started($user, (string)$cred['email']);
        try {
            $res = ['ok' => true] + Gosposia::scan($uid, $cred, null, null, true);
            if (!$running) GosposiaNotify::scanned($user, (string)$cred['email'], $res);
        } catch (GosposiaImapAuthFailed $e) {
            $res = $this->fail($e->getMessage() . ' Odłącz pocztę i połącz ją jeszcze raz.', 401);
        } catch (\RuntimeException $e) {
            $res = $this->fail($e->getMessage(), 502);
        }
        if (empty($res['ok'])) Gosposia::progressWrite($uid, ['stage' => 'error', 'done' => 0, 'total' => 0]);
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); Gosposia::persistScan(); }
        return $res;
    }

    /** GET: postęp trwającego przeglądu (bez tematów i adresów). */
    public function progress(): array
    {
        return ['ok' => true] + Gosposia::progressRead((int)current_user()['id']);
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
        if (!$cred) return $this->fail('Najpierw połącz pocztę.', 409);
        $sel = json_decode((string)($_POST['selection'] ?? ''), true);
        if (!is_array($sel)) return $this->fail('Nie zaznaczono żadnej wiadomości.', 422);
        try {
            $res = ['ok' => true] + Gosposia::moveToTrash($uid, $cred, $sel);
            GosposiaNotify::trashed(current_user(), (string)$cred['email'], $res);
            return $res;
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
