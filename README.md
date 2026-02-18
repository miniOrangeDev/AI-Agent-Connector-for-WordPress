=== AI Agent Connector for WordPress ===
Contributors: miniOrange
Donate link: https://plugins.miniorange.com
Requires at least: 5.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: Expat
License URI: https://plugins.miniorange.com/mit-license

Turn your WordPress site into a secure OAuth 2.0 server so AI assistants like ChatGPT and Cursor can connect with user consent. Lightweight, standards-based (Authorization Code + PKCE), and ready in minutes.

## Documentation

**Step-by-step setup with ChatGPT and MCP Adapter**

Get your WordPress site talking to ChatGPT (or other LLMs) with our illustrated guide:

→ [Connect ChatGPT to WordPress Using MCP Adapter and LLM OAuth Connector](https://plugins.miniorange.com/wordpress-chatgpt-integration)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/` (or install via **Plugins → Add New → Upload**).
2. Activate the plugin from the **Plugins** menu.
3. Open **Settings → AI Agent Connector for WordPress** to view your Client ID and Client Secret.

Your credentials are generated automatically on first activation—no signup or external service required.

## How It Works

### The OAuth flow (user perspective)

1. **Authorization request** — The AI app sends you to your site’s authorization URL. If you’re not logged in, WordPress prompts you to sign in first.

2. **Consent** — You see a simple “Authorize AI Agent Access” screen. One click grants access.

3. **Redirect with code** — Your site issues a one-time authorization code and sends you back to the AI app.

4. **Token exchange** — The app exchanges the code for an access token (and optional refresh token). The code is invalidated immediately and expires in 5 minutes.

5. **Authenticated API access** — The app calls your WordPress REST API using `Authorization: Bearer <token>`. Access tokens last 24 hours; refresh tokens last 30 days.

### Security at a glance

* **Client credentials** — Only the client registered in your settings can obtain tokens.
* **PKCE (S256)** — Optional; when used, the client can skip sending the client secret at the token endpoint (ideal for public clients like ChatGPT).
* **Single-use codes** — Authorization codes are deleted after use and expire in 5 minutes.
* **Short-lived tokens** — Access tokens: 24 hours. Refresh tokens: 30 days.
* **Secure storage** — Tokens and codes are stored via WordPress transients.
* **CSRF protection** — Authorization form is protected with a nonce (`llm_oauth_approve`).

## API Endpoints

### Discovery (OAuth 2.0 Authorization Server Metadata)

`GET /.well-known/oauth-authorization-server`

Returns JSON with `issuer`, `authorization_endpoint`, `token_endpoint`, supported response types, grant types, PKCE method (S256), token endpoint auth methods (none, client_secret_post), and `scopes_supported` (basic). Clients can use this to auto-configure.

### Authorization

`GET /llm-oauth/authorize?client_id={client_id}&redirect_uri={redirect_uri}&response_type=code`

Alternative: `/?llm_oauth=authorize&...` (query-string form).

* `client_id` (required) — Your OAuth client ID from plugin settings.
* `redirect_uri` (required) — Callback URL after authorization.
* `response_type` (required) — Must be `code`.
* `state` (optional) — State parameter for CSRF protection.
* `code_challenge` (optional) — PKCE: base64url(SHA256(code_verifier)).
* `code_challenge_method` (optional) — Must be `S256` when using PKCE.

### Token

`POST /wp-json/llm-oauth/v1/token`

**Authorization code exchange (form-data or JSON):**

* `grant_type` (required) — `authorization_code`
* `code` (required) — One-time authorization code
* `client_id` (required) — Your OAuth client ID
* `redirect_uri` (required) — Must match the redirect_uri used in the authorization request
* `client_secret` (required if no PKCE) — Your OAuth client secret
* `code_verifier` (required if PKCE was used) — Code verifier for the code_challenge sent at authorize

**Refresh token exchange:**

* `grant_type` (required) — `refresh_token`
* `refresh_token` (required) — Valid refresh token from a previous token response
* `client_id` (required) — Your OAuth client ID

**Example response:**

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

### 404 on authorization or discovery URL

The plugin registers rewrite rules on activation. If `/llm-oauth/authorize` or `/.well-known/oauth-authorization-server` returns 404, go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.

### "Invalid Client ID"

Use the exact Client ID from **Settings → AI Agent Connector for WordPress**. It is generated when you first activate the plugin.

### "Authorization code is invalid or expired"

Codes expire after 5 minutes and can be used only once. Complete the token exchange immediately or start a new authorization flow.

### Token not working

* Send the token in the header: `Authorization: Bearer <token>`.
* Confirm the token has not expired (24-hour lifetime).
* Ensure there are no extra spaces or invalid characters in the token.

### Redirect URI mismatch

The `redirect_uri` in the token request must match the one used in the authorization request exactly (including trailing slashes if applicable).

## Credentials

Client ID and Client Secret are created automatically on first activation and stored in WordPress options. They appear on **Settings → AI Agent Connector for WordPress**. There is no in-plugin “Regenerate” button; to change them you must update the options (e.g. via code or another plugin). Changing credentials invalidates all existing tokens and authorization codes.

## Technical Details

### Token and code storage

* WordPress transients API.
* Access tokens: 24-hour expiration.
* Refresh tokens: 30-day expiration.
* Authorization codes: 5-minute expiration.

### Authentication

* Authorization screen and login use WordPress’s built-in user system.
* Bearer tokens are validated via the `determine_current_user` filter and set the current user for REST requests.
* Works alongside normal WordPress cookie authentication.

### Compatibility

* WordPress 5.0+
* PHP 7.4+ (uses `random_bytes`, REST API)
* Requires the WordPress REST API (enabled by default)

### Permalinks

On activation, the plugin adds rewrite rules for `/llm-oauth/authorize` and `/.well-known/oauth-authorization-server` and flushes rules. If you still see 404s, visit **Settings → Permalinks** and click **Save Changes**.

## License

Expat (MIT). See License URI in the plugin header.

## Support

1. Follow the [setup guide](https://plugins.miniorange.com/wordpress-chatgpt-integration) for ChatGPT and MCP Adapter.
2. Use the Troubleshooting section above.
3. Confirm your WordPress REST API is reachable.
4. Check WordPress debug logs for detailed errors.
