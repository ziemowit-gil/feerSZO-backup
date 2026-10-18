<?php
/**
 * modules/org_moves/logic/OrgMovesController.php — akcje modułu Przenoszenie w strukturze.
 */
require_once dirname(__DIR__) . '/lib/orgMoves.php';
require_once dirname(__DIR__, 3) . '/includes/directory.php';

final class OrgMovesController
{
    public function index(): ?string
    {
        $units   = orgMovesUnits();
        $unitId  = (int)($_GET['unit'] ?? 0);
        $userId  = (int)($_GET['user'] ?? 0);
        $q       = trim((string)($_GET['q'] ?? ''));
        $members = orgMovesMembers(isset($units[$unitId]) ? $unitId : 0, $q);
        if ($userId) $members = array_values(array_filter($members, fn($m) => (int)$m['user_id'] === $userId));
        $PAGE_TITLE = 'Przenoszenie w strukturze';
        ob_start();
        include dirname(__DIR__, 3) . '/directory/includes/header_dir.php';
        echo Router::view(dirname(__DIR__) . '/view/index.php', compact('units', 'unitId', 'userId', 'q', 'members'));
        include dirname(__DIR__, 3) . '/directory/includes/footer_dir.php';
        return (string)ob_get_clean();
    }

    public function moveMembers(): ?string
    {
        $back = $this->back();
        try {
            $ids = array_map('intval', (array)($_POST['members'] ?? []));
            if (!$ids) throw new \RuntimeException('Zaznacz co najmniej jedną osobę.');
            $r = orgMovesMoveMembers($ids, (int)($_POST['target'] ?? 0), (int)current_user()['id']);
            flash_set('success', 'Przeniesiono: ' . $r['moved'] . ($r['merged'] ? ', już były w celu (zamknięto stare przypisanie): ' . $r['merged'] : '')
                . ($r['skipped'] ? ', pominięto: ' . $r['skipped'] : '') . '.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect($back);
    }

    public function moveUnits(): ?string
    {
        try {
            $ids = array_map('intval', (array)($_POST['units'] ?? []));
            if (!$ids) throw new \RuntimeException('Zaznacz co najmniej jeden dział.');
            $r = orgMovesMoveUnits($ids, (int)($_POST['target_unit'] ?? 0), (int)current_user()['id']);
            flash_set('success', 'Przeniesiono działów: ' . $r['moved'] . ($r['skipped'] ? ', pominięto: ' . $r['skipped'] : '') . '.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('moves.index') . '#dzialy');
    }

    /** Powrót na listę z tymi samymi filtrami (tylko znane parametry). */
    private function back(): string
    {
        $q = array_filter(['unit' => (int)($_POST['f_unit'] ?? 0) ?: null, 'q' => trim((string)($_POST['f_q'] ?? '')) ?: null]);
        return route('moves.index') . ($q ? '?' . http_build_query($q) : '');
    }
}
