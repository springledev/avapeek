# Roadmap and known issues

This page lists what is known to be missing or imperfect, and what is planned. Nothing here has a date. The [security model](security.md) describes the current behaviour in detail; this page only tracks the gaps.

Bug reports go through GitHub issues. Security issues go through [SECURITY.md](../SECURITY.md), not public issues.

## Planned: cryptography

- **Key rotation when a member is removed or leaves.** Today the child's DEK does not change, so a removed member who already holds the key or downloaded ciphertext can still read it. The plan is a new DEK, with the remaining photo keys re-wrapped in the browser and tags re-encrypted.
- **Binding ciphertext to its photo with AAD.** AES-GCM is used without additional authenticated data, so a malicious server could swap blobs between photos undetected. The plan is to authenticate the photo id and field name. This needs format versioning first.
- **Format versioning.** No ciphertext or key-wrap format carries a version marker. The plan is a version byte or a `crypto_version` column, so cipher, IV length or AAD can change safely.
- **PBKDF2 upgrade path.** Iterations and algorithm are fixed in client code and not stored per user. The plan is a stored KDF version, with a transparent re-wrap on the next login.
- **Argon2id.** A memory-hard KDF in place of PBKDF2, delivered through the same upgrade path.
- **Invite key fingerprint verification.** Invites wrap the DEK with a server-supplied public key. The plan is a fingerprint that the inviter and invitee can compare out of band.
- **12-byte content IVs.** Content is encrypted with 16-byte random IVs. This is safe but unconventional; moving to 12 bytes would ride on format versioning.

## Known issues

- **No Content-Security-Policy outside the bundled Caddy.** The CSP and other security headers are sent by the bundled Caddy. If you put the app behind a different web server, you must set equivalent headers yourself. HSTS is also left to your TLS proxy.
- **Some metadata is plaintext.** Child names, photo dates, sizes, MIME types, membership and comment timing are visible to whoever holds the database.
- **Login throttle is per client IP.** Devices sharing an address (a home network) share the limit, and there is no per-account lockout for the password step, only for MFA codes. Behind a proxy or tunnel it is per proxy until you set [`UPSTREAM_TRUSTED_PROXIES`](configuration.md#client-ip).
- **Password strength is checked in the browser only,** because the server never sees the password.
- **No password recovery and no audit log.** A forgotten password means unreadable data.
- **Exports and cached images are plaintext on the device.** Exported files, the decrypted image cache and the share inbox are not encrypted at rest by Avapeek.
- **A shared-photo wrap is trusted from the client.** A member could submit an invalid wrap when sharing to another child, making that photo undecryptable there.
- **Not independently audited.**
