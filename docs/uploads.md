# File uploads

Firefiber uploads stage files through a dedicated multipart endpoint,
bind them to component state, and promote them to permanent storage on
demand — all through SahilPHP's storage, validation and session stack.

## Declaring an upload field

```php
final class ProfilePage extends Page
{
    protected array $uploadDefinitions = [
        'photo' => [
            'maxFiles' => 1,
            'maxSize' => 2 * 1024 * 1024,      // bytes
            'extensions' => ['png', 'jpg', 'webp'],  // or 'mimes' => ['image/png', …]
            'lifetime' => 3600,                 // staged seconds (min 60)
            'disk' => 'local',                  // storage disk
            'permanentDirectory' => 'profiles', // store() destination
        ],
    ];
}
```

File inputs bind with `fiber:upload`:

```html
<input type="file" fiber:upload="photo" accept="image/*">
```

Selecting a file POSTs it to `/firefiber/uploads`. Progress arrives as
`firefiber:uploadprogress` runtime events; failures surface as
`firefiber:error` and standard validation errors.

## The upload lifecycle

1. **Stage** — the request hydrates the component from its signed
   snapshot, re-checks `authorize($user, 'upload:photo')`, verifies the
   field exists in `$uploadDefinitions`, and stores the file under a
   random name in `firefiber/uploads/<id>/` on the configured disk.
   Size, count (`maxFiles`, counting already-staged files), and real
   content-type (server-side MIME detection — the browser's `type` is
   ignored) are enforced; executable extensions are always refused.
2. **Record** — a sidecar (`<componentId>::__firefiber_uploads::<id>`)
   binds the file to the component, session and user; the signed
   snapshot's `uploads` state gets a sanitized record (no session/user
   binding crosses the trust boundary).
3. **Use** — inside actions, `uploadedFile()`/`uploadedFiles()` wrap
   trusted state records in `UploadedFile` objects.
4. **Promote** — `$file->store('profiles')` moves the staged file to
   permanent storage, marks the record and syncs component state. The
   `promoteUpload` action re-checks `authorize($user,
   'upload:promote:<field>')` independently of the initial upload check.
5. **Clean up** — staged files outlive their sidecar only by accident:
   `php sahil firefiber:cleanup-uploads` sweeps `firefiber/uploads/`
   directories older than `--max-age` seconds (default 3600). Run it
   hourly from a scheduler.

## Working with uploads

```php
use Sahil\Firefiber\Uploads\UploadedFile;

public function save(): void
{
    $photo = $this->uploadedFile('photo');      // ?UploadedFile

    if ($photo === null) {
        $this->validateForm(['photo' => null]); // your own error path
        return;
    }

    $path = $photo->store('profiles');          // permanent storage path

    $this->updateState(['avatarPath' => $path]);
}
```

`UploadedFile` exposes `id()`, `field()`, `originalName()` (sanitized),
`storedName()`, `size()`, `mimeType()`, `extension()`, `disk()`,
`temporaryPath()`, `contents()`, `isPromoted()`, `permanentPath()` and
`isExpired()`. `store()` is idempotent (repeat calls return the same
path), and `delete()` removes the file, its sidecar and its state entry.
Multiple files per field arrive through `uploadedFiles('gallery')`.

Validation failures — oversize, wrong type, too many files, missing
files — surface as standard validation errors on the field, exactly like
form validation.

## Removal and replacement

The `removeUpload` action (`Firefiber.dispatchSubmit` /
`window.Firefiber` actions, or `removeUploads` server-side) deletes
staged files, their records and their state entries after re-checking
`authorize($user, 'upload:remove:<field>')`. Re-uploading a staged id
replaces rather than duplicates the record.

## Security

- Random `bin2hex` ids and storage names — client filenames never reach
  the filesystem (the original name is sanitized metadata only).
- MIME is detected from file contents; PHP-disguised-as-PNG is refused
  even when the browser claims `image/png`.
- Ownership is component- and session-bound: sidecars refuse
  cross-component or cross-user promotion/removal.
- Uploads hydrate through the same signed snapshot + CSRF rotation ring
  as actions; forged snapshots and expired staged records fail.
- `permanentDirectory`/`store()` paths are normalized to safe relative
  storage paths — `..`, absolute paths and odd characters are rejected.
- Staged records expire (`lifetime`), expired records never reach
  `uploadedFile()`, and the cleanup command reclaims orphaned files.

## Configuration

```php
// config/firefiber.php (or env equivalents)
'upload_path' => '/firefiber/uploads',   // endpoint
```

Field-level limits live in `$uploadDefinitions` (`maxFiles`, `maxSize`,
`extensions`/`mimes`, `lifetime`, `disk`, `permanentDirectory`). Nothing
else is required — uploads use whatever disks `filesystems.disks`
configures.

## Troubleshooting

- **403 on upload** — `authorize()` rejected `upload:<field>`; check the
  component's `authorize()` and that the session/CSRF is intact.
- **"The uploaded file is missing or expired"** — the sidecar TTL lapsed;
  upload again (files expire after `lifetime` seconds).
- **Type rejected despite a valid extension** — content MIME detection
  found a different type than the extension suggests.
- **Files accumulating on disk** — schedule `firefiber:cleanup-uploads`.
