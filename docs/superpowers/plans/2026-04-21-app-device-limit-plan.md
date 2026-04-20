# App Device Limit & Session Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform the generic session system into a Hardware Device Management System, imposing app device limits, deduplicating devices, and returning detailed session info.

**Architecture:** We will extend Laravel Sanctum's `personal_access_tokens` table via a migration to hold device metadata. `AuthService` will handle token generation and device deduplication. `UserController` endpoints will be updated to calculate and return `can_connect_vpn` based on the user's `device_limit`, and `getActiveSession` will return structured device lists indicating active/blocked status.

**Tech Stack:** Laravel, PHP, MySQL, Sanctum

---

### Task 1: Database Migration

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_device_fields_to_personal_access_tokens_table.php` (timestamp dynamically generated)

- [ ] **Step 1: Create the migration file**

Run: `php artisan make:migration add_device_fields_to_personal_access_tokens_table --table=personal_access_tokens`
Expected: PASS with "Created Migration"

- [ ] **Step 2: Implement the migration logic**

Modify the generated migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->boolean('is_app')->default(false)->after('abilities');
            $table->string('device_id')->nullable()->after('is_app');
            $table->string('device_type')->nullable()->after('device_id');
            // 'name' column already exists in Sanctum, we will use it for device_name.
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['is_app', 'device_id', 'device_type']);
        });
    }
};
```

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: PASS with "Migrated"

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat: add device metadata fields to personal_access_tokens table"
```

### Task 2: Update AuthService for Device Handling

**Files:**
- Modify: `app/Services/AuthService.php`

- [ ] **Step 1: Modify generateAuthData to handle device parameters**

Update `generateAuthData` to accept an array of device params instead of just a string, and implement deduplication.

```php
    public function generateAuthData(array $deviceInfo = []): array
    {
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

        // Create a new Sanctum token
        $token = $this->user->createToken(
            $deviceName, // Maps to the 'name' column in personal_access_tokens
            ['*'],
            now()->addYear()
        );

        // Save custom metadata
        $tokenModel = $token->accessToken;
        $tokenModel->is_app = $isApp;
        $tokenModel->device_id = $deviceId;
        $tokenModel->device_type = $deviceType;
        $tokenModel->save();

        // Format token
        $tokenParts = explode('|', $token->plainTextToken);
        $formattedToken = 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);

        return [
            'token' => $this->user->token,
            'auth_data' => $formattedToken,
            'is_admin' => $this->user->is_admin,
            'session_id' => $tokenModel->id,
        ];
    }
```

- [ ] **Step 2: Add utility method canConnectVpn**

Add this method to `AuthService.php` to calculate the current token's active status.

```php
    public static function canConnectVpn(User $user, $currentTokenId): bool
    {
        // Get all app tokens ordered by creation time
        $tokens = $user->tokens()->where('is_app', true)->orderBy('created_at', 'asc')->get();
        $rank = 1;
        foreach ($tokens as $t) {
            if ($t->id == $currentTokenId) {
                // If the user device_limit is 0 or null, maybe no limit or no connection. 
                // Default Xboard logic applies later, but here we just check limit.
                // Assuming device_limit > 0.
                return $rank <= max(1, (int)$user->device_limit);
            }
            $rank++;
        }
        // If not an app token, no app-level restriction
        return true; 
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/AuthService.php
git commit -m "feat: update AuthService with device deduplication and limits"
```

### Task 3: Update AuthController to Pass Device Info

**Files:**
- Modify: `app/Http/Controllers/V1/Passport/AuthController.php`

- [ ] **Step 1: Update login and register to pass device arrays**

Find `public function login` and `public function register`. Change the `generateAuthData` call.

For `login`:
```php
        $deviceInfo = [
            'is_app' => $request->boolean('is_app', false),
            'device_id' => $request->input('device_id'),
            'device_name' => $request->input('device_name', 'unknown'),
            'device_type' => $request->input('device_type'),
        ];
        return $this->success($authService->generateAuthData($deviceInfo));
```

Do the same for `register`:
```php
        $deviceInfo = [
            'is_app' => $request->boolean('is_app', false),
            'device_id' => $request->input('device_id'),
            'device_name' => $request->input('device_name', 'unknown'),
            'device_type' => $request->input('device_type'),
        ];
        return $this->success($authService->generateAuthData($deviceInfo));
```

- [ ] **Step 2: Update token2Login**

In `token2Login`, update the call to `$authService->generateAuthData`:
```php
            return response()->json([
                'data' => $authService->generateAuthData([
                    'is_app' => false,
                    'device_name' => 'web'
                ])
            ]);
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/V1/Passport/AuthController.php
git commit -m "feat: pass device info from AuthController to AuthService"
```

### Task 4: Update UserController Session & Limit Logic

**Files:**
- Modify: `app/Http/Controllers/V1/User/UserController.php`

- [ ] **Step 1: Update getActiveSession to return structured data**

Find `getActiveSession`. Update it to structure the tokens and calculate `is_active`.

```php
    public function getActiveSession(Request $request)
    {
        $user = $request->user();
        $tokens = $user->tokens()->orderBy('created_at', 'asc')->get();
        $currentTokenId = $user->currentAccessToken()?->id;
        $deviceLimit = max(1, (int)$user->device_limit);
        
        $appRank = 1;
        $result = [];

        foreach ($tokens as $token) {
            $isApp = (bool)$token->is_app;
            $isActive = true;
            
            if ($isApp) {
                if ($appRank > $deviceLimit) {
                    $isActive = false;
                }
                $appRank++;
            }

            $result[] = [
                'id' => $token->id,
                'is_app' => $isApp,
                'device_id' => $token->device_id,
                'device_name' => $token->name, // Sanctum stores it in name
                'device_type' => $token->device_type,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
                'is_current' => $token->id == $currentTokenId,
                'is_active' => $isActive, // true means allowed, false means over limit
            ];
        }

        return $this->success($result);
    }
```

- [ ] **Step 2: Update info endpoint to return can_connect_vpn**

Find `public function info`. After fetching the `$user` array/object, calculate the flag.

```php
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        
        // Add can_connect_vpn flag
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $user['can_connect_vpn'] = $currentTokenId ? \App\Services\AuthService::canConnectVpn($request->user(), $currentTokenId) : true;

        return $this->success($user);
```

- [ ] **Step 3: Update getSubscribe endpoint to return can_connect_vpn**

Find `public function getSubscribe`. Similar logic.

```php
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        
        // Add can_connect_vpn flag
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $user['can_connect_vpn'] = $currentTokenId ? \App\Services\AuthService::canConnectVpn($request->user(), $currentTokenId) : true;

        $user = HookManager::filter('user.subscribe.response', $user);
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/V1/User/UserController.php
git commit -m "feat: return can_connect_vpn flag and detailed active sessions"
```
