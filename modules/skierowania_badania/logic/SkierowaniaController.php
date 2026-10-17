<?php
/**
 * modules/skierowania_badania/logic/SkierowaniaController.php — akcje modułu (trasy w router.php).
 */

require_once __DIR__ . '/skierowania.php';
require_once __DIR__ . '/skierowaniaEzd.php';

final class SkierowaniaController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string {
        skbMigrate();
        $f = ['q' => (string)($_GET['q'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'typ' => (string)($_GET['typ'] ?? ''), 'wygasa' => (int)($_GET['wygasa'] ?? 0)];
        $expiring = (int)(db_one("SELECT COUNT(*) c FROM skierowania_badania WHERE status='zrealizowane' AND wazne_do IS NOT NULL AND wazne_do <= date('now','+30 days')")['c'] ?? 0);
        return Router::view(self::VIEW . 'index.php', ['rows' => skbList($f), 'f' => $f, 'expiring' => $expiring]);
    }

    /** Nowe skierowanie: bez umowy → wybór umowy; z umową → formularz z danymi z umowy. */
    public function create(): string {
        skbMigrate();
        $type = (string)($_GET['type'] ?? '');
        $cid  = (int)($_GET['id'] ?? 0);
        $c = skbContract($type, $cid);
        if (!$c) return Router::view(self::VIEW . 'wybor.php', ['q' => (string)($_GET['q'] ?? ''), 'contracts' => skbContractsSearch((string)($_GET['q'] ?? ''))]);
        return Router::view(self::VIEW . 'form.php', ['s' => skbPrefill($type, $c), 'c' => $c, 'history' => skbForContract($type, $cid)]);
    }

    public function store(): ?string {
        $user = current_user();
        $type = (string)($_POST['contract_type'] ?? '');
        $cid  = (int)($_POST['contract_id'] ?? 0);
        $d = skbValidate($_POST, $_POST);
        if (is_string($d)) { flash_set('danger', $d); return Router::redirect(route('skb.create', ['type' => $type, 'id' => $cid])); }
        $id = skbCreate($type, $cid, $d, $user);
        if (is_string($id)) { flash_set('danger', $id); return Router::redirect(route('skb.create', ['type' => $type, 'id' => $cid])); }
        $msg = 'Wystawiono skierowanie ' . skbGet($id)['numer'] . '.';
        if (skbEzdActive() && skbSetting('ezd_auto') !== '0') {
            $r = skbEzdRegister($id, $user);
            $msg .= is_int($r) ? ' Zarejestrowano w EZD.' : ' EZD: ' . $r;
        }
        flash_set('success', $msg);
        return Router::redirect(route('skb.show', ['id' => $id]));
    }

    public function show(int $id): string {
        $s = skbGet($id);
        if (!$s) { http_response_code(404); return 'Nie znaleziono skierowania.'; }
        $c = skbContract((string)$s['contract_type'], (int)$s['contract_id']) ?: [];
        return Router::view(self::VIEW . 'form.php', ['s' => $s, 'c' => $c, 'history' => skbForContract((string)$s['contract_type'], (int)$s['contract_id'])]);
    }

    public function update(int $id): ?string {
        $d = skbValidate($_POST, $_POST);
        $err = is_string($d) ? $d : skbUpdate($id, $d, current_user());
        flash_set($err ? 'danger' : 'success', $err ?? 'Zapisano zmiany. Jeśli skierowanie było już w EZD, zarejestruj nową wersję.');
        return Router::redirect(route('skb.show', ['id' => $id]));
    }

    public function pdf(int $id): ?string {
        $s = skbGet($id);
        if (!$s) { http_response_code(404); return 'Nie znaleziono skierowania.'; }
        require_once dirname(__DIR__) . '/lib/skierowaniePdf.php';
        $pdf = skbPdf($s);
        audit_log('skierowania.pdf', ['id' => $id, 'numer' => $s['numer']], (int)(current_user()['id'] ?? 0) ?: null);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . skbPdfFilename($s) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        return null;
    }

    public function ezd(int $id): ?string {
        $r = skbEzdRegister($id, current_user());
        flash_set(is_int($r) ? 'success' : 'danger', is_int($r) ? 'Zarejestrowano skierowanie w koszulce EZD umowy.' : $r);
        return Router::redirect(route('skb.show', ['id' => $id]));
    }

    public function result(int $id): ?string {
        $err = skbSaveResult($id, $_POST, current_user());
        flash_set($err ? 'danger' : 'success', $err ?? 'Zapisano wynik badania.');
        return Router::redirect(route('skb.show', ['id' => $id]));
    }

    public function cancel(int $id): ?string {
        $err = skbCancel($id, current_user());
        flash_set($err ? 'danger' : 'success', $err ?? 'Skierowanie anulowane.');
        return Router::redirect(route('skb.show', ['id' => $id]));
    }

    public function settings(): ?string {
        if (!is_admin()) { flash_set('danger', 'Ustawienia zmienia administrator.'); return Router::redirect(route('skb.index')); }
        skbSettingsSave($_POST);
        flash_set('success', 'Zapisano ustawienia skierowań.');
        return Router::redirect(route('skb.index'));
    }
}
