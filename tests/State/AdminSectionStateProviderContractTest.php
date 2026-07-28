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

use Milpa\Admin\Settings\RepositorySettingsStateProvider;
use Milpa\Admin\Settings\SettingsEntity;
use Milpa\Admin\Settings\SettingsRepository;
use Milpa\Admin\State\AdminSectionStateProvider;
use PHPUnit\Framework\TestCase;

/**
 * El fold de P5.7: `SettingsStateProvider` se generaliza al contrato `AdminSectionStateProvider` (`state():
 * array`). Un provider de settings ES un `AdminSectionStateProvider`, y `state()` devuelve la config persistida
 * sin el id — prueba la generalización con el primer consumidor real.
 */
final class AdminSectionStateProviderContractTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/milpa-section-state-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_settings_provider_is_an_admin_section_state_provider_exposing_state(): void
    {
        $repository = new SettingsRepository($this->file);
        $repository->save(new SettingsEntity('Acme', true, 'dark'));
        $provider = new RepositorySettingsStateProvider($repository);

        self::assertInstanceOf(AdminSectionStateProvider::class, $provider);

        $state = $provider->state();
        self::assertArrayNotHasKey('id', $state);
        self::assertSame('Acme', $state['siteName']);
        self::assertTrue($state['maintenance']);
        self::assertSame('dark', $state['theme']);
    }
}
