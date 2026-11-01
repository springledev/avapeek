---
title: Hosted version
prev: false
next: false
---

# Hosted version

A hosted Avapeek is planned for people who cannot or do not want to run a server. **It is not available yet**, and there is no sign-up list. Watch the [GitHub repository](https://github.com/springledev/avapeek) (Watch → Custom → Releases) to hear when that changes.

## What it will and will not change

The encryption works the same way: photos, thumbnails, EXIF data, tags and comments are encrypted in your browser, and the hosting provider stores only ciphertext.

What changes is who runs the server. The app that does the encryption is delivered by the server, so with a hosted version you trust the operator not to serve modified code that could read your password or photos. When you self-host, that operator is you. This limit is described in the [threat model](/docs/security#threat-model).

If that trade-off does not suit you, the [self-hosted install](/docs/installation) is and will stay free and fully featured.
