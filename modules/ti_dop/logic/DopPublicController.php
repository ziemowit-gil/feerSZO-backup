<?php
declare(strict_types=1);

/**
 * modules/ti_dop/logic/DopPublicController.php — portal: podpis elektroniczny wniosku DOP1 / DOP2 przez wnioskodawcę
 * (link z e-maila / SMS; token 48 hex, w bazie SHA-256, 30 dni, jednorazowy; CSRF, honeypot, limit prób z IP).
 */
require_once dirname(__DIR__) . '/lib/DopService.php';

final class DopPublicController
{
    private const VIEW = __DIR__ . '/../view/public/';
    private DopService $svc;

    public function __construct()
    {
        $this->svc = new DopService();
    }

    private function view(string $file, array $data): string
    {
        return Router::view(self::VIEW . $file, $data + ['org' => ldOrgName()]);
    }

    private function message(string $title, string $text, string $tone, array $numbers = [], string $sym = 'DOP'): string
    {
        return Router::view(dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/public/message.php', ['title' => $title, 'text' => $text, 'tone' => $tone, 'numbers' => $numbers, 'org' => ldOrgName(), 'sym' => $sym]);
    }

    private function throttled(): bool
    {
        $k = 'dop_fail_' . md5((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $row = $_SESSION[$k] ?? ['n' => 0, 't' => time()];
        return time() - $row['t'] <= 900 && $row['n'] >= 10;
    }

    private function bad(): string
    {
        $k = 'dop_fail_' . md5((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $row = $_SESSION[$k] ?? ['n' => 0, 't' => time()];
        if (time() - $row['t'] > 900) $row = ['n' => 0, 't' => time()];
        $_SESSION[$k] = ['n' => $row['n'] + 1, 't' => $row['t']];
        usleep(300000);
        http_response_code(404);
        return $this->message('Link jest nieaktualny', 'Ten link wygasł, został już wykorzystany albo jest niepełny. Skontaktuj się z biurem ' . ldOrgName() . '.', 'danger');
    }

    public function form(string $token, array $errors = [], array $old = []): string
    {
        if ($this->throttled()) { http_response_code(429); return $this->message('Zbyt wiele prób', 'Spróbuj ponownie za kilkanaście minut.', 'danger'); }
        $r = $this->svc->bySignToken((string)$token);
        if (!$r) return $this->bad();
        return $this->view('sign.php', ['r' => $r, 'token' => $token, 'errors' => $errors, 'old' => $old ?: ['name' => $r['client_name']], 'sym' => $r['form']]);
    }

    public function pdf(string $token): ?string
    {
        $r = $this->svc->bySignToken((string)$token);
        if (!$r) return $this->bad();
        $out = $this->svc->document($r);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $out['mime'] . ($out['ext'] === 'html' ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename="' . str_replace('/', '-', (string)$r['number']) . '.' . $out['ext'] . '"');
        header('Cache-Control: no-store, private');
        echo $out['bytes'];
        return null;
    }

    public function submit(string $token): ?string
    {
        $r = $this->svc->bySignToken((string)$token);
        if (!$r) return $this->bad();
        if (trim((string)($_POST['www'] ?? '')) !== '') return Router::redirect(route('dop.pub.done'));
        $err = $this->svc->confirm($r, LdInput::str($_POST, 'name', 190), LdInput::bool($_POST, 'confirm'));
        if ($err) return $this->form($token, $err, $_POST);
        $_SESSION['dop_done'] = (string)$r['number'];
        return Router::redirect(route('dop.pub.done'));
    }

    public function done(): string
    {
        $n = (string)($_SESSION['dop_done'] ?? '');
        unset($_SESSION['dop_done']);
        return $this->message('Wniosek podpisany', 'Dziękujemy. Wniosek trafił do weryfikacji — o decyzji poinformujemy e-mailem (dokument w PDF). Realizacja do ' . LD_REALIZATION_DAYS . ' dni.', 'success', $n !== '' ? [$n] : []);
    }
}
