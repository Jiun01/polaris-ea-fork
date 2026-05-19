# Authentication Codemap

**Last Updated:** 2026-05-20
**Entry Points:** BearerAuthHook::validate(), MY_Controller::from_bearer()

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Incoming HTTP Request                                      │
├─────────────────────────────────────────────────────────────┤
│  ↓                                                          │
│  CI3 pre_controller hook fires                              │
│  ↓                                                          │
│  BearerAuthHook::validate()                                 │
│  ├─ If NOT /api/v1/* path → return (skip auth)            │
│  ├─ extract_bearer_token()                                 │
│  │  ├─ Read Authorization header                           │
│  │  ├─ Parse "Bearer <token>"                              │
│  │  └─ Return token string or null                         │
│  ├─ If token is null → respond_401('token_missing')        │
│  ├─ MY_Controller::from_bearer(token)                      │
│  │  ├─ Read polaris_jwt_secret from config                 │
│  │  ├─ JWT::decode(token, secret, 'HS256')                │
│  │  ├─ On ExpiredException → throw RuntimeException        │
│  │  ├─ On decode error → throw RuntimeException            │
│  │  ├─ Validate iss == 'polaris'                           │
│  │  ├─ Validate aud (== 'ea-fork' or array member)         │
│  │  ├─ Extract tenant_id claim                             │
│  │  ├─ UUID format check                                   │
│  │  ├─ MY_Controller::set_tenant_context(tenant_id)        │
│  │  └─ Return (context now set)                            │
│  ├─ On RuntimeException → respond_401(error code)          │
│  └─ Proceed to route handler                               │
│                                                             │
│  ↓                                                          │
│  Controller action executes with MY_Controller::$tenant_id │
│  set; all MY_Model queries auto-scoped to this tenant      │
│                                                             │
│  ↓                                                          │
│  Response sent                                              │
└─────────────────────────────────────────────────────────────┘
```

## Key Modules

| Module | File | Purpose |
|--------|------|---------|
| **BearerAuthHook** | `application/hooks/BearerAuthHook.php` | CI3 pre_controller hook; validates Bearer token on /api/v1/* routes |
| **MY_Controller** | `application/core/MY_Controller.php` | Static tenant context holder + JWT decoder |
| **JWT Library** | `vendor/firebase/php-jwt` | HS256 decode + claim extraction |

## Token Validation Flow

### JWT Decode Process

```
Token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJwb2xhcmlzIiwi...

↓ MY_Controller::from_bearer(token)

1. Read config['polaris_jwt_secret'] from environment
   └─ getenv('POLARIS_EA_JWT_SECRET')
   └─ Min 32 bytes entropy (32+ char string)

2. Call JWT::decode(token, new Key(secret, 'HS256'))
   ├─ Verify signature (HS256 = HMAC-SHA256)
   ├─ Decode Base64 header + payload
   ├─ Extract all claims
   └─ Throws ExpiredException if exp < time() (with 10 s leeway)
   └─ Throws other exceptions on malformed token

3. Validate issuer claim
   ├─ Read config['polaris_jwt_issuer'] (default: 'polaris')
   ├─ Assert decoded->iss == issuer
   └─ Fail if mismatch

4. Validate audience claim
   ├─ Read config['polaris_jwt_audience'] (default: 'ea-fork')
   ├─ Assert decoded->aud == audience
   ├─ OR decoded->aud is array AND audience ∈ array
   └─ Fail if mismatch

5. Validate tenant_id claim
   ├─ Extract decoded->tenant_id
   ├─ Must not be empty string
   ├─ Must match UUID regex: ^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$
   └─ Fail if invalid

6. Optional: Validate tenant_branch_id claim
   ├─ If present, extract decoded->tenant_branch_id
   ├─ Must match UUID regex (same as tenant_id)
   ├─ Fail if present but malformed
   └─ If absent or valid, store it (may be null)

7. Set context
   └─ MY_Controller::set_tenant_context(tenant_id, branch_id)
   └─ MY_Controller::$tenant_id = tenant_id
   └─ MY_Controller::$tenant_branch_id = branch_id

Success → request proceeds with context set
```

### Error Responses

BearerAuthHook::respond_401() returns HTTP 401 with JSON:

```json
{
  "message": "<error_code>"
}
```

Error codes (safe to return to client; do not leak server internals):

| Code | Meaning | Action |
|------|---------|--------|
| **token_missing** | No Authorization header or invalid format | Client must provide Bearer token |
| **token_expired** | JWT exp claim < now (+ 10 s leeway) | Client must refresh/reissue token |
| **token_invalid** | Signature, issuer, audience, tenant_id format, or claim mismatch | Client must verify token and secret |

## Configuration

### Runtime Config (`application/config/polaris.php`)

```php
// Shared secret (HS256 key)
$config['polaris_jwt_secret']   = getenv('POLARIS_EA_JWT_SECRET') ?: '';

// Optional: issuer claim value (default 'polaris')
$config['polaris_jwt_issuer']   = 'polaris';

// Optional: audience claim value (default 'ea-fork')
$config['polaris_jwt_audience'] = 'ea-fork';
```

### Hook Registration (`application/config/hooks.php`)

```php
$hook['pre_controller'] = [
    'class'    => 'BearerAuthHook',
    'function' => 'validate',
    'filename' => 'BearerAuthHook.php',
    'filepath' => 'hooks',
];
```

## Clock Skew Tolerance

The library sets `JWT::$leeway = 10` seconds. This allows tokens issued by Polaris (which may have a slightly ahead clock) to be accepted by EA (which may have a slightly behind clock). Without leeway, clock drift > 1 second would cause valid tokens to be rejected.

```php
// Allow up to 10 s of clock skew between Polaris and EA hosts.
\Firebase\JWT\JWT::$leeway = 10;
```

## Token Lifetime Best Practices

**Tokens are validated but NOT revoked server-side.** Token revocation must be handled at the Polaris end by not issuing tokens for suspended tenants or by shortening token TTL.

Recommended token lifetime: **15 minutes** (refreshed by Polaris as needed). If a token is issued for tenant-1 and then tenant-1 is suspended, the token will remain valid until expiry. Polaris must:

1. Stop issuing new tokens for suspended tenants
2. Instruct clients to refresh their token (which will fail if tenant is suspended)
3. Rely on short TTL (15 min) for token expiry

## Related Codemaps

- **multi_tenancy.md** — Tenant context storage (MY_Controller statics)
- **api_surface.md** — Controllers that depend on auth context
- **docker_build.md** — Secret handling in multi-stage build
