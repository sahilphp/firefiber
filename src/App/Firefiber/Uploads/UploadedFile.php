<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Uploads;

use Sahil\Contracts\Storage\StorageContract;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Storage\StateStorageInterface;

/**
 * A staged or promoted upload belonging to a component field.
 *
 * Instances wrap the authoritative upload record persisted by the upload
 * middleware (`<componentId>::__firefiber_uploads::<id>`) — never
 * browser-supplied data. Temporary files live under
 * `firefiber/uploads/<id>/<random-name>` on the record's disk; `store()`
 * promotes the file to a permanent directory, marks the record and syncs
 * the component's own `uploads` state, mirroring the `promoteUpload`
 * action exactly.
 *
 * Typical usage inside a component action:
 *
 *     $photo = $this->uploadedFile('photo');
 *     $path = $photo?->store('profiles');
 */
final class UploadedFile
{
    /**
     * @param array<string, mixed> $record trusted upload record from component state
     */
    public function __construct(
        private array $record,
        private readonly Component $component,
    ) {
    }

    public function id(): string
    {
        return (string) ($this->record['id'] ?? '');
    }

    public function field(): string
    {
        return (string) ($this->record['field'] ?? '');
    }

    public function originalName(): string
    {
        return (string) ($this->record['originalName'] ?? '');
    }

    public function storedName(): string
    {
        return (string) ($this->record['storedName'] ?? '');
    }

    public function size(): int
    {
        return (int) ($this->record['size'] ?? 0);
    }

    public function mimeType(): string
    {
        return (string) ($this->record['mimeType'] ?? '');
    }

    public function extension(): string
    {
        return (string) ($this->record['extension'] ?? '');
    }

    public function disk(): string
    {
        return is_string($this->record['disk'] ?? null) ? $this->record['disk'] : 'local';
    }

    /**
     * Storage-relative path of the staged file (never an absolute
     * filesystem path — resolve it through the storage contract).
     */
    public function temporaryPath(): string
    {
        return (string) ($this->record['path'] ?? '');
    }

    public function isPromoted(): bool
    {
        return !empty($this->record['promoted']);
    }

    public function permanentPath(): ?string
    {
        $path = $this->record['permanentPath'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function isExpired(): bool
    {
        $expiresAt = (int) ($this->record['expiresAt'] ?? 0);

        return $expiresAt > 0 && $expiresAt <= time();
    }

    /**
     * Read the staged file's contents through the storage contract.
     */
    public function contents(): string
    {
        if ($this->isPromoted() && $this->permanentPath() !== null) {
            return $this->storage()->get($this->permanentPath(), $this->disk());
        }

        $this->assertUsable();

        return $this->storage()->get($this->temporaryPath(), $this->disk());
    }

    /**
     * Move the staged file to permanent storage and mark the record
     * promoted. Returns the permanent storage path.
     *
     * The directory is normalized to a safe relative storage path —
     * absolute paths, drive letters and `..` segments are rejected.
     */
    public function store(string $directory = 'firefiber/permanent'): string
    {
        if ($this->isPromoted() && $this->permanentPath() !== null) {
            return $this->permanentPath();
        }

        $this->assertUsable();

        $directory = self::safeDirectory($directory);
        $permanentPath = $directory.'/'.$this->id().'/'.$this->storedName();
        $storage = $this->storage();

        $storage->move($this->temporaryPath(), $permanentPath, $this->disk());

        $this->record['promoted'] = true;
        $this->record['permanentPath'] = $permanentPath;
        $this->record['expiresAt'] = 0;

        $this->persistRecord();
        $this->syncComponentRecord();

        return $permanentPath;
    }

    /**
     * Delete the file (temporary and, when present, permanent) plus its
     * record, and remove it from the component's `uploads` state.
     */
    public function delete(): void
    {
        $storage = $this->storage();

        if ($this->temporaryPath() !== '') {
            $storage->delete($this->temporaryPath(), $this->disk());
        }

        $permanentPath = $this->permanentPath();
        if ($permanentPath !== null) {
            $storage->delete($permanentPath, $this->disk());
        }

        $this->stateStorage()->deleteState($this->recordKey());
        $this->removeComponentRecord();
    }

    /**
     * Build from a component-state upload record, or null when the entry is
     * not a usable upload record for this component.
     *
     * @param array<string, mixed> $entry
     */
    public static function fromRecord(array $entry, Component $component): ?self
    {
        if (!is_string($entry['id'] ?? null) || !is_string($entry['path'] ?? null)) {
            return null;
        }

        return new self($entry, $component);
    }

    private function assertUsable(): void
    {
        if ($this->temporaryPath() === '' || $this->id() === '') {
            throw new InvalidStateException('The upload record is incomplete.');
        }

        if ($this->isExpired()) {
            throw new InvalidStateException('The temporary upload has expired.');
        }
    }

    private function safeDirectory(string $directory): string
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');

        if ($directory === '') {
            return 'firefiber/permanent';
        }

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $directory) !== 1 || str_contains($directory, '..')) {
            throw new InvalidStateException(sprintf('The upload destination [%s] is not permitted.', $directory));
        }

        return $directory;
    }

    private function recordKey(): string
    {
        return $this->component->id().'::__firefiber_uploads::'.$this->id();
    }

    private function persistRecord(): void
    {
        $record = $this->record;

        if ($this->component->trustedMetadata() !== []) {
            $metadata = $this->component->trustedMetadata();
            $record['sessionId'] = $metadata['sessionId'] ?? null;
            $record['userId'] = $metadata['userId'] ?? null;
        }

        if (!$this->stateStorage()->saveState($this->recordKey(), $record, 0)) {
            throw new \RuntimeException('Unable to persist upload record.');
        }
    }

    /**
     * Keep the component's signed `uploads` list in step — the promoted
     * record is permanent now and must not expire or render as staged.
     */
    private function syncComponentRecord(): void
    {
        $listed = $this->component->state()['uploads'] ?? [];

        if (!is_array($listed)) {
            return;
        }

        foreach ($listed as $index => $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $this->id()) {
                $entry['promoted'] = true;
                $entry['expiresAt'] = 0;
                $entry['permanentPath'] = $this->permanentPath();
                $listed[$index] = $entry;
            }
        }

        $this->component->updateState(['uploads' => array_values($listed)]);
    }

    private function removeComponentRecord(): void
    {
        $listed = $this->component->state()['uploads'] ?? [];

        if (!is_array($listed) || $listed === []) {
            return;
        }

        $id = $this->id();
        $this->component->updateState([
            'uploads' => array_values(array_filter($listed, static function (mixed $entry) use ($id): bool {
                return !is_array($entry) || ($entry['id'] ?? null) !== $id;
            })),
        ]);
    }

    private function storage(): StorageContract
    {
        return app()->container()->make(StorageContract::class);
    }

    private function stateStorage(): StateStorageInterface
    {
        return app()->container()->make(StateStorageInterface::class);
    }
}
