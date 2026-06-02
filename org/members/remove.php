<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/org.php';
require_role('admin'); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location:'.APP_URL.'/org/index.php'); exit; }
csrf_check();

$id     = (int)($_POST['id'] ?? 0);
$member = org_member_get($id);
if (!$member) { flash_set('error','Przypisanie nie istnieje.'); header('Location:'.APP_URL.'/org/index.php'); exit; }

$unit_id = (int)$member['unit_id'];
org_member_remove($id, (int)current_user()['id']);
flash_set('success','Przypisanie zakończone (zachowane historycznie).');
header('Location:'.APP_URL.'/org/units/view.php?id='.$unit_id); exit;
