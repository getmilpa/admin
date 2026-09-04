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
use Milpa\Runtime\Stack\ServiceDeclaration;

/**
 * Projects service declarations to a docker-compose fragment — `services:` and one entry per service.
 *
 * Deterministic text, two-space indent, trailing newline, no YAML library: the subset compose reads
 * (`image`, `ports`, `environment`, `volumes`, `command`) written by hand so the output is the same
 * bytes for the same declarations. Secrets are never inlined — they come out as `${NAME}` and the
 * operator supplies them; a `configKey` the app holds is inlined, one it lacks becomes `${NAME}` too.
 * Every scalar that YAML could misread (ports like `3000:80`, `:80`, numbers, booleans, `#`) is
 * single-quoted; multi-line values are block scalars. Named volumes a service mounts are declared at
 * the top level, as compose requires for the file to be a project and not just a fragment.
 */
final class ComposeProjection
{
    private const INDENT = '  ';

    /**
     * The compose fragment for the given services, in the given order.
     *
     * @param list<ServiceDeclaration> $services
     * @param Config|null              $config   the app's config bag, read for `configKey` values
     */
    public function yaml(array $services, ?Config $config): string
    {
        if ($services === []) {
            return "services: {}\n";
        }

        $lines = ['services:'];
        $named = [];
        foreach ($services as $service) {
            array_push($lines, ...$this->service($service, $config));
            array_push($named, ...self::namedVolumes($service));
        }

        $named = array_values(array_unique($named));
        sort($named, SORT_STRING);
        if ($named !== []) {
            $lines[] = 'volumes:';
            foreach ($named as $name) {
                $lines[] = self::INDENT . self::scalar($name) . ': {}';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The named volumes a service mounts — `name:target[:mode]` where the source is not a path — which
     * compose requires declared at the top level. Bind mounts (`./`, `../`, `~`, `/`) and anonymous
     * volumes (a bare container path) are not.
     *
     * @return list<string>
     */
    private static function namedVolumes(ServiceDeclaration $service): array
    {
        $names = [];
        foreach ($service->volumes as $volume) {
            $colon = strpos($volume, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $source = substr($volume, 0, $colon);
            if ($source[0] === '.' || $source[0] === '~' || $source[0] === '/') {
                continue;
            }
            $names[] = $source;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function service(ServiceDeclaration $service, ?Config $config): array
    {
        $one = self::INDENT;
        $two = str_repeat(self::INDENT, 2);
        $three = str_repeat(self::INDENT, 3);

        $lines = [$one . self::scalar($service->name) . ':'];
        $lines[] = $two . 'image: ' . self::scalar($service->image);

        if ($service->ports !== []) {
            $lines[] = $two . 'ports:';
            foreach ($service->ports as $port) {
                $lines[] = $three . '- ' . self::scalar($port->toCompose());
            }
        }
        if ($service->env !== []) {
            $lines[] = $two . 'environment:';
            foreach ($service->env as $var) {
                $resolved = ResolvedEnv::of($var, $config);
                array_push($lines, ...self::entry($three, $resolved->name, $resolved->composeValue()));
            }
        }
        if ($service->volumes !== []) {
            $lines[] = $two . 'volumes:';
            foreach ($service->volumes as $volume) {
                $lines[] = $three . '- ' . self::scalar($volume);
            }
        }
        if ($service->command !== []) {
            $lines[] = $two . 'command:';
            foreach ($service->command as $word) {
                $lines[] = $three . '- ' . self::scalar($word);
            }
        }

        return $lines;
    }

    /**
     * A `key: value` mapping entry — one line, or a block scalar when the value spans lines.
     *
     * @return list<string>
     */
    private static function entry(string $indent, string $key, string $value): array
    {
        if (!str_contains($value, "\n")) {
            return [$indent . $key . ': ' . self::scalar($value)];
        }

        $chomp = '-';
        $body = $value;
        if (str_ends_with($body, "\n")) {
            $body = substr($body, 0, -1);
            $chomp = str_ends_with($body, "\n") ? '+' : '';
        }
        $indicator = $body !== '' && ($body[0] === ' ' || $body[0] === "\t") ? '2' : '';

        $lines = [$indent . $key . ': |' . $indicator . $chomp];
        foreach (explode("\n", $body) as $line) {
            $lines[] = $line === '' ? '' : $indent . self::INDENT . $line;
        }

        return $lines;
    }

    /** The scalar as YAML reads it back as the same string: plain when safe, single-quoted otherwise. */
    private static function scalar(string $value): string
    {
        return self::needsQuotes($value) ? "'" . str_replace("'", "''", $value) . "'" : $value;
    }

    private static function needsQuotes(string $value): bool
    {
        if ($value === '' || trim($value) !== $value) {
            return true;
        }
        if (str_contains($value, ':') || str_contains($value, '#')) {
            return true;
        }
        if (preg_match('/^[-?,\[\]{}&*!|>\'"%@`]/', $value) === 1) {
            return true;
        }
        if (preg_match('/^[-+.]?[0-9]/', $value) === 1) {
            return true;
        }

        return preg_match('/^(?:true|false|yes|no|on|off|y|n|null|~|\.inf|-\.inf|\.nan)$/i', $value) === 1;
    }
}
