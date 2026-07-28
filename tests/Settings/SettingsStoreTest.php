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

namespace Milpa\Admin\Tests\Settings;

use Milpa\Admin\Settings\RepositorySettingsStateProvider;
use Milpa\Admin\Settings\SettingsEntity;
use Milpa\Admin\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsStoreTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }

        $this->file = null;
    }

    public function test_round_trip_persists_and_reads_back(): void
    {
        $this->file = sys_get_temp_dir() . '/milpa-admin-settings-' . bin2hex(random_bytes(4)) . '.json';
        $repo = new SettingsRepository($this->file);
        $repo->save(new SettingsEntity('Acme', true, 'dark'));

        $provider = new RepositorySettingsStateProvider($repo);
        self::assertSame(['siteName' => 'Acme', 'maintenance' => true, 'theme' => 'dark'], $provider->state());
    }

    public function test_defaults_when_empty(): void
    {
        $this->file = sys_get_temp_dir() . '/milpa-admin-settings-' . bin2hex(random_bytes(4)) . '.json';
        $provider = new RepositorySettingsStateProvider(new SettingsRepository($this->file));
        self::assertSame(['siteName' => 'Milpa Admin', 'maintenance' => false, 'theme' => 'light'], $provider->state());
    }
}
