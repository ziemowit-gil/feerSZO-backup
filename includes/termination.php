<?php
require_once __DIR__ . '/termination_schema.php';
require_once __DIR__ . '/m365_deactivation_schema.php';

const TERMINATION_STATUSES = [
    'oczekuje'     => ['label' => 'Oczekuje',     'class' => 'warning'],
    'zaakceptowany'=> ['label' => 'Zaakceptowany','class' => 'success'],
    'odrzucony'    => ['label' => 'Odrzucony',    'class' => 'danger'],
];

// Statusy umów, dla których rozwiązanie ma sens
const TERMINABLE_STATUSES = ['podpisana', 'w realizacji', 'obowiązująca'];

// Strona wypowiadająca porozumienie (§ 7 contracts/wolontariat/print.php)
const TERMINATION_INITIATORS = [
    'wolontariusz'       => 'Wolontariusz',
    'korzystajacy'       => 'Organizacja (Korzystający)',
    'porozumienie_stron' => 'Obie strony (za porozumieniem)',
];

// Tryby rozwiązania porozumienia wolontariackiego wraz z okresem wypowiedzenia
// (w dniach) i informacją czy skutek jest natychmiastowy — patrz klauzule
// wariantów A–E przygotowane dla § 7 contracts/wolontariat/print.php.
const TERMINATION_VARIANTS = [
    'standard_14' => [
        'label'       => 'Standardowy (14 dni)',
        'notice_days' => 14,
        'immediate'   => false,
    ],
    'skrocony_7' => [
        'label'       => 'Skrócony / elastyczny (7 dni)',
        'notice_days' => 7,
        'immediate'   => false,
    ],
    'trzydniowy_3' => [
        'label'       => 'Okazjonalny (3 dni)',
        'notice_days' => 3,
        'immediate'   => false,
    ],
    'natychmiastowy' => [
        'label'       => 'Natychmiastowy (z ważnych powodów)',
        'notice_days' => 0,
        'immediate'   => true,
    ],
    'porozumienie_stron' => [
        'label'       => 'Za porozumieniem stron (skutek natychmiastowy)',
        'notice_days' => 0,
        'immediate'   => true,
    ],
];

function termination_variant_label(?string $variant): string {
    return TERMINATION_VARIANTS[$variant]['label'] ?? '';
}

function termination_initiator_label(?string $initiator): string {
    return TERMINATION_INITIATORS[$initiator] ?? '';
}

/** Wylicza efektywną datę zakończenia współpracy dla wybranego trybu. */
function termination_compute_effective_date(?string $variant, ?string $base_date): ?string {
    $variant_def = TERMINATION_VARIANTS[$variant] ?? null;
    if (!$variant_def) return null;
    $base = $base_date ?: date('Y-m-d');
    if (!empty($variant_def['immediate'])) return $base;
    $days = (int)$variant_def['notice_days'];
    return date('Y-m-d', strtotime($base . " +{$days} days"));
}

function termination_status_badge(string $status): string {
    $s = TERMINATION_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function get_termination_requests(string $type, int $id): array {
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_termination_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users d ON d.id = r.decided_by
         WHERE r.contract_type = ? AND r.contract_id = ?
         ORDER BY r.id DESC",
        [$type, $id]
    );
}

function get_pending_termination_for_contract(string $type, int $id): ?array {
    return db_one(
        "SELECT * FROM contract_termination_requests
         WHERE contract_type = ? AND contract_id = ? AND status = 'oczekuje'
         ORDER BY id DESC LIMIT 1",
        [$type, $id]
    );
}

function get_user_termination_requests(int $user_id): array {
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_termination_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users d ON d.id = r.decided_by
         WHERE r.requested_by = ?
         ORDER BY r.id DESC",
        [$user_id]
    );
}

function get_all_termination_requests(string $status = ''): array {
    $where = $status ? "WHERE r.status = ?" : "";
    $params = $status ? [$status] : [];
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_termination_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users d ON d.id = r.decided_by
         {$where}
         ORDER BY r.id DESC",
        $params
    );
}

function get_pending_terminations_count(): int {
    try {
        return (int)(db_one(
            "SELECT COUNT(*) AS c FROM contract_termination_requests WHERE status='oczekuje'"
        )['c'] ?? 0);
    } catch (\Exception $e) {
        return 0;
    }
}

function create_termination_request(
    string $type, int $id, ?int $user_id,
    string $name, string $powod, ?string $proposed_date,
    ?string $initiator = null, ?string $variant = null
): int {
    $notice_days    = null;
    $effective_date = null;
    if (isset(TERMINATION_VARIANTS[$variant])) {
        $notice_days    = (int)TERMINATION_VARIANTS[$variant]['notice_days'];
        $effective_date = $proposed_date ?: termination_compute_effective_date($variant, date('Y-m-d'));
    }

    return db_insert('contract_termination_requests', [
        'contract_type'  => $type,
        'contract_id'    => $id,
        'requested_by'   => $user_id,
        'requester_name' => $name,
        'powod'          => $powod,
        'proposed_date'  => $proposed_date ?: null,
        'status'         => 'oczekuje',
        'created_at'     => date('Y-m-d H:i:s'),
        'initiator'      => isset(TERMINATION_INITIATORS[$initiator]) ? $initiator : null,
        'variant'        => isset(TERMINATION_VARIANTS[$variant]) ? $variant : null,
        'notice_days'    => $notice_days,
        'effective_date' => $effective_date,
    ]);
}

function decide_termination(int $req_id, int $admin_id, string $decision, string $note): bool {
    $req = db_one(
        "SELECT r.*, u.name AS requested_by_name
         FROM contract_termination_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         WHERE r.id = ?",
        [$req_id]
    );
    if (!$req || $req['status'] !== 'oczekuje') return false;

    db()->prepare(
        "UPDATE contract_termination_requests
         SET status=?, decided_by=?, decided_at=?, decision_note=? WHERE id=?"
    )->execute([$decision, $admin_id, date('Y-m-d H:i:s'), $note, $req_id]);

    require_once __DIR__ . '/approval.php';

    if ($decision === 'zaakceptowany') {
        // Zmień status umowy na "rozwiązana"
        $table = table_for_type($req['contract_type']);
        db()->prepare("UPDATE {$table} SET status='rozwiązana', updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $req['contract_id']]);

        // Okres ochronny: konto M365 i login do panelu ("konto SZO") pozostają
        // aktywne jeszcze 4h od decyzji — wyłącza je dopiero cron/sync_m365.php
        // po upływie tego terminu (patrz includes/m365.php::m365_should_be_active()).
        // Tabele bez integracji M365 (praca/uslugi/inne) nie mają tej kolumny —
        // wtedy po prostu nic nie ma tu do zaplanowania.
        try {
            db()->prepare("UPDATE {$table} SET m365_deactivate_after = datetime('now', '+4 hours') WHERE id = ?")
                ->execute([$req['contract_id']]);
        } catch (\Throwable $e) {}

        log_contract_action($req['contract_type'], $req['contract_id'], $admin_id,
            'termination_approved',
            'Zaakceptowano wniosek o rozwiązanie. Umowa rozwiązana. Konto M365/SZO zostanie wyłączone za 4h.' . ($note ? ' ' . $note : '')
        );
    } else {
        log_contract_action($req['contract_type'], $req['contract_id'], $admin_id,
            'termination_rejected',
            'Odrzucono wniosek o rozwiązanie.' . ($note ? ' ' . $note : '')
        );
    }

    _termination_send_decision_email($req, $decision, $note);
    return true;
}

// ── Powiadomienia e-mail ───────────────────────────────────────────────────────

function _termination_notify_admins(
    string $type, array $row, string $name, string $powod, ?string $proposed_date,
    ?string $initiator = null, ?string $variant = null, ?string $effective_date = null
): void {
    require_once __DIR__ . '/approval.php';
    $org       = defined('ORG_NAME') ? ORG_NAME : '';
    $admin_url = APP_URL . '/admin/terminations.php';
    $typ_label = CONTRACT_TYPES[$type] ?? $type;
    $nr        = $row['numer_umowy'] ?? '';

    $date_line = $proposed_date
        ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Proponowana data:</td><td>" . htmlspecialchars(date_pl($proposed_date)) . "</td></tr>"
        : '';
    $initiator_line = $initiator
        ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Strona wypowiadająca:</td><td>" . htmlspecialchars(termination_initiator_label($initiator)) . "</td></tr>"
        : '';
    $variant_line = $variant
        ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Tryb rozwiązania:</td><td>" . htmlspecialchars(termination_variant_label($variant)) . "</td></tr>"
        : '';
    $effective_line = $effective_date
        ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Efektywna data zakończenia:</td><td><strong>" . htmlspecialchars(date_pl($effective_date)) . "</strong></td></tr>"
        : '';

    $body = "
<p>Dzień dobry,</p>
<p>Złożono wniosek o rozwiązanie umowy w systemie Rejestru Umów <strong>" . htmlspecialchars($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Umowa:</td><td><strong>" . htmlspecialchars("{$typ_label} {$nr}") . "</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Wnioskujący:</td><td>" . htmlspecialchars($name) . "</td></tr>
  {$initiator_line}
  {$variant_line}
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Powód:</td><td>" . htmlspecialchars($powod) . "</td></tr>
  {$date_line}
  {$effective_line}
</table>
<p><a href='" . htmlspecialchars($admin_url) . "'
   style='background:#dc3545;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>
  Rozpatrz wniosek →
</a></p>
";
    $admins = db_all("SELECT * FROM users WHERE role='admin' AND is_active=1");
    foreach ($admins as $admin) {
        approval_send_email($admin['email'], "Wniosek o rozwiązanie umowy {$nr} — {$org}", $body);
    }
}

/**
 * Rozwiązuje rzeczywisty adres e-mail wolontariusza dla wniosku o rozwiązanie
 * porozumienia wolontariackiego. Wniosek złożony przez pracownika w imieniu
 * organizacji (contracts/wolontariat/view.php) ma requested_by = konto
 * pracownika, więc adres wolontariusza trzeba wziąć z samej umowy, a nie
 * z konta wnioskującego.
 */
function _termination_volunteer_contact(array $req): ?array {
    if ($req['contract_type'] !== 'wolontariat') return null;
    $contract = db_one(
        "SELECT imie_nazwisko, email FROM umowy_wolontariat WHERE id = ?",
        [(int)$req['contract_id']]
    );
    if (!$contract || empty($contract['email'])) return null;
    return ['email' => $contract['email'], 'name' => $contract['imie_nazwisko'] ?: ($req['requester_name'] ?? '')];
}

/**
 * Potwierdzenie dla wolontariusza — złożono/odnotowano oświadczenie o
 * rezygnacji lub rozwiązaniu porozumienia (§ 7 contracts/wolontariat/print.php).
 * Wysyłane niezależnie od tego, czy wniosek złożył sam wolontariusz, czy
 * pracownik w jego imieniu — adres bierzemy z umowy, nie z konta wnioskującego.
 */
function _termination_notify_volunteer_new_request(int $req_id, array $contract_row): void {
    if (empty($contract_row['email'])) return;
    require_once __DIR__ . '/mail_queue.php';
    require_once __DIR__ . '/email_templates.php';

    $req = db_one("SELECT * FROM contract_termination_requests WHERE id = ?", [$req_id]);
    if (!$req) return;

    $org      = defined('ORG_NAME') ? ORG_NAME : '';
    $osoba    = $contract_row['imie_nazwisko'] ?? '';
    $view_url = APP_URL . '/contracts/wolontariat/view.php?id=' . $req['contract_id'];

    $variant   = $req['variant'] ?? null;
    $effective = $req['effective_date'] ?? null;
    $tryb_line = $variant
        ? termination_variant_label($variant) . (
            !empty(TERMINATION_VARIANTS[$variant]['immediate'])
                ? ' — ze skutkiem natychmiastowym'
                : ' — okres wypowiedzenia: ' . (int)($req['notice_days'] ?? TERMINATION_VARIANTS[$variant]['notice_days']) . ' dni'
          )
        : 'do ustalenia przez administratora';

    $rendered = email_tpl_render('termination_confirmation', [
        'accent'            => '#0d6efd',
        'osoba'             => $osoba,
        'numer'             => $contract_row['numer_umowy'] ?? '',
        'data_oswiadczenia' => date_pl(substr($req['created_at'] ?? date('Y-m-d H:i:s'), 0, 10)),
        'powod'             => (string)($req['powod'] ?? ''),
        'tryb'              => $tryb_line,
        'data_zakonczenia'  => $effective ? date_pl($effective) : 'zostanie ustalona przez administratora',
        'url'               => $view_url,
        'org'               => $org,
    ]);
    if (!$rendered['enabled']) return;

    mail_queue_add($contract_row['email'], $osoba, $rendered['subject'], $rendered['html'], '', 'wolontariat', (int)$req['contract_id']);
}

function _termination_send_decision_email(array $req, string $decision, string $note): void {
    require_once __DIR__ . '/approval.php';

    // Zawsze powiadamiamy wnioskującego (konto, które złożyło wniosek), a dla
    // umów wolontariackich DODATKOWO samego wolontariusza — jeśli wniosek
    // złożył pracownik w jego imieniu (contracts/wolontariat/view.php),
    // requested_by wskazuje na konto pracownika, nie na adres wolontariusza.
    $recipients = []; // email => imię i nazwisko
    if (!empty($req['requested_by'])) {
        $requester = db_one("SELECT * FROM users WHERE id=?", [$req['requested_by']]);
        if ($requester && !empty($requester['email'])) {
            $recipients[$requester['email']] = $requester['name'] ?? '';
        }
    }
    $volunteer = _termination_volunteer_contact($req);
    if ($volunteer) {
        $recipients[$volunteer['email']] = $volunteer['name'];
    }
    if (!$recipients) return;

    $org        = defined('ORG_NAME') ? ORG_NAME : '';
    $view_url   = APP_URL . '/contracts/' . $req['contract_type'] . '/view.php?id=' . $req['contract_id'];
    $is_ok      = $decision === 'zaakceptowany';
    $color      = $is_ok ? '#198754' : '#dc3545';
    $icon       = $is_ok ? '✓' : '✗';
    $label      = $is_ok ? 'Zaakceptowany — umowa została rozwiązana' : 'Odrzucony';
    $effective_line = ($is_ok && !empty($req['effective_date']))
        ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Data zakończenia współpracy:</td><td><strong>" . htmlspecialchars(date_pl($req['effective_date'])) . "</strong></td></tr>"
        : '';

    $body = "
<p>Dzień dobry,</p>
<p>Wniosek o rozwiązanie umowy złożony w systemie Rejestru Umów <strong>" . htmlspecialchars($org) . "</strong> otrzymał decyzję.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Decyzja:</td>
      <td><strong style='color:{$color}'>{$icon} {$label}</strong></td></tr>
  {$effective_line}
  " . ($note ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Uwaga:</td><td>" . htmlspecialchars($note) . "</td></tr>" : "") . "
</table>
<p><a href='" . htmlspecialchars($view_url) . "'>Otwórz umowę →</a></p>
";
    foreach ($recipients as $email => $name) {
        approval_send_email($email, "Decyzja ws. rozwiązania umowy — {$org}", $body);
    }
}
