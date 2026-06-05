<?php
/**
 * includes/docusign.php — Integracja z DocuSign eSign API (JWT Grant).
 *
 * Wymaga: composer require docusign/esign-client:^6.0
 *
 * Konfiguracja w tabeli settings:
 *   docusign_integration_key  — Integration Key (Client ID) z DocuSign Dev Console
 *   docusign_account_id       — Account ID (GUID) z DocuSign
 *   docusign_user_id          — User ID (GUID) konta, w imieniu którego działa JWT
 *   docusign_rsa_private_key  — Klucz prywatny RSA (PEM) do JWT Grant
 *   docusign_demo             — '1' = środowisko demo, '0' = produkcja
 *   docusign_enabled          — '1' = integracja aktywna
 */

use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Client\Configuration;
use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\RecipientViewRequest;

function docusign_setting(string $key): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $row          = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key]  = $row['value'] ?? '';
    }
    return $cache[$key];
}

function docusign_save_setting(string $key, string $value): void {
    $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$key]);
    if ($exists) {
        db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $key]);
    } else {
        db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$key, $value]);
    }
}

function docusign_is_enabled(): bool {
    return docusign_setting('docusign_enabled') === '1';
}

/**
 * Dodaje kolumny DocuSign do wszystkich tabel umów (migracja idempotentna).
 */
function docusign_migrate(): void {
    $tables = [
        'umowy_zlecenie', 'umowy_uslugi', 'umowy_wolontariat',
        'umowy_dzielo', 'umowy_praca', 'umowy_inne',
    ];
    $cols = [
        'docusign_status'        => 'VARCHAR(50)',
        'docusign_signer_email'  => 'VARCHAR(255)',
        'docusign_signer_name'   => 'VARCHAR(255)',
    ];
    foreach ($tables as $table) {
        foreach ($cols as $col => $type) {
            try {
                db()->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
            } catch (\PDOException) {
                // Kolumna już istnieje — ignorujemy
            }
        }
    }
}

// ── Etykiety statusów ────────────────────────────────────────────────────────

const DOCUSIGN_STATUS_LABELS = [
    'created'   => 'Utworzono',
    'sent'      => 'Wysłano',
    'delivered' => 'Dostarczono',
    'completed' => 'Podpisano',
    'declined'  => 'Odrzucono',
    'voided'    => 'Unieważniono',
];

const DOCUSIGN_STATUS_BADGES = [
    'created'   => 'bg-secondary',
    'sent'      => 'bg-warning text-dark',
    'delivered' => 'bg-info text-dark',
    'completed' => 'bg-success',
    'declined'  => 'bg-danger',
    'voided'    => 'bg-secondary',
];

// ── Klasa główna ─────────────────────────────────────────────────────────────

class DocuSignClient {
    private ?ApiClient $apiClient = null;
    private string $account_id;
    private string $integration_key;
    private string $user_id;
    private string $rsa_private_key;
    private string $oauth_host;
    private string $api_base;

    public function __construct() {
        $is_demo             = docusign_setting('docusign_demo') !== '0';
        $this->account_id    = docusign_setting('docusign_account_id');
        $this->integration_key = docusign_setting('docusign_integration_key');
        $this->user_id       = docusign_setting('docusign_user_id');
        $this->rsa_private_key = docusign_setting('docusign_rsa_private_key');
        $this->oauth_host    = $is_demo ? 'account-d.docusign.com' : 'account.docusign.com';
        $this->api_base      = $is_demo
            ? 'https://demo.docusign.net/restapi'
            : 'https://na3.docusign.net/restapi';
    }

    public function is_configured(): bool {
        return !empty($this->account_id)
            && !empty($this->integration_key)
            && !empty($this->user_id)
            && !empty($this->rsa_private_key);
    }

    /**
     * URL do wyrażenia zgody użytkownika (jednorazowy krok wymagany przy pierwszym uruchomieniu JWT).
     */
    public function consent_url(): string {
        return 'https://' . $this->oauth_host
            . '/oauth/auth?response_type=code&scope=signature%20impersonation&client_id='
            . urlencode($this->integration_key)
            . '&redirect_uri=' . urlencode(APP_URL . '/api/docusign_webhook.php?consent=1');
    }

    private function client(): ApiClient {
        if ($this->apiClient) return $this->apiClient;

        $config = new Configuration();
        $config->setHost($this->api_base);

        $this->apiClient = new ApiClient($config);
        $this->apiClient->setOAuthBasePath($this->oauth_host);

        [$token] = $this->apiClient->requestJWTUserToken(
            $this->integration_key,
            $this->user_id,
            $this->rsa_private_key,
            ['signature'],
            3600
        );

        $this->apiClient->getConfig()->addDefaultHeader(
            'Authorization', 'Bearer ' . $token->getAccessToken()
        );

        return $this->apiClient;
    }

    /**
     * Wyślij kopertę do podpisu.
     *
     * @param  string $pdf_path       Bezwzględna ścieżka do pliku PDF.
     * @param  string $signer_name    Imię i nazwisko podpisującego.
     * @param  string $signer_email   E-mail podpisującego.
     * @param  string $doc_name       Nazwa dokumentu wyświetlana w DocuSign.
     * @return string envelope_id
     */
    public function send_envelope(
        string $pdf_path,
        string $signer_name,
        string $signer_email,
        string $doc_name = 'Umowa'
    ): string {
        $pdf_bytes = file_get_contents($pdf_path);
        if ($pdf_bytes === false) {
            throw new RuntimeException('Nie można odczytać pliku PDF: ' . $pdf_path);
        }

        $document = new Document([
            'document_base64' => base64_encode($pdf_bytes),
            'name'            => $doc_name,
            'file_extension'  => 'pdf',
            'document_id'     => '1',
        ]);

        $sign_here = new SignHere([
            'document_id'  => '1',
            'page_number'  => '1',
            'recipient_id' => '1',
            'tab_label'    => 'Podpis',
            'x_position'   => '300',
            'y_position'   => '650',
        ]);

        $signer = new Signer([
            'email'         => $signer_email,
            'name'          => $signer_name,
            'recipient_id'  => '1',
            'routing_order' => '1',
            'tabs'          => new Tabs(['sign_here_tabs' => [$sign_here]]),
        ]);

        $envelope = new EnvelopeDefinition([
            'email_subject' => 'Proszę o podpisanie dokumentu: ' . $doc_name,
            'documents'     => [$document],
            'recipients'    => new Recipients(['signers' => [$signer]]),
            'status'        => 'sent',
        ]);

        $api    = new EnvelopesApi($this->client());
        $result = $api->createEnvelope($this->account_id, $envelope);

        return $result->getEnvelopeId();
    }

    /**
     * Pobierz aktualny status koperty z DocuSign.
     */
    public function get_status(string $envelope_id): string {
        $api = new EnvelopesApi($this->client());
        $env = $api->getEnvelope($this->account_id, $envelope_id);
        return $env->getStatus();
    }

    /**
     * Pobierz podpisany dokument (scalony PDF) i zapisz do pliku.
     */
    public function download_signed_document(string $envelope_id, string $save_path): void {
        $api  = new EnvelopesApi($this->client());
        $file = $api->getDocument($this->account_id, $envelope_id, 'combined');

        if ($file instanceof \SplFileObject) {
            copy($file->getPathname(), $save_path);
        } else {
            file_put_contents($save_path, $file);
        }
    }

    /**
     * Unieważnij kopertę.
     */
    public function void_envelope(string $envelope_id, string $reason = 'Anulowane przez administratora'): void {
        $api      = new EnvelopesApi($this->client());
        $envelope = new EnvelopeDefinition(['status' => 'voided', 'voided_reason' => $reason]);
        $api->update($this->account_id, $envelope_id, $envelope);
    }

    /**
     * Zwróć URL do podpisania embedded (podpisanie w oknie przeglądarki w ramach naszej aplikacji).
     */
    public function get_signing_url(
        string $envelope_id,
        string $signer_name,
        string $signer_email,
        string $return_url
    ): string {
        $api          = new EnvelopesApi($this->client());
        $view_request = new RecipientViewRequest([
            'authentication_method' => 'none',
            'client_user_id'        => '1000',
            'recipient_id'          => '1',
            'return_url'            => $return_url,
            'user_name'             => $signer_name,
            'email'                 => $signer_email,
        ]);
        $result = $api->createRecipientView($this->account_id, $envelope_id, $view_request);
        return $result->getUrl();
    }
}
