<?php
declare(strict_types=1);

require_once __DIR__ . '/ContactExport.php';

/**
 * modules/vcardy/logic/VcardyController.php — /pomocne/vcardy: kontakty z Outlooka (Microsoft 365) do pliku VCF albo CSV.
 *
 *   GET  /pomocne/vcardy          — strona: konto Microsoft, wybór folderu i formatu, instrukcja importu w chmurze, dPoczcie i telefonie;
 *   POST /pomocne/vcardy/pobierz  — plik do pobrania (pola: upn, folder ('*' = wszystkie), format vcf|csv, source graph|copy).
 * Tylko własne konta Microsoft (ContactExport::upns). CSRF dla POST sprawdza Router. Pobranie zapisujemy w audit_logs (contacts.export).
 */
final class VcardyController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string
    {
        $me = current_user();
        $upns = ContactExport::upns($me);
        $accounts = [];
        foreach ($upns as $upn) {
            $a = ['upn' => $upn, 'folders' => null, 'error' => '', 'copyDate' => '', 'copyCount' => 0];
            if (ContactExport::graphReady()) {
                try { $a['folders'] = ContactExport::folders($upn); }
                catch (PimGraphGone $e) { $a['error'] = 'gone'; }
                catch (\Throwable $e) { $a['error'] = 'down'; }
            } else {
                $a['error'] = 'down';
            }
            if ($a['error'] !== '') {
                $copy = ContactExport::copyVcf($upn);
                if ($copy !== null) { $a['copyDate'] = ContactExport::copyDate($upn); $a['copyCount'] = ContactExport::countCards($copy); }
            }
            $accounts[] = $a;
        }
        return Router::view(self::VIEW . 'index.php', ['user' => $me, 'accounts' => $accounts, 'flash' => flash_get()]);
    }

    public function download(): ?string
    {
        @set_time_limit(300);
        $me = current_user();
        $upn = strtolower(trim((string)($_POST['upn'] ?? '')));
        $format = ($_POST['format'] ?? '') === 'csv' ? 'csv' : 'vcf';
        $source = ($_POST['source'] ?? '') === 'copy' ? 'copy' : 'graph';
        if (!in_array($upn, ContactExport::upns($me), true)) return $this->back('danger', 'To nie jest Twoje konto Microsoft 365.');
        session_write_close();

        $count = 0;
        try {
            if ($source === 'copy') {
                $vcf = ContactExport::copyVcf($upn);
                if ($vcf === null) return $this->back('warning', 'Nie ma kopii Twoich kontaktów. Napisz do Helpdesku IT.');
                $count = ContactExport::countCards($vcf);
                $body = $format === 'csv' ? ContactExport::csvFromVcf($vcf) : $vcf;
            } else {
                $folder = (string)($_POST['folder'] ?? '*');
                $rows = ContactExport::fetch($upn, $folder === '*' ? null : $folder);
                $count = count($rows);
                if ($count === 0) return $this->back('info', 'W tym miejscu nie ma żadnych kontaktów. Wybierz „Wszystkie foldery” i spróbuj jeszcze raz.');
                $body = $format === 'csv' ? ContactExport::csv($rows) : ContactExport::vcf($rows);
            }
        } catch (PimGraphGone $e) {
            return $this->back('warning', 'Microsoft 365 nie ma już skrzynki dla konta ' . $upn . '. Napisz do Helpdesku IT, sprawdzimy kopię.');
        } catch (\Throwable $e) {
            return $this->back('danger', 'Nie udało się pobrać kontaktów z Microsoft 365. Spróbuj za kilka minut. Jeśli dalej nie działa, napisz do Helpdesku IT.');
        }

        if (function_exists('audit_log')) {
            try { audit_log('contacts.export', ['upn' => $upn, 'format' => $format, 'source' => $source, 'count' => $count]); } catch (\Throwable $e) {}
        }
        $name = 'kontakty-' . preg_replace('/[^a-z0-9._-]+/', '-', explode('@', $upn)[0]) . '-' . date('Y-m-d') . '.' . $format;
        header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/vcard') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $body;
        return null;
    }

    private function back(string $type, string $msg): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        flash_set($type, $msg);
        return Router::redirect(route('vcardy.index'));
    }
}
