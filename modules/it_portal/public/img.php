<?php
/**
 * modules/it_portal/public/img.php — publiczny odczyt zrzutów ekranu z artykułów Portalu Usług IT.
 * /uslugi-it/img/{20 znaków hex}.{png|jpg|gif|webp} — nazwa walidowana wzorcem, nic poza uploads/it_portal/.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/logic/itPortalBlocks.php';

$f = (string)($_GET['f'] ?? '');
$path = itPortalImageDir() . $f;
if (!preg_match(ITP_IMG_RE, $f) || !is_file($path)) { http_response_code(404); exit; }
$type = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][pathinfo($f, PATHINFO_EXTENSION)];
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($path));
readfile($path);
