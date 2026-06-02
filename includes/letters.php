<?php
const LETTER_DIRECTIONS = [
    'wychodzące'   => ['label' => 'Wychodzące',   'class' => 'primary',   'icon' => 'bi-arrow-up-right-circle'],
    'przychodzące' => ['label' => 'Przychodzące', 'class' => 'success',   'icon' => 'bi-arrow-down-left-circle'],
    'wewnętrzne'   => ['label' => 'Wewnętrzne',   'class' => 'secondary', 'icon' => 'bi-arrow-left-right'],
];

const LETTER_TYPES = [
    'wezwanie'      => ['label' => 'Wezwanie',       'class' => 'danger',    'icon' => 'bi-exclamation-triangle'],
    'powiadomienie' => ['label' => 'Powiadomienie',  'class' => 'info',      'icon' => 'bi-bell'],
    'wypowiedzenie' => ['label' => 'Wypowiedzenie',  'class' => 'warning',   'icon' => 'bi-file-earmark-x'],
    'potwierdzenie' => ['label' => 'Potwierdzenie',  'class' => 'success',   'icon' => 'bi-check-circle'],
    'odpowiedź'     => ['label' => 'Odpowiedź',      'class' => 'secondary', 'icon' => 'bi-reply'],
    'informacja'    => ['label' => 'Informacja',     'class' => 'info',      'icon' => 'bi-info-circle'],
    'przypomnienie' => ['label' => 'Przypomnienie',  'class' => 'warning',   'icon' => 'bi-clock'],
    'inne'          => ['label' => 'Inne pismo',     'class' => 'secondary', 'icon' => 'bi-file-text'],
];

function letter_type_badge(string $type): string {
    $t = LETTER_TYPES[$type] ?? ['label' => $type, 'class' => 'secondary', 'icon' => 'bi-file-text'];
    return '<span class="badge bg-' . $t['class'] . '"><i class="bi ' . $t['icon'] . ' me-1"></i>'
         . htmlspecialchars($t['label']) . '</span>';
}

function letter_direction_badge(string $dir): string {
    $d = LETTER_DIRECTIONS[$dir] ?? ['label' => $dir, 'class' => 'secondary', 'icon' => 'bi-arrow-right'];
    return '<span class="badge bg-' . $d['class'] . ' bg-opacity-75 text-dark border border-' . $d['class'] . '">'
         . '<i class="bi ' . $d['icon'] . ' me-1"></i>' . htmlspecialchars($d['label']) . '</span>';
}

function get_contract_letters(string $type, int $id): array {
    return db_all(
        "SELECT l.*, u.name AS created_by_name
         FROM contract_letters l
         LEFT JOIN users u ON u.id = l.created_by
         WHERE l.contract_type = ? AND l.contract_id = ?
         ORDER BY l.data_pisma DESC, l.id DESC",
        [$type, $id]
    );
}

function get_all_letters(string $kierunek = '', string $typ = '', string $contract_type = ''): array {
    $where = []; $params = [];
    if ($kierunek)      { $where[] = 'l.kierunek = ?';       $params[] = $kierunek; }
    if ($typ)           { $where[] = 'l.typ_pisma = ?';      $params[] = $typ; }
    if ($contract_type) { $where[] = 'l.contract_type = ?';  $params[] = $contract_type; }
    $sql = "SELECT l.*, u.name AS created_by_name
            FROM contract_letters l
            LEFT JOIN users u ON u.id = l.created_by"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY l.data_pisma DESC, l.id DESC";
    return db_all($sql, $params);
}

function get_letter(int $id): ?array {
    return db_one(
        "SELECT l.*, u.name AS created_by_name
         FROM contract_letters l
         LEFT JOIN users u ON u.id = l.created_by
         WHERE l.id = ?",
        [$id]
    );
}

function create_letter(array $data): int {
    return db_insert('contract_letters', $data);
}

function handle_letter_upload(string $field): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'docx'], true)) return null;
    if ($f['size'] > 30 * 1024 * 1024) return null;
    $dir = UPLOAD_DIR . 'letters/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return null;
    return 'letters/' . $name;
}

function letter_file_url(?string $path): string {
    if (!$path) return '';
    return APP_URL . '/uploads/' . $path;
}

// ── Generowanie treści ─────────────────────────────────────────────────────────

function generate_letter_content(string $typ_pisma, string $contract_type, array $row, array $meta): string {
    $org        = defined('ORG_NAME') ? ORG_NAME : '';
    $miejsce    = org_setting('org_miejscowosc') ?: $org;
    $ctype      = CONTRACT_TYPES[$contract_type] ?? $contract_type;
    $nr         = $row['numer_umowy'] ?? '';
    $dz         = !empty($row['data_zawarcia'])    ? date_pl($row['data_zawarcia']) : '—';
    $od         = !empty($row['data_rozpoczecia']) ? date_pl($row['data_rozpoczecia']) : '—';
    $do_d       = !empty($row['data_zakonczenia']) ? date_pl($row['data_zakonczenia']) : '—';
    $osoba      = $row['imie_nazwisko'] ?? $row['nazwa_firmy'] ?? '';
    $today      = date_pl(date('Y-m-d'));
    $data_pisma    = !empty($meta['data_pisma']) ? date_pl($meta['data_pisma']) : $today;
    $naglowek_data = $miejsce . ', dnia ' . $data_pisma;
    // Nadpisz skróty używane w szablonach, żeby wszędzie był format "Miejscowość, dnia …"
    $today      = $naglowek_data;
    $data_pisma = $naglowek_data;

    $adres_blok = $osoba ? "{$osoba}\n" : '';
    if (!empty($row['adres'])) $adres_blok .= $row['adres'] . "\n";

    $umowa_ref = "{$ctype} nr {$nr} z dnia {$dz}";

    $templates = [
        'wezwanie' => "
{$org}
{$today}

{$adres_blok}
WEZWANIE DO REALIZACJI ZOBOWIĄZAŃ UMOWNYCH

Dotyczy: {$umowa_ref}

Szanowna Pani / Szanowny Panie,

W związku z zawartą między nami {$umowa_ref}, obowiązującą w okresie od {$od} do {$do_d}, wzywamy Panią/Pana do niezwłocznego wypełnienia zobowiązań wynikających z niniejszej umowy.

Prosimy o kontakt w terminie 7 dni od daty otrzymania niniejszego wezwania.

Z poważaniem,

............................................
{$org}
{$data_pisma}
",
        'powiadomienie' => "
{$org}
{$data_pisma}

{$adres_blok}
POWIADOMIENIE

Dotyczy: {$umowa_ref}

Szanowna Pani / Szanowny Panie,

Informujemy, że w związku z {$umowa_ref} zachodzi konieczność przekazania Pani/Panu następujących informacji:

[treść powiadomienia]

W razie pytań prosimy o kontakt.

Z poważaniem,

............................................
{$org}
",
        'wypowiedzenie' => "
{$org}
{$data_pisma}

{$adres_blok}
WYPOWIEDZENIE UMOWY

Dotyczy: {$umowa_ref}

Szanowna Pani / Szanowny Panie,

Niniejszym składamy oświadczenie o wypowiedzeniu {$umowa_ref} z dnia {$dz}, ze skutkiem na dzień ............................................

Wypowiedzenie następuje z powodu: [podaj powód]

Prosimy o potwierdzenie odbioru niniejszego pisma.

Z poważaniem,

............................................
{$org}
",
        'potwierdzenie' => "
{$org}
{$data_pisma}

{$adres_blok}
POTWIERDZENIE

Dotyczy: {$umowa_ref}

Szanowna Pani / Szanowny Panie,

Niniejszym potwierdzamy [przedmiot potwierdzenia] w związku z {$umowa_ref}.

Z poważaniem,

............................................
{$org}
",
        'odpowiedź' => "
{$org}
{$data_pisma}

{$adres_blok}
ODPOWIEDŹ

W nawiązaniu do Pani/Pana pisma z dnia ............................................,
dotyczącego: {$umowa_ref},

informujemy, że:

[treść odpowiedzi]

Z poważaniem,

............................................
{$org}
",
        'informacja' => "
{$org}
{$data_pisma}

{$adres_blok}
INFORMACJA

Dotyczy: {$umowa_ref}

Szanowna Pani / Szanowny Panie,

Przekazujemy Pani/Panu następującą informację dotyczącą {$umowa_ref}:

[treść informacji]

Z poważaniem,

............................................
{$org}
",
        'przypomnienie' => "
{$org}
{$data_pisma}

{$adres_blok}
PRZYPOMNIENIE

Dotyczy: {$umowa_ref}

Szanowna Pani / Szanowny Panie,

Uprzejmie przypominamy o zobowiązaniach wynikających z {$umowa_ref}, obowiązującej w okresie od {$od} do {$do_d}.

[szczegóły przypomnienia]

W przypadku pytań prosimy o kontakt.

Z poważaniem,

............................................
{$org}
",
        'inne' => "
{$org}
{$data_pisma}

{$adres_blok}
PISMO

Dotyczy: {$umowa_ref}

Szanowna Pani / Szanowny Panie,

[treść pisma]

Z poważaniem,

............................................
{$org}
",
    ];

    return ltrim($templates[$typ_pisma] ?? $templates['inne']);
}

// ── Postivo.pl ────────────────────────────────────────────────────────────────

function postivo_status_badge(string $status): string {
    $map = [
        'draft'      => ['secondary', 'Szkic'],
        'processing' => ['warning',   'W realizacji'],
        'sent'       => ['primary',   'Wysłany'],
        'delivered'  => ['success',   'Dostarczony'],
        'failed'     => ['danger',    'Błąd'],
    ];
    $s = $map[$status] ?? ['secondary', h($status)];
    return '<span class="badge bg-' . $s[0] . '">' . $s[1] . '</span>';
}

// ── E-mail ─────────────────────────────────────────────────────────────────────

function letter_send_email(array $letter, array $contract_row): bool {
    if (empty($letter['odbiorca_email'])) return false;
    require_once __DIR__ . '/approval.php';

    $org       = defined('ORG_NAME') ? ORG_NAME : '';
    $typ       = LETTER_TYPES[$letter['typ_pisma']]['label'] ?? $letter['typ_pisma'];
    $view_url  = APP_URL . '/contracts/letters/view.php?id=' . $letter['id'];
    $has_file  = !empty($letter['plik']);
    $has_text  = !empty($letter['tresc']);

    $file_block = '';
    if ($has_file) {
        $file_url   = letter_file_url($letter['plik']);
        $file_block = "<p><a href='" . htmlspecialchars($file_url) . "'
          style='background:#0d6efd;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>
          ⬇ Pobierz plik pisma
        </a></p>";
    }
    $text_block = $has_text
        ? "<p><a href='" . htmlspecialchars($view_url) . "' style='background:#198754;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>📄 Otwórz pismo</a></p>
           <pre style='background:#f8f9fa;border:1px solid #dee2e6;padding:1rem;border-radius:4px;font-size:.85rem;white-space:pre-wrap'>" . htmlspecialchars($letter['tresc']) . "</pre>"
        : '';

    $body = "
<p>Dzień dobry,</p>
<p>Przesyłamy pismo od <strong>" . htmlspecialchars($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Typ:</td><td><strong>" . htmlspecialchars($typ) . "</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Temat:</td><td>" . htmlspecialchars($letter['tytul']) . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Data pisma:</td><td>" . htmlspecialchars(date_pl($letter['data_pisma'])) . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Umowa:</td><td>" . htmlspecialchars($contract_row['numer_umowy'] ?? '') . "</td></tr>
</table>
{$file_block}
{$text_block}
<p style='color:#888;font-size:.85em'>Wysłano automatycznie przez system Rejestru Umów {$org}.</p>
";
    return approval_send_email(
        $letter['odbiorca_email'],
        htmlspecialchars($typ) . ': ' . htmlspecialchars($letter['tytul']) . ' — ' . $org,
        $body
    );
}
