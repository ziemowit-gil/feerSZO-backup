<?php
/**
 * Integracja z Postivo.pl — wysyłka fizycznych listów pocztą.
 * Używa oficjalnego SDK: postivo/postivo-client (Composer).
 */

use Postivo\Client as PostivoSDK;
use Postivo\Models\Components;

function postivo_setting(string $key): string {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $r['value'] ?? '';
    }
    return $cache[$key];
}

class PostivoClient
{
    private ?PostivoSDK $sdk = null;

    private function sdk(): PostivoSDK
    {
        if ($this->sdk === null) {
            $this->sdk = PostivoSDK::builder()
                ->setSecurity(postivo_setting('postivo_api_key'))
                ->build();
        }
        return $this->sdk;
    }

    public function is_configured(): bool
    {
        return postivo_setting('postivo_api_key') !== '';
    }

    /**
     * Testuje połączenie — GET /account. Zwraca saldo konta przy sukcesie.
     *
     * @return array ['ok' => bool, 'msg' => string, 'balance' => float|null]
     */
    public function ping(): array
    {
        if (!$this->is_configured()) {
            return ['ok' => false, 'msg' => 'Brak klucza API.', 'balance' => null];
        }
        try {
            $resp    = $this->sdk()->accounts->get();
            $balance = $resp->accountResponse?->credit ?? null;
            $msg     = 'Połączenie nawiązane pomyślnie.';
            if ($balance !== null) {
                $msg .= ' Saldo konta: ' . number_format((float)$balance, 2, ',', ' ') . ' zł.';
            }
            return ['ok' => true, 'msg' => $msg, 'balance' => $balance !== null ? (float)$balance : null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage(), 'balance' => null];
        }
    }

    /**
     * Pobiera metadane API: nośniki (z usługami), papiery, koperty.
     *
     * SDK zwraca obiekty (MetadataResponseCarrier, Paper, EnvelopeTemplate, Envelope)
     * z polami camelCase — od razu spłaszczamy je do zwykłych tablic asocjacyjnych
     * z kluczami snake_case, żeby reszta aplikacji nie musiała znać kształtu SDK.
     *
     * @return array ['carriers' => [...], 'papers' => [...], 'envelope_templates' => [...]]
     * @throws RuntimeException
     */
    public function get_metadata(): array
    {
        try {
            $resp = $this->sdk()->metadata->list();
            $md   = $resp->metadataResponse;

            $carriers = array_map(static function ($c) {
                return [
                    'carrier_id'   => $c->carrierId,
                    'carrier_name' => $c->carrierName,
                    'services'     => array_map(static function ($s) {
                        return [
                            'service_id'         => $s->serviceId,
                            'service_name'        => $s->serviceName,
                            'service_return_fee'  => $s->serviceReturnFee,
                        ];
                    }, $c->services ?? []),
                ];
            }, $md?->carriers ?? []);

            $papers = array_map(static function ($p) {
                return ['paper_id' => $p->paperId, 'paper_name' => $p->paperName];
            }, $md?->papers ?? []);

            $envelope_templates = array_map(static function ($g) {
                return [
                    'envelope_group_name' => $g->envelopeGroupName,
                    'envelope'            => array_map(static function ($e) {
                        return [
                            'envelope_id'   => $e->envelopeId,
                            'envelope_name' => $e->envelopeName,
                            'max_sheets'    => $e->maxSheets,
                        ];
                    }, $g->envelope ?? []),
                ];
            }, $md?->envelopeTemplates ?? []);

            return [
                'carriers'           => $carriers,
                'papers'             => $papers,
                'envelope_templates' => $envelope_templates,
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException('Błąd pobierania metadanych Postivo.pl: ' . $e->getMessage());
        }
    }

    /**
     * Tworzy zlecenie wysyłki listu.
     *
     * @param array $params Wymagane: recipient_name, address_line1, city, postcode
     *   Opcjonalne: address_line2, home_number, flat_number, phone_number, postscript,
     *               custom_id, country, pdf_path
     *
     * @return array ['id' => string]
     * @throws RuntimeException
     */
    public function send_letter(array $params): array
    {
        if (!$this->is_configured()) {
            throw new RuntimeException(
                'Brak klucza API Postivo.pl. Skonfiguruj w: Administracja → Postivo (poczta).'
            );
        }

        $pdf_path = $params['pdf_path'] ?? '';
        if (!$pdf_path || !file_exists($pdf_path)) {
            throw new RuntimeException('Plik PDF nie istnieje: ' . $pdf_path);
        }

        $recipient = new Components\RecipientInline(
            name:        $params['recipient_name'],
            address:     $params['address_line1'],
            postCode:    $params['postcode'],
            city:        $params['city'],
            name2:       $params['address_line2'] ?? null,
            homeNumber:  $params['home_number']   ?? null,
            flatNumber:  $params['flat_number']   ?? null,
            phoneNumber: $params['phone_number']  ?? null,
            postscript:  $params['postscript']    ?? null,
            customId:    $params['custom_id']     ?? null,
        );

        $document = new Components\DocumentPdf(
            fileStream: base64_encode(file_get_contents($pdf_path)),
            fileName:   basename($pdf_path),
        );

        $shipment = new Components\Shipment(
            recipients: $recipient,
            documents:  $document,
            options:    $this->_build_options(
                isset($params['carrier_id']) ? (int)$params['carrier_id'] : null,
                isset($params['service_id']) ? (int)$params['service_id'] : null,
            ),
        );

        try {
            $resp   = $this->sdk()->shipments->dispatch($shipment);
            $detail = $resp->shipmentDetails[0] ?? null;
            $id     = $detail?->id ?? '';

            if (!$id) {
                throw new RuntimeException('Postivo.pl nie zwróciło ID zlecenia.');
            }
            return ['id' => $id];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Błąd wysyłki Postivo.pl: ' . $e->getMessage());
        }
    }

    /**
     * Pobiera status zlecenia po ID.
     *
     * @return array ['status' => string, 'tracking' => string, 'updated_at' => string]
     * @throws RuntimeException
     */
    /**
     * @return array{
     *   status:string, tracking:string, updated_at:string, job_id:string,
     *   operator:string, service_name:string, status_name:string,
     *   dispatch_date:string, pages:int,
     *   events:array<array{code:string,name:string,date:string}>
     * }
     */
    public function get_status(string $postivo_id): array
    {
        try {
            $resp   = $this->sdk()->shipments->status([$postivo_id]);
            $detail = $resp->statusDetails[0] ?? null;
            $sd     = $detail?->shipmentDetails;
            $code   = strtoupper($sd?->status?->code ?? '');

            $status = match($code) {
                'ACCEPTED'   => 'processing',
                'PROCESSING' => 'processing',
                'SENT'       => 'sent',
                'DELIVERED'  => 'delivered',
                'FAILED'     => 'failed',
                default      => strtolower($code) ?: 'unknown',
            };

            $date = $sd?->status?->date;

            $events = [];
            foreach ($detail?->statusEvents ?? [] as $ev) {
                $events[] = [
                    'code' => (string)($ev->code ?? ''),
                    'name' => (string)($ev->name ?? ''),
                    'date' => $ev->date ? $ev->date->format('Y-m-d H:i:s') : '',
                ];
            }

            return [
                'status'        => $status,
                'tracking'      => $sd?->trackingNumber ?? '',
                'updated_at'    => $date ? $date->format('Y-m-d H:i:s') : '',
                'job_id'        => $sd?->id ?? $postivo_id,
                'operator'      => $sd?->carrier?->name ?? '',
                'service_name'  => $sd?->service?->name ?? '',
                'status_name'   => $sd?->status?->name ?? '',
                'dispatch_date' => $sd?->dispatchDate ? (string)$sd->dispatchDate : '',
                'pages'         => (int)($sd?->pageNumber ?? 0),
                'events'        => $events,
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException('Błąd pobierania statusu Postivo.pl: ' . $e->getMessage());
        }
    }

    /**
     * Pobiera dokument (EPO/cert nadania) dla zlecenia.
     *
     * @param  string        $postivo_id
     * @param  string        $type  'epo_pdf'|'epo_xml'|'dispatch_cert'|'envelope'
     * @return string|null   Zawartość pliku (binary) lub null przy braku
     * @throws RuntimeException
     */
    public function get_document(string $postivo_id, string $type = 'epo_pdf'): ?string
    {
        try {
            $docType = match($type) {
                'epo_pdf'      => \Postivo\Models\Operations\DocumentType::EpoPdf,
                'epo_xml'      => \Postivo\Models\Operations\DocumentType::EpoXml,
                'dispatch_cert'=> \Postivo\Models\Operations\DocumentType::DispatchCert,
                'envelope'     => \Postivo\Models\Operations\DocumentType::Envelope,
                default        => throw new RuntimeException('Nieznany typ dokumentu: ' . $type),
            };
            $resp = $this->sdk()->shipments->documents($postivo_id, $docType);
            $stream = $resp->documentResponse?->fileStream ?? null;
            return $stream !== null ? base64_decode($stream) : null;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Błąd pobierania dokumentu Postivo.pl: ' . $e->getMessage());
        }
    }

    /**
     * Anuluje zlecenie wysyłki.
     *
     * @throws RuntimeException
     */
    public function cancel(string $postivo_id): void
    {
        try {
            $this->sdk()->shipments->cancel([$postivo_id]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Błąd anulowania zlecenia Postivo.pl: ' . $e->getMessage());
        }
    }

    /**
     * Sprawdza szacowaną cenę wysyłki.
     *
     * @return float|null Cena w PLN lub null przy błędzie
     */
    public function get_price(array $params): ?float
    {
        if (!$this->is_configured()) {
            return null;
        }

        $pdf_path = $params['pdf_path'] ?? '';
        if (!$pdf_path || !file_exists($pdf_path)) {
            return null;
        }

        try {
            $recipient = new Components\RecipientInline(
                name:     $params['recipient_name'] ?? 'Test',
                address:  $params['address_line1']  ?? '',
                postCode: $params['postcode']        ?? '',
                city:     $params['city']            ?? '',
            );

            $document = new Components\DocumentPdf(
                fileStream: base64_encode(file_get_contents($pdf_path)),
                fileName:   basename($pdf_path),
            );

            $shipment = new Components\Shipment(
                recipients: $recipient,
                documents:  $document,
                options:    $this->_build_options(
                    isset($params['carrier_id']) ? (int)$params['carrier_id'] : null,
                    isset($params['service_id']) ? (int)$params['service_id'] : null,
                ),
            );

            $resp = $this->sdk()->shipments->price($shipment);
            return isset($resp->shipmentPrice[0]->price)
                ? (float)$resp->shipmentPrice[0]->price
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** ID nadawcy skonfigurowanego w koncie Postivo.pl (panel → Nadawcy) — domyślnie 6140. */
    private function _sender_id(): int
    {
        $raw = postivo_setting('postivo_sender_id');
        return $raw !== '' ? (int)$raw : 6140;
    }

    /**
     * @param ?int $carrier_id_override Wybór usługi PER WYSYŁKA (np. z modala
     *        "Zarejestruj w wychodzących") — nadpisuje domyślny nośnik z
     *        Administracja → Postivo. Wymaga podania razem z $service_id_override.
     */
    private function _build_options(?int $carrier_id_override = null, ?int $service_id_override = null): Components\ShipmentOptions
    {
        $carrier_id = $carrier_id_override ?? (int)postivo_setting('postivo_carrier_id');
        $service_id = $service_id_override ?? (int)postivo_setting('postivo_service_id');
        $sender_id  = $this->_sender_id();

        if ($carrier_id && $service_id) {
            $inline = new Components\InlineConfig(
                carrierId:          $carrier_id,
                serviceId:          $service_id,
                paperId:            ($p = (int)postivo_setting('postivo_paper_id'))    ? $p : null,
                envelopeId:         ($e = (int)postivo_setting('postivo_envelope_id')) ? $e : null,
                colorPrint:         postivo_setting('postivo_color_print')          === '1' ? true : null,
                duplexPrint:        postivo_setting('postivo_duplex_print')         === '1' ? true : null,
                envelopeColorPrint: postivo_setting('postivo_envelope_color_print') === '1' ? true : null,
            );
            return new Components\ShipmentOptions(inlineConfig: $inline, senderId: $sender_id);
        }

        if ($config_id = (int)postivo_setting('postivo_config_id')) {
            return new Components\ShipmentOptions(predefinedConfigId: $config_id, senderId: $sender_id);
        }

        throw new RuntimeException(
            'Brak konfiguracji wysyłki. Skonfiguruj nośnik i usługę w: Administracja → Postivo (poczta).'
        );
    }
}
