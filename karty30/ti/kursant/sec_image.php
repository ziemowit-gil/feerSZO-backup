<?php
/**
 * karty30/ti/kursant/sec_image.php — obrazek bezpieczeństwa dla podanego loginu (JSON: svg + opis).
 * Nieznany login → ogólny obrazek (nie zdradzamy, które loginy istnieją).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_sec_image.php';

$login = trim((string)($_GET['login'] ?? ''));
$idx   = -1;
if ($login !== '' && mb_strlen($login) <= 100) {
    $acc = db_one(
        "SELECT id FROM k30_ti_student_accounts
          WHERE (login = ? OR (login_alias != '' AND login_alias = ?)) AND is_active = 1",
        [$login, $login]
    );
    if ($acc && !ti_sec_image_required((int)$acc['id'])) {   // konsultacje / szkolenia krótkie — bez obrazka
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        echo json_encode(['svg' => '', 'label' => '', 'none' => true]); exit;
    }
    if ($acc) $idx = ti_sec_image_assign((int)$acc['id']);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'svg'   => ti_sec_image_svg($idx),
    'label' => $idx >= 0 ? ti_sec_image_label($idx) : 'Obrazek ogólny (login nie jest rozpoznany)',
], JSON_UNESCAPED_UNICODE);
