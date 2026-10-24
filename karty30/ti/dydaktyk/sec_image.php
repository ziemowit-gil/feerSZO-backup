<?php
/**
 * karty30/ti/dydaktyk/sec_image.php — obrazek bezpieczeństwa dla adresu e-mail prowadzącego (JSON: svg + opis).
 * Nieznany adres → ogólny obrazek (nie zdradzamy, kto ma konto).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_sec_image_dyd.php';

$email = trim((string)($_GET['email'] ?? ''));
$idx   = -1;
if ($email !== '' && mb_strlen($email) <= 200) {
    $u = db_one("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND is_active = 1", [$email]);
    if ($u) $idx = ti_sec_dyd_assign((int)$u['id']);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'svg'   => ti_sec_image_svg($idx),
    'label' => $idx >= 0 ? ti_sec_image_label($idx) : 'Obrazek ogólny (adres nie jest rozpoznany)',
], JSON_UNESCAPED_UNICODE);
