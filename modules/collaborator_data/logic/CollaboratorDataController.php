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
        $res = (new CollaboratorDataService($this->uid()))->submit($in, $this->uid(), 'self');
        if ($res->errors) {
            $_SESSION['_cdc_errors'] = $res->errors; $_SESSION['_cdc_old'] = $in;
            flash_set('danger', 'Popraw zaznaczone pola — nic nie zostało zapisane.');
        } elseif (!$res->applied && !$res->pending) {
            flash_set('info', 'Nie wprowadzono żadnych zmian.');
        } else {
            flash_set('success', trim(($res->applied ? 'Zapisano zmiany: ' . $res->applied . '. ' : '')
                . ($res->pending ? 'Do akceptacji: ' . $res->pending . ' (np. rachunek bankowy) — dostaniesz powiadomienie po decyzji. ' : '')
                . ($res->applicationNumber !== '' ? 'Utworzono wniosek ' . $res->applicationNumber . ' — potwierdzenie złożenia (UPZ) znajdziesz w „Moich wnioskach”.' : '')));
        }
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
