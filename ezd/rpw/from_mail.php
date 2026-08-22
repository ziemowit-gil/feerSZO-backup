<?php
/**
 * ezd/rpw/from_mail.php — rejestracja wiadomości e-mail w dzienniku podawczym (RPW).
 *
 * Nadaje mailowi kolejny numer RPW w roku wpływu, tak samo jak przesyłce papierowej.
 * Dziennik podawczy ma odzwierciedlać CAŁĄ korespondencję wpływającą, a poczta
 * elektroniczna dotąd go omijała — wchodziła wprost do koszulek albo nigdzie.
 *
 * OSOBNE od dołączania maila do koszulki (assignToSprawa / import_mail.php):
 * tam wiadomość staje się pismem w aktach sprawy, tu dostaje numer wpływu.
 * Jedno nie wyklucza drugiego — mail zarejestrowany w RPW może później trafić
 * do koszulki, a numer RPW zostaje.
 *
 * Powtórna rejestracja tej samej wiadomości jest blokowana przez UNIQUE
 * na ezd_rpw.comm_id.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_mail.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . APP_URL . '/ezd/poczta/index.php');
    exit;
}
csrf_check();

$comm_id = (int)($_POST['comm_id'] ?? 0);
$back    = trim((string)($_POST['back'] ?? ''));

$dest = APP_URL . '/ezd/poczta/index.php';
if ($back !== '' && str_starts_with($back, APP_URL . '/')) $dest = $back;

if (!can_write('ezd') && !is_admin()) {
    flash_set('error', 'Brak uprawnień do rejestracji w dzienniku podawczym.');
    header('Location: ' . $dest); exit;
}

$comm = $comm_id
    ? db_one("SELECT * FROM crm_communications WHERE id=? AND direction='in'", [$comm_id])
    : null;
if (!$comm) {
    flash_set('error', 'Nie znaleziono wiadomości przychodzącej.');
    header('Location: ' . $dest); exit;
}

// Już zarejestrowana — prowadzimy do istniejącego wpisu zamiast nadawać drugi numer.
$exists = db_one("SELECT id, rpw_nr, rok FROM ezd_rpw WHERE comm_id=?", [$comm_id]);
if ($exists) {
    flash_set('info', 'Ta wiadomość jest już w dzienniku podawczym: '
        . ezd_rpw_label($exists) . '.');
    header('Location: ' . APP_URL . '/ezd/rpw/view.php?id=' . (int)$exists['id']); exit;
}

$sent = !empty($comm['sent_at']) ? strtotime((string)$comm['sent_at']) : time();
$rok  = (int)date('Y', $sent);

$nadawca = trim((string)($comm['from_name'] ?? ''));
$email   = trim((string)($comm['from_email'] ?? ''));
if ($nadawca === '')      $nadawca = $email ?: 'nieznany nadawca';
elseif ($email !== '')    $nadawca .= ' <' . $email . '>';

// Znak obcy z tematu — ten sam parser, którym poczta rozpoznaje znak sprawy.
$znak_obcy = '';
try {
    $znak_obcy = (string)(EzdMailService::detectEzdSign((string)($comm['subject'] ?? '')) ?? '');
} catch (\Throwable $e) { $znak_obcy = ''; }

$rpw_id = db_insert('ezd_rpw', [
    'rpw_nr'      => _ezd_next_rpw($rok),
    'rok'         => $rok,
    'data_wplywu' => date('Y-m-d', $sent),
    'typ'         => 'email',
    'nadawca'     => mb_substr($nadawca, 0, 255),
    'znak_obcy'   => mb_substr($znak_obcy, 0, 100),
    'opis'        => mb_substr(trim((string)($comm['subject'] ?? '')) ?: '(bez tematu)', 0, 500),
    'uwagi'       => 'Zarejestrowano z Poczty EZD',
    'status'      => 'nowa',
    'sprawa_id'   => !empty($comm['ezd_sprawa_id']) ? (int)$comm['ezd_sprawa_id'] : null,
    'comm_id'     => $comm_id,
    'created_by'  => (int)(current_user()['id'] ?? 0) ?: null,
    'created_at'  => date('Y-m-d H:i:s'),
]);

$row = db_one("SELECT rpw_nr, rok FROM ezd_rpw WHERE id=?", [$rpw_id]);
flash_set('success', 'Wiadomość zarejestrowana w dzienniku podawczym: '
    . ($row ? ezd_rpw_label($row) : 'RPW') . '.');

header('Location: ' . APP_URL . '/ezd/rpw/view.php?id=' . (int)$rpw_id);
exit;
