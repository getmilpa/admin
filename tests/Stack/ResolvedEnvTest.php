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

use Milpa\Admin\Stack\ResolvedEnv;
use Milpa\Runtime\Config;
use Milpa\Runtime\Stack\EnvVar;
use PHPUnit\Framework\TestCase;

/**
 * The one rule the card and the compose file share, named in precedence order:
 * secret → placeholder (never the value) > config value > literal > unset placeholder.
 */
final class ResolvedEnvTest extends TestCase
{
    public function testASecretIsAPlaceholderEvenWhenTheConfigHoldsAValue(): void
    {
        $config = new Config(['hub' => ['key' => 'held-by-the-app']]);

        $secret = ResolvedEnv::of(new EnvVar('HUB_JWT_KEY', configKey: 'hub.key', secret: true), $config);

        self::assertSame(ResolvedEnv::SECRET, $secret->source);
        self::assertNull($secret->value, 'the config value a secret points at is never read out');
        self::assertSame('hub.key', $secret->configKey, 'the pointer is still reported so the operator knows where it lives');
        self::assertSame('${HUB_JWT_KEY}', $secret->composeValue());
    }

    public function testAConfigValueBeatsTheLiteral(): void
    {
        $config = new Config(['db' => ['user' => 'from-config']]);

        $resolved = ResolvedEnv::of(new EnvVar('DB_USER', value: 'from-code', configKey: 'db.user'), $config);

        self::assertSame(ResolvedEnv::CONFIG, $resolved->source);
        self::assertSame('from-config', $resolved->value);
        self::assertSame('from-config', $resolved->composeValue());
    }

    public function testTheLiteralIsTheFallbackWhenTheConfigLacksTheKeyOrThereIsNoConfig(): void
    {
        $lacking = ResolvedEnv::of(new EnvVar('DB_HOST', value: 'localhost', configKey: 'db.host'), new Config(['db' => []]));
        $noConfig = ResolvedEnv::of(new EnvVar('DB_HOST', value: 'localhost', configKey: 'db.host'), null);
        $noKey = ResolvedEnv::of(new EnvVar('DB_HOST', value: 'localhost'), new Config(['db' => ['host' => 'ignored']]));

        foreach ([$lacking, $noConfig, $noKey] as $resolved) {
            self::assertSame(ResolvedEnv::LITERAL, $resolved->source);
            self::assertSame('localhost', $resolved->value);
            self::assertSame('localhost', $resolved->composeValue());
        }
        self::assertSame('db.host', $lacking->configKey);
        self::assertNull($noKey->configKey);
    }

    public function testNothingToResolveIsAnUnsetPlaceholder(): void
    {
        $pointed = ResolvedEnv::of(new EnvVar('DB_NAME', configKey: 'db.name'), new Config([]));
        $bare = ResolvedEnv::of(new EnvVar('DB_NAME'), null);

        foreach ([$pointed, $bare] as $resolved) {
            self::assertSame(ResolvedEnv::UNSET, $resolved->source);
            self::assertNull($resolved->value);
            self::assertSame('${DB_NAME}', $resolved->composeValue());
        }
        self::assertSame('db.name', $pointed->configKey);
    }

    public function testConfigScalarsSpellThemselvesAndANonScalarCountsAsAbsent(): void
    {
        $config = new Config(['db' => ['port' => 5432, 'ssl' => false, 'ratio' => 1.5, 'opts' => ['not', 'a', 'string']]]);

        self::assertSame('5432', ResolvedEnv::of(new EnvVar('DB_PORT', configKey: 'db.port'), $config)->value);
        self::assertSame('false', ResolvedEnv::of(new EnvVar('DB_SSL', configKey: 'db.ssl'), $config)->value);
        self::assertSame('1.5', ResolvedEnv::of(new EnvVar('DB_RATIO', configKey: 'db.ratio'), $config)->value);
        $opts = ResolvedEnv::of(new EnvVar('DB_OPTS', value: 'literal', configKey: 'db.opts'), $config);
        self::assertSame(ResolvedEnv::LITERAL, $opts->source, 'an array is not a value the container can read');
        self::assertSame(ResolvedEnv::UNSET, ResolvedEnv::of(new EnvVar('DB_OPTS', configKey: 'db.opts'), $config)->source);
    }

    public function testComposeValueEscapesEveryDollarItInlinesAndLeavesThePlaceholderAlone(): void
    {
        $config = new Config(['app' => ['home' => '${HOME}/app']]);

        $literal = ResolvedEnv::of(new EnvVar('PRICE', value: 'costs $5 or $$6'), null);
        $fromConfig = ResolvedEnv::of(new EnvVar('APP_HOME', configKey: 'app.home'), $config);
        $unset = ResolvedEnv::of(new EnvVar('OPERATOR'), null);

        self::assertSame('costs $$5 or $$$$6', $literal->composeValue(), 'compose interpolates $, so every one doubles');
        self::assertSame('costs $5 or $$6', $literal->value, 'the card shows the bytes the app holds');
        self::assertSame('$${HOME}/app', $fromConfig->composeValue(), 'a config value that looks like a placeholder is still a value');
        self::assertSame('${OPERATOR}', $unset->composeValue(), 'only the deliberate placeholder is left for compose');
    }
}
