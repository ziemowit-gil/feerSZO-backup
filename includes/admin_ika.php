<?php
// Weryfikacja IKA dla panelu administracyjnego (TTL: 1 godzina)
if (!defined('SKIP_ADMIN_IKA')) {
    ika_require(APP_URL . '/admin/', 3600);
}
