<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Sessions\List;

use N1ebieski\KSEFClient\Contracts\HeadersInterface;
use N1ebieski\KSEFClient\Contracts\ParametersInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Requests\ContinuationToken;
use N1ebieski\KSEFClient\ValueObjects\Requests\ReferenceNumber;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\DateClosedFrom;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\DateClosedTo;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\DateCreatedFrom;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\DateCreatedTo;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\DateModifiedFrom;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\DateModifiedTo;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\PageSize;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\SessionStatus;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\SessionType;

final class ListRequest extends AbstractRequest implements ParametersInterface, HeadersInterface
{
    /**
     * @param Optional|array<int, SessionStatus> $statuses
     */
    public function __construct(
        public readonly SessionType $sessionType,
        public readonly Optional | ReferenceNumber $referenceNumber = new Optional(),
        public readonly Optional | DateCreatedFrom $dateCreatedFrom = new Optional(),
        public readonly Optional | DateCreatedTo $dateCreatedTo = new Optional(),
        public readonly Optional | DateClosedFrom $dateClosedFrom = new Optional(),
        public readonly Optional | DateClosedTo $dateClosedTo = new Optional(),
        public readonly Optional | DateModifiedFrom $dateModifiedFrom = new Optional(),
        public readonly Optional | DateModifiedTo $dateModifiedTo = new Optional(),
        public readonly Optional | array $statuses = new Optional(),
        public readonly Optional | PageSize $pageSize = new Optional(),
        public readonly Optional | ContinuationToken $continuationToken = new Optional(),
    ) {
    }

    public function toParameters(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray(only: ['pageSize', 'sessionType', 'referenceNumber', 'dateCreatedFrom', 'dateCreatedTo', 'dateClosedFrom', 'dateClosedTo', 'dateModifiedFrom', 'dateModifiedTo', 'statuses']);
    }

    public function toHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            ...($this->continuationToken instanceof ContinuationToken ? [
                'x-continuation-token' => $this->continuationToken->value
            ] : [])
        ];
    }
}
