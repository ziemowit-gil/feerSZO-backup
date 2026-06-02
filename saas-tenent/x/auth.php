<?php
/**
 * Dane dostępu do panelu SaaS.
 * Takie same jak konto serwisowe (serwis@local / serwis).
 */
define('SAAS_PASSWORD',    'serwis');
define('SAAS_SESSION_KEY', 'saas_auth_' . substr(sha1(__FILE__), 0, 8));
