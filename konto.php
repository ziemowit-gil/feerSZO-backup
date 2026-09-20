<?php
/**
 * konto.php — krótki, łatwy do podania adres dla publicznej sprawdzarki
 * numeru konta do wpłat (modules/sprawdz_konto/). Sama logika/strona
 * zostaje w modules/ zgodnie z przyjętą konwencją dla nowych komponentów —
 * to tylko przekierowanie pod wygodniejszy, krótszy adres.
 */
require_once __DIR__ . '/config.php';
$token = trim($_GET['t'] ?? '');
$qs    = $token !== '' ? '?t=' . urlencode($token) : '';
header('Location: ' . APP_URL . '/modules/sprawdz_konto/' . $qs, true, 302);
exit;
