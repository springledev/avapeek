# Security model

Avapeek encrypts photos and comments in the browser, so a **compromised or leaked database, backup or object storage** exposes only ciphertext. It does **not** protect against a **malicious or compromised server operator**: the login password is sent to the server and, together with the server-stored `master_key_salt`, derives the KEK, so whoever controls the server can in principle obtain the keys. This page describes exactly how the scheme works and where it has limits. It was written from the code in `frontend/src/crypto/` and the database migrations; if you find a discrepancy, please report it.

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
- **User key pair** (RSA-OAEP 2048, SHA-256). The public key is stored in the clear (`users.public_key`); the private key is stored wrapped by the KEK. It is used to hand a child's DEK to another member.
- **DEK (Data Encryption Key)** is a random AES-256 key per child ("family"). Each member holds a copy wrapped under their own KEK (`wrap_type = kek`). While an invite is being completed, the DEK can briefly be stored wrapped with the invitee's RSA public key (`wrap_type` other than `kek`); the invitee's browser re-wraps it under its own KEK on first login.
- **Tag names** are encrypted with the child's DEK (AES-256-GCM, fresh random IV per tag, stored as `tags.name_encrypted` + `tags.iv_name`). Tags are per child, so a member only receives ciphertext of tags whose child they belong to.
- **PEK (Photo Encryption Key)** is a random AES-256 key per photo. It is wrapped by the child's DEK and stored per child in the `child_photo` pivot (so a photo can be shared with several children, each wrapping the same PEK with its own DEK).
- **Wrap format.** Every AES-GCM key wrap (private key, DEK, PEK) uses a fresh random 12-byte IV per wrap. KEK-wrapped private keys and DEKs are stored as `base64(iv || ciphertext)`; a wrapped DEK is 60 bytes (12-byte IV + 32-byte key + 16-byte tag). Older fixed-IV (48-byte) DEK wraps are not accepted. PEKs are stored as ciphertext with a separate `iv_pek` column.
- Content (photo bytes, thumbnail, LQIP, EXIF JSON, comment text) is encrypted with AES-GCM using the PEK and a fresh random IV per object, stored alongside as `iv_*` columns.

Password change re-wraps the private key and every DEK under the new KEK in the browser; the DEKs and PEKs themselves do not change, so photos stay readable.

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
- **The password also reaches the server.** Login and registration send the password (over TLS) so the server can verify a bcrypt hash, and the same password derives the KEK. A server that logs request bodies, or serves modified code, can capture the password and then derive the KEK from the stored salt and unwrap everything. Client and server hashing are not separated. Treat the server as trusted for availability and for not tampering with the app, not as untrusted storage only.
- **A compromised, shared or unencrypted device.** The derived KEK (exported as JWK) and the bearer token are kept in browser storage: `localStorage` by default ("stay signed in" is on by default), `sessionStorage` if you turn it off. Decrypted images are **not** only held in memory: `src/lib/cache/decrypted-cache.ts` persists decrypted thumbnails (indefinitely) and decrypted originals (LRU, up to 500 MB) as **plaintext blobs in IndexedDB** (`avapeek-decrypted`). This cache is cleared on logout or a 401 response, not when the tab or browser is closed. Malware, a malicious browser extension or someone with disk access can read it. Use full-disk encryption on your devices and **log out on shared devices**.
- **Cross-site scripting.** The app currently sets no Content-Security-Policy. Because the KEK and token sit in `localStorage`, any XSS bug would compromise keys.
- **Weak passwords.** The KEK strength equals password strength. PBKDF2 slows guessing but does not stop it; anyone who obtains the database (wrapped keys + salt) can attack passwords offline.
- **Metadata analysis**, per the table above.
- Denial of service, deletion or tampering by the operator. AES-GCM detects modified ciphertext, but a server can delete data.

## Known limitations

- **Invites trust a server-supplied public key.** When you invite someone, your browser wraps the child's DEK with the invitee's RSA public key as returned by the server. There is no fingerprint or out-of-band verification, so a malicious server can substitute its own key and receive the DEK without modifying any JavaScript.
- **No binding of ciphertext to its photo.** AES-GCM is used without additional authenticated data (AAD) tying a ciphertext to its photo id, so the server could swap blobs between photos and the client would not detect it.
- **No key rotation when a member is removed.** Removing a member deletes their wrapped key copies from the server, but the DEK does not change. Anyone who already downloaded ciphertext and had decrypted or cached the keys keeps the ability to read those photos, and the DEK still protects future uploads they could theoretically obtain ciphertext of. A forward-secrecy design (new DEK, re-wrap remaining PEKs client-side) is planned but not implemented. Treat removal as revoking access to the app, not as cryptographic erasure.
- **Leaving a family does not rotate the key either.** A member who leaves, and a member who is removed, lose their wrapped key copies on the server and their access in the app, but the DEK is unchanged. Anyone who already held the DEK or downloaded ciphertext can still decrypt what they have. Key rotation is tracked as B11 and is not part of role management.
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
