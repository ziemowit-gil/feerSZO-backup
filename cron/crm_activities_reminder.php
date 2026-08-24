<?php
/**
 * cron/crm_activities_reminder.php — poranne przypomnienie o działaniach CRM.
 *
 * Uruchamiaj raz dziennie rano:
 *   0 7 * * * php /var/www/html/cron/crm_activities_reminder.php
 *
 * Wysyła JEDEN e-mail na osobę, z zaległymi i dzisiejszymi działaniami razem.
 * Jeden zbiorczy list zamiast maila na każde działanie — inaczej dzień z pięcioma
 * telefonami zaczyna się od pięciu powiadomień i kończy ignorowaniem wszystkich.
 *
 * Osoba bez zaległości i bez planu na dziś nie dostaje nic. Cisza jest
 * informacją; „masz 0 działań” to spam.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/includes/email_templates.php';

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_activities_reminder\n";

if (function_exists('org_setting') && org_setting('crm_enabled') === '0') {
    echo "  Moduł CRM wyłączony — koniec.\n";
    exit(0);
}
crm_migrate();

// Zaległe i dzisiejsze planowane działania, pogrupowane po osobie odpowiedzialnej.
$rows = db_all(
    "SELECT a.id, a.title, a.type, a.scheduled_at, a.assigned_to,
            c.id AS contact_id, c.imie_nazwisko,
            u.email AS user_email,
            CASE WHEN u.first_name<>'' AND u.last_name<>'' THEN u.first_name||' '||u.last_name ELSE u.name END AS user_name
       FROM crm_activities a
       JOIN crm_contacts c ON c.id = a.contact_id AND c.crm_active = 1
       JOIN users u        ON u.id = a.assigned_to AND u.is_active = 1
      WHERE a.status = 'planned'
        AND a.scheduled_at IS NOT NULL
        AND date(a.scheduled_at) <= date('now','localtime')
        AND u.email IS NOT NULL AND u.email <> ''
      ORDER BY a.assigned_to, a.scheduled_at"
);

if (!$rows) {
    echo "  Brak zaległych i dzisiejszych działań — nic do wysłania.\n";
    exit(0);
}

$by_user = [];
foreach ($rows as $r) $by_user[(int)$r['assigned_to']][] = $r;

$today = date('Y-m-d');
$sent  = 0;
$errs  = 0;

foreach ($by_user as $user_id => $items) {
    $email = (string)$items[0]['user_email'];
    $name  = (string)($items[0]['user_name'] ?? '');

    $late  = array_filter($items, fn($i) => substr((string)$i['scheduled_at'], 0, 10) <  $today);
    $now   = array_filter($items, fn($i) => substr((string)$i['scheduled_at'], 0, 10) >= $today);

    $render = function (array $list) {
        $out = '';
        foreach ($list as $i) {
            $out .= '<li style="margin-bottom:.4rem">'
                  . '<strong>' . h((string)$i['title']) . '</strong><br>'
                  . '<span style="color:#6B7280;font-size:13px">'
                  . h((string)$i['imie_nazwisko']) . ' · '
                  . date('d.m.Y H:i', strtotime((string)$i['scheduled_at']))
                  . '</span></li>';
        }
        return $out;
    };

    $inner = '<p>Dzień dobry' . ($name !== '' ? ', ' . h($name) : '') . '.</p>';
    if ($late) {
        $inner .= '<p style="margin-bottom:.3rem"><strong style="color:#B42318">Zaległe ('
                . count($late) . ')</strong></p><ul>' . $render($late) . '</ul>';
    }
    if ($now) {
        $inner .= '<p style="margin-bottom:.3rem"><strong>Na dziś (' . count($now) . ')</strong></p><ul>'
                . $render($now) . '</ul>';
    }
    $inner .= '<p style="margin-top:1rem"><a href="' . h(rtrim(APP_URL, '/')) . '/crm/activities.php?view=zalegle">'
            . 'Otwórz listę działań</a></p>';

    $subject = sprintf(
        'CRM: %s%s na dziś',
        $late ? count($late) . ' zaległych, ' : '',
        count($now) . ' ' . (count($now) === 1 ? 'działanie' : 'działań')
    );

    try {
        mail_queue_add(
            $email, $name, $subject,
            _email_tpl_default_wrap($inner, 'Twoje działania w CRM na dziś'),
            '', 'crm_activities', $user_id
        );
        printf("  ✓ %s: zaległe %d, dziś %d\n", $email, count($late), count($now));
        $sent++;
    } catch (\Throwable $e) {
        printf("  ✗ %s: %s\n", $email, $e->getMessage());
        $errs++;
    }
}

printf("[%s] Koniec: wysłano %d, błędów %d\n", date('Y-m-d H:i:s'), $sent, $errs);
exit($errs ? 1 : 0);
