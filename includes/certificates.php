<?php

// ── Migracja kolumn ───────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN cert_number          TEXT"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN sign_type            TEXT NOT NULL DEFAULT 'papierowe'"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN issued_by_name       TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN send_at              TEXT"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN docusign_envelope_id TEXT"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN verify_code          TEXT"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN ezd_pismo_id         INTEGER"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN certificate_type    TEXT NOT NULL DEFAULT 'wolontariat'"); } catch (\Throwable $e) {}
})();

/**
 * Zapewnia kod weryfikacyjny dla zaświadczenia (publiczna weryfikacja /weryfikuj).
 * Generuje losowy, niezgadywalny kod (40 bitów) i zapisuje, jeśli jeszcze nie istnieje.
 */
function cert_ensure_verify_code(int $req_id): string {
    $row = db_one("SELECT verify_code FROM certificate_requests WHERE id=?", [$req_id]);
    if ($row && !empty($row['verify_code'])) return $row['verify_code'];

    do {
        $code = strtoupper(bin2hex(random_bytes(5))); // 10 znaków hex
        $dup  = db_one("SELECT id FROM certificate_requests WHERE verify_code=?", [$code]);
    } while ($dup);

    db()->prepare("UPDATE certificate_requests SET verify_code=? WHERE id=?")->execute([$code, $req_id]);
    return $code;
}

/** Publiczny URL weryfikacji dla danego kodu. */
function certificate_verify_url(string $code): string {
    return rtrim(APP_URL, '/') . '/weryfikuj/?kod=' . urlencode($code);
}

/** Zaświadczenie po kodzie weryfikacyjnym. */
function get_certificate_by_verify_code(string $code): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    return db_one("SELECT * FROM certificate_requests WHERE verify_code=? LIMIT 1", [$code]);
}

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

// ── Typy zaświadczeń (prefix, etykieta, powiązane typy umów) ─────────────────
const CERTIFICATE_TYPES = [
    'wolontariat' => [
        'label'     => 'Zaświadczenie o wolontariacie',
        'prefix'    => 'ZAWOL',
        'desc'      => 'Potwierdza udział w wolontariacie na podstawie umowy wolontariackiej.',
        'for_types' => ['wolontariat'],
    ],
    'zatrudnienie' => [
        'label'     => 'Zaświadczenie o zatrudnieniu',
        'prefix'    => 'ZAWPR',
        'desc'      => 'Potwierdza fakt zatrudnienia, zajmowane stanowisko i okres pracy.',
        'for_types' => ['praca'],
    ],
    'wspolpraca' => [
        'label'     => 'Zaświadczenie o współpracy / wykonaniu umowy',
        'prefix'    => 'ZAWWS',
        'desc'      => 'Potwierdza realizację umowy cywilnoprawnej lub współpracę z organizacją.',
        'for_types' => ['zlecenie', 'dzielo', 'uslugi', 'powierzenie', 'inne'],
    ],
];

/** Zwraca prefix numeracji dla danego typu zaświadczenia. */
function cert_type_prefix(string $cert_type): string {
    return CERTIFICATE_TYPES[$cert_type]['prefix'] ?? 'ZAWOL';
}

/** Zwraca domyślny typ zaświadczenia pasujący do danego typu umowy. */
function cert_default_type_for_contract(string $contract_type): string {
    foreach (CERTIFICATE_TYPES as $key => $ct) {
        if (in_array($contract_type, $ct['for_types'], true)) return $key;
    }
    return 'wolontariat';
}

const CERTIFICATE_STATUSES = [
    'oczekuje'       => ['label' => 'Oczekuje',           'class' => 'warning'],
    'gotowe'         => ['label' => 'Gotowe (nie wysłane)', 'class' => 'info'],
    'esign_oczekuje' => ['label' => 'Oczekuje na podpis', 'class' => 'primary'],
    'wydane'         => ['label' => 'Wydane',             'class' => 'success'],
    'odrzucone'      => ['label' => 'Odrzucone',          'class' => 'danger'],
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
        return (int) (db_one(
            "SELECT COUNT(*) AS c FROM certificate_requests WHERE status IN ('oczekuje','gotowe','esign_oczekuje')"
        )['c'] ?? 0);
    } catch (\Exception $e) {
        return 0;
    }
}

function create_certificate_request(string $type, int $id, ?int $user_id, string $name, string $email, string $cel, string $cert_type = 'wolontariat'): int {
    if (!array_key_exists($cert_type, CERTIFICATE_TYPES)) $cert_type = 'wolontariat';
    return db_insert('certificate_requests', [
        'contract_type'    => $type,
        'contract_id'      => $id,
        'requested_by'     => $user_id,
        'requester_name'   => $name,
        'requester_email'  => $email,
        'cel'              => $cel,
        'certificate_type' => $cert_type,
        'status'           => 'oczekuje',
        'created_at'       => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Wydaje zaświadczenie. Przyjmuje treść tekstową i/lub ścieżkę do uploadowanego pliku.
 * Przynajmniej jedno z $content / $file_path musi być niepuste.
 *
 * Logika wysyłki:
 *  - sign_type = 'esign'         → status esign_oczekuje, dokument trafia do DocuSign; e-mail po podpisaniu (webhook)
 *  - sign_type = 'elektroniczne' → status gotowe; admin decyduje kiedy wysłać
 *  - file_path niepuste          → status gotowe; admin decyduje kiedy wysłać
 *  - pozostałe (papierowe + tekst) → status wydane; e-mail wysyłany natychmiast
 */
function issue_certificate(int $req_id, int $admin_id, string $content, ?string $file_path = null, string $sign_type = 'papierowe'): bool {
    $req = get_certificate_request($req_id);
    if (!$req || $req['status'] !== 'oczekuje') return false;
    if (!$content && !$file_path) return false;

    $number    = $req['cert_number'] ?: cert_next_number(cert_type_prefix($req['certificate_type'] ?? 'wolontariat'));
    $issuer    = db_one("SELECT name FROM users WHERE id=?", [$admin_id]);
    $issuer_nm = $issuer['name'] ?? '';

    if ($sign_type === 'esign') {
        $new_status = 'esign_oczekuje';
    } elseif ($sign_type === 'elektroniczne' || $file_path) {
        $new_status = 'gotowe';
    } else {
        $new_status = 'wydane';
    }

    db()->prepare(
        "UPDATE certificate_requests
         SET status=?, issued_by=?, issued_by_name=?, issued_at=?,
             certificate_content=?, certificate_file=?, cert_number=?, sign_type=?
         WHERE id=?"
    )->execute([$new_status, $admin_id, $issuer_nm, date('Y-m-d H:i:s'), $content ?: null, $file_path, $number, $sign_type, $req_id]);

    // Kod weryfikacyjny — dla publicznej weryfikacji autentyczności (/weryfikuj).
    cert_ensure_verify_code($req_id);

    require_once __DIR__ . '/approval.php';
    $note = 'Wydano zaświadczenie dla: ' . $req['requester_name'];
    if ($file_path) $note .= ' [plik]';
    log_contract_action($req['contract_type'], $req['contract_id'], $admin_id, 'certificate_issued', $note);

    $req['certificate_content'] = $content;
    $req['certificate_file']    = $file_path;

    // Rejestracja w EZD (Wirtualne biurko) pod hasłem JRWA zaświadczeń — nie blokuje wydania
    if (module_enabled('ezd_enabled')) {
        try {
            require_once __DIR__ . '/ezd.php';
            if (function_exists('ezd_register_certificate')) {
                ezd_register_certificate(get_certificate_request($req_id), $admin_id);
            }
        } catch (\Throwable $e) { error_log('EZD cert register: ' . $e->getMessage()); }
    }

    if ($new_status === 'wydane') {
        _certificate_send_issued_email($req);
    } elseif ($new_status === 'esign_oczekuje') {
        _certificate_send_to_docusign($req_id, $req, $file_path);
    }

    return true;
}

/**
 * Wysyła gotowe (wstrzymane) zaświadczenie do wnioskodawcy.
 * Zmienia status z 'gotowe' na 'wydane' i wysyła e-mail.
 */
function send_certificate_now(int $req_id, int $admin_id): bool {
    $req = get_certificate_request($req_id);
    if (!$req || $req['status'] !== 'gotowe') return false;

    db()->prepare(
        "UPDATE certificate_requests SET status='wydane', send_at=? WHERE id=?"
    )->execute([date('Y-m-d H:i:s'), $req_id]);

    require_once __DIR__ . '/approval.php';
    log_contract_action($req['contract_type'], $req['contract_id'], $admin_id, 'certificate_sent',
        'Wysłano zaświadczenie do: ' . $req['requester_email']);

    _certificate_send_issued_email($req);
    return true;
}

/**
 * Wysyła plik zaświadczenia do DocuSign celem podpisania przez wnioskodawcę.
 * Po podpisaniu webhook (api/docusign_webhook.php) automatycznie wyśle e-mail.
 */
function _certificate_send_to_docusign(int $req_id, array $req, ?string $file_path): void {
    if (!$file_path) return;

    require_once __DIR__ . '/docusign.php';
    if (!docusign_is_enabled()) return;

    try {
        $ds = new DocuSignClient();
        if (!$ds->is_configured()) return;

        $full_path = UPLOAD_DIR . $file_path;
        if (!file_exists($full_path)) return;

        $envelope_id = $ds->send_envelope(
            $full_path,
            $req['requester_name'],
            $req['requester_email'],
            'Zaświadczenie nr ' . ($req['cert_number'] ?? "#{$req_id}")
        );

        db()->prepare(
            "UPDATE certificate_requests SET docusign_envelope_id=? WHERE id=?"
        )->execute([$envelope_id, $req_id]);

    } catch (\Throwable $e) {
        error_log('DocuSign certificate error for req #' . $req_id . ': ' . $e->getMessage());
    }
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
 * Dispatcher: generuje treść zaświadczenia w zależności od jego typu.
 * Wynik zawiera wyłącznie akapity merytoryczne (bez tytułu, daty, podpisu).
 * Akapity oddzielone pustą linią (\n\n).
 */
function generate_certificate_content(string $type, array $row, array $req): string {
    return match ($req['certificate_type'] ?? 'wolontariat') {
        'zatrudnienie' => _cert_content_zatrudnienie($type, $row, $req),
        'wspolpraca'   => _cert_content_wspolpraca($type, $row, $req),
        default        => _cert_content_wolontariat($type, $row, $req),
    };
}

function _cert_content_wolontariat(string $type, array $row, array $req): string {
    $org  = defined('ORG_NAME') ? ORG_NAME : '';
    $name = get_contract_person_name($type, $row);
    $nr   = $row['numer_umowy'] ?? '';
    $od   = !empty($row['data_rozpoczecia']) ? date_pl($row['data_rozpoczecia']) : '—';
    $do   = !empty($row['data_zakonczenia']) ? date_pl($row['data_zakonczenia']) : '—';
    $dz   = !empty($row['data_zawarcia'])    ? date_pl($row['data_zawarcia'])    : '—';

    $przedmiot = $row['przedmiot_porozumienia'] ?? $row['przedmiot_zlecenia']
        ?? $row['zakres_dzialan'] ?? $row['stanowisko'] ?? '';

    $p1 = "Niniejszym zaświadcza się, że Pan/Pani {$name} jest wolontariuszem/wolontariuszką "
        . "w {$org} na podstawie porozumienia o Wolontariacie nr {$nr}, zawartego w dniu {$dz}.";

    $p2 = !empty($row['bezterminowa'])
        ? "Porozumienie zawarto na czas nieokreślony, od dnia {$od}."
        : "Okres wolontariatu: od {$od} do {$do}.";

    $p3 = $przedmiot ? "Zakres działań wolontariackich:\n{$przedmiot}" : '';

    $p4 = '';
    $h_week = $row['godzin_tygodniowo'] ?? '';
    $h_tot  = $row['godzin_przepracowanych'] ?? '';
    if ($h_week || $h_tot) {
        $parts = [];
        if ($h_week) $parts[] = "wymiar: {$h_week} godz./tydzień";
        if ($h_tot)  $parts[] = "przepracowanych łącznie: {$h_tot} godz.";
        $p4 = "Wymiar zaangażowania: " . implode(', ', $parts) . ".";
    }

    $p5 = $req['cel']
        ? "Zaświadczenie wydaje się na wniosek zainteresowanego/zainteresowanej w celu: {$req['cel']}."
        : "Zaświadczenie wydaje się na wniosek zainteresowanego/zainteresowanej.";

    return implode("\n\n", array_filter([$p1, $p2, $p3, $p4, $p5]));
}

function _cert_content_zatrudnienie(string $type, array $row, array $req): string {
    $org        = defined('ORG_NAME') ? ORG_NAME : '';
    $name       = get_contract_person_name($type, $row);
    $nr         = $row['numer_umowy'] ?? '';
    $od         = !empty($row['data_rozpoczecia']) ? date_pl($row['data_rozpoczecia']) : '—';
    $do         = !empty($row['data_zakonczenia']) ? date_pl($row['data_zakonczenia']) : '—';
    $dz         = !empty($row['data_zawarcia'])    ? date_pl($row['data_zawarcia'])    : '—';
    $stanowisko = trim($row['stanowisko'] ?? '');

    $p1 = "Niniejszym zaświadcza się, że Pan/Pani {$name} jest zatrudniony/a w {$org} "
        . "na podstawie umowy o pracę nr {$nr}, zawartej w dniu {$dz}.";

    $p2 = $stanowisko ? "Zajmowane stanowisko: {$stanowisko}." : '';

    $p3 = !empty($row['czas_nieokreslony'])
        ? "Umowa zawarta na czas nieokreślony, od dnia {$od}."
        : "Okres zatrudnienia: od {$od} do {$do}.";

    $p4 = $req['cel']
        ? "Zaświadczenie wydaje się na wniosek zainteresowanego/zainteresowanej w celu: {$req['cel']}."
        : "Zaświadczenie wydaje się na wniosek zainteresowanego/zainteresowanej.";

    return implode("\n\n", array_filter([$p1, $p2, $p3, $p4]));
}

function _cert_content_wspolpraca(string $type, array $row, array $req): string {
    $org  = defined('ORG_NAME') ? ORG_NAME : '';
    $name = get_contract_person_name($type, $row);
    if (!$name) $name = $row['nazwa_wykonawcy'] ?? $row['nazwa_firmy'] ?? '';
    $nr   = $row['numer_umowy'] ?? '';
    $od   = !empty($row['data_rozpoczecia']) ? date_pl($row['data_rozpoczecia']) : '—';
    $do   = !empty($row['data_zakonczenia']) ? date_pl($row['data_zakonczenia']) : '—';
    $dz   = !empty($row['data_zawarcia'])    ? date_pl($row['data_zawarcia'])    : '—';

    $przedmiot = $row['przedmiot_zlecenia'] ?? $row['przedmiot_dziela']
        ?? $row['przedmiot_porozumienia'] ?? $row['zakres_uslug']
        ?? $row['przedmiot_uslugi']       ?? $row['zakres_dzialan'] ?? '';

    $typ_label = CONTRACT_TYPES[$type] ?? 'umowy';

    $p1 = "Niniejszym zaświadcza się, że Pan/Pani {$name} wykonał/a zlecenie na rzecz {$org} "
        . "na podstawie {$typ_label} nr {$nr}, zawartej w dniu {$dz}.";

    $p2 = $przedmiot ? "Przedmiot umowy:\n{$przedmiot}" : '';

    $p3 = "Okres realizacji: od {$od} do {$do}.";

    $p4 = $req['cel']
        ? "Zaświadczenie wydaje się na wniosek zainteresowanego/zainteresowanej w celu: {$req['cel']}."
        : "Zaświadczenie wydaje się na wniosek zainteresowanego/zainteresowanej.";

    return implode("\n\n", array_filter([$p1, $p2, $p3, $p4]));
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

    $allowed_mimes = [
        'application/pdf' => ['pdf'],
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
    ];
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($allowed_mimes[$mime]) || !in_array($ext, $allowed_mimes[$mime], true)) return null;

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

/**
 * Przygotowuje wszystkie zmienne widoku potrzebne do wydruku/PDF zaświadczenia.
 * Zwraca: type, row, org, org_city, org_nip, org_krs, org_adres,
 *         logo_b64, logo_mime, cert_type_key, cert_type_label,
 *         cert_number, issued_date, sign_type, issuer_name, paragraphs.
 * Nie generuje kodu QR — robi to wywołujący.
 */
function cert_view_vars(array $req): array {
    $type  = $req['contract_type'];
    $table = table_for_type($type);
    $row   = db_one("SELECT * FROM {$table} WHERE id=?", [$req['contract_id']]) ?? [];

    $stored    = org_setting('org_name');
    $const     = defined('ORG_NAME') ? ORG_NAME : '';
    $org       = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
    $org_city  = org_setting('org_miejscowosc') ?: '';
    $org_nip   = org_setting('org_nip') ?: '';
    $org_krs   = org_setting('org_krs') ?: '';
    $org_adres = org_setting('org_adres') ?: '';

    $logo_b64 = ''; $logo_mime = 'image/png';
    $lf = org_setting('org_logo');
    if ($lf) {
        $lp = dirname(__DIR__) . '/assets/logo/' . basename($lf);
        if (file_exists($lp) && filesize($lp) < 500_000) {
            $logo_b64  = base64_encode(file_get_contents($lp));
            $logo_mime = str_ends_with(strtolower($lf), '.svg') ? 'image/svg+xml' : 'image/png';
        }
    }

    $cert_type_key   = $req['certificate_type'] ?? 'wolontariat';
    $cert_type_label = CERTIFICATE_TYPES[$cert_type_key]['label'] ?? 'Zaświadczenie';
    $cert_number     = $req['cert_number']
        ?: (cert_type_prefix($cert_type_key) . '/' . str_pad((int)$req['id'], 4, '0', STR_PAD_LEFT) . '/' . date('Y'));
    $issued_date = $req['issued_at'] ? date('d.m.Y', strtotime($req['issued_at'])) : date('d.m.Y');
    $sign_type   = $req['sign_type'] ?? 'papierowe';
    $issuer_name = $req['issued_by_name'] ?? '';

    $raw = trim($req['certificate_content'] ?? '');
    $raw = preg_replace('/^ZAŚWIADCZENIE\s+/u', '', $raw);
    $raw = preg_replace('/\s*\.{10,}.*$/su', '', $raw);
    $raw = preg_replace('/\s*Podpis osoby.*$/su', '', $raw);
    $raw = preg_replace('/\s*\d{1,2}\s+\w+\s+\d{4}\s*$/u', '', $raw);
    if ($org && str_ends_with(rtrim($raw), $org)) {
        $raw = substr($raw, 0, strrpos($raw, $org));
    }
    $raw = trim($raw);

    $paragraphs = [];
    foreach (preg_split('/\n{2,}/', $raw) as $p) {
        $p = trim($p);
        if ($p !== '') $paragraphs[] = $p;
    }

    return compact(
        'type', 'row', 'org', 'org_city', 'org_nip', 'org_krs', 'org_adres',
        'logo_b64', 'logo_mime', 'cert_type_key', 'cert_type_label',
        'cert_number', 'issued_date', 'sign_type', 'issuer_name', 'paragraphs'
    );
}
