<?php
/**
 * Micro-timer dekretacji: eskalacja gdy wykonawca nie odczytał przez N godzin.
 * Uruchamiane co godzinę: 0 * * * *
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

if (PHP_SAPI !== 'cli' && !defined('CRON_ALLOW_HTTP')) {
    http_response_code(403); exit;
}

$hours  = max(1, (int)(function_exists('org_setting') ? (org_setting('ezd_eskalacja_godzin') ?: 24) : 24));
$thresh = 'datetime(\'now\', \'-' . $hours . ' hours\')';

$rows = db_all(
    "SELECT d.id, d.sprawa_id, d.dyspozycja, d.created_at, d.rola_target,
            d.wykonawca_id, d.zlecajacy_id,
            z.name AS zlecajacy_name, z.email AS zlecajacy_email,
            w.name AS wykonawca_name, w.email AS wykonawca_email,
            s.znak_sprawy
     FROM ezd_dekretacje d
     LEFT JOIN users z ON z.id = d.zlecajacy_id
     LEFT JOIN users w ON w.id = d.wykonawca_id
     LEFT JOIN ezd_sprawy s ON s.id = d.sprawa_id
     WHERE d.status = 'oczekuje'
       AND d.read_at IS NULL
       AND d.claimed_by IS NULL
       AND d.escalated_at IS NULL
       AND d.created_at <= " . $thresh
);

$sent = 0;
foreach ($rows as $d) {
    $sprawa_label = $d['znak_sprawy'] ? '[' . $d['znak_sprawy'] . ']' : ('sprawa #' . $d['sprawa_id']);
    $dysp_label   = EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja'];
    $age_hours    = round((time() - strtotime($d['created_at'])) / 3600, 1);
    $url          = defined('APP_URL') ? APP_URL . '/ezd/sprawy/view.php?id=' . $d['sprawa_id'] : '';

    if ($d['rola_target']) {
        // Do roli — nikt nie odebrał
        $subject = 'EZD: Nieodbrana dekretacja do roli — ' . $sprawa_label;
        $body    = '<p>Dekretacja <strong>' . htmlspecialchars($dysp_label) . '</strong> '
                 . 'do roli <strong>' . htmlspecialchars($d['rola_target']) . '</strong> '
                 . 'w sprawie ' . htmlspecialchars($sprawa_label) . ' '
                 . 'wystawiona przez <strong>' . htmlspecialchars($d['zlecajacy_name']) . '</strong> '
                 . 'nie została odebrana przez <strong>' . $age_hours . ' godz.</strong></p>'
                 . ($url ? '<p><a href="' . $url . '">Przejdź do sprawy</a></p>' : '');
        // Wyślij do wystawiającego
        if ($d['zlecajacy_email']) {
            if (function_exists('mail_queue_add')) {
                mail_queue_add($d['zlecajacy_email'], $d['zlecajacy_name'], $subject, $body, '', 'ezd_dekretacja', $d['id']);
            } else {
                @mail($d['zlecajacy_email'], $subject, strip_tags($body));
            }
        }
    } else {
        // Do osoby — nie odczytała
        $subject = 'EZD: Nieodczytana dekretacja — ' . $sprawa_label;
        $body    = '<p>Dekretacja <strong>' . htmlspecialchars($dysp_label) . '</strong> '
                 . 'w sprawie ' . htmlspecialchars($sprawa_label) . ' '
                 . 'od <strong>' . htmlspecialchars($d['zlecajacy_name']) . '</strong> '
                 . 'nie została odczytana przez <strong>' . htmlspecialchars($d['wykonawca_name']) . '</strong> '
                 . 'przez <strong>' . $age_hours . ' godz.</strong></p>'
                 . ($url ? '<p><a href="' . $url . '">Przejdź do sprawy</a></p>' : '');
        // Przypomnij wykonawcy
        if ($d['wykonawca_email']) {
            if (function_exists('mail_queue_add')) {
                mail_queue_add($d['wykonawca_email'], $d['wykonawca_name'], $subject, $body, '', 'ezd_dekretacja', $d['id']);
            } else {
                @mail($d['wykonawca_email'], $subject, strip_tags($body));
            }
        }
        // Powiadom wystawiającego
        if ($d['zlecajacy_email'] && $d['zlecajacy_email'] !== $d['wykonawca_email']) {
            $body_zl = '<p>Dekretacja <strong>' . htmlspecialchars($dysp_label) . '</strong> '
                     . 'wystawiona przez Ciebie dla <strong>' . htmlspecialchars($d['wykonawca_name']) . '</strong> '
                     . 'w sprawie ' . htmlspecialchars($sprawa_label) . ' '
                     . 'nie została odczytana przez <strong>' . $age_hours . ' godz.</strong></p>'
                     . ($url ? '<p><a href="' . $url . '">Przejdź do sprawy</a></p>' : '');
            if (function_exists('mail_queue_add')) {
                mail_queue_add($d['zlecajacy_email'], $d['zlecajacy_name'], 'EZD: Eskalacja dekretacji — ' . $sprawa_label, $body_zl, '', 'ezd_dekretacja', $d['id']);
            } else {
                @mail($d['zlecajacy_email'], 'EZD: Eskalacja dekretacji — ' . $sprawa_label, strip_tags($body_zl));
            }
        }
    }

    db()->prepare("UPDATE ezd_dekretacje SET escalated_at=datetime('now') WHERE id=?")->execute([$d['id']]);
    $sent++;
}

if (PHP_SAPI === 'cli') {
    echo "Escalation run: {$sent} dekretacji przetworzono (próg: {$hours}h).\n";
}
