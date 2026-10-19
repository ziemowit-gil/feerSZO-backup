<?php
/**
 * modules/onedrive_picker/logic/OneDrivePickerController.php — lista plików OneDrive dla okna
 * wyboru (view/picker.js). Pobranie wybranych plików robi moduł docelowy (Zadania, CRM, …)
 * przez OneDrivePicker::fetch() — tu tylko przeglądanie.
 */
require_once dirname(__DIR__) . '/lib/OneDrivePicker.php';

final class OneDrivePickerController
{
    /** POST /list {folder?, q?, ext?: "pdf,docx,…"} → {ok, items:[…]} */
    public function list(): array
    {
        $folder = trim((string)($_POST['folder'] ?? ''));
        $query  = trim((string)($_POST['q'] ?? ''));
        $ext    = array_values(array_filter(array_map(
            fn($e) => strtolower(ltrim(trim($e), '.')),
            explode(',', (string)($_POST['ext'] ?? ''))
        ), fn($e) => $e !== '' && preg_match('/^[a-z0-9]{1,10}$/', $e)));

        if ($folder !== '' && !preg_match('/^[A-Za-z0-9!._-]{1,200}$/', $folder)) {
            return ['ok' => false, 'error' => 'Nieprawidłowy folder.'];
        }
        try {
            $picker = new OneDrivePicker();
            return ['ok' => true, 'items' => $picker->listItems($folder, $query, $ext), 'search' => $query !== ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 240)];
        }
    }
}
