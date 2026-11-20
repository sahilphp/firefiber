<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use Sahil\Firefiber\Storage\StateStorageInterface;

/**
 * Server-side parent links for components rendered in a page tree.
 *
 * Links are deliberately stored outside the component snapshot. They are not
 * secrets, but they are authoritative routing data and are therefore never
 * accepted from the browser.
 */
final class ComponentParentRegistry
{
    private const PREFIX = '__firefiber_parents_';
    private const CHILD_PREFIX = '__firefiber_children_';

    public function __construct(
        private readonly StateStorageInterface $storage,
    ) {
    }

    public function link(Component $parent, Component $child): void
    {
        $key = self::PREFIX.$child->id();
        $record = $this->storage->retrieveState($key);
        $parents = is_array($record['parents'] ?? null) ? $record['parents'] : [];
        $candidate = ['id' => $parent->id(), 'class' => $parent::class];
        $exists = false;

        foreach ($parents as $existing) {
            if (is_array($existing) && ($existing['id'] ?? null) === $candidate['id'] && ($existing['class'] ?? null) === $candidate['class']) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $parents[] = $candidate;
            $this->storage->saveState($key, ['parents' => $parents], 0);
        }

        // Reverse index: parent → children, so event delivery can walk the
        // rendered tree downward (descendants, siblings via the root).
        $childKey = self::CHILD_PREFIX.$parent->id();
        $childRecord = $this->storage->retrieveState($childKey);
        $children = is_array($childRecord['children'] ?? null) ? $childRecord['children'] : [];
        $childCandidate = ['id' => $child->id(), 'class' => $child::class];
        $childExists = false;

        foreach ($children as $existing) {
            if (is_array($existing) && ($existing['id'] ?? null) === $childCandidate['id'] && ($existing['class'] ?? null) === $childCandidate['class']) {
                $childExists = true;
                break;
            }
        }

        if (!$childExists) {
            $children[] = $childCandidate;
            $this->storage->saveState($childKey, ['children' => $children], 0);
        }
    }

    /**
     * @return list<array{id: string, class: string}>
     */
    public function childrenFor(string $parentId): array
    {
        if ($parentId === '') {
            return [];
        }

        $record = $this->storage->retrieveState(self::CHILD_PREFIX.$parentId);
        $children = is_array($record['children'] ?? null) ? $record['children'] : [];
        $result = [];

        foreach ($children as $child) {
            if (is_array($child) && is_string($child['id'] ?? null) && is_string($child['class'] ?? null)) {
                $result[] = ['id' => $child['id'], 'class' => $child['class']];
            }
        }

        return $result;
    }

    /**
     * @return list<array{id: string, class: string}>
     */
    public function parentsFor(string $childId): array
    {
        if ($childId === '') {
            return [];
        }

        $record = $this->storage->retrieveState(self::PREFIX.$childId);
        $parents = is_array($record['parents'] ?? null) ? $record['parents'] : [];
        $result = [];

        foreach ($parents as $parent) {
            if (is_array($parent) && is_string($parent['id'] ?? null) && is_string($parent['class'] ?? null)) {
                $result[] = ['id' => $parent['id'], 'class' => $parent['class']];
            }
        }

        return $result;
    }
}
