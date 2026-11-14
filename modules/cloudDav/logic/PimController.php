<?php
declare(strict_types=1);

require_once __DIR__ . '/PimImport.php';

/**
 * modules/cloudDav/logic/PimController.php — kalendarze i kontakty z Microsoft 365 do chmury (PimImport).
 *   GET  /kalendarz-kontakty/m365                — administrator: stan zadań, zlecanie, ponawianie;
 *   POST /kalendarz-kontakty/m365/zlec           — administrator: wszystkie konta Microsoft 365 albo jedno (pole upn);
 *   POST /kalendarz-kontakty/m365/zadanie        — administrator: ponów (kopia od nowa), importuj ponownie, anuluj (pola id, op);
 *   POST /kalendarz-kontakty/przenies            — użytkownik: przenieś moje kalendarze i kontakty (własne konta Microsoft).
 */
final class PimController
{
    private const VIEW = __DIR__ . '/../view/';

    public function admin(): string
    {
        $flash = function_exists('flash_get') ? flash_get() : null;
        return Router::view(self::VIEW . 'pimAdmin.php', [
            'jobs' => PimImport::jobs(), 'counts' => PimImport::counts(), 'flash' => $flash,
            'enabled' => PimImport::enabled(), 'exportOk' => PimImport::exportAvailable(), 'importOk' => PimImport::importAvailable(), 'diag' => NcPim::diagnose(),
            'org' => (string)(org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO')),
        ]);
    }

    /** POST /kalendarz-kontakty/m365/wlacz — wyłącznik cloud_pim_enabled (on=1 włącza, inaczej wyłącza). */
    public function toggle(): ?string
    {
        $on = ($_POST['on'] ?? '') === '1';
        org_setting_set('cloud_pim_enabled', $on ? '1' : '0');
        if (function_exists('audit_log')) { try { audit_log('cloud.pim.' . ($on ? 'enabled' : 'disabled'), ['by' => (string)(current_user()['email'] ?? '')]); } catch (\Throwable $e) {} }
        $this->flash('success', $on ? 'Włączone. Agent zacznie robić zadania w ciągu 5 minut, a użytkownicy zobaczą przycisk na stronie Kalendarz i kontakty.' : 'Wyłączone. Agent nie robi zadań, kopie zostają.');
        return Router::redirect($this->adminUrl());
    }

    public function queue(): ?string
    {
        $by = (string)(current_user()['email'] ?? 'admin');
        try {
            if (($_POST['scope'] ?? '') === 'all') {
                $n = PimImport::queueAll($by);
                $this->flash('success', 'Dodano zadań: ' . $n . '. Agent zrobi kopie i import w tle, porcjami co 5 minut.');
            } else {
                $id = PimImport::queue((string)($_POST['upn'] ?? ''), '', $by);
                $this->flash('success', 'Zadanie #' . $id . ' w kolejce.');
            }
        } catch (\Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }
        return Router::redirect($this->adminUrl());
    }

    public function jobAction(): ?string
    {
        $id = (int)($_POST['id'] ?? 0);
        $job = PimImport::job($id);
        $by = (string)(current_user()['email'] ?? 'admin');
        if (!$job) { $this->flash('danger', 'Nie ma takiego zadania.'); return Router::redirect($this->adminUrl()); }
        $op = (string)($_POST['op'] ?? '');
        $ok = match ($op) {
            'again'    => PimImport::queue((string)$job['upn'], (string)$job['email'], $by) > 0,
            'reimport' => PimImport::reimport($id, $by),
            'cancel'   => PimImport::cancel($id),
            default    => false,
        };
        $this->flash($ok ? 'success' : 'warning', $ok ? 'Zrobione: zadanie #' . $id . '.' : 'Nie udało się (zadanie #' . $id . ').');
        return Router::redirect($this->adminUrl());
    }

    /** Użytkownik zleca przeniesienie swoich kalendarzy i kontaktów (tylko własne konta Microsoft, bez wpisywania adresu). */
    public function self(): ?string
    {
        $me = current_user();
        $u = db_one("SELECT id, email, m365_login FROM users WHERE id=?", [(int)($me['id'] ?? 0)]) ?: [];
        $upns = PimImport::upnsFor($u);
        $back = rtrim(APP_URL, '/') . '/kalendarz-kontakty#m365';
        if (!PimImport::enabled()) { $this->flash('warning', 'Przenoszenie z Microsoft 365 nie jest jeszcze włączone.'); return Router::redirect($back); }
        if (!$upns || !PimImport::exportAvailable()) { $this->flash('warning', 'Nie znaleźliśmy Twojego konta Microsoft 365. Napisz do Helpdesku IT.'); return Router::redirect($back); }
        foreach ($upns as $upn) {
            $j = PimImport::forUpn($upn);
            if ($j && in_array($j['status'], ['queued', 'running'], true)) continue;
            PimImport::queue($upn, (string)($u['email'] ?? ''), (string)($u['email'] ?? 'self'));
        }
        if (function_exists('log_user_action')) { try { log_user_action((int)$u['id'], (int)$u['id'], 'cloud_pim_self', 'Kalendarz i kontakty z Microsoft 365 do chmury'); } catch (\Throwable $e) {} }
        $this->flash('success', 'Gotowe. Przenosimy Twoje kalendarze i kontakty w tle. Stan zobaczysz na tej stronie. Zwykle trwa to kilkanaście minut.');
        return Router::redirect($back);
    }

    private function adminUrl(): string { return rtrim(APP_URL, '/') . '/kalendarz-kontakty/m365'; }

    private function flash(string $t, string $m): void { if (function_exists('flash_set')) flash_set($t, $m); }
}
