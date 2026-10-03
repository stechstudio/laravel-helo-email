<?php

namespace STS\HeloEmail;

use ArrayIterator;
use Closure;
use Countable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use IteratorAggregate;
use Traversable;

/**
 * One page of a Helo list. Helo pages most lists by offset and activity by an
 * "after" cursor; callers see the same object either way.
 *
 * @implements IteratorAggregate<int, Response>
 */
final class Page implements Countable, IteratorAggregate
{
    /**
     * @param Collection<int, Response> $items
     * @param (Closure(): Page)|null $next
     */
    private function __construct(
        private Collection $items,
        private ?int $total,
        private ?Closure $next,
    ) {
    }

    /** @param Closure(int): array<mixed> $fetch */
    public static function offset(Closure $fetch, int $offset = 0): self
    {
        $json = $fetch($offset);
        $results = self::results($json);
        $seen = $offset + count($results);

        // A short list must never pass for a complete one: Postmaster clears
        // local suppressions that a "complete" list doesn't contain. So every
        // page must carry a usable total.
        $total = $json['totalCount'] ?? null;

        if (! is_int($total) || $total < 0) {
            throw new HeloException('Helo returned a list response without a valid totalCount.');
        }

        if ($results === [] && $offset < $total) {
            throw new HeloException("Helo returned an empty page at offset {$offset} of {$total}.");
        }

        $next = $results !== [] && $seen < $total
            ? fn () => self::offset($fetch, $seen)
            : null;

        return new self(self::responses($results), $total, $next);
    }

    /** @param Closure(?int): array<mixed> $fetch */
    public static function cursor(Closure $fetch, ?int $after = null): self
    {
        $json = $fetch($after);
        $results = self::results($json);
        $cursor = $json['after'] ?? null;

        // Stop on a missing or unchanged cursor rather than loop forever.
        $next = $results !== [] && is_int($cursor) && $cursor !== $after
            ? fn () => self::cursor($fetch, $cursor)
            : null;

        return new self(self::responses($results), self::parseTotal($json), $next);
    }

    /** @return Collection<int, Response> */
    public function items(): Collection
    {
        return $this->items;
    }

    public function total(): ?int
    {
        return $this->total;
    }

    public function hasMore(): bool
    {
        return $this->next !== null;
    }

    public function next(): ?self
    {
        return $this->next ? ($this->next)() : null;
    }

    /**
     * Every item from this page on, fetching later pages as they're needed.
     *
     * @return LazyCollection<int, Response>
     */
    public function lazy(): LazyCollection
    {
        return LazyCollection::make(function () {
            for ($page = $this; $page !== null; $page = $page->next()) {
                foreach ($page->items as $item) {
                    yield $item;
                }
            }
        });
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items->all());
    }

    /**
     * @param array<mixed> $json
     * @return list<array<mixed>>
     */
    private static function results(array $json): array
    {
        $results = $json['results'] ?? null;

        if (! is_array($results) || ! array_is_list($results)) {
            throw new HeloException('Helo returned a list response without results.');
        }

        foreach ($results as $result) {
            if (! is_array($result)) {
                throw new HeloException('Helo returned a list entry that is not an object.');
            }
        }

        return $results;
    }

    /** @param array<mixed> $json */
    private static function parseTotal(array $json): ?int
    {
        // Activity responses report totalCount as a JSON number, not an integer.
        return is_numeric($json['totalCount'] ?? null) ? (int) $json['totalCount'] : null;
    }

    /**
     * @param list<array<mixed>> $results
     * @return Collection<int, Response>
     */
    private static function responses(array $results): Collection
    {
        return collect($results)->map(fn (array $result) => new Response($result));
    }
}
