# Security model

Avapeek encrypts photos and comments in the browser, so a **compromised or leaked database, backup or object storage** exposes only ciphertext. It does **not** protect against a **malicious or compromised server operator** who serves modified JavaScript (the app that does the encryption comes from the server). The password itself is never sent to the server: it receives only an auth hash derived from the KEK (see below). This page describes exactly how the scheme works and where it has limits. It was written from the code in `frontend/src/crypto/` and the database migrations; if you find a discrepancy, please report it.

Avapeek has not been independently audited.

## Key hierarchy

```
password + master_key_salt
        |  PBKDF2-SHA256, 600,000 iterations
        v
      KEK (AES-256-GCM key, in the browser only)
        |-- wraps --> user RSA-OAEP private key   (stored as users.encrypted_private_key)
        |-- wraps --> child DEK (one per child)   (encryption_keys.encrypted_dek, wrap_type "kek")
                         |-- wraps --> photo PEK  (child_photo.encrypted_pek + iv_pek)
                                          |-- encrypts --> original photo      (AES-256-GCM)
                                          |-- encrypts --> thumbnail
                                          |-- encrypts --> LQIP placeholder
                                          |-- encrypts --> comments on the photo
```

- **KEK (Key Encryption Key)** is derived in the browser from the password and a random per-user salt (`users.master_key_salt`). It is never sent to the server.
- **Auth hash.** What the server sees instead of the password: `HMAC-SHA256(key = raw KEK bytes, message = "avapeek-auth-v1")` as 64 lowercase hex characters (`frontend/src/crypto/auth-hash.ts`). It is one-way, so the KEK cannot be recovered from it, and guessing the password from it still costs a full PBKDF2 run per guess. Registration, login and every "confirm your password" request send only this hash; the server stores a bcrypt hash of it in `users.password_hash`. Before login the client asks `POST /api/auth/prelogin` for the account's salt (unknown emails get a stable fake salt). The server has no code path that accepts a plaintext password. Moving an existing install to this scheme requires a fresh install: accounts created before it stored a hash of the plaintext password and cannot sign in. Password strength rules are enforced in the browser only, because the server never sees the password.
- **User key pair** (RSA-OAEP 2048, SHA-256). The public key is stored in the clear (`users.public_key`); the private key is stored wrapped by the KEK. It is used to hand a child's DEK to another member.
- **DEK (Data Encryption Key)** is a random AES-256 key per child ("family"). Each member holds a copy wrapped under their own KEK (`wrap_type = kek`). While an invite is being completed, the DEK can briefly be stored wrapped with the invitee's RSA public key (`wrap_type` other than `kek`); the invitee's browser re-wraps it under its own KEK on first login.
- **Tag names** are encrypted with the child's DEK (AES-256-GCM, fresh random IV per tag, stored as `tags.name_encrypted` + `tags.iv_name`). Tags are per child, so a member only receives ciphertext of tags whose child they belong to.
- **PEK (Photo Encryption Key)** is a random AES-256 key per photo. It is wrapped by the child's DEK and stored per child in the `child_photo` pivot (so a photo can be shared with several children, each wrapping the same PEK with its own DEK).
- **Wrap format.** Every AES-GCM key wrap (private key, DEK, PEK) uses a fresh random 12-byte IV per wrap. KEK-wrapped private keys and DEKs are stored as `base64(iv || ciphertext)`; a wrapped DEK is 60 bytes (12-byte IV + 32-byte key + 16-byte tag). Older fixed-IV (48-byte) DEK wraps are not accepted. PEKs are stored as ciphertext with a separate `iv_pek` column.
- Content (photo bytes, thumbnail, LQIP, EXIF JSON, comment text) is encrypted with AES-GCM using the PEK and a fresh random IV per object, stored alongside as `iv_*` columns.

Password change re-wraps the private key and every DEK under the new KEK in the browser; the DEKs and PEKs themselves do not change, so photos stay readable.

## Login protocol

All derivation happens in the browser (`frontend/src/crypto/keychain.ts`, `auth-hash.ts`). The server never receives the password or the KEK.

1. **Salt.** At registration the browser generates 32 random bytes and stores them hex-encoded (64 characters) as `users.master_key_salt`. The salt is not secret.
2. **Pre-login.** The client calls `POST /api/auth/prelogin` with the email and receives `master_key_salt`. For an unknown email the server returns `HMAC-SHA256(key = APP_KEY, message = lowercased trimmed email)` as 64 hex characters, which has the same shape as a real salt and is the same on every request.
3. **Derivation.** `KEK = PBKDF2-SHA256(password, salt, 600,000 iterations)`, an AES-256-GCM key. `auth_hash = HMAC-SHA256(key = raw KEK bytes, message = "avapeek-auth-v1")`, 64 lowercase hex characters. One PBKDF2 run produces both.
4. **Login.** The client sends `{email, auth_hash, remember}` to `POST /api/auth/login`. The server checks `auth_hash` against `users.password_hash`, which is `bcrypt(auth_hash)` (Laravel `Hash::make`, 12 rounds by default, `BCRYPT_ROUNDS`). For an unknown email it still runs a bcrypt check against a dummy hash, so response time does not reveal whether the account exists, and both cases return the same error.
5. **Second factor.** If the account has MFA enabled, the server returns `mfa_required` and a 5-minute, single-use challenge token (an encrypted payload with a nonce kept in the cache) instead of a session. The client completes it at `POST /api/auth/mfa/challenge` with a TOTP code, a recovery code or a passkey assertion. Passkeys and TOTP are only a second factor for the server session; they play no part in deriving or unwrapping keys.
6. **Session.** The server issues a Laravel Sanctum personal access token (`Authorization: Bearer`, stored hashed server-side). With "stay signed in" (the default) the token has no expiry; otherwise it expires after 12 hours. The response also carries the user's `master_key_salt`, `public_key`, `encrypted_private_key` and wrapped DEKs, which the client unwraps with the KEK. Logout deletes the token, and a password change deletes all other tokens.

**Rate limits** (per IP unless noted, from `AppServiceProvider` and `routes/api.php`): login 5 per minute; pre-login 10 per minute; registration 3 per minute; MFA challenge and passkey options 10 per minute; MFA code failures lock the account's code entry after 10 failures in 15 minutes; password-confirmation endpoints 10 per minute per user; authenticated API 60 per minute per user; invite creation 10 per hour; uploads 30 per minute; upload intents 60 per minute.

Honest notes on this design:

- **Enumeration.** Pre-login does not distinguish known from unknown emails without `APP_KEY`, and the login path is timing-equalised. It is not perfect: registration validates `unique:users`, so while registration is open (the first account, or with a valid invite) a request reveals whether an email is taken, throttled to 3 per minute.
- **Online guessing** is limited by the 5-per-minute login throttle, which is keyed by IP only. There is no per-account lockout for the password step (only for MFA codes), so a distributed attacker is not stopped by it.
- **Offline guessing.** Someone with a database copy can test password guesses against either `bcrypt(auth_hash)` or the wrapped private key. Both cost one PBKDF2 run per guess, so bcrypt adds no protection beyond what PBKDF2 already gives; it only stops a database leak from being a ready-made login credential.
- **Transport.** The auth hash is a bearer-equivalent secret for the login step. Use HTTPS.

## Cryptographic parameters

| Item | Value | Source |
| --- | --- | --- |
| Password KDF | PBKDF2-HMAC-SHA256, 600,000 iterations, 32-byte (256-bit) random salt | `keychain.ts` `deriveKEK`, `generateSalt` |
| KEK, DEK, PEK | AES-256-GCM | `keychain.ts` |
| Content encryption (photo, thumbnail, LQIP, EXIF, filename, comments, tag names) | AES-GCM, random **16-byte** IV per object | `encrypt.ts` `generateIV` |
| Key wraps (private key, DEK, PEK) | AES-GCM, random 12-byte IV per wrap | `keychain.ts` |
| User key pair | RSA-OAEP 2048, SHA-256 | `keychain.ts` |

### PBKDF2 policy

600,000 iterations of SHA-256 follows the OWASP password-storage guidance for PBKDF2-HMAC-SHA256 and is a practical cost on phones in the browser. PBKDF2 is not memory-hard, so GPUs and ASICs attack it more cheaply than Argon2id; the strength of the KEK is bounded by the strength of the password.

The iteration count and the algorithm are constants in the client code. They are **not stored per user**, and there is no KDF version field. Changing them today would break every existing login.

*Planned, not implemented:* store a KDF identifier and parameters per user (for example a `kdf_version` column, with the current scheme as version 1), return it from pre-login, and derive with whatever the account specifies. On the next successful login with the old parameters, the client would derive the new KEK, re-wrap the private key and every DEK (the same operation as a password change) and upload the new auth hash in one transaction. The same mechanism would carry a move to Argon2id.

### IV uniqueness

Every AES-GCM encryption draws a fresh IV from `crypto.getRandomValues`; no IV is derived from a counter or reused. Content IVs are 16 bytes (stored as 32 hex characters in `iv_*` columns); key-wrap IVs are 12 bytes.

For AES-GCM with random IVs, NIST SP 800-38D recommends at most 2^32 invocations under one key, to keep the chance of an IV collision negligible. Content is encrypted under per-photo keys (PEK), which encrypt only a handful of objects each (original, thumbnail, LQIP, EXIF, filename and that photo's comments). Tag names are encrypted under the per-child DEK, which still sees only as many encryptions as the family has tags. Every key is therefore many orders of magnitude below the bound.

The standard IV length is 96 bits (12 bytes). Other lengths are allowed by the specification: the IV is run through GHASH to form the initial counter block, and security under the random-IV bound is unchanged. 12 bytes would be the conventional choice; 16 bytes is a historical choice that is safe but unusual, and changing it would need the format versioning below.

### Ciphertext formats and versioning

| Data | Storage |
| --- | --- |
| Photo and thumbnail bytes | AES-GCM ciphertext (with tag) as an object in storage; IV in `photos.iv_original`, `photos.iv_thumbnail` (32 hex characters) |
| LQIP, EXIF, filename, comment body, tag name | base64 ciphertext in `lqip_encrypted`, `exif_encrypted`, `name_encrypted`, `body_encrypted` (comments), `name_encrypted` (tags), with the IV in the matching `iv_lqip`, `iv_exif`, `iv_name`, `iv_body` column |
| PEK | `child_photo.encrypted_pek` (base64, 48 bytes) + `iv_pek` (base64 of 12 bytes) |
| DEK (KEK wrap) | `encryption_keys.encrypted_dek` = `base64(iv ‖ ciphertext)`, 60 bytes |
| DEK (invite, RSA wrap) | `encryption_keys.encrypted_dek` = base64 RSA-OAEP output, `wrap_type` other than `kek` |
| Private key | `users.encrypted_private_key` = `base64(iv ‖ pkcs8 ciphertext)` |

**There is no format version marker.** The formats are identified only by column and length (the client rejects a KEK-wrapped DEK that is not exactly 60 bytes), and the auth hash label `avapeek-auth-v1` is the only versioned string. `encryption_keys.key_version` exists but numbers DEK generations for the planned key rotation; it does not describe a cipher or encoding.

*Planned, not implemented:* add a version to each format so that algorithms, IV length or AAD binding can change without guessing. Options are a leading version byte inside wrapped blobs, and a `crypto_version` column on `photos`, `comments`, `tags` and `users`. Clients would read every version they know and write only the newest, and old data would be migrated lazily or by a background re-encryption job.

## What the server sees

**Never plaintext:** photo and thumbnail bytes, LQIP placeholders, EXIF data (`exif_encrypted`), original filenames (`name_encrypted`, PEK), comment text (`body_encrypted`), the passwords' derived key, DEKs, PEKs, private keys.

**Plaintext metadata (today):**

| Data | Where |
| --- | --- |
| Account email, display name | `users` |
| Child names, slugs, birth dates, avatar colour | `children` |
| Who belongs to which child, their role and relationship label | `child_users` |
| Which photo belongs to which child, who uploaded it, when (`created_at`) | `photos`, `child_photo` |
| `taken_at` timestamp of each photo (used for sorting, filters and the server-side month/day match for `GET /api/memories`, interpreted in the viewer's IANA time zone) | `photos.taken_at` |
| Original size in bytes, pixel width and height, MIME type | `photos` |
| Number and timing of comments, and who wrote them | `comments` |
| Tag metadata (names are encrypted, see above): which child a tag belongs to, how many tags a child has, which photos share a tag, how many tags a photo has, who created a tag and when, and the ciphertext length (approximate name length). The server can no longer read, list, match or filter on tag names; deduplication, suggestions and search run in the browser | `tags`, `photo_tags` |
| Push subscriptions (browser push endpoint URL and its two keys, plaintext because the server must call the endpoint) and per-child mute flags, only when push is enabled | `push_subscriptions`, `notification_preferences` |
| Invite tokens, roles, expiry | `invites` |
| Access patterns: IP addresses and request timing in web server logs | infrastructure |
| Total storage used | `storage_usage` |

Anyone with the database can therefore tell that a family has N photos taken on certain dates, of certain sizes, and read the child names. Tag names are ciphertext, but the tag structure listed above is visible. They cannot see what the photos show.

## Threat model

Protects against:

- a curious or compromised database, backup or object storage (S3 provider, Garage volume): they hold ciphertext and wrapped keys only;
- other users of the same instance reading a family they are not a member of (authorization checks on every photo, comment and key request);
- a passive network observer (use HTTPS).

Does **not** protect against:

- **A malicious or compromised server operator who serves modified JavaScript.** The web app that does the encryption is delivered by the same server. This is the inherent limit of browser-based E2EE: an attacker controlling the server can ship code that exfiltrates keys or passwords. Self-hosting means you are that operator; the risk is mostly relevant to people who use an instance run by someone else.
- **A compromised, shared or unencrypted device.** The derived KEK (exported as JWK) and the bearer token are kept in browser storage: `localStorage` by default ("stay signed in" is on by default), `sessionStorage` if you turn it off. Decrypted images are **not** only held in memory: `src/lib/cache/decrypted-cache.ts` persists decrypted thumbnails (indefinitely) and decrypted originals (LRU, up to 500 MB) as **plaintext blobs in IndexedDB** (`avapeek-decrypted`). This cache is cleared on logout or a 401 response, not when the tab or browser is closed. Malware, a malicious browser extension or someone with disk access can read it. Use full-disk encryption on your devices and **log out on shared devices**.
- **Decrypted cache is namespaced per user, and the share inbox holds raw originals.** The decrypted cache database is now named `avapeek-decrypted-<userId>`, and it is wiped when a different user signs in as well as on logout or 401. When you share photos to Avapeek from the Android gallery (Web Share Target), the service worker stages the **raw, unencrypted original files** in a separate IndexedDB (`avapeek-share-inbox`) until the upload page picks them up. They are deleted on pickup, after at most 1 hour (purged when the service worker activates and whenever the upload page opens), and on logout or 401. If you share and then never open the app, those originals stay on the device for up to an hour. The service worker has no runtime caching: only static build assets are precached, and nothing from `/api` or object storage (`/photos`) is ever stored in Cache Storage.
- **Cross-site scripting.** Because the KEK and token sit in `localStorage`, any XSS bug would compromise keys. The bundled Caddy therefore sends a strict Content-Security-Policy on every response: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; font-src 'self'; connect-src 'self' <storage origin>; worker-src 'self' blob:; manifest-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'`. Only same-origin scripts run: no inline scripts, no `eval`, no third-party origins (the HEIC decoder uses heic-to's CSP-safe build). This greatly reduces the risk but is not a complete defence: script that is already running could still leak data, for example by navigating the page. The storage origin is derived by the backend from its S3 settings at startup (`php artisan storage:csp-origin`) and shared with Caddy through a volume, so no domain is configured by hand; with the bundled Garage it is your own origin. Caddy also sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` and a `Permissions-Policy` that turns off camera, microphone, geolocation, payment, USB and Topics. `upgrade-insecure-requests` is not set because TLS is terminated outside the stack and plain-HTTP local installs must keep working; enable HSTS at your TLS proxy.
- **Weak passwords.** The KEK strength equals password strength. PBKDF2 slows guessing but does not stop it; anyone who obtains the database (wrapped keys + salt) can attack passwords offline.
- **Push services see metadata.** With notifications enabled, Google, Apple, Mozilla or Microsoft (whichever runs the browser's push service) learn that a given browser received a message of a given size at a given time, which leaks "family activity". Payloads are encrypted (RFC 8291) and contain only `{type, url}`: never names, photo ids, counts or comment text, and the service worker chooses the generic title ("New photos in Avapeek"). The subscription endpoint and keys let anyone who also holds the VAPID private key push to that browser, so they are hidden from API responses and never logged (only a hash). Users opt in per device and can mute per child and type. The server fetches user-supplied endpoint URLs, so they are SSRF-checked (https, port 443, no IP literals, no private/loopback/link-local resolution, checked again before every send, DNS pinned, no redirects).
- **Metadata analysis**, per the table above.
- Denial of service, deletion or tampering by the operator. AES-GCM detects modified ciphertext, but a server can delete data.

## Known limitations

- **Invites trust a server-supplied public key.** When you invite someone, your browser wraps the child's DEK with the invitee's RSA public key as returned by the server. There is no fingerprint or out-of-band verification, so a malicious server can substitute its own key and receive the DEK without modifying any JavaScript.
- **No binding of ciphertext to its photo.** AES-GCM is used without additional authenticated data (AAD) tying a ciphertext to its photo id, so the server could swap blobs between photos and the client would not detect it.
- **No key rotation when a member is removed.** Removing a member deletes their wrapped key copies from the server, but the DEK does not change. Anyone who already downloaded ciphertext and had decrypted or cached the keys keeps the ability to read those photos, and the DEK still protects future uploads they could theoretically obtain ciphertext of. A forward-secrecy design (new DEK, re-wrap remaining PEKs client-side) is planned but not implemented. Treat removal as revoking access to the app, not as cryptographic erasure.
- **Leaving a family does not rotate the key either.** A member who leaves, and a member who is removed, lose their wrapped key copies on the server and their access in the app, but the DEK is unchanged. Anyone who already held the DEK or downloaded ciphertext can still decrypt what they have. Key rotation is planned (see [roadmap.md](roadmap.md)) and is not part of role management.
- Several metadata fields are plaintext (see above). Tag names are encrypted, but tag structure (which photos share a tag, tags per photo, ciphertext length) is not hidden. The server cannot see names, so it cannot prevent two members racing to create the same tag; the client merges same-named tags into one in the UI. Tags are not re-encrypted when a DEK rotates (part of the planned forward-secrecy work).
- PBKDF2 rather than a memory-hard function (the source notes an Argon2id upgrade as a possibility).
- Presigned download URLs are short-lived bearer links; anyone holding one can fetch that ciphertext until it expires. The ciphertext is useless without the key.
- Presigned upload (PUT) URLs are short-lived (15 minute) bearer **write** links to one server-chosen key. They are not size-limited by the signature; the server checks the object size on completion, deletes mismatches, and prunes unused slots. Until a URL expires, its holder (the uploading user) could overwrite their own just-completed blob; AES-GCM makes such tampering detectable on decrypt, and keys are per-upload so it cannot touch another user's data.
- **Exports put plaintext on your disk.** "Download all photos" (child settings) and the single-photo download save decrypted originals, and the export adds a `manifest.json` that can contain GPS EXIF, comments with author names and tag names. Avapeek cannot protect those files afterwards. Decryption and zip assembly happen only in the browser; nothing about the export is sent back to the server, and the export listing endpoints return ciphertext only (the requested child's wrapped PEK and tags). The export deliberately bypasses the decrypted cache (IndexedDB `avapeek-decrypted`), so nothing it decrypts persists in the browser after the tab closes. Any member of a child can export it; the listing endpoints are rate limited (30 requests per minute per user) and membership-scoped. Browsers without the File System Access API buffer each zip part in memory before saving it.
- The original filename is stored encrypted with the photo key (`photos.name_encrypted` + `iv_name`); photos uploaded before this field existed have none and get a generated name on download. The proxy upload sends the file under a neutral part name, so the real name is not visible in the multipart body.
- **Sharing to another kid trusts the client's wrap.** The browser re-wraps the photo key under the target kid's key (`rewrapPEK`); the key is only ever in memory for that step and the server receives just the new wrap. The server checks the wrap's shape (base64, 48 and 12 bytes) but cannot check that it decrypts, so a member could submit a garbage wrap and make that photo undecryptable for the target kid's other members. This matches how uploads already trust client wraps. It is limited by: only members of the target kid can do it, the user must be able to view the photo, and an existing link is never overwritten (so a wrap that already works cannot be replaced).
- No audit log, no admin recovery of any kind.

## Password loss

There is **no password reset** and no recovery key. Because the password derives the KEK that unwraps your keys, a forgotten password means your wrapped private key and DEKs can never be opened and your photos are unrecoverable by you or the operator. Another member of the family who still has access can invite you again with a new account to regain access to the same children; photos they can see, you can see. If every member loses their password, the data is gone.

Two-factor recovery codes only help you pass the login challenge; they do not recover encryption keys.

## Authorization

Beyond encryption, the API checks membership on every request: a photo, comment, tag or key operation is allowed only if the user belongs to a child linked to the photo through `child_photo`. Registration is closed after the first account; further accounts need an invite. Login, registration, MFA, password-confirmation, upload and invite endpoints are rate limited. Optional MFA supports TOTP, passkeys and recovery codes.

Roles per child are `owner`, `admin` and `member`, and the server enforces them:

- A child has exactly one owner at a time. Role changes and ownership transfer run in a transaction under a row lock on the child, and re-read the memberships inside the lock.
- Only the owner can promote or demote (admin or member), transfer ownership (the password must be re-entered), invite someone as admin, or remove an admin.
- Admins can invite members and remove plain members. Plain members can only leave.
- The owner cannot be removed and cannot leave; they transfer ownership first. A leaver's uploaded photos stay with the child.
- When a member leaves, is removed or is demoted, the invite links they created that are still unused are revoked.
- Roles only gate server actions. They do not change who holds a wrapped key.

## Reporting a vulnerability

See [SECURITY.md](../SECURITY.md). Please do not open public issues for vulnerabilities.
