<?php
/**
 * modules/contract_status/logic/ContractStatusController.php — widok administratora: wyszukanie umowy,
 * historia zmian statusu, ustawienie dowolnego statusu albo cofnięcie ostatniej zmiany (trasy w router.php).
 */

require_once __DIR__ . '/contractStatus.php';

final class ContractStatusController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string {
        $q    = trim((string)($_GET['q'] ?? ''));
        $type = (string)($_GET['type'] ?? '');
        return Router::view(self::VIEW . 'index.php', ['q' => $q, 'type' => $type, 'found' => $q !== '' ? contractStatusFind($type, $q) : []]);
    }

    public function show(string $type, int $id): string {
        $row = contractStatusRow($type, $id);
        if (!$row) { http_response_code(404); return 'Nie znaleziono umowy.'; }
        $hist = contractStatusHistory($type, $id);
        return Router::view(self::VIEW . 'show.php', ['type' => $type, 'row' => $row, 'hist' => $hist, 'undo' => contractStatusUndoTarget($hist)]);
    }

    public function update(string $type, int $id): void {
        $row = contractStatusRow($type, $id);
        $back = route('cst.show', ['type' => $type, 'id' => $id]);
        if (!$row) { flash_set('error', 'Nie znaleziono umowy.'); header('Location: ' . route('cst.index')); exit; }
        $undo   = ($_POST['mode'] ?? '') === 'undo';
        $to     = $undo ? (string)contractStatusUndoTarget(contractStatusHistory($type, $id)) : (string)($_POST['to'] ?? '');
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '')                { flash_set('error', 'Podaj powód zmiany statusu (trafi do dziennika umowy).'); header('Location: ' . $back); exit; }
        if (!isset(contractStatuses()[$to])) { flash_set('error', $undo ? 'Nie da się ustalić poprzedniego statusu z dziennika — wybierz status ręcznie.' : 'Wybierz status.'); header('Location: ' . $back); exit; }
        if ($to === (string)$row['status']) { flash_set('info', 'Umowa ma już ten status.'); header('Location: ' . $back); exit; }
        $old = contractStatusSet($type, $row, $to, $reason, (int)(current_user()['id'] ?? 0), 'Panel administratora', $undo);
        flash_set('success', 'Status zmieniony: ' . contractStatusLabel($old) . ' → ' . contractStatusLabel($to) . '.');
        header('Location: ' . $back); exit;
    }
}
