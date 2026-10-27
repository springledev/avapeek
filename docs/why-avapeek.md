# Why Avapeek?

"Why not just use Instagram, a shared album, or a family photo app?" Fair question. This page is an honest answer, including where those options are the better choice.

Avapeek is a small, self-hosted web app for one job: sharing photos of your children with the people who should see them, without handing the photos to a company or to the machine that stores them.

> **Hosted version coming soon.** If you cannot or do not want to run a server, a hosted Avapeek is planned. It is not available yet. Watch the [GitHub repository](https://github.com/springledev/avapeek) for news.

## The short version

| | Social media (Instagram, Facebook, ...) | Shared albums (Google Photos, iCloud, ...) | Family photo apps (FamilyAlbum-style) | Avapeek |
| --- | --- | --- | --- | --- |
| Built for | Public or semi-public sharing | Storing and sharing your whole library | Sharing baby and kid photos with family | Sharing kid photos with family |
| Provider can technically view your photos | Yes, in general | Yes, in general | Depends on the app; check its documentation | Not from the database, backups or storage (they hold ciphertext). See the caveat below |
| Who runs it | The platform | The platform | The vendor | You |
| Business model | Usually advertising or data | Usually a storage subscription | Usually a subscription | None: free software (AGPL-3.0) |
| Leaving | Export tools vary | Export tools vary | Varies | Download everything yourself, you also own the server and bucket |

The rows about other products are deliberately general. Features and policies change, so check the current terms of any service you are comparing.

## What Avapeek actually does

Everything below exists in the code today.

**Encrypted in your browser.** Photos, thumbnails, low-quality placeholders, EXIF data, original filenames, tag names and comments are encrypted with AES-GCM in the browser before upload. The database, the backups and the object storage only hold ciphertext and wrapped keys. Your password is never sent to the server, only a one-way auth hash derived from it. Details: [security model](security.md).

**Family-only access, per child.** Each child is its own group. You invite relatives with a link and they see that child's photos and nothing else. Roles (owner, admin, member) are enforced on the server, and a member can be set to view-only so they can look and comment but not upload. Registration is closed after the first account, so strangers cannot sign up on your instance.

**No ads, no tracking, no AI training.** There is no advertising, no analytics SDK and no third-party tracker in the app. Photos are ciphertext to the server, so there is nothing for the server to train a model on. Optional push notifications carry no photo content (only a generic type and a link), though the browser's push provider can see that a message was delivered.

**You own the data.** You choose where the encrypted files live: the bundled [Garage](https://garagehq.deuxfleurs.fr/) store, or any S3-compatible provider (AWS S3, Backblaze B2, Cloudflare R2 and others). See [storage](storage.md).

**No lock-in.** Any member can download all photos of a child as a zip with a manifest (tags, comments, dates), or a single photo at original quality. Decryption happens in the browser. The software is open source, so the service cannot be withdrawn from you.

**Family-album niceties.** A combined feed, recents, tags, date filters, an "On this day" memories card, bulk actions, sharing a photo into another child's album, an installable mobile web app (PWA), Android share-to-Avapeek, optional push notifications, and optional two-factor authentication (TOTP, passkeys, recovery codes).

## Compared with Instagram and other social media

Social media is built around reach: followers, public profiles, discovery, engagement. For family photos this works against you. Even with a private account, photos sit on a platform whose business is attention and data, and children appear in feeds you do not control.

Avapeek has no public profile, no discovery, no likes, no followers. The audience is exactly the people you invited. If you want to post to an audience or build a following, use social media; Avapeek is not trying to be that.

## Compared with shared albums (Google Photos, iCloud, ...)

Shared albums are convenient, polished and often already installed on everyone's phone. They are excellent at backing up and searching a whole library, with features such as face grouping and automatic search that Avapeek does not have and, because the server cannot see your photos, cannot offer in the same way.

The difference is who can read the photos. With a mainstream shared album the provider holds the data in a form it can process. With Avapeek the server cannot read the contents. Avapeek is also grouped by child rather than by event, which matches how families tend to look at photos.

Avapeek is not a backup of your whole camera roll. Keep your own backups.

## Compared with family photo apps (FamilyAlbum-style)

Apps in this category solve the same problem: a private place for kid photos. They are generally easier to start with: install, sign up, done. Check each product's own documentation for how it handles encryption, hosting, pricing and export; we do not make claims about specific products here.

What Avapeek offers instead is a different set of trade-offs: you run it (or soon, a hosted version), the encryption design is public and documented in [security.md](security.md), the source is open under the AGPL, and your data is not tied to a vendor staying in business.

## Honest trade-offs

Avapeek is not for everyone. Know these before you commit.

- **Self-hosting takes effort.** You need a small server (about 1 GB RAM), Docker, a domain for HTTPS, and you are responsible for updates and backups. `./up.sh` makes installing easy, but running it is still your job. A hosted version is planned for people who would rather not.
- **A lost password means lost photos.** There is no password reset and no recovery key, because the server never has your keys. If you forget your password, your own keys cannot be recovered. Another family member with access can invite you again with a new account. See [password loss](security.md#password-loss).
- **Lose the database, lose the photos.** The database holds the wrapped keys. The bucket alone is unreadable. Back up both. See [backups](installation.md#backups).
- **A malicious server operator is not covered.** The encryption code is delivered by the server you use. If you run it yourself, that operator is you. If you ever use an instance run by someone else, you are trusting them not to ship modified code. See the [threat model](security.md#threat-model).
- **Some metadata is visible to the server.** Child names, who belongs to which child, photo dates and sizes, and comment timing are not encrypted. See [what the server sees](security.md#what-the-server-sees).
- **Removing a member does not rotate keys.** Someone who already saved photos or keys keeps them. See [known limitations](security.md#known-limitations).
- **Not independently audited.**
- **Smaller feature set.** No face recognition, no automatic albums, no full-library search, no native iOS or Android app (it is an installable web app), no video-focused features. Offline use is limited.
- **A young project.** Expect rough edges and a small community.

## Who it suits

- Parents who want grandparents and relatives to see photos without a social network or a company in the middle.
- People who already self-host, or have a family member who does.
- Anyone who wants a family archive they can export and keep for decades.

If that is not you, a mainstream shared album may be the right tool, and that is fine.

Ready to try it? See the [installation guide](installation.md). Questions: [FAQ](faq.md).
