<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Str;

class RefreshTokenService
{
    /**
     * Issue an access token (Sanctum) + a rotating refresh token for the user.
     *
     * @return array{user: User, access_token: string, refresh_token: string}
     */
    public function issuePair(User $user, string $device = 'mobile'): array
    {
        $access = $user->createToken($device);

        $refresh = $this->issueRefreshToken($user, $access->accessToken->getKey());

        return [
            'user'          => $user,
            'access_token'  => $access->plainTextToken,
            'refresh_token' => $refresh,
        ];
    }

    /**
     * Validate a refresh token and rotate it: revoke the old refresh token and its
     * access token, then issue a fresh pair. Returns null if invalid/expired/revoked.
     *
     * @return array{user: User, access_token: string, refresh_token: string}|null
     */
    public function rotate(string $plainToken, string $device = 'mobile'): ?array
    {
        $record = RefreshToken::where('token', $this->hash($plainToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $record || ! ($user = $record->user)) {
            return null;
        }

        $record->update(['revoked_at' => now()]);

        if ($record->access_token_id) {
            $user->tokens()->whereKey($record->access_token_id)->delete();
        }

        return $this->issuePair($user, $device);
    }

    /**
     * Revoke any refresh tokens tied to a given Sanctum access token (used on logout).
     */
    public function revokeForAccessToken(int|string $accessTokenId): void
    {
        RefreshToken::where('access_token_id', $accessTokenId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function issueRefreshToken(User $user, int|string $accessTokenId): string
    {
        $plain = Str::random(64);

        RefreshToken::create([
            'user_id'         => $user->id,
            'token'           => $this->hash($plain),
            'access_token_id' => $accessTokenId,
            'expires_at'      => now()->addDays((int) config('tokens.refresh_days', 7)),
        ]);

        return $plain;
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
