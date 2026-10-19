<?php
declare(strict_types=1);

/** One node in the recent-activity singly linked list. */
final class RecentActivityNode
{
    public ?RecentActivityNode $next = null;

    public function __construct(public string $activity)
    {
    }
}

/** A newest-first singly linked list with a small maximum size. */
final class RecentActivityLinkedList
{
    private ?RecentActivityNode $head = null;
    private int $size = 0;

    public function __construct(private readonly int $limit = 10)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The activity list limit must be at least one.');
        }
    }

    /** Link a new node before the current head, making it the newest event. */
    public function insertAtHead(string $activity): void
    {
        $activity = trim($activity);
        if ($activity === '') {
            return;
        }

        $node = new RecentActivityNode($activity);
        $node->next = $this->head;
        $this->head = $node;
        $this->size++;

        if ($this->size > $this->limit) {
            $this->removeTail();
        }
    }

    /** Traverse next links from the head to return newest activity first. */
    public function getActivities(): array
    {
        $activities = [];
        $node = $this->head;

        while ($node !== null) {
            $activities[] = $node->activity;
            $node = $node->next;
        }

        return $activities;
    }

    /**
     * Rebuild the linked nodes from plain session strings (newest first).
     * The session stores no custom objects, so PHP object unserialization is not used.
     */
    public static function fromNewestFirstArray(array $activities, int $limit = 10): self
    {
        $list = new self($limit);
        $validActivities = [];

        foreach (array_slice($activities, 0, $limit) as $activity) {
            if (is_string($activity) && trim($activity) !== '') {
                $validActivities[] = $activity;
            }
        }

        // Insert oldest-to-newest so the newest ends up at the head again.
        foreach (array_reverse($validActivities) as $activity) {
            $list->insertAtHead($activity);
        }

        return $list;
    }

    /** Traverse the nodes to produce simple strings safe to store in a session. */
    public function toArray(): array
    {
        return $this->getActivities();
    }

    /** Remove the oldest node by following links to the node before the tail. */
    private function removeTail(): void
    {
        if ($this->head === null) {
            return;
        }

        if ($this->head->next === null) {
            $this->head = null;
            $this->size = 0;
            return;
        }

        $node = $this->head;
        while ($node->next?->next !== null) {
            $node = $node->next;
        }

        $node->next = null;
        $this->size--;
    }
}
