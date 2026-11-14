<?php
declare(strict_types=1);

require_once __DIR__ . '/ContractIt.php';

/**
 * modules/contractIt/logic/ContractItController.php — GET router.php?_route=/stan&type=…&email=…&unit=…
 * Panel „Konta i grupy IT” w oknie dodawania umowy pyta tu o konto osoby po wpisaniu e-maila (osoba wracająca często ma już konto
 * logowania jednokrotnego i w chmurze) oraz o propozycję jednostki i działania. Tylko odczyt, administrator i redaktor (jak formularze umów).
 */
final class ContractItController
{
    public function state(): array
    {
        $type = (string)($_GET['type'] ?? '');
        if (!in_array($type, ContractIt::TYPES, true)) $type = 'wolontariat';
        $acc = ContractIt::account((string)($_GET['email'] ?? ''));
        $unitId = (int)($_GET['unit'] ?? 0);
        $out = [
            'email'     => $acc['email'],
            'logto'     => $acc['logto'],
            'logtoUser' => $acc['logtoUser'],
            'cloud'     => $acc['cloud'],
            'cloudUid'  => $acc['cloudUid'],
            'unit'      => $unitId > 0 ? null : ContractIt::suggestUnit($type, $acc['email']),
        ];
        if ($type === 'wolontariat') $out['action'] = ContractIt::suggestAction($unitId);
        header('Cache-Control: no-store');
        return $out;
    }
}
