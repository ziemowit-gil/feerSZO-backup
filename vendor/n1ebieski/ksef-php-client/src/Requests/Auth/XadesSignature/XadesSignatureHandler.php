<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Auth\XadesSignature;

use N1ebieski\KSEFClient\Actions\SignDocument\SignDocumentAction;
use N1ebieski\KSEFClient\Actions\SignDocument\SignDocumentHandler;
use N1ebieski\KSEFClient\Contracts\HttpClient\HttpClientInterface;
use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\DTOs\Config;
use N1ebieski\KSEFClient\DTOs\HttpClient\Request;
use N1ebieski\KSEFClient\Requests\AbstractHandler;
use N1ebieski\KSEFClient\Support\Utility;
use N1ebieski\KSEFClient\Validator\Rules\Xml\SchemaRule;
use N1ebieski\KSEFClient\Validator\Validator;
use N1ebieski\KSEFClient\ValueObjects\HttpClient\Method;
use N1ebieski\KSEFClient\ValueObjects\HttpClient\Uri;
use N1ebieski\KSEFClient\ValueObjects\SchemaPath;

final class XadesSignatureHandler extends AbstractHandler
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly SignDocumentHandler $signDocument,
        private readonly Config $config
    ) {
    }

    public function handle(XadesSignatureRequest | XadesSignatureXmlRequest $request): ResponseInterface
    {
        $signedXml = $request->toXml();

        if ($request instanceof XadesSignatureRequest) {
            if ($this->config->validateXml) {
                Validator::validate($signedXml, [
                    new SchemaRule(SchemaPath::from(Utility::basePath('resources/xsd/authv2.xsd')))
                ]);
            }

            $signedXml = $this->signDocument->handle(
                new SignDocumentAction(
                    certificate: $request->certificate,
                    document: $request->toXml(),
                )
            );
        }

        return $this->client
            ->withoutAccessToken()
            ->sendRequest(new Request(
                method: Method::Post,
                uri: Uri::from('auth/xades-signature'),
                headers: [
                    'Content-Type' => 'application/xml',
                    'Accept' => 'application/json',
                    ...$request->toHeaders()
                ],
                parameters: $request->toParameters(),
                body: $signedXml
            ));
    }
}
