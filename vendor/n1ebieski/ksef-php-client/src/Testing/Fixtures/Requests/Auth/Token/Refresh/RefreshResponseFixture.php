<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Auth\Token\Refresh;

use DateTimeImmutable;
use DateTimeInterface;
use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;
use N1ebieski\KSEFClient\ValueObjects\AccessToken;

final class RefreshResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'accessToken' => [
            'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ0b2tlbi10eXBlIjoiQ29udGV4dFRva2VuIiwiY29udGV4dC1pZGVudGlmaWVyLXR5cGUiOiJOaXAiLCJjb250ZXh0LWlkZW50aWZpZXItdmFsdWUiOiIzNzU2OTc3MDQ5IiwiYXV0aGVudGljYXRpb24tbWV0aG9kIjoiUXVhbGlmaWVkU2VhbCIsInN1YmplY3QtZGV0YWlscyI6IntcIlN1YmplY3RJZGVudGlmaWVyXCI6e1wiVHlwZVwiOlwiTmlwXCIsXCJWYWx1ZVwiOlwiMzc1Njk3NzA0OVwifX0iLCJleHAiOjE3NDcyMjAxNDksImlhdCI6MTc0NzIxOTI0OSwiaXNzIjoia3NlZi1hcGktdGkiLCJhdWQiOiJrc2VmLWFwaS10aSJ9.R_3_R2PbdCk8T4WP_0XGOO1iVNu2ugNxmkDvsD0soIE',
            'validUntil' => '2023-01-01T00:00:00+00:00',
        ]
    ];

    public function withValidUntil(DateTimeInterface $validUntil): self
    {
        $this->data['accessToken']['validUntil'] = $validUntil->format('Y-m-d\TH:i:sP');

        return $this;
    }

    public function getAccessToken(): AccessToken
    {
        return AccessToken::from(
            $this->data['accessToken']['token'],
            new DateTimeImmutable($this->data['accessToken']['validUntil'])
        );
    }
}
