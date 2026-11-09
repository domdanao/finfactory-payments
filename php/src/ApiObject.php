<?php

declare(strict_types=1);

namespace FinFactory\Payments;

/**
 * An object from the API. Read fields as properties or as array keys:
 *
 *     $intent->status;  $intent['status'];  $intent->next_action->redirect_to_url->url;
 *
 * A field the API does not send reads as null, so code keeps working as fields are added. Objects
 * are read-only; toArray() gives the plain array.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class ApiObject implements \ArrayAccess, \JsonSerializable, \Countable
{
    /** Maps the API's `object` field to the class that represents it. */
    private const CLASSES = [
        'payment_intent' => Resource\PaymentIntent::class,
        'payment_method' => Resource\PaymentMethod::class,
        'refund' => Resource\Refund::class,
        'dispute' => Resource\Dispute::class,
        'webhook_endpoint' => Resource\WebhookEndpoint::class,
        'event' => Resource\Event::class,
        'list' => Collection::class,
    ];

    /** @var array<string, mixed> */
    protected array $values = [];

    private ?ResponseInfo $lastResponse = null;

    /** @param array<string, mixed> $values */
    final public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            $this->values[(string) $key] = self::convert($value);
        }
    }

    /**
     * Build the right ApiObject subclass for a decoded API response.
     *
     * @param array<string, mixed> $values
     */
    public static function constructFrom(array $values, ?ResponseInfo $response = null): static
    {
        $class = static::class;
        if ($class === self::class && isset($values['object']) && \is_string($values['object'])) {
            $class = self::CLASSES[$values['object']] ?? self::class;
        }
        /** @var static $object */
        $object = new $class($values);
        $object->lastResponse = $response;
        return $object;
    }

    private static function convert(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::convert(...), $value);
        }
        return ApiObject::constructFrom($value); // Not self::, which would forward late static binding.
    }

    /** The HTTP response this object came from: status, headers, request id. */
    public function getLastResponse(): ?ResponseInfo
    {
        return $this->lastResponse;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            $out[$key] = self::unconvert($value);
        }
        return $out;
    }

    private static function unconvert(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }
        if (\is_array($value)) {
            return array_map(self::unconvert(...), $value);
        }
        return $value;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function __get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function __set(string $name, mixed $value): never
    {
        throw new \LogicException('FinFactory: API objects are read-only. Use toArray() for a copy you can change.');
    }

    public function __unset(string $name): never
    {
        throw new \LogicException('FinFactory: API objects are read-only.');
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        $this->__set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): never
    {
        $this->__unset((string) $offset);
    }

    public function count(): int
    {
        return \count($this->values);
    }

    public function jsonSerialize(): mixed
    {
        return $this->values === [] ? new \stdClass() : $this->toArray();
    }

    public function __toString(): string
    {
        return static::class . ' ' . json_encode($this, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
