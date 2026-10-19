<?php
declare(strict_types=1);

/** A small FIFO queue for support-request records. */
final class SupportRequestQueue
{
    /** @var array<int, array<string, mixed>> */
    private array $items = [];
    private int $frontIndex = 0;

    /** Add a request to the back of the queue. */
    public function enqueue(array $request): void
    {
        $this->items[] = $request;
    }

    /** Remove and return the oldest request, or null when the queue is empty. */
    public function dequeue(): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        $request = $this->items[$this->frontIndex];
        unset($this->items[$this->frontIndex]);
        $this->frontIndex++;

        if ($this->isEmpty()) {
            $this->items = [];
            $this->frontIndex = 0;
        }

        return $request;
    }

    /** Return the oldest request without removing it, or null when empty. */
    public function front(): ?array
    {
        return $this->isEmpty() ? null : $this->items[$this->frontIndex];
    }

    public function isEmpty(): bool
    {
        return !array_key_exists($this->frontIndex, $this->items);
    }
}
