# Google reCAPTCHA (Login and Sign up)

reCAPTCHA protects the **Login** and **Sign up** forms against bots and
password guessing. It is controlled from **Admin → Settings → Users → Google
reCAPTCHA**.

| Setting | Meaning |
|---|---|
| Require reCAPTCHA | **OFF**: no widget, no Google script, no verification. **ON**: the check is shown on both forms and verified on the server. |
| Type | `v2` "I'm not a robot" checkbox, or `v3` invisible (score based). Must match the key type created at Google. |
| Minimum score | v3 only: requests scoring below it are refused (0.1 lenient – 0.9 strict, default 0.5). |
| Site key | Public key rendered in the page. |
| Secret key | Stored **encrypted** with `APP_KEY`, never shown again and never sent to browsers. Leave empty to keep the saved one. |

Keys can also be set in `.env` (`RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY`);
they then override the admin fields. reCAPTCHA can only be switched ON when
both keys are present, and a configuration without keys behaves as OFF, so a
mistake can never lock every user out.

## Create the keys

1. Open <https://www.google.com/recaptcha/admin> and create a site.
2. Choose **reCAPTCHA v2 → "I'm not a robot" Checkbox** (or **v3**).
3. Add your domain (for example `example.com`; add `www.example.com` too if used).
4. Copy the site key and secret key into Admin → Settings → Users, pick the
   same type, switch **Require reCAPTCHA** ON and save.

## How it is verified

- The browser sends the token as `g-recaptcha-response` with the form.
- The server calls `https://www.google.com/recaptcha/api/siteverify` with the
  secret key, the token and the visitor's IP **before** the password is checked
  or an account is created (CSRF is checked first, as for every form).
- It is refused when the token is missing, invalid, expired or already used,
  when a v3 score is below the minimum or the v3 action is not `login` /
  `register`, and when Google cannot be reached (fail closed). The user sees a
  clear inline error and nothing is authenticated or created.
- While reCAPTCHA is ON, only the Login and Sign up pages allow Google's script
  and frames in their Content-Security-Policy; every other page keeps the
  strict default policy.
- **Sign in with Google** (OAuth) does not use reCAPTCHA: Google already
  verified the person, and the OAuth state / nonce / PKCE checks are unchanged.
