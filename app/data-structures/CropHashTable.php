<?php
declare(strict_types=1);

/** Hash table for crop records, indexed separately by ID and crop name. */
final class CropHashTable
{
    /** @var array<int, array<int, array{key: string, crop: array<string, mixed>}>> */
    private array $buckets;

    public function __construct(private readonly int $bucketCount = 31)
    {
        if ($bucketCount < 1) {
            throw new InvalidArgumentException('The hash table needs at least one bucket.');
        }

        $this->buckets = array_fill(0, $bucketCount, []);
    }

    /** Add one crop to the ID and name indexes. */
    public function insert(array $crop): void
    {
        if (!isset($crop['Crop_ID'], $crop['Crop_Name'])) {
            throw new InvalidArgumentException('A crop needs Crop_ID and Crop_Name fields.');
        }

        $this->store('id:' . (string) $crop['Crop_ID'], $crop);
        $this->store($this->nameKey((string) $crop['Crop_Name']), $crop);
    }

    /** Find a crop by its numeric database ID. */
    public function searchById(int $cropId): ?array
    {
        if ($cropId < 1) {
            return null;
        }

        return $this->search('id:' . $cropId);
    }

    /** Find a crop by name, ignoring surrounding spaces and ASCII letter case. */
    public function searchByName(string $cropName): ?array
    {
        $cropName = trim($cropName);
        return $cropName === '' ? null : $this->search($this->nameKey($cropName));
    }

    public function getBucketCount(): int
    {
        return $this->bucketCount;
    }

    private function nameKey(string $cropName): string
    {
        return 'name:' . strtolower(trim($cropName));
    }

    /**
     * Hash each byte into a bucket index. Reducing modulo the bucket count
     * keeps indexes in range; different keys may still share a bucket.
     */
    private function hash(string $key): int
    {
        $hashValue = 0;
        $length = strlen($key);

        for ($i = 0; $i < $length; $i++) {
            $hashValue = (($hashValue * 31) + ord($key[$i])) % $this->bucketCount;
        }

        return $hashValue;
    }

    /** Store collisions in a linked-style bucket chain and update duplicate keys. */
    private function store(string $key, array $crop): void
    {
        $bucketIndex = $this->hash($key);

        foreach ($this->buckets[$bucketIndex] as $entryIndex => $entry) {
            if ($entry['key'] === $key) {
                $this->buckets[$bucketIndex][$entryIndex]['crop'] = $crop;
                return;
            }
        }

        // Separate chaining: append a different key to this bucket's chain.
        $this->buckets[$bucketIndex][] = ['key' => $key, 'crop' => $crop];
    }

    /** Search only the chain in the bucket selected by this key's hash. */
    private function search(string $key): ?array
    {
        $bucketIndex = $this->hash($key);

        foreach ($this->buckets[$bucketIndex] as $entry) {
            if ($entry['key'] === $key) {
                return $entry['crop'];
            }
        }

        return null;
    }
}
