<?php
/**
 * modules/contract_card/logic/ContractCardController.php — akcje karty umowy (trasy w router.php).
 */

require_once __DIR__ . '/contractCard.php';

final class ContractCardController
{
    private const VIEW = __DIR__ . '/../view/';

    public function show(string $type, int $id): string {
        if (!isset(CONTRACT_TYPES[$type])) { http_response_code(404); return 'Nieznany typ umowy.'; }
        $row = contractCardRow($type, $id);
        if (!$row) { http_response_code(404); return 'Nie znaleziono umowy.'; }
        if (!viewer_owns_contract($type, $row)) { http_response_code(403); return 'Nie masz dostępu do tej umowy.'; }

        $user = current_user();
        try {
            require_once dirname(__DIR__, 3) . '/includes/approval.php';
            log_contract_action($type, $id, (int)($user['id'] ?? 0), 'print', 'Wydruk: Karta umowy');
        } catch (\Throwable $e) {}

        return Router::view(self::VIEW . 'karta.php', [
            'type'     => $type,
            'row'      => $row,
            'sections' => contractCardSections($type, $row),
            'head'     => contractCardHead($type, $row),
            'history'  => contractCardHistory($type, $id),
            'user'     => $user,
        ]);
    }
}
