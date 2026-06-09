<?php

// ── Migracja kolumn ───────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN cert_number  TEXT"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN sign_type    TEXT NOT NULL DEFAULT 'papierowe'"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN issued_by_name TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
})();

// ── Numeracja: ZAWOL/NNNN/RRRR ───────────────────────────────────────────────
function cert_next_number(string $prefix = 'ZAWOL'): string {
    $year = date('Y');
    $last = db_one(
        "SELECT cert_number FROM certificate_requests WHERE cert_number LIKE ? ORDER BY id DESC LIMIT 1",
        ["{$prefix}/%/{$year}"]
    );
    if ($last) {
        $seq = (int)explode('/', $last['cert_number'])[1] + 1;
    } else {
        $seq = 1;
    }
    return "{$prefix}/" . str_pad($seq, 4, '0', STR_PAD_LEFT) . "/{$year}";
}

const CERTIFICATE_STATUSES = [
    'oczekuje'   => ['label' => 'Oczekuje',  'class' => 'warning'],
    'wydane'     => ['label' => 'Wydane',    'class' => 'success'],
    'odrzucone'  => ['label' => 'Odrzucone', 'class' => 'danger'],
];

function certificate_status_badge(string $status): string {
    $s = CERTIFICATE_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function get_certificate_requests(string $type, int $id): array {
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, a.name AS issued_by_name
         FROM certificate_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users a ON a.id = r.issued_by
         WHERE r.contract_type = ? AND r.contract_id = ?
         ORDER BY r.id DESC",
        [$type, $id]
    );
}

function get_user_certificate_requests(int $user_id): array {
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, a.name AS issued_by_name
         FROM certificate_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users a ON a.id = r.issued_by
         WHERE r.requested_by = ?
         ORDER BY r.id DESC",
        [$user_id]
    );
}

function get_all_certificate_requests(string $status = ''): array {
    if ($status) {
        return db_all(
            "SELECT r.*, u.name AS requested_by_name, a.name AS issued_by_name
             FROM certificate_requests r
             LEFT JOIN users u ON u.id = r.requested_by
             LEFT JOIN users a ON a.id = r.issued_by
             WHERE r.status = ?
             ORDER BY r.id DESC",
            [$status]
        );
    }
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, a.name AS issued_by_name
         FROM certificate_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users a ON a.id = r.issued_by
         ORDER BY r.id DESC"
    );
}

function get_certificate_request(int $req_id): ?array {
    return db_one(
        "SELECT r.*, u.name AS requested_by_name, a.name AS issued_by_name
         FROM certificate_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users a ON a.id = r.issued_by
         WHERE r.id = ?",
        [$req_id]
    );
}

function get_pending_certificates_count(): int {
    try {
        return (int) (db_one("SELECT COUNT(*) AS c FROM certificate_requests WHERE status='oczekuje'")['c'] ?? 0);
    } catch (\Exception $e) {
        return 0;
    }
}

function create_certificate_request(string $type, int $id, ?int $user_id, string $name, string $email, string $cel): int {
    return db_insert('certificate_requests', [
        'contract_type'   => $type,
        'contract_id'     => $id,
        'requested_by'    => $user_id,
        'requester_name'  => $name,
        'requester_email' => $email,
        'cel'             => $cel,
        'status'          => 'oczekuje',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Wydaje zaświadczenie. Przyjmuje treść tekstową i/lub ścieżkę do uploadowanego pliku.
 * Przynajmniej jedno z $content / $file_path musi być niepuste.
 */
function issue_certificate(int $req_id, int $admin_id, string $content, ?string $file_path = null, string $sign_type = 'papierowe'): bool {
    $req = get_certificate_request($req_id);
    if (!$req || $req['status'] !== 'oczekuje') return false;
    if (!$content && !$file_path) return false;

    $number    = $req['cert_number'] ?: cert_next_number();
    $issuer    = db_one("SELECT name FROM users WHERE id=?", [$admin_id]);
    $issuer_nm = $issuer['name'] ?? '';

    db()->prepare(
        "UPDATE certificate_requests
         SET status='wydane', issued_by=?, issued_by_name=?, issued_at=?,
             certificate_content=?, certificate_file=?, cert_number=?, sign_type=?
         WHERE id=?"
    )->execute([$admin_id, $issuer_nm, date('Y-m-d H:i:s'), $content ?: null, $file_path, $number, $sign_type, $req_id]);

    require_once __DIR__ . '/approval.php';
    $note = 'Wydano zaświadczenie dla: ' . $req['requester_name'];
    if ($file_path) $note .= ' [plik]';
    log_contract_action($req['contract_type'], $req['contract_id'], $admin_id, 'certificate_issued', $note);

    // Odśwież req z nowymi danymi
    $req['certificate_content'] = $content;
    $req['certificate_file']    = $file_path;
    _certificate_send_issued_email($req);
    return true;
}

function reject_certificate_request(int $req_id, int $admin_id, string $note): bool {
    $req = get_certificate_request($req_id);
    if (!$req || $req['status'] !== 'oczekuje') return false;

    db()->prepare(
        "UPDATE certificate_requests SET status='odrzucone', issued_by=?, issued_at=?, rejection_note=? WHERE id=?"
    )->execute([$admin_id, date('Y-m-d H:i:s'), $note, $req_id]);

    require_once __DIR__ . '/approval.php';
    log_contract_action($req['contract_type'], $req['contract_id'], $admin_id, 'certificate_rejected',
        'Odrzucono wniosek o zaświadczenie dla: ' . $req['requester_name'] . ($note ? '. ' . $note : ''));

    _certificate_send_rejected_email($req, $note);
    return true;
}

function get_contract_person_name(string $type, array $row): string {
    return $row['imie_nazwisko'] ?? $row['nazwa_firmy'] ?? '';
}

/**
 * Generuje treść zaświadczenia (wyłącznie akapity merytoryczne).
 * Nie zawiera tytułu, daty ani linii podpisu — za to odpowiada print.php.
 * Akapity oddzielone pustą linią (\n\n).
 */
function generate_certificate_content(string $type, array $row, array $req): string {
    $org  = defined('ORG_NAME') ? ORG_NAME : '';
    $name = get_contract_person_name($type, $row);
    $nr   = $row['numer_umowy'] ?? '';
    $od   = !empty($row['data_rozpoczecia']) ? date_pl($row['data_rozpoczecia']) : '—';
    $do   = !empty($row['data_zakonczenia']) ? date_pl($row['data_zakonczenia']) : '—';
    $dz   = !empty($row['data_zawarcia'])    ? date_pl($row['data_zawarcia'])    : '—';

    $przedmiot = '';
    if (!empty($row['przedmiot_porozumienia'])) $przedmiot = $row['przedmiot_porozumienia'];
    elseif (!empty($row['przedmiot_zlecenia'])) $przedmiot = $row['przedmiot_zlecenia'];
    elseif (!empty($row['przedmiot_dziela']))   $przedmiot = $row['przedmiot_dziela'];
    elseif (!empty($row['zakres_dzialan']))      $przedmiot = $row['zakres_dzialan'];
    elseif (!empty($row['stanowisko']))          $przedmiot = $row['stanowisko'];
    elseif (!empty($row['zakres_uslug']))        $przedmiot = $row['zakres_uslug'];

    // Akapit 1 — kto, co, kiedy
    $p1 = "Niniejszym zaświadcza się, że Pan/Pani {$name} jest wolontariuszem/wolontariuszką "
        . "w {$org} na podstawie porozumienia o Wolontariacie nr {$nr}, "
        . "zawartego w dniu {$dz}.";

    // Akapit 2 — okres
    if (!empty($row['bezterminowa'])) {
        $p2 = "Porozumienie zawarto na czas nieokreślony,  od dnia {$od}.";
    } else {
        $p2 = "Okres wolontariatu: od {$od} do {$do}.";
    }

    // Akapit 3 — zakres (opcjonalnie)
    $p3 = $przedmiot ? "Zakres działań wolontariackich:\n{$przedmiot}" : '';

    // Akapit 4 — godziny (opcjonalnie)
    $p4 = '';
    $h_week = $row['godzin_tygodniowo'] ?? '';
    $h_tot  = $row['godzin_przepracowanych'] ?? '';
    if ($h_week || $h_tot) {
        $parts = [];
        if ($h_week) $parts[] = "wymiar: {$h_week} godz./tydzień";
        if ($h_tot)  $parts[] = "przepracowanych łącznie: {$h_tot} godz.";
    }

    // Akapit 5 — cel
    $p5 = $req['cel']
        ? "Zaświadczenie wydaje się na wniosek zainteresowanego/zainteresowanej w celu: {$req['cel']}."
        : "Zaświadczenie wydaje się na wniosek zainteresowanego/zainteresowanej.";

    return implode("\n\n", array_filter([$p1, $p2, $p3, $p4, $p5]));
}

/**
 * Upload pliku zaświadczenia (skan PDF / ePodpis). Zwraca względną ścieżkę lub null.
 */
function handle_certificate_upload(string $field): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return null;

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) return null;
    if ($f['size'] > 30 * 1024 * 1024) return null;

    $dir = UPLOAD_DIR . 'certificates/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return null;

    return 'certificates/' . $name;
}

function certificate_file_url(?string $path): string {
    if (!$path) return '';
    return APP_URL . '/uploads/' . $path;
}

// ── E-mail ─────────────────────────────────────────────────────────────────────

function _certificate_send_issued_email(array $req): void {
    require_once __DIR__ . '/approval.php';
    $org       = defined('ORG_NAME') ? ORG_NAME : '';
    $print_url = APP_URL . '/certificates/print.php?id=' . $req['id'];
    $has_file  = !empty($req['certificate_file']);
    $has_text  = !empty($req['certificate_content']);

    $file_block = '';
    if ($has_file) {
        $file_url   = certificate_file_url($req['certificate_file']);
        $file_block = "
<p><strong>Do pobrania (skan / ePodpis):</strong></p>
<p><a href='" . htmlspecialchars($file_url) . "' style='background:#0d6efd;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>
  ⬇ Pobierz plik zaświadczenia
</a></p>";
    }

    $text_block = '';
    if ($has_text) {
        $text_block = "
<p><a href='" . htmlspecialchars($print_url) . "' style='background:#198754;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>
  📄 Otwórz / wydrukuj zaświadczenie
</a></p>
<pre style='background:#f8f9fa;border:1px solid #dee2e6;padding:1rem;border-radius:4px;font-size:.85rem;white-space:pre-wrap'>" . htmlspecialchars($req['certificate_content']) . "</pre>";
    }

    $body = "
<p>Dzień dobry,</p>
<p>Na Państwa wniosek zostało wydane zaświadczenie przez <strong>" . htmlspecialchars($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Cel:</td><td>" . htmlspecialchars($req['cel']) . "</td></tr>
</table>
{$file_block}
{$text_block}
<p style='color:#888;font-size:.85em'>Wygenerowane automatycznie przez system Rejestru Umów {$org}.</p>
";
    approval_send_email($req['requester_email'], "Zaświadczenie — {$org}", $body);
}

function _certificate_send_rejected_email(array $req, string $note): void {
    require_once __DIR__ . '/approval.php';
    $org = defined('ORG_NAME') ? ORG_NAME : '';

    $body = "
<p>Dzień dobry,</p>
<p>Wniosek o zaświadczenie złożony do <strong>" . htmlspecialchars($org) . "</strong> został odrzucony.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Cel:</td><td>" . htmlspecialchars($req['cel']) . "</td></tr>
  " . ($note ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Powód:</td><td>" . htmlspecialchars($note) . "</td></tr>" : "") . "
</table>
<p style='color:#888;font-size:.85em'>W razie pytań prosimy o kontakt z organizacją.</p>
";
    approval_send_email($req['requester_email'], "Wniosek o zaświadczenie — odrzucony — {$org}", $body);
}

function _certificate_notify_admins(string $type, array $row, array $req): void {
    require_once __DIR__ . '/approval.php';
    $org       = defined('ORG_NAME') ? ORG_NAME : '';
    $admin_url = APP_URL . '/admin/certificates.php';
    $typ_label = CONTRACT_TYPES[$type] ?? $type;

    $admins = db_all("SELECT * FROM users WHERE role='admin' AND is_active=1");
    foreach ($admins as $admin) {
        $body = "
<p>Dzień dobry,</p>
<p>Złożono nowy wniosek o zaświadczenie w systemie Rejestru Umów <strong>" . htmlspecialchars($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Umowa:</td><td><strong>" . htmlspecialchars($typ_label . ' ' . ($row['numer_umowy'] ?? '')) . "</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Wnioskodawca:</td><td>" . htmlspecialchars($req['requester_name']) . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>E-mail:</td><td>" . htmlspecialchars($req['requester_email']) . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Cel:</td><td>" . htmlspecialchars($req['cel']) . "</td></tr>
</table>
<p><a href='" . htmlspecialchars($admin_url) . "' style='background:#0d6efd;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>Przejdź do wniosków →</a></p>
";
        approval_send_email($admin['email'], "Nowy wniosek o zaświadczenie — {$org}", $body);
    }
}
