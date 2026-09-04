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

namespace Milpa\Admin\Tests\Stack;

use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Tests\Fixtures\HubPlugin;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Runtime\Stack\EnvVar;
use Milpa\Runtime\Stack\PortMapping;
use Milpa\Runtime\Stack\ServiceDeclaration;
use PHPUnit\Framework\TestCase;

final class ComposeProjectionTest extends TestCase
{
    public function testSecretsBecomeVariablesAndConfigValuesAreInlined(): void
    {
        $config = new Config(['hub' => ['public_url' => 'http://localhost:3000', 'key' => 'also-secret']]);
        $services = (new HubPlugin(new DIContainer()))->services();

        $yaml = (new ComposeProjection())->yaml($services, $config);

        $expected = "services:\n"
            . "  hub:\n"
            . "    image: 'example/hub:1'\n"
            . "    ports:\n"
            . "      - '3000:80'\n"
            . "    environment:\n"
            . "      SERVER_NAME: ':80'\n"
            . "      HUB_PUBLIC_URL: 'http://localhost:3000'\n"
            . "      HUB_JWT_KEY: \${HUB_JWT_KEY}\n"
            . "    volumes:\n"
            . "      - 'hub-data:/data'\n"
            . "volumes:\n"
            . "  hub-data: {}\n";
        self::assertSame($expected, $yaml);
        self::assertStringNotContainsString(HubPlugin::SECRET, $yaml, 'the literal a secret carries is never inlined');
        self::assertStringNotContainsString('also-secret', $yaml, 'nor the config value a secret points at');
    }

    public function testAConfigKeyTheAppLacksFallsBackToTheLiteralThenToTheVariable(): void
    {
        $service = new ServiceDeclaration(name: 'db', image: 'postgres', env: [
            new EnvVar('POSTGRES_DB', value: 'app', configKey: 'db.name'),
            new EnvVar('POSTGRES_USER', configKey: 'db.user'),
            new EnvVar('POSTGRES_PASSWORD'),
            new EnvVar('POSTGRES_PORT', configKey: 'db.port'),
            new EnvVar('POSTGRES_SSL', configKey: 'db.ssl'),
            new EnvVar('POSTGRES_OPTS', value: 'literal', configKey: 'db.opts'),
        ]);
        $config = new Config(['db' => ['port' => 5432, 'ssl' => false, 'opts' => ['not', 'a', 'string']]]);

        $yaml = (new ComposeProjection())->yaml([$service], $config);

        self::assertStringContainsString("      POSTGRES_DB: app\n", $yaml, 'the literal is the fallback of an absent config key');
        self::assertStringContainsString("      POSTGRES_USER: \${POSTGRES_USER}\n", $yaml, 'no literal, no config → the operator supplies it');
        self::assertStringContainsString("      POSTGRES_PASSWORD: \${POSTGRES_PASSWORD}\n", $yaml);
        self::assertStringContainsString("      POSTGRES_PORT: '5432'\n", $yaml, 'an int config value is a quoted string');
        self::assertStringContainsString("      POSTGRES_SSL: 'false'\n", $yaml, 'a bool config value spells itself');
        self::assertStringContainsString("      POSTGRES_OPTS: literal\n", $yaml, 'a non-scalar config value counts as absent');
        $withoutConfig = (new ComposeProjection())->yaml([$service], null);
        self::assertNotSame($yaml, $withoutConfig, 'the config bag changes the projection');
        self::assertStringContainsString("      POSTGRES_PORT: \${POSTGRES_PORT}\n", $withoutConfig, 'without config the port is a variable');
    }

    public function testMultilineValuesAreBlockScalars(): void
    {
        $service = new ServiceDeclaration(name: 'mercure', image: 'dunglas/mercure', env: [
            new EnvVar('MERCURE_EXTRA_DIRECTIVES', value: "cors_origins http://localhost:3000\nanonymous"),
            new EnvVar('KEEPS_ONE_NEWLINE', value: "a\nb\n"),
            new EnvVar('KEEPS_ALL_NEWLINES', value: "a\n\n"),
            new EnvVar('LEADING_SPACE', value: " indented\nline"),
        ]);

        $yaml = (new ComposeProjection())->yaml([$service], null);

        self::assertStringContainsString(
            "      MERCURE_EXTRA_DIRECTIVES: |-\n        cors_origins http://localhost:3000\n        anonymous\n",
            $yaml,
        );
        self::assertStringContainsString("      KEEPS_ONE_NEWLINE: |\n        a\n        b\n", $yaml);
        self::assertStringContainsString("      KEEPS_ALL_NEWLINES: |+\n        a\n\n", $yaml);
        self::assertStringContainsString("      LEADING_SPACE: |2-\n         indented\n        line\n", $yaml);
    }

    public function testScalarsThatYamlCouldMisreadAreSingleQuoted(): void
    {
        $service = new ServiceDeclaration(name: 'no', image: 'plain/image', env: [
            new EnvVar('EMPTY', value: ''),
            new EnvVar('APOSTROPHE', value: "it's"),
            new EnvVar('ESCAPED', value: "it's #1"),
            new EnvVar('COLON_SPACE', value: 'a: b'),
            new EnvVar('HASH', value: 'x #y'),
            new EnvVar('BOOL', value: 'true'),
            new EnvVar('NUMBER', value: '42'),
            new EnvVar('PLAIN', value: 'plain-value'),
            new EnvVar('PADDED', value: ' padded'),
            new EnvVar('DASH', value: '- dash'),
            new EnvVar('URL', value: 'http://x'),
        ]);

        $yaml = (new ComposeProjection())->yaml([$service], null);

        self::assertStringContainsString("  'no':\n", $yaml, 'a service named like a YAML boolean is quoted');
        self::assertStringContainsString("    image: plain/image\n", $yaml);
        self::assertStringContainsString("      EMPTY: ''\n", $yaml);
        self::assertStringContainsString("      APOSTROPHE: it's\n", $yaml, 'a mid-string apostrophe is a plain scalar');
        self::assertStringContainsString("      ESCAPED: 'it''s #1'\n", $yaml, 'inside quotes an apostrophe doubles');
        self::assertStringContainsString("      COLON_SPACE: 'a: b'\n", $yaml);
        self::assertStringContainsString("      HASH: 'x #y'\n", $yaml);
        self::assertStringContainsString("      BOOL: 'true'\n", $yaml);
        self::assertStringContainsString("      NUMBER: '42'\n", $yaml);
        self::assertStringContainsString("      PLAIN: plain-value\n", $yaml);
        self::assertStringContainsString("      PADDED: ' padded'\n", $yaml);
        self::assertStringContainsString("      DASH: '- dash'\n", $yaml);
        self::assertStringContainsString("      URL: 'http://x'\n", $yaml);
    }

    public function testUdpPortsCommandAndTheEmptyStack(): void
    {
        $service = new ServiceDeclaration(
            name: 'dns',
            image: 'coredns/coredns',
            ports: [new PortMapping(container: 53, protocol: 'udp'), new PortMapping(container: 53, host: 5353, protocol: 'udp'), new PortMapping(container: 9153)],
            command: ['-conf', '/etc/coredns/Corefile'],
        );

        $yaml = (new ComposeProjection())->yaml([$service], null);

        $expected = "services:\n"
            . "  dns:\n"
            . "    image: coredns/coredns\n"
            . "    ports:\n"
            . "      - '53/udp'\n"
            . "      - '5353:53/udp'\n"
            . "      - '9153'\n"
            . "    command:\n"
            . "      - '-conf'\n"
            . "      - /etc/coredns/Corefile\n";
        self::assertSame($expected, $yaml);
        self::assertSame("services: {}\n", (new ComposeProjection())->yaml([], null));
    }

    public function testTheSameDeclarationsAreTheSameBytesInTheGivenOrder(): void
    {
        $a = new ServiceDeclaration(name: 'a', image: 'a:1', volumes: ['a-data:/a']);
        $b = new ServiceDeclaration(name: 'b', image: 'b:1');
        $projection = new ComposeProjection();

        $first = $projection->yaml([$b, $a], null);
        $second = $projection->yaml([$b, $a], null);

        self::assertSame($first, $second);
        self::assertSame("services:\n  b:\n    image: 'b:1'\n  a:\n    image: 'a:1'\n    volumes:\n      - 'a-data:/a'\nvolumes:\n  a-data: {}\n", $first);
        self::assertStringEndsWith("\n", $first);
    }

    public function testNamedVolumesAreDeclaredOnceAtTheTopLevelAndBindMountsAreNot(): void
    {
        $web = new ServiceDeclaration(name: 'web', image: 'nginx', volumes: [
            './conf:/etc/nginx/conf.d:ro',
            '../shared:/shared',
            '~/certs:/certs',
            '/var/run/docker.sock:/var/run/docker.sock',
            '/anonymous',
            'shared:/s',
            'shared:/t',
            ':odd',
        ]);
        $worker = new ServiceDeclaration(name: 'worker', image: 'app', volumes: ['app-cache:/cache', 'shared:/shared']);

        $yaml = (new ComposeProjection())->yaml([$web, $worker], null);

        self::assertStringEndsWith("volumes:\n  app-cache: {}\n  shared: {}\n", $yaml, 'named volumes, unique, sorted, once');
        self::assertSame(1, substr_count($yaml, "\nvolumes:\n"), 'one top-level block');
        self::assertStringNotContainsString("  conf: {}", $yaml);
        self::assertStringNotContainsString("  var: {}", $yaml);
        self::assertStringNotContainsString("  anonymous", $yaml);
        self::assertStringNotContainsString("volumes:\n", (new ComposeProjection())->yaml([new ServiceDeclaration(name: 'x', image: 'x')], null));
    }
}
