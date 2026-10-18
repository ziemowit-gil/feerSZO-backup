<?php
declare(strict_types=1);

/**
 * modules/collaborator_data/logic/CollaboratorDataController.php — trasy modułu „Zmiana danych współpracownika”:
 * panel osoby (formularz + dziennik + wnioski SZD/SKK/UPO/DYD) i ekran administratora (akceptacja, dziennik).
 */

require_once dirname(__DIR__) . '/lib/CollaboratorDataService.php';

final class CollaboratorDataController
{
    private const VIEW = __DIR__ . '/../view/';

    private function uid(): int { return (int)(current_user()['id'] ?? 0); }

    private static function isStaff(): bool { return in_array(current_user()['role'] ?? '', ['admin', 'editor'], true); }

    public function index(): string
    {
        $svc = new CollaboratorDataService($this->uid());
        return Router::view(self::VIEW . 'panel.php', [
            'fields'  => CollaboratorDataService::fields(),
            'current' => $svc->current(),
            'person'  => $svc->person(),
            'history' => CollaboratorDataService::history($this->uid(), null, 50),
            'types'   => CollaboratorDataService::applicationTypes(),
            'errors'  => $_SESSION['_cdc_errors'] ?? [], 'old' => $_SESSION['_cdc_old'] ?? [],
        ] + (function () { unset($_SESSION['_cdc_errors'], $_SESSION['_cdc_old']); return []; })());
    }

    public function update(): void
    {
        $in = [];
        foreach (array_keys(CollaboratorDataService::fields()) as $k) if (isset($_POST[$k])) $in[$k] = (string)$_POST[$k];
        // Każdy zapis tworzy wniosek SZD do podpisu — zmiany wchodzą dopiero po podpisaniu i złożeniu wniosku
        $r = (new CollaboratorDataService($this->uid()))->requestSignature($in);
        if (!empty($r['errors'])) {
            $_SESSION['_cdc_errors'] = $r['errors']; $_SESSION['_cdc_old'] = $in;
            flash_set('danger', 'Popraw zaznaczone pola — nic nie zostało zapisane.');
            header('Location: ' . route('cdc.index')); exit;
        }
        if (empty($r['token'])) { flash_set('info', 'Nie wprowadzono żadnych zmian.'); header('Location: ' . route('cdc.index')); exit; }
        if (empty($r['sent']['ok'])) flash_set('warning', (string)($r['sent']['error'] ?? 'Nie udało się wysłać kodu podpisu.'));
        header('Location: ' . route('cdc.sign', ['token' => $r['token']])); exit;
    }

    public function signForm(string|int $token): string
    {
        $token = (string)$token;
        $svc = new CollaboratorDataService($this->uid());
        $req = $svc->signRequest($token);
        if (!$req) { http_response_code(404); return 'Nie znaleziono wniosku do podpisu.'; }
        if ($req['status'] !== 'pending') { header('Location: ' . route('cdc.index')); exit; }
        return Router::view(self::VIEW . 'sign.php', ['req' => $req, 'rows' => $svc->previewRows($req), 'user' => $svc->user(), 'token' => $token]);
    }

    public function sign(string|int $token): void
    {
        $token = (string)$token;
        $r = (new CollaboratorDataService($this->uid()))->sign($token, (string)($_POST['code'] ?? ''), $this->uid());
        if (!$r['ok']) { flash_set('danger', (string)$r['error']); header('Location: ' . route('cdc.sign', ['token' => $token])); exit; }
        $res = $r['result'];
        flash_set('success', 'Wniosek ' . ($res->applicationNumber ?: 'SZD') . ' podpisany i złożony. '
            . ($res->applied ? 'Wprowadzono zmian: ' . $res->applied . '. ' : '')
            . ($res->pending ? 'Do akceptacji: ' . $res->pending . ' (np. rachunek bankowy). ' : '')
            . 'Potwierdzenie złożenia (UPZ) znajdziesz w „Moich wnioskach”.');
        header('Location: ' . route('cdc.index')); exit;
    }

    public function resend(string|int $token): void
    {
        $token = (string)$token;
        $r = (new CollaboratorDataService($this->uid()))->sendCode($token, ($_POST['channel'] ?? '') === 'email' ? 'email' : null);
        flash_set($r['ok'] ? 'success' : 'warning', $r['ok'] ? 'Wysłano nowy kod (' . ($r['channel'] === 'sms' ? 'SMS' : 'e-mail') . ': ' . $r['to'] . ').' : (string)$r['error']);
        header('Location: ' . route('cdc.sign', ['token' => $token])); exit;
    }

    public function cancel(string|int $token): void
    {
        $token = (string)$token;
        (new CollaboratorDataService($this->uid()))->cancelSignature($token);
        flash_set('info', 'Anulowano — zmiany nie zostały wprowadzone.');
        header('Location: ' . route('cdc.index')); exit;
    }

    public function admin(): string
    {
        $u = (int)($_GET['u'] ?? 0);
        return Router::view(self::VIEW . 'admin.php', [
            'pending' => CollaboratorDataService::history(null, CollabChangeStatus::Pending->value, 200),
            'log'     => CollaboratorDataService::history($u ?: null, null, 200),
            'fields'  => CollaboratorDataService::fields(),
            'filterUser' => $u ? db_one("SELECT id, name, email FROM users WHERE id = ?", [$u]) : null,
        ]);
    }

    public function decide(int $id): void
    {
        $ok = CollaboratorDataService::decide($id, ($_POST['decision'] ?? '') === 'approve', $this->uid(), trim((string)($_POST['note'] ?? '')));
        flash_set($ok ? 'success' : 'warning', $ok ? 'Decyzja zapisana.' : 'Ta zmiana została już rozpatrzona.');
        header('Location: ' . route('cdc.admin')); exit;
    }
}
