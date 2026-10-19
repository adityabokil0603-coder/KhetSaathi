<?php
declare(strict_types=1);

/** A small last-in, first-out stack for support status changes. */
final class SupportStatusStack
{
    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    /** Push a new status-change record onto the top of the stack. */
    public function push(array $change): void
    {
        $this->items[] = $change;
    }

    /** Pop and return the newest change, or null if the stack is empty. */
    public function pop(): ?array
    {
        return array_pop($this->items) ?: null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** Return contents bottom-to-top so the session can restore stack order. */
    public function toArray(): array
    {
        return $this->items;
    }
}
