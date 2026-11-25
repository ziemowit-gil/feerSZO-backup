<?php
declare(strict_types=1);

/**
 * modules/kontoBezosobowe/logic/KontoBezosoboweController.php — /kontabezosobowe (administrator + IKA).
 * Hasła trzymamy w sesji tylko 15 minut — do pokazania raz i do wydruku PDF.
 */
require_once __DIR__ . '/KontoBezosobowe.php';
require_once dirname(__DIR__, 2) . '/dhosting_migrator/lib/DhostingCredPdf.php';

final class KontoBezosoboweController
{
    private const VIEW = __DIR__ . '/../view/';
    private const K_FLASH = 'kboFlash';
    private const K_NEW = 'kboNew';     // ostatnio nadane dane (z hasłem) — do pokazania i PDF
    private const TTL = 900;

    public function index(): string
    {
        ika_require();
        return Router::view(self::VIEW . 'index.php', [
            'accounts' => KontoBezosobowe::all(),
            'ready'    => KontoBezosobowe::ready(),
            'domain'   => DhostingContractMail::domain(),
            'fresh'    => $this->fresh(),
            'flash'    => $this->take(self::K_FLASH),
        ]);
    }

    public function create(): ?string
    {
        $back = route('kbo.index');
        if (!ika_ok($back)) return $this->flash($back, 'warning', 'Sesja kodu IKA wygasła. Konta NIE założono. Potwierdź kod IKA i powtórz.');
        try {
            $res = KontoBezosobowe::create((string)($_POST['label'] ?? ''), (string)($_POST['note'] ?? ''), $this->by());
        } catch (\Throwable $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        $this->adminAudit('impersonal_create', 'Konto bezosobowe ' . $res['mailbox'] . ' (' . $res['label'] . ')', $res['id']);
        $_SESSION[self::K_NEW] = ['until' => time() + self::TTL] + $res;
        return $this->flash($back . '#wynik', 'success', 'Założono konto bezosobowe ' . $res['mailbox'] . '.');
    }

    public function password(): ?string
    {
        $back = route('kbo.index');
        if (!ika_ok($back)) return $this->flash($back, 'warning', 'Sesja kodu IKA wygasła. Hasła NIE zmieniono. Potwierdź kod IKA i powtórz.');
        try {
            $res = KontoBezosobowe::newPassword((int)($_POST['id'] ?? 0), $this->by());
        } catch (\Throwable $e) {
            return $this->flash($back, 'danger', $e->getMessage());
        }
        $this->adminAudit('impersonal_password', 'Nowe hasło konta bezosobowego ' . $res['mailbox'], $res['id']);
        $_SESSION[self::K_NEW] = ['until' => time() + self::TTL] + $res;
        return $this->flash($back . '#wynik', 'success', 'Nadano nowe hasło do ' . $res['mailbox'] . '.');
    }

    /** GET /kontabezosobowe/pdf — dane logowania z hasłem, tylko 15 minut po założeniu / zmianie hasła. */
    public function pdf(): ?string
    {
        $back = route('kbo.index');
        $n = $this->fresh();
        if (!$n) return $this->flash($back, 'warning', 'Nie ma danych do wydruku. Hasło można wydrukować tylko przez 15 minut od jego nadania. Nadaj nowe hasło.');
        try { audit_log('impersonal_account.pdf', ['id' => $n['id'], 'mailbox' => $n['mailbox']]); } catch (\Throwable $e) {}
        $org = function_exists('org_setting') ? (org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja')) : 'Fundacja';
        $bytes = DhostingCredPdf::render([[
            'name' => 'Konto bezosobowe: ' . $n['label'],
            'mailbox' => $n['mailbox'],
            'mail_password' => $n['password'],
            'issued_at' => $n['issued_at'],
        ]], (string)$org);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="konto-bezosobowe-' . preg_replace('/@.*/', '', (string)$n['mailbox']) . '.pdf"');
        header('Cache-Control: no-store, private');
        echo $bytes;
        exit;
    }

    private function fresh(): ?array
    {
        $n = $_SESSION[self::K_NEW] ?? null;
        if (!is_array($n) || (int)($n['until'] ?? 0) < time()) { unset($_SESSION[self::K_NEW]); return null; }
        return $n;
    }

    private function by(): string
    {
        $me = function_exists('ctx_real_user') ? (ctx_real_user() ?: current_user()) : current_user();
        return (string)($me['email'] ?? '');
    }

    private function adminAudit(string $action, string $msg, int $id): void
    {
        try {
            if (function_exists('admin_audit_migrate')) admin_audit_migrate();
            if (function_exists('admin_audit')) admin_audit($action, 'impersonal_account', $msg, $id);
        } catch (\Throwable $e) { error_log('[kontoBezosobowe] admin_audit: ' . $e->getMessage()); }
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
