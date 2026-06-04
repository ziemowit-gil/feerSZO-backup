<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Tokens\List;

use N1ebieski\KSEFClient\Contracts\HeadersInterface;
use N1ebieski\KSEFClient\Contracts\ParametersInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Requests\ContinuationToken;
use N1ebieski\KSEFClient\ValueObjects\Requests\PageSize;
use N1ebieski\KSEFClient\ValueObjects\Requests\Tokens\AuthenticationTokenStatus;
use N1ebieski\KSEFClient\ValueObjects\Requests\Tokens\AuthorIdentifier;
use N1ebieski\KSEFClient\ValueObjects\Requests\Tokens\AuthorIdentifierType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Tokens\Description;

final class ListRequest extends AbstractRequest implements ParametersInterface, HeadersInterface
{
    /**
     * @param Optional|array<int, AuthenticationTokenStatus> $status
     */
    public function __construct(
        public readonly Optional | array $status = new Optional(),
        public readonly Optional | Description $description = new Optional(),
        public readonly Optional | AuthorIdentifier $authorIdentifier = new Optional(),
        public readonly Optional | AuthorIdentifierType $authorIdentifierType = new Optional(),
        public readonly Optional | PageSize $pageSize = new Optional(),
        public readonly Optional | ContinuationToken $continuationToken = new Optional(),
    ) {
    }

    public function toParameters(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray(only: ['status', 'description', 'authorIdentifier', 'authorIdentifierType', 'pageSize']);
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
