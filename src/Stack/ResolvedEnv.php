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

namespace Milpa\Admin\Stack;

use Milpa\Runtime\Config;
use Milpa\Runtime\Stack\EnvVar;

/**
 * One declared environment variable after the panel decided where its value comes from.
 *
 * The rule is the same for the card and for the compose file, so it lives once, in precedence
 * order: a `secret` has no value here, ever — the contract refuses a secret that carries a literal,
 * and this class masks one anyway as defense in depth; a `configKey` the app's config holds yields
 * that value (`config`); a literal the plugin wrote yields it (`literal`); anything else is `unset`
 * — the operator supplies it. Whatever has no value is projected as `${NAME}`.
 */
final readonly class ResolvedEnv
{
    public const LITERAL = 'literal';
    public const CONFIG = 'config';
    public const SECRET = 'secret';
    public const UNSET = 'unset';

    /**
     * @param string      $name      the variable as the container reads it
     * @param string      $source    one of {@see self::LITERAL}, {@see self::CONFIG}, {@see self::SECRET}, {@see self::UNSET}
     * @param string|null $value     the value the panel may show and inline, null for secrets and unset variables
     * @param string|null $configKey the app config key the plugin pointed at, when it did
     */
    public function __construct(
        public string $name,
        public string $source,
        public ?string $value,
        public ?string $configKey,
    ) {
    }

    /** Applies the rule to one declaration against the app's config bag (absent when the panel has none). */
    public static function of(EnvVar $var, ?Config $config): self
    {
        if ($var->secret) {
            return new self($var->name, self::SECRET, null, $var->configKey);
        }
        if ($var->configKey !== null && $config !== null && $config->has($var->configKey)) {
            $value = self::stringify($config->get($var->configKey));
            if ($value !== null) {
                return new self($var->name, self::CONFIG, $value, $var->configKey);
            }
        }
        if ($var->value !== null) {
            return new self($var->name, self::LITERAL, $var->value, $var->configKey);
        }

        return new self($var->name, self::UNSET, null, $var->configKey);
    }

    /**
     * The compose `environment:` value — the resolved string, or `${NAME}` when the operator supplies it.
     *
     * Compose interpolates `$` in every value, so an inlined value escapes each `$` as `$$` and reads
     * back as the bytes the app holds; only the deliberate `${NAME}` placeholder is left for compose
     * to substitute.
     */
    public function composeValue(): string
    {
        return $this->value === null ? '${' . $this->name . '}' : str_replace('$', '$$', $this->value);
    }

    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? 'true' : 'false',
            default => null,
        };
    }
}
