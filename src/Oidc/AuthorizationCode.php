<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

/**
 * The state bound to a single issued authorization code: the client and
 * redirect it was issued to, the PKCE challenge to be proven at the token
 * endpoint, the requested scope/nonce, and the resolved end-user
 * identity. Single-use and short-lived (see {@see AuthorizationCodeStore}).
 */
final class AuthorizationCode
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
        public readonly string $scope,
        public readonly ?string $nonce,
        public readonly ResolvedIdentity $identity,
        public readonly int $expiresAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => $this->codeChallengeMethod,
            'scope' => $this->scope,
            'nonce' => $this->nonce,
            'identity' => $this->identity->toArray(),
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $identityData = isset($data['identity']) && is_array($data['identity']) ? $data['identity'] : [];

        return new self(
            clientId: isset($data['client_id']) && is_string($data['client_id']) ? $data['client_id'] : '',
            redirectUri: isset($data['redirect_uri']) && is_string($data['redirect_uri']) ? $data['redirect_uri'] : '',
            codeChallenge: isset($data['code_challenge']) && is_string($data['code_challenge']) ? $data['code_challenge'] : '',
            codeChallengeMethod: isset($data['code_challenge_method']) && is_string($data['code_challenge_method']) ? $data['code_challenge_method'] : '',
            scope: isset($data['scope']) && is_string($data['scope']) ? $data['scope'] : '',
            nonce: isset($data['nonce']) && is_string($data['nonce']) && $data['nonce'] !== '' ? $data['nonce'] : null,
            identity: ResolvedIdentity::fromArray($identityData),
            expiresAt: isset($data['expires_at']) ? (int) $data['expires_at'] : 0,
        );
    }
}
