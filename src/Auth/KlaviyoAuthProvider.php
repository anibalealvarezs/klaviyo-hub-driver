<?php

declare(strict_types=1);

namespace Anibalealvarezs\KlaviyoHubDriver\Auth;

use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;

class KlaviyoAuthProvider extends BaseAuthProvider
{
    public function getAccessToken(): string
    {
        return $this->data['klaviyo_auth']['access_token'] ?? $this->data['klaviyo_api_key'] ?? "";
    }

    public function setAccessToken(string $token): void
    {
        if (!isset($this->data['klaviyo_auth'])) {
            $this->data['klaviyo_auth'] = [];
        }
        $this->data['klaviyo_auth']['access_token'] = $token;
        $this->save();
    }
}
