<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Auth;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Auth\Sessions\SessionsResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Auth\Token\TokenResourceInterface;
use N1ebieski\KSEFClient\Requests\Auth\KsefToken\KsefTokenRequest;
use N1ebieski\KSEFClient\Requests\Auth\Status\StatusRequest;
use N1ebieski\KSEFClient\Requests\Auth\XadesSignature\XadesSignatureRequest;
use N1ebieski\KSEFClient\Requests\Auth\XadesSignature\XadesSignatureXmlRequest;

interface AuthResourceInterface
{
    public function challenge(): ResponseInterface;

    /**
     * @param XadesSignatureRequest|XadesSignatureXmlRequest|array<string, mixed> $request
     */
    public function xadesSignature(XadesSignatureRequest | XadesSignatureXmlRequest | array $request): ResponseInterface;

    /**
     * @param KsefTokenRequest|array<string, mixed> $request
     */
    public function ksefToken(KsefTokenRequest | array $request): ResponseInterface;

    /**
     * @param StatusRequest|array<string, mixed> $request
     */
    public function status(StatusRequest | array $request): ResponseInterface;

    public function token(): TokenResourceInterface;

    public function sessions(): SessionsResourceInterface;
}
