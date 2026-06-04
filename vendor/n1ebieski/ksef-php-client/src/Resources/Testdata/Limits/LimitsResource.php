<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Resources\Testdata\Limits;

use CuyZ\Valinor\Cache\Cache;
use N1ebieski\KSEFClient\Contracts\Exception\ExceptionHandlerInterface;
use N1ebieski\KSEFClient\Contracts\HttpClient\HttpClientInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\LimitsResourceInterface;
use N1ebieski\KSEFClient\Resources\AbstractResource;
use N1ebieski\KSEFClient\Resources\Testdata\Limits\Context\ContextResource;
use N1ebieski\KSEFClient\Resources\Testdata\Limits\Subject\SubjectResource;
use Throwable;

final class LimitsResource extends AbstractResource implements LimitsResourceInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ExceptionHandlerInterface $exceptionHandler,
        private readonly ?Cache $valinorCache = null
    ) {
    }

    public function context(): ContextResource
    {
        try {
            return new ContextResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }

    public function subject(): SubjectResource
    {
        try {
            return new SubjectResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }
}
