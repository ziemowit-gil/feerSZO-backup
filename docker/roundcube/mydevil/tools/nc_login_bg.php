<?php
// Użycie (na serwerze): php84 nc_login_bg.php /usr/home/feer/nextcloud/theming/feer-login-bg.jpg
// potem: php occ theming:config background <plik> ; php occ theming:config background_color "#172554"
// Tło logowania Nextclouda jak w poczcie FEER (feer_theme: granatowy gradient 160° + wzór kształtów)
$w = 2560; $h = 1440; $tile = 420;
$shapes = '<rect x="24" y="40" width="120" height="120" rx="8"/><circle cx="330" cy="96" r="58"/>'
        . '<rect x="210" y="250" width="150" height="150" rx="8"/><path d="M0 210l70-70v46l-24 24zm52 132l96-96v46l-50 50z"/>'
        . '<path d="M300 0l60 60-24 24-60-60z"/>';
// 160° w CSS = od góry-lewej lekko w prawo w dół; przybliżenie wektorem
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
     . '<defs><linearGradient id="g" x1="0.33" y1="0" x2="0.67" y2="1">'
     . '<stop offset="0" stop-color="#1e3a8a"/><stop offset="0.55" stop-color="#172554"/><stop offset="1" stop-color="#0b1220"/></linearGradient>'
     . '<pattern id="p" width="' . $tile . '" height="' . $tile . '" patternUnits="userSpaceOnUse" x="' . (($w / 2) % $tile) . '">'
     . '<g fill="#ffffff" fill-opacity="0.035">' . $shapes . '</g></pattern></defs>'
     . '<rect width="100%" height="100%" fill="url(#g)"/><rect width="100%" height="100%" fill="url(#p)"/></svg>';
$im = new Imagick();
$im->setBackgroundColor('#172554');
$im->readImageBlob($svg);
$im->setImageFormat('jpeg');
$im->setImageCompressionQuality(88);
$out = $argv[1];
$im->writeImage($out);
echo "zapisano $out ", $im->getImageWidth(), 'x', $im->getImageHeight(), ' ', filesize($out), " B\n";
