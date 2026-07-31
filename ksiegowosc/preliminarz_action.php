<?php
/**
 * AJAX — zmiana status_platnosci dla jednego lub wielu dokumentów KDOK.
 * Używane przez Preliminarz Płatności (przyciski wiersza + masowe akcje).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

header('Content-Type: application/json; charset=utf-8');

function json_err(string $msg): never {
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Tylko POST.');

try { csrf_check(); } catch (\Throwable $e) { json_err('Błąd CSRF.'); }

if (!kdok_has_role('zatwierdza')) json_err('Brak uprawnień.');

$action = trim($_POST['action'] ?? '');
$ids    = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));

$allowed_actions = array_keys(KDOK_STATUS_PLATNOSCI);
if (!in_array($action, $allowed_actions, true)) json_err('Nieprawidłowy status.');
if (!$ids) json_err('Brak dokumentów do zmiany.');

$user     = current_user();
$user_id  = (int)$user['id'];
$user_name = $user['name'] ?? ('user#' . $user_id);
$ip       = $_SERVER['REMOTE_ADDR'] ?? '';

$changed  = 0;
$errors   = [];

foreach ($ids as $doc_id) {
    $doc = kdok_one("SELECT * FROM kdok_documents WHERE id=? AND status='zaakceptowany'", [$doc_id]);
    if (!$doc) {
        $errors[] = "Dokument #$doc_id nie istnieje lub nie jest zaakceptowany.";
        continue;
    }

    $prev = $doc['status_platnosci'] ?? 'nowy';
    if ($prev === $action) continue; // bez zmiany

    // Blokada cofnięcia opłaconego — tylko admin może
    if ($prev === 'oplacony' && !is_admin()) {
        $errors[] = "Dokument #$doc_id: nie można cofnąć statusu 'Opłacony' bez uprawnień admina.";
        continue;
    }

    kdok_exec(
        "UPDATE kdok_documents SET status_platnosci=?, updated_at=datetime('now') WHERE id=?",
        [$action, $doc_id]
    );
    $label_prev = KDOK_STATUS_PLATNOSCI[$prev]['label']   ?? $prev;
    $label_new  = KDOK_STATUS_PLATNOSCI[$action]['label'] ?? $action;
    kdok_log($doc_id, "Status płatności: $label_prev → $label_new");

    // Synchronizuj status EZD koszulki jeśli powiązana
    if (!empty($doc['ezd_sprawa_id']) && module_enabled('ezd_enabled')) {
        try {
            require_once __DIR__ . '/../includes/ezd.php';
            $ezd_etap = match($action) {
                'do_realizacji' => 'w_toku',
                'oplacony'      => 'zakonczony',
                'anulowany'     => 'umorzony',
                default         => null,
            };
            if ($ezd_etap) {
                ezd_sprawa_set_etap((int)$doc['ezd_sprawa_id'], $ezd_etap, $user_id);
            }
        } catch (\Throwable $e) {
            // Nie blokuj — EZD sync jest pomocnicza
        }
    }

    $changed++;
}

echo json_encode([
    'ok'      => true,
    'changed' => $changed,
    'errors'  => $errors,
    'message' => $changed . ' ' . ($changed === 1 ? 'dokument zaktualizowany' : 'dokumenty zaktualizowane')
               . ($errors ? ' (' . count($errors) . ' błędów)' : ''),
]);
