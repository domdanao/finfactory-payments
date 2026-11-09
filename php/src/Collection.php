<?php

declare(strict_types=1);

namespace FinFactory\Payments;

/**
 * One page of a list: `data`, `has_more` and `next_cursor`. Iterate it for this page's objects;
 * use a service's all() to go through every page.
 *
 * @property-read string $object Always "list".
 * @template T of ApiObject
 *
 * @property-read list<T> $data
 * @property-read bool $has_more
 * @property-read string|null $next_cursor Pass as `cursor` for the next page.
 *
 * @implements \IteratorAggregate<int, T>
 */
class Collection extends ApiObject implements \IteratorAggregate
{
    /** @return \ArrayIterator<int, T> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values['data'] ?? []);
    }

    /** How many objects are on this page. */
    public function count(): int
    {
        return \count($this->values['data'] ?? []);
    }

    public function hasMore(): bool
    {
        return (bool) ($this->values['has_more'] ?? false);
    }

    public function nextCursor(): ?string
    {
        return $this->values['next_cursor'] ?? null;
    }
}
