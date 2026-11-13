<?php
declare(strict_types=1);

/**
 * modules/nowe_haslo/logic/NoweHasloController.php — ekrany /nowehaslo (samodzielna strona bez menu SZO, wygląd METRO).
 *
 * GET  /nowehaslo          — zalogowany: konta i formularz zmiany; bez logowania: dane (prywatny e-mail / PESEL + adres
 *                             służbowy), potem formularz z kodem SMS;
 * POST /nowehaslo/wejscie  — gość: op=ident (dane) | restart;
 * POST /nowehaslo/kod      — wyślij kod SMS (albo ponownie);
 * POST /nowehaslo/zmien    — zmiana hasła: kod SMS + co (sso | mail) + jak (own | random) + hasło dwa razy;
 * POST /nowehaslo/zakoncz  — koniec sesji gościa.
 * Komunikaty idą przez sesję (PRG); hasła nigdy nie wracają do widoku.
 */
require_once __DIR__ . '/NoweHaslo.php';

final class NoweHasloController
{
    private const VIEW = __DIR__ . '/../view/';
    private const K_FLASH = 'nhFlash';
    private const K_FORM  = 'nhForm';   // ostatni wybór w formularzu (bez haseł), żeby po błędzie nie zaznaczać od nowa

    public function index(): string
    {
        $st = NoweHasloAuth::state();
        $p = $st['p'];
        return Router::view(self::VIEW . 'index.php', [
            'st'    => $st,
            'acc'   => $st['confirmed'] && $p ? NoweHaslo::overview($p) : null,
            'code'  => NoweHasloAuth::codeInfo(),
            'flash' => $this->take(self::K_FLASH),
            'form'  => $this->take(self::K_FORM) ?? [],
        ]);
    }

    public function guestPost(): ?string
    {
        $back = route('nh.index');
        if (current_user()) return Router::redirect($back);
        try {
            if (($_POST['op'] ?? '') === 'ident') {
                NoweHasloAuth::guestStart((string)($_POST['ident'] ?? ''), (string)($_POST['address'] ?? ''));
                return $this->flash($back, 'info', 'Jeśli dane się zgadzają, wysłaliśmy kod SMS na numer komórkowy zapisany w SZO.');
            }
            NoweHasloAuth::guestEnd();
        } catch (\RuntimeException $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        return Router::redirect($back);
    }

    public function codePost(): ?string
    {
        $back = route('nh.index');
        try {
            NoweHasloAuth::sendCode();
        } catch (\RuntimeException $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        $st = NoweHasloAuth::state();
        return $this->flash($back . '#zmiana', 'success', $st['confirmed'] && $st['p']
            ? 'Wysłaliśmy kod SMS na numer ' . DhostingMigrator::maskPhone((string)$st['p']['phone']) . '. Jest ważny 10 minut.'
            : 'Jeśli dane się zgadzają, wysłaliśmy nowy kod SMS.');
    }

    public function changePost(): ?string
    {
        $back = route('nh.index');
        $target = (string)($_POST['target'] ?? '');
        $mode = (string)($_POST['mode'] ?? '');
        $_SESSION[self::K_FORM] = ['target' => $target, 'mode' => $mode];
        $st = NoweHasloAuth::state();
        if ($st['mode'] === 'none') return $this->flash($back, 'warning', 'Sesja wygasła. Wpisz dane jeszcze raz.');
        $codeOk = false;
        try {
            $p = NoweHasloAuth::checkCode((string)($_POST['code'] ?? ''));
            if (!$p) throw new RuntimeException('Kod się nie zgadza.');      // atrapa (brak dopasowania) — tu nie dojdzie, ale na wszelki wypadek
            $codeOk = true;
            $by = $st['mode'] === 'user' ? (string)$p['email'] : 'bez logowania: ' . (string)$p['address'];
            $msg = NoweHaslo::change($p, $target, $mode, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''), $by);
            NoweHasloAuth::consumeCode();                                   // każda kolejna zmiana = nowy kod SMS
        } catch (\RuntimeException $e) {
            if ($codeOk) NoweHasloAuth::changeFailed();
            try {
                require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
                audit_log('nowehaslo.failed', ['target' => $target, 'mode' => $mode, 'error' => $e->getMessage(), 'who' => $st['p']['key'] ?? 'gość']);
            } catch (\Throwable $e2) {}
            return $this->flash($back . '#zmiana', 'danger', $e->getMessage());
        }
        unset($_SESSION[self::K_FORM]);
        return $this->flash($back, 'success', $msg);
    }

    public function endPost(): ?string
    {
        NoweHasloAuth::guestEnd();
        return $this->flash(route('nh.index'), 'success', 'Zakończono. Aby wrócić, wpisz dane jeszcze raz.');
    }

    private function flash(string $back, string $type, string $msg): ?string
    {
        $_SESSION[self::K_FLASH] = ['type' => $type, 'msg' => $msg];
        return Router::redirect($back);
    }

    private function take(string $key): mixed
    {
        $v = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $v;
    }
}
