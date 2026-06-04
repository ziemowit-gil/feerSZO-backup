<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Resources\Permissions\Query;

use CuyZ\Valinor\Cache\Cache;
use N1ebieski\KSEFClient\Contracts\Exception\ExceptionHandlerInterface;
use N1ebieski\KSEFClient\Contracts\HttpClient\HttpClientInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\QueryResourceInterface;
use N1ebieski\KSEFClient\Resources\AbstractResource;
use N1ebieski\KSEFClient\Resources\Permissions\Query\Authorizations\AuthorizationsResource;
use N1ebieski\KSEFClient\Resources\Permissions\Query\Entities\EntitiesResource;
use N1ebieski\KSEFClient\Resources\Permissions\Query\EuEntities\EuEntitiesResource;
use N1ebieski\KSEFClient\Resources\Permissions\Query\Personal\PersonalResource;
use N1ebieski\KSEFClient\Resources\Permissions\Query\Persons\PersonsResource;
use N1ebieski\KSEFClient\Resources\Permissions\Query\SubordinateEntities\SubordinateEntitiesResource;
use N1ebieski\KSEFClient\Resources\Permissions\Query\Subunits\SubunitsResource;
use Throwable;

final class QueryResource extends AbstractResource implements QueryResourceInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ExceptionHandlerInterface $exceptionHandler,
        private readonly ?Cache $valinorCache = null
    ) {
    }

    public function authorizations(): AuthorizationsResource
    {
        try {
            return new AuthorizationsResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }

    public function entities(): EntitiesResource
    {
        try {
            return new EntitiesResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }

    public function euEntities(): EuEntitiesResource
    {
        try {
            return new EuEntitiesResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }

    public function personal(): PersonalResource
    {
        try {
            return new PersonalResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }

    public function persons(): PersonsResource
    {
        try {
            return new PersonsResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }

    public function subunits(): SubunitsResource
    {
        try {
            return new SubunitsResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }

    public function subordinateEntities(): SubordinateEntitiesResource
    {
        try {
            return new SubordinateEntitiesResource($this->client, $this->exceptionHandler, $this->valinorCache);
        } catch (Throwable $throwable) {
            throw $this->exceptionHandler->handle($throwable);
        }
    }
}
