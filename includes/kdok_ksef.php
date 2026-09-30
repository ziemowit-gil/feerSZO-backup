<?php
/**
 * includes/kdok_ksef.php — Integracja z KSeF (Krajowy System e-Faktur)
 *
 * Używa oficjalnego SDK: n1ebieski/ksef-php-client
 * Dokumentacja SDK: https://github.com/N1ebieski/ksef-php-client
 *
 * Ustawienia (via org_setting()):
 *   kdok_ksef_enabled       — '0'/'1'
 *   kdok_ksef_env           — 'production'|'demo'|'test'
 *   kdok_ksef_nip           — NIP organizacji (10 cyfr)
 *   kdok_ksef_token         — token autoryzujący KSeF (plain text)
 *   kdok_ksef_cert_pem_*    — certyfikat X.509 PEM (per środowisko)
 *   kdok_ksef_key_pem_*     — klucz prywatny PEM (per środowisko)
 *   kdok_ksef_key_pass_*    — hasło do klucza prywatnego (per środowisko)
 *   kdok_ksef_last_sync     — datetime ostatniej synchronizacji
 */

use N1ebieski\KSEFClient\ClientBuilder;
use N1ebieski\KSEFClient\Factories\EncryptionKeyFactory;
use N1ebieski\KSEFClient\ValueObjects\EncryptionKey;
use N1ebieski\KSEFClient\ValueObjects\Mode;
use N1ebieski\KSEFClient\ValueObjects\Requests\KsefNumber;
use N1ebieski\KSEFClient\ValueObjects\Requests\ReferenceNumber;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\SubjectType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\DateType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\DateRangeFrom;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\DateRangeTo;
use N1ebieski\KSEFClient\DTOs\Requests\Invoices\DateRange;
use N1ebieski\KSEFClient\DTOs\Requests\Invoices\Exports\Filters as ExportFilters;
use N1ebieski\KSEFClient\Requests\Invoices\Query\Metadata\MetadataRequest;
use N1ebieski\KSEFClient\Requests\Invoices\Download\DownloadRequest;
use N1ebieski\KSEFClient\Requests\Invoices\Exports\Init\InitRequest as ExportInitRequest;
use N1ebieski\KSEFClient\Requests\Invoices\Exports\Status\StatusRequest as ExportStatusRequest;
use N1ebieski\KSEFClient\Actions\DecryptDocument\DecryptDocumentAction;
use N1ebieski\KSEFClient\Actions\DecryptDocument\DecryptDocumentHandler;

// ── Środowiska ────────────────────────────────────────────────────────────────

const KSEF_ENVS = [
    'production' => ['label' => 'Produkcyjne',        'mode' => 'production'],
    'demo'       => ['label' => 'Demo (pre-produkcja)','mode' => 'demo'],
    'test'       => ['label' => 'Testowe',             'mode' => 'test'],
];

// Zachowanie kompatybilności: stara wartość 'prod' → 'production'
function kdok_ksef_env_id(): string {
    $e = org_setting('kdok_ksef_env') ?: 'production';
    return $e === 'prod' ? 'production' : $e;
}

function kdok_ksef_env(): array {
    return KSEF_ENVS[kdok_ksef_env_id()] ?? KSEF_ENVS['production'];
}

// ── Helper: zapis ustawienia ──────────────────────────────────────────────────

function kdok_ksef_setting_save(string $key, string $value): void {
    if (DB_TYPE === 'sqlite') {
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?,?)")
            ->execute([$key, $value]);
    } else {
        db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")
            ->execute([$key, $value, $value]);
    }
}

// ── Budowanie klienta SDK ─────────────────────────────────────────────────────

/**
 * Zwraca gotowy do użycia klient KSeF (już zaautoryzowany).
 * Wybiera automatycznie metodę: certyfikat > token.
 *
 * @throws \Throwable gdy autoryzacja się nie powiedzie
 */
function kdok_ksef_client(): \N1ebieski\KSEFClient\Resources\ClientResource {
    $env_id = kdok_ksef_env_id();
    $mode   = Mode::from($env_id);
    $nip    = org_setting('kdok_ksef_nip');

    if (!$nip) {
        throw new RuntimeException('Brak NIP w konfiguracji KSeF.');
    }

    $builder = (new ClientBuilder())
        ->withMode($mode)
        ->withIdentifier($nip);

    // Certyfikat ma pierwszeństwo przed tokenem
    $cert = org_setting('kdok_ksef_cert_pem_' . $env_id);
    $key  = org_setting('kdok_ksef_key_pem_'  . $env_id);
    $pass = org_setting('kdok_ksef_key_pass_' . $env_id);

    if ($cert && $key && str_contains($cert, 'BEGIN') && str_contains($key, 'BEGIN')) {
        $builder = $builder->withCertificate($cert, $key, $pass ?: null);
    } else {
        $token = org_setting('kdok_ksef_token');
        if (!$token) {
            throw new RuntimeException('Brak tokena KSeF i certyfikatu w konfiguracji.');
        }
        $builder = $builder->withKsefToken($token);
    }

    return $builder->build();
}

/**
 * Klient z losowym kluczem szyfrującym AES-256 (wymagany do eksportu).
 * Klucz jest jednorazowy — generowany przy każdym wywołaniu.
 * Zwraca [client, encryptionKey].
 */
function kdok_ksef_client_with_key(): array {
    $env_id = kdok_ksef_env_id();
    $mode   = Mode::from($env_id);
    $nip    = org_setting('kdok_ksef_nip');
    if (!$nip) throw new RuntimeException('Brak NIP w konfiguracji KSeF.');

    $encKey  = EncryptionKeyFactory::makeRandom();
    $builder = (new ClientBuilder())
        ->withMode($mode)
        ->withIdentifier($nip)
        ->withEncryptionKey($encKey);

    $cert = org_setting('kdok_ksef_cert_pem_' . $env_id);
    $key  = org_setting('kdok_ksef_key_pem_'  . $env_id);
    $pass = org_setting('kdok_ksef_key_pass_' . $env_id);
    if ($cert && $key && str_contains($cert, 'BEGIN') && str_contains($key, 'BEGIN')) {
        $builder = $builder->withCertificate($cert, $key, $pass ?: null);
    } else {
        $token = org_setting('kdok_ksef_token');
        if (!$token) throw new RuntimeException('Brak tokena KSeF i certyfikatu w konfiguracji.');
        $builder = $builder->withKsefToken($token);
    }

    return [$builder->build(), $encKey];
}

// ── Bramka IKA + IKAKS dla operacji KSeF ─────────────────────────────────────

/**
 * Weryfikuje tożsamość użytkownika przed operacją KSeF.
 * Sprawdza IKA (kod sesyjny) + IKAKS (indywidualny kod autoryzacyjny).
 *
 * @param string $ikaks_plain  Kod IKAKS wpisany przez użytkownika (może być pusty)
 * @param string $ika_plain    Kod IKA wpisany przez użytkownika (może być pusty)
 * @return array ['ok'=>bool, 'error'=>string|null, 'method'=>string]
 */
function kdok_ksef_auth_gate(string $ikaks_plain = '', string $ika_plain = ''): array {
    $user    = current_user();
    $user_id = (int)($user['id'] ?? 0);
    if (!$user_id) return ['ok' => false, 'error' => 'Brak sesji użytkownika.', 'method' => ''];

    // ── Sprawdź sesyjny token IKA (już zweryfikowany wcześniej) ──────────────
    $ika_session_ok = false;
    if (!isset($_SESSION)) @session_start();
    $ika_ts = (int)($_SESSION['_ika_ts'] ?? 0);
    if ($ika_ts > 0 && (time() - $ika_ts) < 3600) {
        $ika_session_ok = true;
    }

    // ── Weryfikuj IKA jeśli nie ma ważnej sesji i podano kod ─────────────────
    if (!$ika_session_ok && $ika_plain !== '') {
        try {
            require_once __DIR__ . '/cpc.php';
            $ok = cpc_verify_ika($user_id, $ika_plain);
            if ($ok) {
                $ika_session_ok = true;
                $_SESSION['_ika_ts'] = time();
            } else {
                return ['ok' => false, 'error' => 'Nieprawidłowy kod IKA.', 'method' => 'ika'];
            }
        } catch (\Throwable $e) {
            // Brak modułu CPC — pomiń IKA
            $ika_session_ok = true;
        }
    }

    // ── Sprawdź IKAKS ─────────────────────────────────────────────────────────
    $has_ikaks = kdok_ikaks_has($user_id);

    if ($has_ikaks) {
        // Użytkownik ma IKAKS — musi go podać
        if ($ikaks_plain === '') {
            return ['ok' => false, 'error' => 'Wymagany kod IKAKS.', 'method' => 'ikaks'];
        }
        if (!kdok_ikaks_verify($user_id, $ikaks_plain)) {
            return ['ok' => false, 'error' => 'Nieprawidłowy kod IKAKS.', 'method' => 'ikaks'];
        }
        return ['ok' => true, 'error' => null, 'method' => 'ikaks'];
    }

    // ── Brak IKAKS — wymagaj IKA ──────────────────────────────────────────────
    if (!$ika_session_ok) {
        if ($ika_plain === '') {
            return ['ok' => false, 'error' => 'Wymagany kod IKA (nie masz ustawionego IKAKS).', 'method' => 'ika'];
        }
        return ['ok' => false, 'error' => 'Kod IKA nieprawidłowy lub brak sesji.', 'method' => 'ika'];
    }

    return ['ok' => true, 'error' => null, 'method' => 'ika_session'];
}

/**
 * Sprawdza czy bieżący użytkownik ma ważną bramkę KSeF (sesja IKA lub IKAKS ustawiony).
 * Zwraca ['verified'=>bool, 'has_ikaks'=>bool, 'has_ika_session'=>bool]
 */
function kdok_ksef_gate_status(): array {
    $user_id = (int)((current_user()['id'] ?? 0));
    if (!isset($_SESSION)) @session_start();
    $ika_ts  = (int)($_SESSION['_ika_ts'] ?? 0);
    $ika_ok  = $ika_ts > 0 && (time() - $ika_ts) < 3600;
    $has_ika = kdok_ikaks_has($user_id);
    return [
        'verified'        => $has_ika || $ika_ok,
        'has_ikaks'       => $has_ika,
        'has_ika_session' => $ika_ok,
    ];
}

// ── Metoda autoryzacji ────────────────────────────────────────────────────────

function kdok_ksef_auth_method(): string {
    $env_id = kdok_ksef_env_id();
    $cert = org_setting('kdok_ksef_cert_pem_' . $env_id);
    $key  = org_setting('kdok_ksef_key_pem_'  . $env_id);
    return ($cert && $key && str_contains($cert, 'BEGIN') && str_contains($key, 'BEGIN'))
        ? 'cert' : 'token';
}

/**
 * Waliduje certyfikat PEM — zwraca metadane lub rzuca RuntimeException.
 */
function kdok_ksef_parse_cert(string $cert_pem): array {
    $cert = @openssl_x509_read($cert_pem);
    if (!$cert) throw new RuntimeException('Nieprawidłowy certyfikat PEM (X.509).');
    $info = openssl_x509_parse($cert);
    if (!$info) throw new RuntimeException('Nie można odczytać danych certyfikatu.');
    return [
        'subject_cn'  => $info['subject']['CN']  ?? ($info['subject']['O'] ?? ''),
        'issuer_cn'   => $info['issuer']['CN']   ?? '',
        'valid_from'  => date('Y-m-d H:i:s', $info['validFrom_time_t'] ?? 0),
        'valid_to'    => date('Y-m-d H:i:s', $info['validTo_time_t']   ?? 0),
        'serial'      => $info['serialNumberHex'] ?? '',
        'is_valid'    => time() >= ($info['validFrom_time_t'] ?? 0)
                      && time() <= ($info['validTo_time_t']   ?? PHP_INT_MAX),
    ];
}

// ── Zachowanie wstecznej kompatybilności (używane w innych plikach) ───────────

/**
 * @deprecated Używaj kdok_ksef_client() bezpośrednio.
 * Zwraca ['ok'=>bool, 'token'=>null, 'error'=>string|null, 'reference'=>null]
 * 'token' jest zawsze null — SDK zarządza tokenami wewnętrznie.
 */
function kdok_ksef_authenticate_auto(string $nip): array {
    try {
        kdok_ksef_client(); // buduje i weryfikuje połączenie
        return ['ok' => true, 'token' => null, 'error' => null, 'reference' => null];
    } catch (\Throwable $e) {
        return ['ok' => false, 'token' => null, 'error' => $e->getMessage(), 'reference' => null];
    }
}

/** @deprecated Używaj kdok_ksef_authenticate_auto() */
function kdok_ksef_authenticate(string $nip, string $plain_token): array {
    return kdok_ksef_authenticate_auto($nip);
}

/** @deprecated SDK nie wymaga ręcznego zamykania sesji */
function kdok_ksef_session_terminate(mixed $jwt): void {
    // SDK zarządza tokenami wewnętrznie — brak ręcznego terminate
}

// ── Pobieranie faktur ─────────────────────────────────────────────────────────

/**
 * Pobiera listę numerów referencyjnych KSeF dla zakresu dat.
 *
 * @param mixed  $jwt_unused  Ignorowane (SDK zarządza tokenami wewnętrznie)
 * @param string $date_from   Data od (Y-m-d)
 * @param string $date_to     Data do (Y-m-d)
 * @param string $role        'buyer' (nabywca) lub 'seller' (sprzedawca)
 * @return array              Tablica numerów referencyjnych KSeF
 */
function kdok_ksef_query_invoices(mixed $jwt_unused, string $date_from, string $date_to, string $role = 'buyer'): array {
    return kdok_ksef_query_by_date(null, $date_from, $date_to, 'Issue', $role);
}

function kdok_ksef_query_by_date(mixed $jwt_unused, string $date_from, string $date_to, string $date_type_val, string $role = 'buyer'): array {
    $client      = kdok_ksef_client();
    $subjectType = $role === 'seller' ? SubjectType::Subject1 : SubjectType::Subject3;
    $dateType    = DateType::from($date_type_val);

    $response = $client->invoices()->query()->metadata(
        new MetadataRequest(
            subjectType: $subjectType,
            dateRange: new DateRange(
                dateType: $dateType,
                from: DateRangeFrom::from($date_from . 'T00:00:00Z'),
                to:   DateRangeTo::from($date_to   . 'T23:59:59Z'),
            )
        )
    );

    $body = json_decode($response->body(), true);
    $list = $body['invoices'] ?? $body['list'] ?? [];

    return array_values(array_filter(array_map(
        fn($item) => $item['ksefReferenceNumber'] ?? $item['referenceNumber'] ?? '',
        $list
    )));
}

/**
 * Pobiera XML faktury po numerze referencyjnym KSeF.
 *
 * @param mixed  $jwt_unused        Ignorowane
 * @param string $reference_number  Numer referencyjny KSeF
 * @return string                   Raw XML faktury
 */
function kdok_ksef_get_invoice_xml(mixed $jwt_unused, string $reference_number): string {
    $client = kdok_ksef_client();

    $response = $client->invoices()->download(
        new DownloadRequest(ksefNumber: KsefNumber::from($reference_number))
    );

    $body = $response->body();
    if (empty($body)) {
        throw new RuntimeException("KSeF: pusta odpowiedź dla faktury {$reference_number}.");
    }
    return $body;
}

/**
 * Pobiera wizualizację faktury (PDF/HTML) po numerze referencyjnym.
 * Próbuje: XML FA3 → generuje podstawowy PDF z danych
 *
 * @return array ['content'=>string, 'mime'=>string, 'ext'=>string]
 */
function kdok_ksef_get_visualisation(mixed $jwt_unused, string $reference_number): array {
    // SDK nie ma dedykowanego endpointu wizualizacji — pobieramy XML
    $xml = kdok_ksef_get_invoice_xml(null, $reference_number);
    return ['content' => $xml, 'mime' => 'application/xml', 'ext' => 'xml'];
}

/**
 * Parsuje XML faktury FA3 i zwraca podstawowe dane.
 */
function kdok_ksef_parse_xml(string $xml): array {
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) return []; // ścisłe: XML z prefiksem (ns0:) jest dla SimpleXML „pusty” w bool

    // Usuń namespace problemy używając local-name()
    $result = [];

    // Numer faktury — P_2 w Fa
    $nodes = $sx->xpath('//*[local-name()="P_2"]');
    $result['invoice_number'] = (string)($nodes[0] ?? '');

    // Sprzedawca — Podmiot1 > DaneIdentyfikacyjne
    $nodes = $sx->xpath('//*[local-name()="Podmiot1"]//*[local-name()="Nazwa"]');
    $result['seller_name'] = (string)($nodes[0] ?? '');

    $nodes = $sx->xpath('//*[local-name()="Podmiot1"]//*[local-name()="NIP"]');
    $result['seller_nip'] = (string)($nodes[0] ?? '');

    // Kwota brutto — P_15
    $nodes = $sx->xpath('//*[local-name()="P_15"]');
    $result['gross_value'] = (string)($nodes[0] ?? '');

    // Waluta
    $nodes = $sx->xpath('//*[local-name()="KodWaluty"]');
    $result['currency'] = (string)($nodes[0] ?? 'PLN');

    // Data wystawienia — P_1
    $nodes = $sx->xpath('//*[local-name()="P_1"]');
    $result['issue_date'] = (string)($nodes[0] ?? '');

    return $result;
}

// ── Synchronizacja ────────────────────────────────────────────────────────────

/**
 * Pobiera nowe faktury z KSeF (od ostatniej synchronizacji) i tworzy dokumenty KDOK.
 * @return array ['imported'=>int, 'skipped'=>int, 'errors'=>array, 'new_doc_ids'=>array]
 */
function kdok_ksef_sync(): array {
    $last = org_setting('kdok_ksef_last_sync');
    $from = $last ? date('Y-m-d', strtotime($last)) : date('Y-m-d', strtotime('-30 days'));
    $to   = date('Y-m-d');
    $stats = kdok_ksef_sync_export($from, $to);
    kdok_ksef_setting_save('kdok_ksef_last_sync', date('Y-m-d H:i:s'));
    return $stats;
}

/**
 * Pobiera faktury przez eksport asynchroniczny KSeF (bez limitu zapytań).
 * Właściwa metoda dla "Pobierz wszystkie" — obsługuje duże zakresy dat.
 *
 * Flow:
 *   1. Inicjuje eksport (POST /invoices/exports) z kluczem AES-256
 *   2. Czeka na zakończenie (polling GET /invoices/exports/{ref})
 *   3. Pobiera zaszyfrowaną paczkę ZIP
 *   4. Rozpakowuje i deszyfruje każdy plik XML
 *   5. Tworzy dokumenty KDOK
 *
 * @param string $from Data od (Y-m-d)
 * @param string $to   Data do (Y-m-d)
 * @return array ['imported'=>int, 'skipped'=>int, 'errors'=>array, 'new_doc_ids'=>array]
 */
function kdok_ksef_sync_export(string $from, string $to, string $queue_table = 'kdok_ksef_queue', callable|string $create_doc_fn = 'kdok_ksef_create_doc'): array {
    if (!org_setting('kdok_ksef_nip')) {
        throw new RuntimeException('Brak NIP w konfiguracji KSeF.');
    }

    $stats = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'new_doc_ids' => [], 'notices' => []];
    $log_prefix = 'KSEF_SYNC [' . kdok_ksef_env_id() . '] NIP=' . org_setting('kdok_ksef_nip');

    // Klient z kluczem szyfrującym — wymagane dla eksportu
    [$client, $encKey] = kdok_ksef_client_with_key();

    // Dziel na 3-miesięczne chunki (limit API)
    $chunks = kdok_ksef_date_chunks($from, $to, 3);

    foreach ($chunks as [$chunk_from, $chunk_to]) {
        try {
            // 1. Inicjuj eksport
            $initResp = $client->invoices()->exports()->init(
                new ExportInitRequest(
                    filters: new ExportFilters(
                        subjectType: SubjectType::Subject3,
                        dateRange: new DateRange(
                            dateType: DateType::Issue,
                            from: DateRangeFrom::from($chunk_from . 'T00:00:00Z'),
                            to:   DateRangeTo::from($chunk_to   . 'T23:59:59Z'),
                        )
                    )
                )
            );
            $initData = json_decode($initResp->body(), true);
            $refNumber = $initData['referenceNumber'] ?? null;
            if (!$refNumber) {
                $stats['errors'][] = "Eksport {$chunk_from}–{$chunk_to}: brak referenceNumber";
                continue;
            }

            // 2. Polling statusu (max 5 minut)
            $downloadUrl = null;
            $lastCode = null;
            $polled = 0;
            for ($i = 0; $i < 60; $i++) {
                sleep(5);
                $polled++;
                $statusResp = $client->invoices()->exports()->status(
                    new ExportStatusRequest(ReferenceNumber::from($refNumber))
                );
                $statusData = json_decode($statusResp->body(), true);
                $code = $statusData['processingCode'] ?? $statusData['status']['code'] ?? null;
                $lastCode = $code;

                if ($code === 'DONE' || $code === 200) {
                    $downloadUrl = $statusData['url']
                        ?? $statusData['packageUrl']
                        ?? $statusData['data']['url']
                        ?? null;
                    break;
                }
                if (is_int($code) && $code >= 400) {
                    throw new RuntimeException("Eksport nie powiódł się. Kod: {$code}");
                }
            }

            if (!$downloadUrl) {
                // Eksport gotowy, ale bez URL-a paczki (albo timeout pollingu po 5 min) —
                // wcześniej to znikało bez śladu, przez co "0 zaimportowano" wyglądało jak
                // sukces zamiast jak nierozstrzygnięty eksport. Zostawiamy jawną notatkę.
                $reason = $polled >= 60
                    ? 'przekroczono czas oczekiwania (5 min), eksport KSeF nie zdążył się przygotować'
                    : 'brak adresu paczki w odpowiedzi (kod statusu: ' . var_export($lastCode, true) . ')';
                $stats['notices'][] = "Eksport {$chunk_from}–{$chunk_to}: {$reason}.";
                continue;
            }

            // 3. Pobierz zaszyfrowaną paczkę ZIP
            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true]);
            $zipData  = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
            if (!$zipData || $httpCode >= 400) {
                $stats['errors'][] = "Pobieranie paczki {$chunk_from}–{$chunk_to}: HTTP {$httpCode}";
                continue;
            }

            // 4. Zapisz ZIP do temp, rozpakuj i przetwórz
            $tmpZip = tempnam(sys_get_temp_dir(), 'ksef_') . '.zip';
            file_put_contents($tmpZip, $zipData);

            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                @unlink($tmpZip);
                $stats['errors'][] = "Rozpakowywanie ZIP {$chunk_from}–{$chunk_to}: błąd otwarcia";
                continue;
            }
            if ($zip->numFiles === 0) {
                // Eksport zakończony poprawnie, ale KSeF nie znalazł żadnych faktur
                // (jako nabywca, wg daty wystawienia) w tym zakresie dat.
                $stats['notices'][] = "Eksport {$chunk_from}–{$chunk_to}: paczka pobrana poprawnie, ale nie zawiera żadnych faktur (brak faktur zakupowych za ten okres w KSeF, środowisko: " . kdok_ksef_env_id() . ").";
            }

            $decryptHandler = new DecryptDocumentHandler();

            for ($j = 0; $j < $zip->numFiles; $j++) {
                $filename    = $zip->getNameIndex($j);
                $fileContent = $zip->getFromIndex($j);
                if ($fileContent === false) continue;

                // Deszyfruj jeśli zaszyfrowane (nie jest raw XML)
                if (!str_starts_with(trim($fileContent), '<') && !str_starts_with(trim($fileContent), '<?')) {
                    try {
                        $fileContent = $decryptHandler->handle(
                            new DecryptDocumentAction($encKey, $fileContent)
                        );
                    } catch (\Throwable $de) {
                        $stats['errors'][] = "Deszyfrowanie {$filename}: " . $de->getMessage();
                        continue;
                    }
                }

                // Przetwórz XML faktury
                $data = kdok_ksef_parse_xml($fileContent);
                $ref  = $data['ksef_reference'] ?? pathinfo($filename, PATHINFO_FILENAME);

                $exists = kdok_one("SELECT 1 FROM {$queue_table} WHERE ksef_reference=?", [$ref]);
                if ($exists) { $stats['skipped']++; continue; }

                try {
                    kdok_insert($queue_table, [
                        'ksef_reference' => $ref,
                        'invoice_number' => $data['invoice_number'] ?? '',
                        'seller_name'    => $data['seller_name']    ?? '',
                        'seller_nip'     => $data['seller_nip']     ?? '',
                        'gross_value'    => $data['gross_value']     ?? '',
                        'currency'       => $data['currency']        ?? 'PLN',
                        'issue_date'     => $data['issue_date']      ?? '',
                        'ksef_date'      => date('Y-m-d'),
                    ]);
                    $doc_id = call_user_func($create_doc_fn, array_merge($data, ['ksef_reference' => $ref]));
                    kdok_exec("UPDATE {$queue_table} SET doc_id=? WHERE ksef_reference=?", [$doc_id, $ref]);
                    $stats['imported']++;
                    $stats['new_doc_ids'][] = $doc_id;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Import {$ref}: " . $e->getMessage();
                }
            }

            $zip->close();
            @unlink($tmpZip);

        } catch (\Throwable $e) {
            $stats['errors'][] = "Chunk {$chunk_from}–{$chunk_to}: " . $e->getMessage();
            error_log("{$log_prefix} ERROR chunk {$chunk_from}–{$chunk_to}: " . $e->getMessage());
        }
    }

    // Log podsumowania
    error_log("{$log_prefix} DONE {$from}–{$to} imported={$stats['imported']} skipped={$stats['skipped']} errors=" . count($stats['errors']));

    return $stats;
}

/**
 * Pobiera faktury z KSeF z podanego przedziału dat.
 * Automatycznie dzieli zakres na przedziały ≤ 3 miesiące (limit SDK).
 *
 * @param string $from  Data od (Y-m-d)
 * @param string $to    Data do (Y-m-d)
 * @param string $role  'buyer' (domyślnie) lub 'seller'
 * @return array ['imported'=>int, 'skipped'=>int, 'errors'=>array, 'new_doc_ids'=>array]
 */
function kdok_ksef_sync_range(string $from, string $to, string $role = 'buyer', string $queue_table = 'kdok_ksef_queue', callable|string $create_doc_fn = 'kdok_ksef_create_doc'): array {
    if (!org_setting('kdok_ksef_nip')) {
        throw new RuntimeException('Brak NIP w konfiguracji KSeF.');
    }

    $stats = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'new_doc_ids' => [], 'notices' => []];

    // Podziel zakres na 3-miesięczne przedziały (limit API KSeF)
    $chunks = kdok_ksef_date_chunks($from, $to, 3);

    // Jeden klient dla wszystkich chunków
    $client = kdok_ksef_client();

    foreach ($chunks as [$chunk_from, $chunk_to]) {
        // Pobierz jako nabywca (Subject3) po wszystkich typach dat — deduplikuj
        $refs = [];
        try {
            $refs = kdok_ksef_query_by_date(null, $chunk_from, $chunk_to, 'Issue');
            if (!$refs) {
                $stats['notices'][] = "Zapytanie {$chunk_from}–{$chunk_to}: KSeF nie zwrócił żadnych faktur zakupowych (środowisko: " . kdok_ksef_env_id() . ").";
            }
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '429')) {
                sleep(5);
                try {
                    $refs = kdok_ksef_query_by_date(null, $chunk_from, $chunk_to, 'Issue');
                } catch (\Throwable $e2) {
                    $stats['errors'][] = "Zapytanie {$chunk_from}–{$chunk_to}: " . $e2->getMessage();
                    continue;
                }
            } else {
                $stats['errors'][] = "Zapytanie {$chunk_from}–{$chunk_to}: " . $e->getMessage();
                continue;
            }
        }

        foreach ($refs as $ref) {
            if (empty($ref)) continue;
            $exists = kdok_one("SELECT 1 FROM {$queue_table} WHERE ksef_reference=?", [$ref]);
            if ($exists) { $stats['skipped']++; continue; }

            try {
                $xml  = kdok_ksef_get_invoice_xml(null, $ref);
                $data = kdok_ksef_parse_xml($xml);

                kdok_insert($queue_table, [
                    'ksef_reference' => $ref,
                    'invoice_number' => $data['invoice_number'] ?? '',
                    'seller_name'    => $data['seller_name']    ?? '',
                    'seller_nip'     => $data['seller_nip']     ?? '',
                    'gross_value'    => $data['gross_value']     ?? '',
                    'currency'       => $data['currency']        ?? 'PLN',
                    'issue_date'     => $data['issue_date']      ?? '',
                    'ksef_date'      => date('Y-m-d'),
                ]);

                $doc_id = call_user_func($create_doc_fn, array_merge($data, ['ksef_reference' => $ref]));
                kdok_exec("UPDATE {$queue_table} SET doc_id=? WHERE ksef_reference=?", [$doc_id, $ref]);

                $stats['imported']++;
                $stats['new_doc_ids'][] = $doc_id;
            } catch (\Throwable $e) {
                $stats['errors'][] = "Ref {$ref}: " . $e->getMessage();
            }
        }
    }

    return $stats;
}

/**
 * Dzieli przedział dat na kawałki po $months miesięcy.
 * @return array<int, array{0:string, 1:string}>
 */
function kdok_ksef_date_chunks(string $from, string $to, int $months = 3): array {
    $chunks  = [];
    $current = new \DateTimeImmutable($from);
    $end     = new \DateTimeImmutable($to);

    while ($current <= $end) {
        $chunk_end = $current->modify("+{$months} months - 1 day");
        if ($chunk_end > $end) $chunk_end = $end;
        $chunks[]  = [$current->format('Y-m-d'), $chunk_end->format('Y-m-d')];
        $current   = $chunk_end->modify('+1 day');
    }

    return $chunks;
}

/**
 * Tworzy dokument KDOK na podstawie danych z KSeF.
 */
function kdok_ksef_create_doc(array $invoice_data): int {
    $title = trim(
        ($invoice_data['invoice_number'] ?? '')
        . ($invoice_data['seller_name'] ? ' — ' . $invoice_data['seller_name'] : '')
    ) ?: ('Faktura KSeF ' . ($invoice_data['ksef_reference'] ?? ''));

    $desc_parts = [];
    if (!empty($invoice_data['seller_nip']))     $desc_parts[] = 'NIP sprzedawcy: ' . $invoice_data['seller_nip'];
    if (!empty($invoice_data['gross_value']))     $desc_parts[] = 'Kwota brutto: ' . $invoice_data['gross_value'] . ' ' . ($invoice_data['currency'] ?? 'PLN');
    if (!empty($invoice_data['issue_date']))      $desc_parts[] = 'Data wystawienia: ' . $invoice_data['issue_date'];
    if (!empty($invoice_data['ksef_reference']))  $desc_parts[] = 'Nr KSeF: ' . $invoice_data['ksef_reference'];

    $issue_date = $invoice_data['issue_date'] ?? '';
    $miesiac = $issue_date ? (int)date('n', strtotime($issue_date)) : (int)date('n');
    $rok     = $issue_date ? (int)date('Y', strtotime($issue_date)) : (int)date('Y');

    $doc_id = kdok_insert('kdok_documents', [
        'number'       => kdok_next_number(),
        'type'         => 'ksef',
        'title'        => $title,
        'description'  => implode(' | ', $desc_parts),
        'kwota'        => $invoice_data['gross_value'] ?? '',
        'creator_name' => 'KSeF (auto-import)',
        'created_by'   => null,
        'status'       => 'w_obiegu',
        'miesiac'      => $miesiac,
        'rok'          => $rok,
    ]);

    foreach (array_keys(KDOK_STEPS) as $step) {
        kdok_insert('kdok_steps', ['doc_id' => $doc_id, 'step_type' => $step]);
    }

    kdok_insert('kdok_history', [
        'doc_id'    => $doc_id,
        'user_id'   => null,
        'user_name' => 'KSeF',
        'action'    => 'Auto-import z KSeF',
        'note'      => 'Nr KSeF: ' . ($invoice_data['ksef_reference'] ?? ''),
        'ip'        => '',
    ]);

    return $doc_id;
}

// ── Migracja bazy ─────────────────────────────────────────────────────────────

function kdok_ksef_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $kdb  = kdok_db();
    $type = org_setting('kdok_db_type');
    $ai   = ($type === 'mysql') ? 'AUTO_INCREMENT' : 'AUTOINCREMENT';
    $now  = ($type === 'mysql') ? 'NOW()' : "(datetime('now'))";

    $kdb->exec("CREATE TABLE IF NOT EXISTS kdok_ksef_queue (
        id              INTEGER PRIMARY KEY {$ai},
        ksef_reference  TEXT    NOT NULL UNIQUE,
        invoice_number  TEXT    NOT NULL DEFAULT '',
        seller_name     TEXT    NOT NULL DEFAULT '',
        seller_nip      TEXT    NOT NULL DEFAULT '',
        gross_value     TEXT    NOT NULL DEFAULT '',
        currency        TEXT    NOT NULL DEFAULT 'PLN',
        issue_date      TEXT    NOT NULL DEFAULT '',
        ksef_date       TEXT    NOT NULL DEFAULT '',
        doc_id          INTEGER DEFAULT NULL,
        created_at      TEXT    NOT NULL DEFAULT {$now}
    )");

    // Domyślne ustawienia w głównej bazie
    foreach ([
        'kdok_ksef_enabled'               => '0',
        'kdok_ksef_env'                   => 'production',
        'kdok_ksef_nip'                   => '',
        'kdok_ksef_token'                 => '',
        'kdok_ksef_last_sync'             => '',
        'kdok_ksef_cert_pem_production'   => '',
        'kdok_ksef_key_pem_production'    => '',
        'kdok_ksef_key_pass_production'   => '',
        'kdok_ksef_cert_pem_demo'         => '',
        'kdok_ksef_key_pem_demo'          => '',
        'kdok_ksef_key_pass_demo'         => '',
        'kdok_ksef_cert_pem_test'         => '',
        'kdok_ksef_key_pem_test'          => '',
        'kdok_ksef_key_pass_test'         => '',
        'kdok_ksef_pubkey_manual_production' => '',
        'kdok_ksef_pubkey_manual_demo'    => '',
        'kdok_ksef_pubkey_manual_test'    => '',
    ] as $k => $v) {
        if (!db_one("SELECT 1 FROM settings WHERE key_=?", [$k])) {
            db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$k, $v]);
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function kdok_ksef_enabled(): bool {
    return org_setting('kdok_ksef_enabled') === '1';
}

function kdok_ksef_status(): array {
    return [
        'enabled'     => kdok_ksef_enabled(),
        'env'         => kdok_ksef_env_id(),
        'env_label'   => kdok_ksef_env()['label'],
        'nip'         => org_setting('kdok_ksef_nip'),
        'has_token'   => org_setting('kdok_ksef_token') !== '',
        'auth_method' => kdok_ksef_auth_method(),
        'last_sync'   => org_setting('kdok_ksef_last_sync'),
    ];
}
