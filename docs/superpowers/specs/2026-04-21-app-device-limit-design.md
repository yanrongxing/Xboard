# App Device Limit & Session Management Design

## 1. Overview
This feature transforms the existing generic session/token system in XBoard into a structured **Hardware Device Management System**. It differentiates between standard Web logins and App logins. App logins are hardware-bound, limited in count based on the user's `device_limit`, and provide detailed device metadata.

## 2. Database Schema Changes
A migration will be created to extend the Laravel Sanctum `personal_access_tokens` table.
New columns:
- `is_app` (boolean, default false): Indicates if the token belongs to an App device.
- `device_id` (string, nullable): Unique hardware identifier.
- `device_name` (string, nullable): Human-readable device name.
- `device_type` (string, nullable): OS or device category (e.g., 'ios', 'android', 'windows', 'macos').

## 3. Login & Authentication Flow
- **Web Login**: Functions normally, creating a token with `is_app = false`.
- **App Login (`/api/v1/passport/auth/login` & `register`)**:
  - The client must include `device_id`, `device_name`, and `device_type` in the request body.
  - **Device Deduplication Check**: Before issuing a new token, the system will check if the user already has an active token with the same `device_id`. If so, the old token will be immediately revoked (deleted).
  - A new Sanctum token is created and the hardware metadata is saved to the token record.

## 4. Frontend Restriction Mechanism (The "Connect" Block)
- In the subscription detail endpoint (`/api/v1/user/getSubscribe` or `info`), a new boolean field `can_connect_vpn` will be injected.
- **Evaluation Logic**:
  1. Retrieve all active App tokens (`is_app = true`) for the current user.
  2. Order them by creation time (ascending).
  3. Find the rank/index of the current token making the request.
  4. If the rank <= `user->device_limit`, `can_connect_vpn` = `true`.
  5. If the rank > `user->device_limit`, `can_connect_vpn` = `false`.
- The frontend will read this flag and enforce the restriction by disabling the VPN connection toggle and prompting the user.

## 5. Session Management APIs
- **`/api/v1/user/getActiveSession`**:
  - Upgraded to return structured device info (Device Name, Type, Last Active, IP, `is_current_device`).
  - Evaluates and returns the `is_over_limit` flag for each device in the list to help the user identify which devices are blocked.
- **`/api/v1/user/removeActiveSession`**:
  - Unchanged in request signature (accepts `session_id`).
  - Kicking a device effectively deletes its token, naturally promoting older restricted devices back into the allowed `device_limit` pool.

## 6. Edge Cases & Considerations
- If a user changes their password, all tokens (Web and App) are revoked as per existing logic.
- If an admin changes a user's `device_limit` in the backend, the evaluation dynamically adjusts on the next App API call.
