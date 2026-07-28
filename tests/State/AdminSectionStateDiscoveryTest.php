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

namespace Milpa\Admin\Tests\State;

use Milpa\Admin\State\AdminSectionStateDiscovery;
use Milpa\Admin\State\AdminSectionStateProvider;
use Milpa\Admin\State\AdminSectionStateSource;
use PHPUnit\Framework\TestCase;

/**
 * `AdminSectionStateDiscovery` resuelve un id de sección → su `AdminSectionStateProvider`, juntando el
 * estado que declaran los plugins `AdminSectionStateSource` booteados — mismo idioma de discovery que
 * `AdminSectionDiscovery` (instanceof sobre los plugins). Es el link sección→estado que permite que un
 * shell (CLI) obtenga el estado de una sección sin routear a un controller web.
 */
final class AdminSectionStateDiscoveryTest extends TestCase
{
    private function provider(array $state): AdminSectionStateProvider
    {
        return new class ($state) implements AdminSectionStateProvider {
            public function __construct(private readonly array $state)
            {
            }

            public function state(): array
            {
                return $this->state;
            }
        };
    }

    private function source(array $map): AdminSectionStateSource
    {
        return new class ($map) implements AdminSectionStateSource {
            /** @param array<string, AdminSectionStateProvider> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function adminSectionStates(): array
            {
                return $this->map;
            }
        };
    }

    public function test_provider_for_resolves_a_declared_section(): void
    {
        $settings = $this->provider(['siteName' => 'Acme']);
        $discovery = new AdminSectionStateDiscovery([
            $this->source(['settings' => $settings]),
            new \stdClass(), // un plugin que NO es AdminSectionStateSource se ignora
        ]);

        self::assertSame($settings, $discovery->providerFor('settings'));
        self::assertSame(['siteName' => 'Acme'], $discovery->providerFor('settings')->state());
    }

    public function test_provider_for_unknown_section_is_null(): void
    {
        $discovery = new AdminSectionStateDiscovery([$this->source(['settings' => $this->provider([])])]);

        self::assertNull($discovery->providerFor('system'));
    }

    public function test_all_returns_the_full_map_across_sources(): void
    {
        $discovery = new AdminSectionStateDiscovery([
            $this->source(['settings' => $this->provider([])]),
            $this->source(['system' => $this->provider(['routes' => []])]),
        ]);

        self::assertSame(['settings', 'system'], array_keys($discovery->all()));
    }
}
