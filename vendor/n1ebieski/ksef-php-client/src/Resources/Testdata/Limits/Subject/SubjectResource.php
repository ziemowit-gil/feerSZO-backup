<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Resources\Testdata\Limits\Subject;

use CuyZ\Valinor\Cache\Cache;
use N1ebieski\KSEFClient\Contracts\Exception\ExceptionHandlerInterface;
use N1ebieski\KSEFClient\Contracts\HttpClient\HttpClientInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\Subject\SubjectResourceInterface;
use N1ebieski\KSEFClient\Resources\AbstractResource;
use N1ebieski\KSEFClient\Resources\Testdata\Limits\Subject\Certificate\CertificateResource;
use Throwable;

final class SubjectResource extends AbstractResource implements SubjectResourceInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ExceptionHandlerInterface $exceptionHandler,
        private readonly ?Cache $valinorCache = null
    ) {
    }

    public function certificate(): CertificateResource
    {
        try {
            return new CertificateResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }
}
