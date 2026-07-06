<?php
/**
 * contracts/wolontariat/guardian_consent_action.php — Ręczne wygenerowanie
 * i wysłanie pisma o odnowieniu zgody przedstawiciela ustawowego, poza
 * automatycznym cyklem cron/guardian_consent_renewal.php (co 6 miesięcy).
 *
 * Otwiera (lub reużywa, jeśli już otwarta) sprawę EZD ze znakiem sprawy
 * i zawsze wysyła e-mail — przydatne np. gdy trzeba przypomnieć opiekunowi
 * wcześniej niż wynikałoby to z automatycznego cyklu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/guardian_consent.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/email_templates.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';

require_role('admin', 'editor');

$id  = (int)($_GET['id'] ?? 0);
$row = db_one("SELECT * FROM umowy_wolontariat WHERE id=?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }

if (empty($row['niepelnoletni'])) {
    flash_set('danger', 'Ta umowa nie dotyczy wolontariusza niepełnoletniego — zgoda przedstawiciela nie ma zastosowania.');
    header('Location: ' . APP_URL . '/contracts/wolontariat/view.php?id=' . $id);
    exit;
}

$guardian_email = trim($row['rodzic_email'] ?? '');
if (!$guardian_email || !filter_var($guardian_email, FILTER_VALIDATE_EMAIL)) {
    flash_set('danger', 'Brak prawidłowego adresu e-mail przedstawiciela ustawowego (pole „E-mail rodzica” na umowie).');
    header('Location: ' . APP_URL . '/contracts/wolontariat/view.php?id=' . $id);
    exit;
}
$guardian_name = $row['rodzic_imie_nazwisko'] ?: $guardian_email;

// Sprawa EZD jest pomocnicza — awaria (np. problem ze schematem/segregatorem)
// nie może uniemożliwić wysłania samego pisma do opiekuna.
$znak_sprawy = '';
try {
    $ezd = ezd_register_guardian_consent_letter($row, $guardian_name, (int)current_user()['id']);
    $znak_sprawy = $ezd['znak_sprawy'] ?? '';
} catch (\Throwable $e) {
    error_log('[guardian_consent_action] EZD: ' . $e->getMessage());
}

$rendered = email_tpl_render('guardian_consent_renewal', [
    'accent'          => '#1D4ED8',
    'org_nazwa'       => org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju "FEER"'),
    'org_adres'       => org_setting('org_adres') ?: 'ul. Barbackiego 28/18, 33-300 Nowy Sącz',
    'org_email'       => org_setting('notify_from_email') ?: '',
    'org_telefon'     => org_setting('org_telefon') ?: '',
    'znak_sprawy'     => $znak_sprawy,
    'miejscowosc'     => org_setting('org_miejscowosc') ?: 'Nowy Sącz',
    'data_pisma'      => date('d.m.Y'),
    'dziecko'         => $row['imie_nazwisko'] ?? '',
    'login_url'       => APP_URL . '/auth/login.php',
    'kontakt_email'   => org_setting('notify_from_email') ?: '',
    'kontakt_telefon' => org_setting('org_telefon') ?: '',
]);

if ($rendered['enabled']) {
    mail_queue_add($guardian_email, $guardian_name, $rendered['subject'], $rendered['html']);
    try { mail_queue_process(); } catch (\Throwable $e) {}
    log_contract_action('wolontariat', $id, (int)current_user()['id'], 'note',
        "Ręcznie wygenerowano i wysłano pismo o odnowieniu zgody przedstawiciela ustawowego"
        . ($znak_sprawy ? " (znak sprawy {$znak_sprawy})" : '') . " do {$guardian_email}.");
    flash_set('success', 'Pismo zostało wygenerowane' . ($znak_sprawy ? " (znak sprawy {$znak_sprawy})" : '') . " i wysłane do {$guardian_email}.");
} else {
    flash_set('warning', 'Sprawa EZD' . ($znak_sprawy ? " {$znak_sprawy}" : '') . ' została otwarta, ale szablon maila jest wyłączony przez administratora (Admin → Maile systemowe) — e-mail nie został wysłany.');
}

header('Location: ' . APP_URL . '/contracts/wolontariat/view.php?id=' . $id);
exit;
