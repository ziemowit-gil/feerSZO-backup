<?php
// „Zapamiętaj mnie”: tryb tokenów (tabela auth_tokens), NIE zaszyfrowane hasło w ciasteczku.
$config['ifpl_login_expire'] = 60 * 60 * 24 * 7;   // 7 dni
$config['ifpl_cookie_name'] = '_pt';
$config['ifpl_use_auth_tokens'] = true;
$config['db_table_auth_tokens'] = 'auth_tokens';
$config['ifpl_netmask_whitelist'] = [];
