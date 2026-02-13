=== LLM OAuth Connector ===
Contributors: miniOrange
Donate link: https://plugins.miniorange.com
Requires at least: 5.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: Expat
License URI: https://plugins.miniorange.com/mit-license

A lightweight, standalone OAuth 2.0/2.1 Authorization Code Grant server plugin for WordPress with PKCE support, designed for LLMs like Cursor and ChatGPT.

## Installation

1. Upload the `llm-oauth-connector` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Settings → LLM OAuth Connector** to view your credentials

## How It Works

### The OAuth Flow

1. **Authorization Request**
   - LLM redirects you to the Authorization URL
   - You must be logged into WordPress (or will be prompted to log in)

2. **User Approval**
   - You see a clean authorization screen
   - Click "Authorize Access" to grant permission

3. **Authorization Code**
   - WordPress generates a one-time authorization code
   - You're redirected back to LLM with the code

4. **Token Exchange**
   - LLM exchanges the authorization code for an access token
   - The code is single-use and expires after 5 minutes

5. **API Access**
   - LLM uses the access token in the `Authorization: Bearer <token>` header
   - The token is valid for 24 hours
   - A refresh token is also provided (valid for 30 days)

### Security Features

- **Client Credentials Validation** - Only registered clients can request tokens
- **PKCE (S256)** - Optional code_challenge/code_verifier; when used, client_secret is not required at token endpoint
- **One-Time Authorization Codes** - Codes are deleted after use
- **Time-Limited Tokens** - Access tokens expire after 24 hours; refresh tokens after 30 days
- **Secure Token Storage** - Uses WordPress transients
- **Nonce Protection** - CSRF protection on authorization form (`llm_oauth_approve`)

## Endpoints

### Discovery Endpoint (OAuth 2.0 Authorization Server Metadata)
```
GET /.well-known/oauth-authorization-server
```

Returns JSON with `issuer`, `authorization_endpoint`, `token_endpoint`, `response_types_supported`, `grant_types_supported`, `code_challenge_methods_supported` (S256), `token_endpoint_auth_methods_supported` (none, client_secret_post), `scopes_supported` (basic), etc. Clients can use this to auto-configure.

### Authorization Endpoint
```
GET /llm-oauth/authorize?client_id={client_id}&redirect_uri={redirect_uri}&response_type=code
```

(Query-string form `/?llm_oauth=authorize&...` also works.)

**Parameters:**
- `client_id` (required) - Your OAuth client ID
- `redirect_uri` (required) - Where to redirect after authorization
- `response_type` (required) - Must be `code`
- `state` (optional) - State parameter for CSRF protection
- `code_challenge` (optional) - PKCE: base64url(SHA256(code_verifier))
- `code_challenge_method` (optional) - Must be `S256` when using PKCE

### Token Endpoint
```
POST /wp-json/llm-oauth/v1/token
```

**Authorization code exchange (form-data or JSON):**
- `grant_type` (required) - `authorization_code`
- `code` (required) - The one-time authorization code
- `client_id` (required) - Your OAuth client ID
- `redirect_uri` (required) - Must match the redirect_uri used in the authorization request
- `client_secret` (required if no PKCE) - Your OAuth client secret
- `code_verifier` (required if PKCE was used) - The code verifier for the code_challenge sent at authorize

**Refresh token exchange:**
- `grant_type` (required) - `refresh_token`
- `refresh_token` (required) - Valid refresh token from a previous token response
- `client_id` (required) - Your OAuth client ID

**Response:**
```json
{
  "access_token": "your_access_token",
  "token_type": "Bearer",
  "expires_in": 86400,
  "refresh_token": "your_refresh_token",
  "scope": "basic"
}
```

## Troubleshooting

### 404 on Authorization or Discovery URL
- The plugin adds rewrite rules on activation. If `/llm-oauth/authorize` or `/.well-known/oauth-authorization-server` returns 404, go to **Settings → Permalinks** and click **Save Changes** to refresh rewrite rules.

### "Invalid Client ID" Error
- Make sure you're using the correct Client ID from Settings → LLM OAuth Connector
- The Client ID is generated on plugin activation

### "Authorization code is invalid or expired"
- Authorization codes expire after 5 minutes
- Codes can only be used once
- Request a new authorization code

### Token Not Working
- Check that you're sending the token in the `Authorization: Bearer <token>` header
- Verify the token hasn't expired (24 hours)
- Make sure the token format is correct (no extra spaces)

### Redirect URI Mismatch
- Ensure the `redirect_uri` in the token request matches the one used in authorization
- The redirect URI must be exactly the same

## Credentials

Client ID and Client Secret are generated automatically on first plugin activation and stored in WordPress options. They are shown on **Settings → LLM OAuth Connector**. There is no in-plugin “Regenerate” action; to change credentials you would need to update those options (e.g. via code or another plugin). Changing them invalidates existing tokens and authorization codes.

## Technical Details

### Token Storage
- Uses WordPress transients API
- Access tokens stored with 24-hour expiration
- Refresh tokens stored with 30-day expiration
- Authorization codes stored with 5-minute expiration

### User Authentication
- Uses WordPress's built-in user authentication
- Bearer tokens are validated via `determine_current_user` filter
- Works alongside WordPress cookie authentication

### Compatibility
- WordPress 5.0+
- PHP 7.0+ (uses `random_bytes`, REST API)
- Requires REST API (enabled by default in WordPress)

### Activation / Permalinks

On activation the plugin registers rewrite rules for `/llm-oauth/authorize` and `/.well-known/oauth-authorization-server` and flushes rewrite rules so these URLs work immediately. If the authorization URL returns 404, go to **Settings → Permalinks** and click **Save Changes** to refresh rewrite rules.

## License

Expat (MIT). See License URI in the plugin header.

## Support

For issues or questions:
1. Check the Troubleshooting section above
2. Verify your WordPress REST API is accessible
3. Check WordPress debug logs for detailed error messages
