<?php
/**
 * AJAX endpoint dla przegladaj.php — zwraca dane dokumentu w formacie JSON.
 * Wymaga zalogowania i weryfikacji PIN-u archiwum (tak samo jak przegladaj.php).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

header('Content-Type: application/json; charset=utf-8');

// Tylko AJAX
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Wymaga zalogowania
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Brak autoryzacji — zaloguj się.']);
    exit;
}

// Weryfikacja PIN-u archiwum
$pin_hash = org_setting('kdok_przeglad_pin_hash');
$pin_set  = $pin_hash !== '';

if ($pin_set) {
    auth_start();
    if (empty($_SESSION['kdok_przeglad_ok'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Wymagana autoryzacja PIN archiwum.']);
        exit;
    }
} elseif (!is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Archiwum dostępne tylko dla administratorów.']);
    exit;
}

kdok_migrate();

$id  = (int)($_GET['id'] ?? 0);
$doc = kdok_get($id);
if (!$doc) {
    http_response_code(404);
    echo json_encode(['error' => 'Dokument nie istnieje lub nie masz do niego dostępu.']);
    exit;
}

$history = kdok_get_history($id);

// Buduj odpowiedź
$doc_out = [
    'id'           => $doc['id'],
    'number'       => $doc['number'],
    'title'        => $doc['title'],
    'type'         => $doc['type'],
    'type_label'   => KDOK_TYPES[$doc['type']]['label'] ?? $doc['type'],
    'status'       => $doc['status'],
    'creator_name' => $doc['creator_name'],
    'created_at_pl'=> date_pl($doc['created_at']),
    'file_sha256'  => $doc['file_sha256'] ?? '',
    'file_size'    => $doc['file_size']   ?? 0,
    'file_size_kb' => $doc['file_size'] ? number_format($doc['file_size'] / 1024, 1) : '0',
    'has_orig'     => !empty($doc['file_path']),
    'kwota'        => $doc['kwota']      ?? '',
    'mpk'          => $doc['mpk']        ?? '',
    'grant_name'   => $doc['grant_name'] ?? '',
    'description'  => $doc['description']?? '',
    'uwagi'        => $doc['uwagi']      ?? '',
];

// Kroki — pełne dane
$steps_raw = kdok_all(
    "SELECT * FROM kdok_steps WHERE doc_id = ? ORDER BY CASE step_type WHEN 'meryt' THEN 1 WHEN 'formal' THEN 2 WHEN 'zatwierdza' THEN 3 END",
    [$id]
);
$steps_out = [];
foreach ($steps_raw as $s) {
    $steps_out[$s['step_type']] = [
        'status'          => $s['status'],
        'user_name'       => $s['user_name']       ?? '',
        'cert_cn'         => $s['cert_cn']         ?? '',
        'cert_fingerprint'=> $s['cert_fingerprint']?? '',
        'cert_subject'    => $s['cert_subject']    ?? '',
        'decided_at'      => $s['decided_at']      ?? '',
        'decided_at_pl'   => $s['decided_at'] ? date('d.m.Y H:i', strtotime($s['decided_at'])) : '',
        'notes'           => $s['notes']           ?? '',
    ];
}

// Wygenerowany PDF
$gen     = $doc['generated'] ?? null;
$gen_out = null;
if ($gen) {
    $gen_full = kdok_one("SELECT * FROM kdok_generated_pdf WHERE id = ?", [$gen['id']]);
    if ($gen_full) {
        $gen_out = [
            'gen_name'       => $gen_full['gen_name']       ?? '',
            'generated_at_pl'=> date('d.m.Y H:i', strtotime($gen_full['generated_at'])),
            'file_sha256'    => $gen_full['file_sha256']    ?? '',
            'file_size_kb'   => number_format(($gen_full['file_size'] ?? 0) / 1024, 1),
        ];
    }
}

// Historia
$history_out = [];
foreach ($history as $h) {
    $history_out[] = [
        'user_name'  => $h['user_name'],
        'action'     => $h['action'],
        'note'       => $h['note'],
        'created_at' => date('d.m.Y H:i', strtotime($h['created_at'])),
    ];
}

echo json_encode([
    'doc'      => $doc_out,
    'steps'    => $steps_out,
    'generated'=> $gen_out,
    'history'  => $history_out,
], JSON_UNESCAPED_UNICODE);
