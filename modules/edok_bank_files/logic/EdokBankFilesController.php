<?php
/**
 * modules/edok_bank_files/logic/EdokBankFilesController.php — wgrywanie PDF-ów z banku, podgląd pliku i ręczne przypisanie do transakcji.
 * Dostęp: podgląd — każda rola EODoK; wgrywanie/przypisywanie/usuwanie — admin albo rola „upload”/„ksiegowy” (jak edok/wyciag.php).
 */
require_once __DIR__ . '/EdokBankFiles.php';

final class EdokBankFilesController
{
    private const BACK = '/edok/wyciag.php';

    /** POST /wgraj — pdf[] (wiele plików naraz). */
    public function upload(): ?string
    {
        $uid = (int)(current_user()['id'] ?? 0);
        $names = (array)($_FILES['pdf']['name'] ?? []);
        $new = 0; $dup = 0; $links = 0; $problems = [];
        foreach ($names as $i => $name) {
            $tmp = (string)($_FILES['pdf']['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) continue;
            try {
                $r = EdokBankFiles::store($tmp, (string)$name, $uid);
                if ($r['status'] === 'duplicate') { $dup++; continue; }
                $new++; $links += $r['links'];
                if ($r['links'] === 0 && $r['note'] !== '') $problems[] = $name . ': ' . $r['note'];
            } catch (\Throwable $e) {
                $problems[] = $e->getMessage();
            }
        }
        if (!$new && !$dup && !$problems) { flash_set('warning', 'Nie wybrano żadnych plików PDF.'); return Router::redirect(APP_URL . self::BACK); }
        $msg = "Wgrano PDF-ów: {$new}, dołączono do transakcji: {$links}" . ($dup ? ", pominięto duplikatów: {$dup}" : '') . '.';
        if ($problems) $msg .= ' Do sprawdzenia: ' . implode(' | ', array_slice($problems, 0, 3));
        flash_set($problems ? 'warning' : 'success', $msg);
        return Router::redirect(APP_URL . self::BACK);
    }

    /** GET /plik/{id} — PDF w przeglądarce. */
    public function file(int $id): ?string
    {
        EdokBankFiles::migrate();
        $f = db_one("SELECT * FROM edok_bank_files WHERE id=?", [$id]);
        $path = $f ? EdokBankFiles::path($f) : '';
        if (!$f || !is_file($path)) { http_response_code(404); return 'Nie ma takiego pliku.'; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode((string)$f['orig_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        return null;
    }

    public function assign(): ?string
    {
        $ok = EdokBankFiles::link((int)($_POST['tx_id'] ?? 0), (int)($_POST['file_id'] ?? 0), 'reczne', (int)(current_user()['id'] ?? 0));
        flash_set($ok ? 'success' : 'warning', $ok ? 'Dołączono PDF do transakcji.' : 'Nie udało się dołączyć (już dołączony albo brak transakcji).');
        return Router::redirect(APP_URL . self::BACK);
    }

    public function detach(): ?string
    {
        EdokBankFiles::unlink((int)($_POST['tx_id'] ?? 0), (int)($_POST['file_id'] ?? 0));
        flash_set('success', 'Odpięto PDF od transakcji.');
        return Router::redirect(APP_URL . self::BACK);
    }

    public function remove(): ?string
    {
        EdokBankFiles::delete((int)($_POST['file_id'] ?? 0));
        flash_set('success', 'Usunięto PDF.');
        return Router::redirect(APP_URL . self::BACK);
    }

    public function rematch(): ?string
    {
        $r = EdokBankFiles::rematchPending();
        flash_set('success', 'Sprawdzono plików: ' . $r['files'] . ', nowych powiązań: ' . $r['links'] . '.');
        return Router::redirect(APP_URL . self::BACK);
    }
}
