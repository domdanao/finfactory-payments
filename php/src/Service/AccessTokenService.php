<?php

declare(strict_types=1);

namespace FinFactory\Payments\Service;

use FinFactory\Payments\ApiObject;

/**
 * POST /oauth/token. The client gets and refreshes tokens by itself; call this only if you want a
 * token for something else.
 */
final class AccessTokenService extends AbstractService
{
    /**
     * @param array<string, mixed> $opts
     * @return ApiObject access_token, token_type, expires_in
     */
    public function create(array $opts = []): ApiObject
    {
        return $this->client->requestAccessToken($opts);
    }
}
