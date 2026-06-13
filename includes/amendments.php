<?php
// ── Status labels ──────────────────────────────────────────────────────────────

const AMENDMENT_STATUSES = [
    'oczekuje'      => ['label' => 'Oczekuje',      'class' => 'warning'],
    'zaakceptowany' => ['label' => 'Zaakceptowany', 'class' => 'success'],
    'odrzucony'     => ['label' => 'Odrzucony',     'class' => 'danger'],
    'wycofany'      => ['label' => 'Wycofany',      'class' => 'secondary'],
];

const EDIT_REQUEST_STATUSES = [
    'oczekuje'      => ['label' => 'Oczekuje',      'class' => 'warning'],
    'zaakceptowany' => ['label' => 'Zaakceptowany', 'class' => 'success'],
    'odrzucony'     => ['label' => 'Odrzucony',     'class' => 'danger'],
    'wycofany'      => ['label' => 'Wycofany',      'class' => 'secondary'],
];

// Pola śledzone przy edycji — nazwa pola → etykieta
const FIELD_LABELS = [
    'numer_umowy'                => 'Numer umowy',
    'status'                     => 'Status',
    'opiekun'                    => 'Opiekun',
    'data_zawarcia'              => 'Data zawarcia',
    'data_rozpoczecia'           => 'Data rozpoczęcia',
    'data_zakonczenia'           => 'Data zakończenia',
    'termin_oddania'             => 'Termin oddania',
    'imie_nazwisko'              => 'Imię i nazwisko',
    'imie_nazwisko_wolontariusza'=> 'Imię i nazwisko wolontariusza',
    'pesel'                      => 'PESEL',
    'seria_nr_dowodu'            => 'Seria/nr dowodu',
    'adres'                      => 'Adres',
    'urzad_skarbowy'             => 'Urząd skarbowy',
    'rachunek_bankowy'           => 'Rachunek bankowy',
    'przedmiot_zlecenia'         => 'Przedmiot zlecenia',
    'przedmiot_uslugi'           => 'Przedmiot usługi',
    'opis_dziela'                => 'Opis dzieła',
    'przedmiot_umowy'            => 'Przedmiot umowy',
    'zakres_wolontariatu'        => 'Zakres wolontariatu',
    'numer_projektu'             => 'Nr projektu',
    'wynagrodzenie_brutto'       => 'Wynagrodzenie brutto',
    'wynagrodzenie_zasadnicze'   => 'Wynagrodzenie zasadnicze',
    'stawka_kwota'               => 'Stawka kwota',
    'typ_stawki'                 => 'Typ stawki',
    'liczba_godzin_planowana'    => 'Liczba godzin',
    'termin_platnosci'           => 'Termin płatności',
    'warunki_platnosci'          => 'Warunki płatności',
    'kup'                        => 'KUP',
    'zaliczka_podatek'           => 'Zaliczka podatek',
    'zus_skladki'                => 'Składki ZUS',
    'zwolnienie_wiek'            => 'Zwolnienie <26 lat',
    'tytul_ubezpieczenia'        => 'Tytuł ubezpieczenia',
    'zus_data_rejestracji'       => 'Data zgłoszenia do ZUS',
    'zus_data_wyrejestrowania'   => 'Data wyrejestrowania z ZUS',
    'wartosc_dziela'             => 'Wartość dzieła',
    'kwota_ryczaltu'             => 'Kwota ryczałtu',
    'stanowisko'                 => 'Stanowisko',
    'wymiar_czasu_pracy'         => 'Wymiar czasu pracy',
    'miejsce_pracy'              => 'Miejsce pracy',
    'wymagany_rachunek'          => 'Wymagany rachunek',
    'data_zl_rachunku'           => 'Data złożenia rachunku',
    'forma_podpisania'           => 'Forma podpisania',
    'platforma_el'               => 'Platforma elektroniczna',
    'id_dokumentu_el'            => 'ID dokumentu',
    'uwagi'                      => 'Uwagi',
    'nr_roboczy'                 => 'Nr roboczy',
    'nr_system'                  => 'Nr ogólny',
    'nr_rejestru'                => 'Nr rejestru',
];

const SKIP_TRACKING = [
    'id', 'created_at', 'updated_at',
    'plik_umowy', 'plik_aneksu', 'plik_potwierdzenia', 'zalaczniki',
    'm365_konto', 'm365_login', 'm365_user_id', 'm365_konto_aktywne',
    'm365_data_utworzenia', 'm365_licencja_przypisana', 'm365_nie_wylaczaj',
];

// ── Badges ────────────────────────────────────────────────────────────────────

function amendment_badge(string $status): string {
    $s = AMENDMENT_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function edit_request_badge(string $status): string {
    $s = EDIT_REQUEST_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

// ── Queries ───────────────────────────────────────────────────────────────────

function get_amendments(string $type, int $id): array {
    return db_all(
        "SELECT a.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_amendments a
         LEFT JOIN users u ON u.id = a.requested_by
         LEFT JOIN users d ON d.id = a.decided_by
         WHERE a.contract_type=? AND a.contract_id=?
         ORDER BY a.id DESC",
        [$type, $id]
    );
}

function get_edit_requests(string $type, int $id): array {
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_edit_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users d ON d.id = r.decided_by
         WHERE r.contract_type=? AND r.contract_id=?
         ORDER BY r.id DESC",
        [$type, $id]
    );
}

function get_workflow_pending_count(): int {
    $n = 0;
    foreach (['contract_approvals', 'contract_amendments', 'contract_edit_requests', 'certificate_requests', 'contract_termination_requests'] as $tbl) {
        try { $n += (int) db_one("SELECT COUNT(*) AS c FROM {$tbl} WHERE status='oczekuje'")['c']; }
        catch (\Exception $e) {}
    }
    return $n;
}

// ── Submit amendment ──────────────────────────────────────────────────────────

function submit_amendment(string $type, int $id, int $user_id, string $numer, string $opis, ?string $plik, array $proposed = []): array {
    $last = db_one("SELECT MAX(numer_aneksu) AS n FROM contract_amendments WHERE contract_type=? AND contract_id=?", [$type, $id]);
    $nr   = ($last['n'] ?? 0) + 1;

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

    $aid = db_insert('contract_amendments', [
        'contract_type'    => $type,
        'contract_id'      => $id,
        'numer_aneksu'     => $nr,
        'requested_by'     => $user_id,
        'opis_zmian'       => $opis,
        'plik_aneksu'      => $plik,
        'proposed_changes' => $proposed ? json_encode($proposed, JSON_UNESCAPED_UNICODE) : null,
        'status'           => 'oczekuje',
        'token'            => $token,
        'token_expires'    => $expires,
    ]);

    require_once __DIR__ . '/approval.php';
    log_contract_action($type, $id, $user_id, 'amendment_submit', "Złożono aneks #{$nr}: {$numer}" . ($proposed ? ' (' . count($proposed) . ' pól)' : ''));

    $admins = db_all("SELECT * FROM users WHERE role='admin' AND is_active=1");
    $sent = 0;
    foreach ($admins as $a) {
        if (_amendment_email_request($a, $type, $id, $numer, $nr, $opis, $token, $proposed)) $sent++;
    }
    db()->prepare("UPDATE contract_amendments SET email_sent=? WHERE id=?")->execute([$sent, $aid]);

    try {
        require_once __DIR__ . '/crm.php';
        CrmManager::autoCreateAmendmentCase($type, $id, $nr, $numer, $opis, $user_id, $aid);
    } catch (\Throwable $e) {
        error_log('[crm_case_aneks] ' . $e->getMessage());
    }

    return ['id' => $aid, 'numer' => $nr, 'emails_sent' => $sent];
}

function render_amendment_changes(?string $json): string {
    if (!$json) return '';
    $changes = json_decode($json, true) ?? [];
    if (!$changes) return '';
    $html = '<table class="table table-sm table-bordered mt-2 mb-0 small">';
    $html .= '<thead class="table-light"><tr><th>Pole</th><th class="text-danger">Przed</th><th class="text-success">Po</th></tr></thead><tbody>';
    foreach ($changes as $ch) {
        $html .= '<tr><td class="fw-semibold">' . htmlspecialchars($ch['label']) . '</td>';
        $html .= '<td class="text-danger">' . nl2br(htmlspecialchars($ch['old'])) . '</td>';
        $html .= '<td class="text-success fw-semibold">' . nl2br(htmlspecialchars($ch['new'])) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

function decide_amendment(int $id, string $decision, string $note, ?int $user_id, bool $via_email = false): bool {
    $a = db_one("SELECT * FROM contract_amendments WHERE id=?", [$id]);
    if (!$a || $a['status'] !== 'oczekuje') return false;

    $now = date('Y-m-d H:i:s');
    db()->prepare(
        "UPDATE contract_amendments SET status=?,decided_by=?,decided_at=?,decision_note=?,via_email=? WHERE id=?"
    )->execute([$decision, $user_id, $now, $note, $via_email ? 1 : 0, $id]);

    // Zastosuj zmiany do umowy po akceptacji i ustaw status "aneks"
    if ($decision === 'zaakceptowany') {
        $tbl = table_for_type($a['contract_type']);
        if (!empty($a['proposed_changes'])) {
            $changes = json_decode($a['proposed_changes'], true) ?? [];
            if ($changes) {
                $setCols = implode(',', array_map(fn($ch) => $ch['field'] . '=?', $changes));
                $vals    = array_map(fn($ch) => $ch['new'], $changes);
                $vals[]  = $a['contract_id'];
                db()->prepare("UPDATE {$tbl} SET {$setCols} WHERE id=?")->execute($vals);
                db()->prepare("UPDATE contract_amendments SET applied_at=? WHERE id=?")->execute([$now, $id]);
            }
        }
        // Zablokuj umowę — status aneks
        db()->prepare("UPDATE {$tbl} SET status='aneks' WHERE id=?")->execute([$a['contract_id']]);
    }

    require_once __DIR__ . '/approval.php';
    $action = $decision === 'zaakceptowany' ? 'amendment_approve' : 'amendment_reject';
    log_contract_action($a['contract_type'], $a['contract_id'], $user_id ?? 0, $action,
        ($via_email ? '[via e-mail] ' : '') . "Aneks #{$a['numer_aneksu']}" . ($note ? ": {$note}" : ''));

    $req = db_one("SELECT * FROM users WHERE id=?", [$a['requested_by']]);
    if ($req) {
        $c = db_one("SELECT numer_umowy FROM " . table_for_type($a['contract_type']) . " WHERE id=?", [$a['contract_id']]);
        _amendment_email_decision($req, $a['contract_type'], $a['contract_id'],
            $c['numer_umowy'] ?? '—', $a['numer_aneksu'], $decision, $note);
    }
    return true;
}

// ── Submit edit request ───────────────────────────────────────────────────────

function submit_edit_request(string $type, int $id, int $user_id, string $numer, string $opis): array {
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

    $rid = db_insert('contract_edit_requests', [
        'contract_type' => $type,
        'contract_id'   => $id,
        'requested_by'  => $user_id,
        'opis_zmian'    => $opis,
        'status'        => 'oczekuje',
        'token'         => $token,
        'token_expires' => $expires,
    ]);

    require_once __DIR__ . '/approval.php';
    log_contract_action($type, $id, $user_id, 'edit_request', "Złożono wniosek o edycję: {$numer}");

    $admins = db_all("SELECT * FROM users WHERE role='admin' AND is_active=1");
    $sent = 0;
    foreach ($admins as $a) {
        if (_edit_request_email_request($a, $type, $id, $numer, $opis, $token)) $sent++;
    }
    db()->prepare("UPDATE contract_edit_requests SET email_sent=? WHERE id=?")->execute([$sent, $rid]);

    return ['id' => $rid, 'emails_sent' => $sent];
}

function decide_edit_request(int $id, string $decision, string $note, ?int $user_id, bool $via_email = false): bool {
    $r = db_one("SELECT * FROM contract_edit_requests WHERE id=?", [$id]);
    if (!$r || $r['status'] !== 'oczekuje') return false;

    db()->prepare(
        "UPDATE contract_edit_requests SET status=?,decided_by=?,decided_at=?,decision_note=?,via_email=? WHERE id=?"
    )->execute([$decision, $user_id, date('Y-m-d H:i:s'), $note, $via_email ? 1 : 0, $id]);

    require_once __DIR__ . '/approval.php';
    $action = $decision === 'zaakceptowany' ? 'edit_approve' : 'edit_reject';
    log_contract_action($r['contract_type'], $r['contract_id'], $user_id ?? 0, $action,
        ($via_email ? '[via e-mail] ' : '') . ($note ?: ''));

    $req = db_one("SELECT * FROM users WHERE id=?", [$r['requested_by']]);
    if ($req) {
        $c = db_one("SELECT numer_umowy FROM " . table_for_type($r['contract_type']) . " WHERE id=?", [$r['contract_id']]);
        _edit_request_email_decision($req, $r['contract_type'], $r['contract_id'],
            $c['numer_umowy'] ?? '—', $decision, $note);
    }
    return true;
}

// ── Field change diff ─────────────────────────────────────────────────────────

function format_field_diff(array $old, array $new): string {
    $changes = [];
    foreach ($new as $field => $val) {
        if (in_array($field, SKIP_TRACKING)) continue;
        $o = (string)($old[$field] ?? '');
        $v = (string)$val;
        if ($o === $v) continue;
        $label  = FIELD_LABELS[$field] ?? $field;
        $oShort = mb_strlen($o) > 80 ? mb_substr($o, 0, 80) . '…' : $o;
        $vShort = mb_strlen($v) > 80 ? mb_substr($v, 0, 80) . '…' : $v;
        $changes[] = "{$label}: [{$oShort}] → [{$vShort}]";
    }
    return implode("\n", $changes);
}

// ── Private email helpers ─────────────────────────────────────────────────────

function _amendment_email_request(array $admin, string $type, int $id, string $numer, int $nr, string $opis, string $token, array $proposed = []): bool {
    $approve = APP_URL . '/contracts/approvals/amendments_approve.php?token=' . $token . '&action=zaakceptowany';
    $reject  = APP_URL . '/contracts/approvals/amendments_approve.php?token=' . $token . '&action=odrzucony';
    $view    = APP_URL . '/contracts/' . $type . '/view.php?id=' . $id;
    $org     = defined('ORG_NAME') ? ORG_NAME : '';

    $changesHtml = '';
    if ($proposed) {
        $changesHtml = "<table style='border-collapse:collapse;margin:12px 0;font-size:.9em'>"
            . "<tr style='background:#f0f0f0'><th style='padding:4px 10px'>Pole</th><th style='padding:4px 10px'>Przed</th><th style='padding:4px 10px'>Po</th></tr>";
        foreach ($proposed as $ch) {
            $changesHtml .= "<tr><td style='padding:4px 10px;font-weight:600'>" . htmlspecialchars($ch['label']) . "</td>"
                . "<td style='padding:4px 10px;color:#dc3545'>" . htmlspecialchars($ch['old']) . "</td>"
                . "<td style='padding:4px 10px;color:#198754;font-weight:600'>" . htmlspecialchars($ch['new']) . "</td></tr>";
        }
        $changesHtml .= "</table>";
    }

    $body = "<p>Dzień dobry,</p>
<p>Złożono wniosek o <strong>Aneks #{$nr}</strong> do umowy <strong>" . htmlspecialchars($numer) . "</strong> w systemie {$org}.</p>
<p><strong>Uzasadnienie:</strong></p>
<blockquote style='border-left:3px solid #ccc;padding-left:12px;color:#555'>" . nl2br(htmlspecialchars($opis)) . "</blockquote>"
    . ($changesHtml ? "<p><strong>Proponowane zmiany:</strong></p>{$changesHtml}" : "")
    . "<p>
  <a href='" . htmlspecialchars($approve) . "' style='background:#198754;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;margin-right:8px;display:inline-block'>✓ Zatwierdź aneks</a>
  <a href='" . htmlspecialchars($reject) . "' style='background:#dc3545;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>✗ Odrzuć</a>
</p>
<p><a href='" . htmlspecialchars($view) . "'>Otwórz umowę →</a></p>
<p style='color:#888;font-size:.85em'>Link ważny 30 dni. Rejestr Umów {$org}</p>";

    require_once __DIR__ . '/approval.php';
    return approval_send_email($admin['email'], "Aneks #{$nr} do {$numer} — {$org}", $body);
}

function _amendment_email_decision(array $user, string $type, int $id, string $numer, int $nr, string $decision, string $note): bool {
    $view  = APP_URL . '/contracts/' . $type . '/view.php?id=' . $id;
    $org   = defined('ORG_NAME') ? ORG_NAME : '';
    $label = $decision === 'zaakceptowany' ? '✓ Zaakceptowany' : '✗ Odrzucony';
    $color = $decision === 'zaakceptowany' ? '#198754' : '#dc3545';
    $body  = "<p>Dzień dobry,</p>
<p>Wniosek o <strong>Aneks #{$nr}</strong> do umowy <strong>" . htmlspecialchars($numer) . "</strong> otrzymał decyzję.</p>
<p><strong style='color:{$color}'>{$label}</strong></p>"
    . ($note ? "<p>Uwaga: " . htmlspecialchars($note) . "</p>" : "")
    . "<p><a href='" . htmlspecialchars($view) . "'>Otwórz umowę →</a></p>";

    require_once __DIR__ . '/approval.php';
    return approval_send_email($user['email'], "Aneks #{$nr} {$label} — {$numer}", $body);
}

function _edit_request_email_request(array $admin, string $type, int $id, string $numer, string $opis, string $token): bool {
    $approve = APP_URL . '/contracts/approvals/changes_approve.php?token=' . $token . '&action=zaakceptowany';
    $reject  = APP_URL . '/contracts/approvals/changes_approve.php?token=' . $token . '&action=odrzucony';
    $view    = APP_URL . '/contracts/' . $type . '/view.php?id=' . $id;
    $org     = defined('ORG_NAME') ? ORG_NAME : '';

    $body = "<p>Dzień dobry,</p>
<p>Złożono <strong>wniosek o edycję</strong> umowy <strong>" . htmlspecialchars($numer) . "</strong> w systemie {$org}.</p>
<blockquote style='border-left:3px solid #ccc;padding-left:12px;color:#555'>" . nl2br(htmlspecialchars($opis)) . "</blockquote>
<p>
  <a href='" . htmlspecialchars($approve) . "' style='background:#198754;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;margin-right:8px;display:inline-block'>✓ Zatwierdź edycję</a>
  <a href='" . htmlspecialchars($reject) . "' style='background:#dc3545;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>✗ Odrzuć</a>
</p>
<p><a href='" . htmlspecialchars($view) . "'>Otwórz umowę →</a></p>
<p style='color:#888;font-size:.85em'>Link ważny 30 dni. Rejestr Umów {$org}</p>";

    require_once __DIR__ . '/approval.php';
    return approval_send_email($admin['email'], "Wniosek o edycję: {$numer} — {$org}", $body);
}

function _edit_request_email_decision(array $user, string $type, int $id, string $numer, string $decision, string $note): bool {
    $view  = APP_URL . '/contracts/' . $type . '/view.php?id=' . $id;
    $org   = defined('ORG_NAME') ? ORG_NAME : '';
    $label = $decision === 'zaakceptowany' ? '✓ Zaakceptowany' : '✗ Odrzucony';
    $color = $decision === 'zaakceptowany' ? '#198754' : '#dc3545';
    $body  = "<p>Dzień dobry,</p>
<p>Wniosek o edycję umowy <strong>" . htmlspecialchars($numer) . "</strong> otrzymał decyzję.</p>
<p><strong style='color:{$color}'>{$label}</strong></p>"
    . ($note ? "<p>Uwaga: " . htmlspecialchars($note) . "</p>" : "")
    . "<p><a href='" . htmlspecialchars($view) . "'>Otwórz umowę →</a></p>";

    require_once __DIR__ . '/approval.php';
    return approval_send_email($user['email'], "Wniosek o edycję {$label} — {$numer}", $body);
}
