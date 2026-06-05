<?php
/**
 * includes/autenti.php — Integracja z Autenti eSign API v2.
 *
 * Konfiguracja w tabeli settings:
 *   autenti_enabled        — '1' = integracja aktywna
 *   autenti_client_id      — Client ID z panelu Autenti
 *   autenti_client_secret  — Client Secret z panelu Autenti
 *   autenti_sandbox        — '1' = środowisko sandbox, '0' = produkcja
 *   autenti_webhook_secret — Klucz weryfikacji webhooków (dowolny ciąg)
 */

function autenti_setting(string $key): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $row         = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $row['value'] ?? '';
    }
    return $cache[$key];
}

function autenti_save_setting(string $key, string $value): void {
    $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$key]);
    if ($exists) {
        db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $key]);
    } else {
        db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$key, $value]);
    }
}

function autenti_is_enabled(): bool {
    return autenti_setting('autenti_enabled') === '1';
}

function autenti_migrate(): void {
    $tables = [
        'umowy_zlecenie', 'umowy_uslugi', 'umowy_wolontariat',
        'umowy_dzielo', 'umowy_praca', 'umowy_inne',
    ];
    $cols = [
        'autenti_document_id'  => 'VARCHAR(255)',
        'autenti_status'       => 'VARCHAR(50)',
        'autenti_signer_email' => 'VARCHAR(255)',
        'autenti_signer_name'  => 'VARCHAR(255)',
    ];
    foreach ($tables as $table) {
        foreach ($cols as $col => $type) {
            try {
                db()->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
            } catch (\PDOException) {}
        }
    }
}

// ── Etykiety i kolory statusów ────────────────────────────────────────────────

const AUTENTI_STATUS_LABELS = [
    'IN_PROGRESS' => 'Oczekuje na podpis',
    'COMPLETED'   => 'Podpisano',
    'DECLINED'    => 'Odrzucono',
    'CANCELLED'   => 'Anulowano',
    'EXPIRED'     => 'Wygasło',
];

const AUTENTI_STATUS_BADGES = [
    'IN_PROGRESS' => 'bg-warning text-dark',
    'COMPLETED'   => 'bg-success',
    'DECLINED'    => 'bg-danger',
    'CANCELLED'   => 'bg-secondary',
    'EXPIRED'     => 'bg-secondary',
];

// ── Klasa klienta API ─────────────────────────────────────────────────────────

class AutentiClient {
    private string  $base_url;
    private string  $client_id;
    private string  $client_secret;
    private ?string $access_token = null;

    public function __construct() {
        $sandbox             = autenti_setting('autenti_sandbox') !== '0';
        $this->base_url      = $sandbox
            ? 'https://api.sandbox.autenti.com'
            : 'https://api.autenti.com';
        $this->client_id     = autenti_setting('autenti_client_id');
        $this->client_secret = autenti_setting('autenti_client_secret');
    }

    public function is_configured(): bool {
        return !empty($this->client_id) && !empty($this->client_secret);
    }

    private function token(): string {
        if ($this->access_token) return $this->access_token;

        $ch = curl_init($this->base_url . '/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("Autenti token error ({$code}): {$body}");
        }
        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Autenti: brak access_token w odpowiedzi.');
        }
        return $this->access_token = $data['access_token'];
    }

    /**
     * Wyślij dokument do podpisu.
     *
     * @return string  ID dokumentu w Autenti
     */
    public function send_document(
        string $pdf_path,
        string $signer_name,
        string $signer_email,
        string $doc_name = 'Umowa'
    ): string {
        $name_parts = explode(' ', trim($signer_name), 2);
        $first_name = $name_parts[0];
        $last_name  = $name_parts[1] ?? '';

        $metadata = json_encode([
            'title'   => $doc_name,
            'message' => 'Proszę o podpisanie dokumentu: ' . $doc_name,
            'signers' => [[
                'email'     => $signer_email,
                'firstName' => $first_name,
                'lastName'  => $last_name,
                'role'      => 'SIGNER',
            ]],
        ]);

        $pdf_content = file_get_contents($pdf_path);
        if ($pdf_content === false) {
            throw new RuntimeException('Nie można odczytać pliku PDF: ' . $pdf_path);
        }

        $boundary = '----AutentiBoundary' . bin2hex(random_bytes(8));
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"data\"\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . basename($pdf_path) . '"' . "\r\n";
        $body .= "Content-Type: application/pdf\r\n\r\n";
        $body .= $pdf_content . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $ch = curl_init($this->base_url . '/v2/documents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token(),
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code > 299) {
            throw new RuntimeException("Autenti API błąd ({$code}): {$resp}");
        }

        $data   = json_decode($resp, true);
        $doc_id = $data['id'] ?? ($data['documentId'] ?? '');
        if (!$doc_id) {
            throw new RuntimeException("Autenti: brak ID dokumentu w odpowiedzi: {$resp}");
        }
        return $doc_id;
    }

    /**
     * Pobierz aktualny status dokumentu.
     */
    public function get_status(string $document_id): string {
        $ch = curl_init($this->base_url . '/v2/documents/' . urlencode($document_id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token()],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("Autenti status error ({$code}): {$body}");
        }
        $data = json_decode($body, true);
        return $data['status'] ?? 'UNKNOWN';
    }

    /**
     * Anuluj dokument.
     */
    public function cancel_document(string $document_id): void {
        $ch = curl_init($this->base_url . '/v2/documents/' . urlencode($document_id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token()],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code > 299) {
            throw new RuntimeException("Autenti cancel error ({$code}): {$body}");
        }
    }

    /**
     * Pobierz podpisany dokument i zapisz do pliku.
     */
    public function download_signed_document(string $document_id, string $save_path): void {
        // Pobierz info o dokumencie żeby znaleźć ID pliku podpisanego
        $ch = curl_init($this->base_url . '/v2/documents/' . urlencode($document_id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token()],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("Autenti download info error ({$code}): {$body}");
        }

        $data    = json_decode($body, true);
        $file_id = null;
        foreach ($data['files'] ?? [] as $file) {
            if (in_array($file['type'] ?? '', ['SIGNED', 'signed'], true) || !empty($file['signed'])) {
                $file_id = $file['id'];
                break;
            }
        }
        // Fallback: pierwszy dostępny plik
        if (!$file_id && !empty($data['files'][0]['id'])) {
            $file_id = $data['files'][0]['id'];
        }
        if (!$file_id) {
            throw new RuntimeException("Autenti: brak pliku podpisanego w dokumencie {$document_id}.");
        }

        $fh = fopen($save_path, 'wb');
        if (!$fh) throw new RuntimeException("Nie można otworzyć pliku do zapisu: {$save_path}");

        $ch = curl_init(
            $this->base_url . '/v2/documents/' . urlencode($document_id)
            . '/files/' . urlencode($file_id) . '/download'
        );
        curl_setopt_array($ch, [
            CURLOPT_FILE       => $fh,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token()],
        ]);
        $ok   = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if (!$ok || $code !== 200) {
            @unlink($save_path);
            throw new RuntimeException("Autenti: błąd pobierania pliku ({$code}).");
        }
    }
}
