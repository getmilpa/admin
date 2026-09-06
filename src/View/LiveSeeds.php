<?php

/**
 * This file is part of Milpa Admin — the administration panel of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\View;

use Milpa\Admin\Section\SeedConflictException;

/**
 * What the page's three seed tags carry — `#milpa-live-signals`, `#milpa-live-persist` and
 * `#milpa-live-computed` — merged from the host's own and every view a section declared
 * (greenhouse decisions/0211).
 *
 * The runtime reads ONE tag of each kind per page (`getElementById`), so the host is the only emitter and
 * this is where the merge happens. Merging is by NAME and it is loud: the same signal declared twice with
 * DIFFERENT values is a {@see SeedConflictException} naming the key and both declarers — never a silent
 * last-one-wins, which would make one guest's seed depend on section order. The same name with the SAME
 * value is not a conflict: two declarers agreeing is agreement, not a clash. `persist` is a list of names,
 * so it merges by deduplication and can never conflict.
 *
 * Every declarer is named when it merges (`the panel`, `section «agent»`), which is what makes the
 * conflict message actionable.
 */
final readonly class LiveSeeds
{
    /**
     * @param array<string, mixed>  $signals  signal name → seed value
     * @param list<string>          $persist  signal names the runtime persists
     * @param array<string, mixed>  $computed signal name → derivation
     * @param array<string, string> $sources  signal or computed name → who declared it, for the conflict message
     */
    private function __construct(
        public array $signals = [],
        public array $persist = [],
        public array $computed = [],
        private array $sources = [],
    ) {
    }

    /** Nothing seeded — the three tags are still emitted, empty: one truth, never a missing tag. */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * The seeds one declarer brings, named.
     *
     * @param string               $source   who declares them — `the panel`, `section «agent»`
     * @param array<string, mixed> $signals
     * @param list<string>         $persist
     * @param array<string, mixed> $computed
     */
    public static function of(string $source, array $signals = [], array $persist = [], array $computed = []): self
    {
        $sources = [];
        foreach ([...array_keys($signals), ...array_keys($computed)] as $name) {
            $sources[(string) $name] = $source;
        }

        return new self($signals, array_values(array_unique($persist)), $computed, $sources);
    }

    /**
     * These seeds plus `$other`'s.
     *
     * @throws SeedConflictException when both declare one name with different values
     */
    public function merge(self $other): self
    {
        return new self(
            signals: $this->mergeMap($this->signals, $other->signals, $other->sources, 'signal'),
            persist: array_values(array_unique([...$this->persist, ...$other->persist])),
            computed: $this->mergeMap($this->computed, $other->computed, $other->sources, 'computed signal'),
            sources: [...$this->sources, ...$other->sources],
        );
    }

    /** True when nothing was seeded at all. */
    public function isEmpty(): bool
    {
        return $this->signals === [] && $this->persist === [] && $this->computed === [];
    }

    /**
     * @param array<string, mixed>  $mine
     * @param array<string, mixed>  $theirs
     * @param array<string, string> $sources
     *
     * @return array<string, mixed>
     */
    private function mergeMap(array $mine, array $theirs, array $sources, string $what): array
    {
        foreach ($theirs as $name => $value) {
            $name = (string) $name;
            if (\array_key_exists($name, $mine) && $mine[$name] !== $value) {
                throw new SeedConflictException(\sprintf(
                    'The %s «%s» is seeded twice with different values: %s says %s, %s says %s. One page seeds each signal once — agree on a value or name them apart.',
                    $what,
                    $name,
                    $this->sources[$name] ?? 'a declarer',
                    self::describe($mine[$name]),
                    $sources[$name] ?? 'another',
                    self::describe($value),
                ));
            }
            $mine[$name] = $value;
        }

        return $mine;
    }

    /** One seed value as a human can read it in the conflict message. */
    private static function describe(mixed $value): string
    {
        $json = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return \is_string($json) ? $json : get_debug_type($value);
    }
}
