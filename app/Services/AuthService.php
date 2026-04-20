<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData($deviceInfo = []): array
    {
        // For backwards compatibility, if it's a string, treat it as deviceName
        if (is_string($deviceInfo)) {
            $deviceInfo = ['device_name' => $deviceInfo];
        }

        $deviceName = $deviceInfo['device_name'] ?? 'unknown';
        $isApp = $deviceInfo['is_app'] ?? false;
        $deviceId = $deviceInfo['device_id'] ?? null;
        $deviceType = $deviceInfo['device_type'] ?? null;

        // Deduplication: if app device and device_id exists, revoke old token
        if ($isApp && $deviceId) {
            $this->user->tokens()
                ->where('is_app', true)
                ->where('device_id', $deviceId)
                ->delete();
        }

        // Create a new Sanctum token with device info
        $token = $this->user->createToken(
            $deviceName, // device identifier: android, ios, windows, macos, web, etc.
            ['*'], // abilities
            now()->addYear() // expiration
        );

        // Save custom metadata
        $tokenModel = $token->accessToken;
        $tokenModel->is_app = $isApp;
        $tokenModel->device_id = $deviceId;
        $tokenModel->device_type = $deviceType;
        $tokenModel->save();

        // Format token: remove ID prefix and add Bearer
        $tokenParts = explode('|', $token->plainTextToken);
        $formattedToken = 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);

        return [
            'token' => $this->user->token,
            'auth_data' => $formattedToken,
            'is_admin' => $this->user->is_admin,
            'session_id' => $tokenModel->id,
        ];
    }

    public static function canConnectVpn(User $user, $currentTokenId): bool
    {
        // Get all app tokens ordered by creation time
        $tokens = $user->tokens()->where('is_app', true)->orderBy('created_at', 'asc')->get();
        $rank = 1;
        foreach ($tokens as $t) {
            if ($t->id == $currentTokenId) {
                // Return true if within the allowed device limit
                return $rank <= max(1, (int)$user->device_limit);
            }
            $rank++;
        }
        // If not an app token, no app-level restriction
        return true; 
    }

    public function getSessions(): array
    {
        return $this->user->tokens()->get()->toArray();
    }

    public function removeSession(string $sessionId): bool
    {
        $this->user->tokens()->where('id', $sessionId)->delete();
        return true;
    }

    public function removeAllSessions(): bool
    {
        $this->user->tokens()->delete();
        return true;
    }

    public static function findUserByBearerToken(string $bearerToken): ?User
    {
        $token = str_replace('Bearer ', '', $bearerToken);
        
        $accessToken = PersonalAccessToken::findToken($token);
        
        $tokenable = $accessToken?->tokenable;
        
        return $tokenable instanceof User ? $tokenable : null;
    }

    /**
     * 解密认证数据
     *
     * @param string $authorization
     * @return array|null 用户数据或null
     */
    public static function decryptAuthData(string $authorization): ?array
    {
        $user = self::findUserByBearerToken($authorization);
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool)$user->is_admin,
            'is_staff' => (bool)$user->is_staff
        ];
    }
}
