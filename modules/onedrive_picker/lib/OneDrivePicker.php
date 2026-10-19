<?php
/**
 * modules/onedrive_picker/lib/OneDrivePicker.php — wybieranie plików z OneDrive bieżącego
 * użytkownika (funkcjonalność współdzielona: Zadania, sprawy CRM, …).
 *
 * Działa po stronie serwera przez Microsoft Graph z uprawnieniem APLIKACJI Files.Read.All
 * (M365Graph::od_*) — bez OneDrive.js/implicit flow, które wymagały rejestracji SPA
 * i aplikacji wielodostępnej. Każda operacja dotyczy wyłącznie dysku zalogowanego
 * użytkownika (konto połączone z Microsoft 365), więc nie da się nią sięgnąć do cudzego
 * OneDrive. Klient widzi tylko ID elementów; pobranie robi serwer (fetch()).
 */
require_once dirname(__DIR__, 3) . '/includes/m365.php';

final class OneDrivePicker
{
    private M365Graph $graph;
    private string $userKey;

    /** Graph skonfigurowany (client id + secret + tenant aplikacji M365). */
    public static function configured(): bool
    {
        try {
            return (new M365Graph())->is_configured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Czy użytkownik (domyślnie bieżący) może wybierać pliki z OneDrive. */
    public static function available(?array $user = null): bool
    {
        $u = $user ?? current_user();
        return self::userKeyOf($u) !== '' && !empty($u['microsoft_id']) && self::configured();
    }

    /** Identyfikator konta M365 do /users/{id}/drive: GUID z logowania, w razie braku e-mail (UPN). */
    private static function userKeyOf(?array $u): string
    {
        return trim((string)($u['microsoft_id'] ?? '')) ?: trim((string)($u['email'] ?? ''));
    }

    /** $graph — wstrzyknięcie klienta Graph (testy hermetyczne); domyślnie M365Graph z ustawień. */
    public function __construct(?array $user = null, ?M365Graph $graph = null)
    {
        $u = $user ?? current_user();
        if (!($graph ? self::userKeyOf($u) !== '' && !empty($u['microsoft_id']) : self::available($u))) {
            throw new \RuntimeException('Twoje konto nie jest połączone z Microsoft 365 — wybieranie plików z OneDrive jest niedostępne.');
        }
        $this->userKey = self::userKeyOf($u);
        $this->graph   = $graph ?? new M365Graph();
    }

    /** ID dysku OneDrive użytkownika — zapamiętane w sesji, żeby każde wejście do folderu nie kosztowało zapytania. */
    public function driveId(): string
    {
        $cached = (string)($_SESSION['od_picker_drive'][$this->userKey] ?? '');
        if ($cached !== '') return $cached;

        $drive = $this->graph->od_drive($this->userKey);
        if (empty($drive['id'])) {
            $st = $this->graph->last_status();
            throw new \RuntimeException(match (true) {
                $st === 403 => 'Aplikacja Microsoft 365 nie ma uprawnienia Files.Read.All — poproś administratora o nadanie go w Azure.',
                $st === 404 => 'Nie znaleziono OneDrive dla Twojego konta (dysk nie został jeszcze utworzony — otwórz raz OneDrive w przeglądarce).',
                default     => 'Nie udało się otworzyć OneDrive (' . ($st ?: 'brak odpowiedzi') . ').',
            });
        }
        $_SESSION['od_picker_drive'][$this->userKey] = (string)$drive['id'];
        return (string)$drive['id'];
    }

    /**
     * Zawartość folderu ('' = katalog główny) albo wyniki wyszukiwania ($query).
     * $allowedExt — rozszerzenia (bez kropki) pokazywanych plików; [] = wszystkie. Foldery zawsze.
     *
     * @return list<array{id:string,name:string,size:int,folder:bool,ext:string,modified:string,count:int}>
     */
    public function listItems(string $folderId = '', string $query = '', array $allowedExt = []): array
    {
        $driveId = $this->driveId();
        $raw = $query !== ''
            ? $this->graph->od_search($driveId, mb_substr($query, 0, 100))
            : $this->graph->od_list($driveId, $folderId);
        if (!$raw && $this->graph->last_status() >= 400) {
            throw new \RuntimeException('Nie udało się odczytać OneDrive (' . $this->graph->last_status() . ').');
        }

        $allowedExt = array_map('strtolower', $allowedExt);
        $items = [];
        foreach ($raw as $it) {
            $isFolder = isset($it['folder']);
            $name     = (string)($it['name'] ?? '');
            $ext      = $isFolder ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!$isFolder && !isset($it['file'])) continue;                       // notesy OneNote, pakiety itp.
            if (!$isFolder && $allowedExt && !in_array($ext, $allowedExt, true)) continue;
            $items[] = [
                'id'       => (string)($it['id'] ?? ''),
                'name'     => $name,
                'size'     => (int)($it['size'] ?? 0),
                'folder'   => $isFolder,
                'ext'      => $ext,
                'modified' => substr((string)($it['lastModifiedDateTime'] ?? ''), 0, 10),
                'count'    => (int)($it['folder']['childCount'] ?? 0),
            ];
        }
        usort($items, fn($a, $b) => [$b['folder'], mb_strtolower($a['name'])] <=> [$a['folder'], mb_strtolower($b['name'])]);
        return $items;
    }

    /**
     * Pobiera plik z OneDrive użytkownika.
     *
     * @return array{name:string,bytes:string,size:int}
     */
    public function fetch(string $itemId, int $maxBytes): array
    {
        if ($itemId === '' || !preg_match('/^[A-Za-z0-9!._-]{1,200}$/', $itemId)) {
            throw new \RuntimeException('Nieprawidłowy identyfikator pliku OneDrive.');
        }
        $driveId = $this->driveId();
        $meta = $this->graph->od_item($driveId, $itemId);
        if (empty($meta['id']) || isset($meta['folder'])) {
            throw new \RuntimeException('Nie znaleziono pliku w OneDrive.');
        }
        $name = (string)$meta['name'];
        if ((int)($meta['size'] ?? 0) > $maxBytes) {
            throw new \RuntimeException('Plik „' . $name . '” jest za duży (max ' . round($maxBytes / 1048576) . ' MB).');
        }
        $bytes = $this->graph->od_download($driveId, $itemId);
        if (strlen($bytes) > $maxBytes) {
            throw new \RuntimeException('Plik „' . $name . '” jest za duży (max ' . round($maxBytes / 1048576) . ' MB).');
        }
        return ['name' => $name, 'bytes' => $bytes, 'size' => strlen($bytes)];
    }
}
