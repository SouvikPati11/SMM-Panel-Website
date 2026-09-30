# Sign in with Google

Customers can register and sign in with **Continue with Google** on the login
and registration pages. It uses Google's OAuth 2.0 / OpenID Connect
authorization-code flow with `state`, `nonce` and PKCE (S256). The client
secret stays on the server.

## 1. Create the OAuth client (Google Cloud Console)

1. Open <https://console.cloud.google.com/> and create or select a project.
2. **APIs & Services → OAuth consent screen**: choose *External*, then enter
   the app name, support email, your domain and the privacy policy URL
   (`https://YOUR-DOMAIN/privacy`). The scopes are `openid`, `email` and
   `profile`, which are the defaults. Publish the app when you are ready,
   because while it is in *Testing* only listed test users can sign in.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**:
   - Application type: **Web application**
   - Authorized JavaScript origins: `https://YOUR-DOMAIN`
   - Authorized redirect URIs: `https://YOUR-DOMAIN/auth/google/callback`
     (Admin → Settings → Users shows the exact value, with a copy button).
4. Copy the **Client ID** and **Client secret**.

## 2. Configure the panel. Choose one of these:

**A. Admin panel (simplest).** Admin → Settings → Users → *Sign in with Google*:
paste the Client ID and Client secret, switch on *Show "Continue with Google"*,
then Save. The secret is encrypted with your `APP_KEY` and is never shown again.

**B. `.env` on Hostinger.** Open hPanel → *File Manager* → `public_html/.env`
(or your deploy directory) and add:

```
GOOGLE_CLIENT_ID=1234567890-abc.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-...
# only if the callback URL is not APP_URL + /auth/google/callback:
GOOGLE_REDIRECT_URI=https://YOUR-DOMAIN/auth/google/callback
```

`.env` is ignored by Git, so a Git deployment never overwrites or publishes
it. Values in `.env` take precedence over the admin fields. You still need to
switch the button on in Admin → Settings → Users.

`APP_URL` must be the exact public `https://` URL (no trailing slash). Otherwise
Google rejects the request with `redirect_uri_mismatch`.

## 3. The one manual test

Google can't be reached from automated tests, so after configuring:

1. Open `/login` in a private window and click **Continue with Google**.
2. Choose a Google account. A new address shows *One last step* (choose a
   username, plus a mobile number if your site asks for one, and accept the
   terms). You then land on the dashboard.
3. Sign out and sign in with Google again. You go straight in.

If Google shows an error page, the redirect URI or consent screen is wrong.
If the panel says *misconfigured (redirect_uri_mismatch / invalid_client)*,
re-check the client ID/secret and the redirect URI. Details are logged in
`storage/logs/auth-*.log`.

## How accounts are matched

| Situation | Result |
|---|---|
| Google account already connected | Signed in (2FA still asked if enabled; suspended accounts refused). |
| No connection, but an account with the same email whose email is **verified** | Google is connected to that account and the user is signed in. |
| Same email, but the account's email is **not verified** | Refused: *sign in with your password, then connect Google in Account → Security*. This prevents someone pre-registering your email to take over your Google sign-in. |
| New email | *One last step* form: username, mobile number (per Settings → Users) and terms. |
| Google says the email is not verified | Refused. |
| Registration closed | New Google users can't create accounts. Existing ones can still sign in. |

- **Email verification:** Google has verified the address, so accounts created
  with Google are marked verified and are never blocked by the email
  verification setting.
- **Mobile number:** asked for on the *One last step* form exactly as on the
  registration form (off / required / optional).
- **Passwords:** Google-created accounts have no password until the user sets
  one in Account → Security. Google can be disconnected only after that.
- **Linking:** signed-in users can connect or disconnect Google in Account →
  Security. One Google identity can belong to one account only.
- **Logout** ends the panel session. The panel stores no Google tokens.
