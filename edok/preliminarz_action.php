<?php
/**
 * edok/preliminarz_action.php — zmiana statusu płatności (jeden wiersz),
 * dla dokumentów z EODoK ('source'=edok) lub archiwalnego KDOK ('source'=kdok).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();
csrf_check();

if (!is_admin() && !edok_has_role('zatwierdza') && !(function_exists('kdok_has_role') && kdok_has_role('zatwierdza'))) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/edok/preliminarz.php');
    exit;
}

$source = $_POST['source'] ?? '';
$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if (!isset(EDOK_STATUS_PLATNOSCI[$status]) || !$id || !in_array($source, ['edok', 'kdok'], true)) {
    flash_set('danger', 'Nieprawidłowe dane.');
    header('Location: ' . APP_URL . '/edok/preliminarz.php');
    exit;
}

if ($source === 'edok') {
    $doc = db_one("SELECT * FROM edok_documents WHERE id=? AND status='zaakceptowany'", [$id]);
    if (!$doc) {
        flash_set('danger', "Dokument #$id nie istnieje lub nie jest zaakceptowany.");
    } else {
        $prev = $doc['status_platnosci'] ?: 'nowy';
        if ($prev === 'oplacony' && !is_admin()) {
            flash_set('danger', "Nie można cofnąć statusu „Opłacony” bez uprawnień admina.");
        } else {
            db_exec("UPDATE edok_documents SET status_platnosci=?, updated_at=datetime('now') WHERE id=?", [$status, $id]);
            edok_log($id, 'status_platnosci', '', $prev, $status, 'Status płatności: ' . (EDOK_STATUS_PLATNOSCI[$prev]['label'] ?? $prev) . ' → ' . EDOK_STATUS_PLATNOSCI[$status]['label']);
            flash_set('success', 'Status płatności zaktualizowany.');
        }
    }
} else {
    try {
        require_once __DIR__ . '/../includes/ksiegowosc.php';
        kdok_migrate();
        $doc = kdok_one("SELECT * FROM kdok_documents WHERE id=? AND status='zaakceptowany'", [$id]);
        if (!$doc) {
            flash_set('danger', "Dokument #$id nie istnieje lub nie jest zaakceptowany.");
        } else {
            $prev = $doc['status_platnosci'] ?: 'nowy';
            if ($prev === 'oplacony' && !is_admin()) {
                flash_set('danger', "Nie można cofnąć statusu „Opłacony” bez uprawnień admina.");
            } else {
                kdok_exec("UPDATE kdok_documents SET status_platnosci=?, updated_at=datetime('now') WHERE id=?", [$status, $id]);
                kdok_log($id, 'Status płatności: ' . ($prev) . ' → ' . $status);
                flash_set('success', 'Status płatności zaktualizowany (KDOK).');
            }
        }
    } catch (\Throwable $e) {
        flash_set('danger', 'Błąd: ' . $e->getMessage());
    }
}

header('Location: ' . APP_URL . '/edok/preliminarz.php');
exit;
