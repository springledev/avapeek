---
title: How the encryption works
---

# How the encryption works

Avapeek encrypts photos in your browser before they leave your device. The server and the storage bucket only ever receive scrambled data (ciphertext) that they cannot open.

## What happens to a photo

```
 Your phone or computer                        Server and storage
+--------------------------+                 +--------------------------+
|  photo, thumbnail,       |                 |                          |
|  EXIF, tags, comments    |                 |  database:               |
|          |               |                 |    wrapped keys,         |
|          |  encrypt      |   ciphertext    |    encrypted metadata    |
|          v               | --------------> |                          |
|  unreadable ciphertext   |                 |  bucket:                 |
|                          |                 |    encrypted photos      |
+--------------------------+                 +--------------------------+
     keys never leave                            nothing here can be
     your device                                 opened without them
```

Viewing works the other way round: your browser downloads the ciphertext and decrypts it locally.

## The keys

```
your password
     |  stretched in the browser (PBKDF2, 600,000 rounds)
     v
your key  ----------  never sent to the server
     |
     +-- unlocks --> a key per child, shared with that child's family
                          |
                          +-- unlocks --> a key per photo
                                               |
                                               +-- encrypts the photo, thumbnail,
                                                   EXIF and comments
```

Your password is never sent anywhere. The server receives a one-way hash it can check at login, but cannot turn back into your password or your key.

When you invite a relative, your browser hands them the child's key, locked so only their account can open it. That is how they can see that child's photos and nothing else.

## What stays private

Encrypted on your device, so the server cannot read them:

- photos, thumbnails and blur-up previews
- EXIF data, including location
- original filenames
- tag names
- comments
- your password and every key

## What the server can see

Some details are needed to run the app and are not encrypted today: account emails and names, child names and birth dates, who belongs to which family, and when photos were taken and how big they are. Anyone with the database can see that a family has 40 photos from last June, but not what is in them.

## Password loss

There is no password reset. Your password is the only way to unlock your keys, so a forgotten password means your copy of the keys is gone. Another family member who still has access can invite you again with a new account, and you will see the same photos.

Back up your database if you self-host. It holds the locked keys, and the photos cannot be opened without it.

## Known limitations

- **Removing a member does not change the key.** They lose access in the app, but anything they already downloaded stays readable to them.
- **Your device holds decrypted copies.** Viewed photos are cached on your device. Log out on shared computers.
- **When you self-host, keep the server secure.** The app is served from your server, so someone who breaks into it could change the app.
- **Not independently audited** yet.

## Full details

The complete security model covers every algorithm, parameter, database column and edge case. It lives in the repository: [docs/security.md on GitHub](https://github.com/springledev/avapeek/blob/master/docs/security.md). To report a problem privately, see the [security policy](/security-policy).
