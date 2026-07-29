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

use Milpa\Admin\Contracts\StorageRootSource;
use Milpa\Admin\Settings\RepositorySettingsStateProvider;
use Milpa\Admin\Settings\SettingsEntity;
use Milpa\Admin\Settings\SettingsRepository;
use Milpa\Admin\Settings\SettingsStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsStoreTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }

        $this->file = null;

        // La raíz atada es estática: dejarla puesta se la hereda al siguiente test, y el que la
        // heredara pasaría por una razón que no es la suya.
        SettingsStore::bindStorageRoot(null);
        putenv('MILPA_ADMIN_SETTINGS_PATH');
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

    public function test_path_hangs_from_the_root_the_host_declared(): void
    {
        putenv('MILPA_ADMIN_SETTINGS_PATH');
        SettingsStore::bindStorageRoot(new class () implements StorageRootSource {
            public function storageRoot(): string
            {
                return '/var/lib/otro-host/storage';
            }
        });

        self::assertSame('/var/lib/otro-host/storage/milpa-admin/settings.json', SettingsStore::path());
    }

    public function test_a_trailing_slash_does_not_double(): void
    {
        putenv('MILPA_ADMIN_SETTINGS_PATH');
        SettingsStore::bindStorageRoot(new class () implements StorageRootSource {
            public function storageRoot(): string
            {
                return '/var/lib/otro-host/storage/';
            }
        });

        self::assertSame('/var/lib/otro-host/storage/milpa-admin/settings.json', SettingsStore::path());
    }

    /**
     * Sin raíz declarada NO hay valor por defecto. Ésta es la prueba del defecto que originó el
     * puerto: antes devolvía una ruta calculada contando directorios, que desde `vendor/` apuntaba
     * dentro de `vendor/` y se borraba en el siguiente `composer install` sin que nadie lo notara.
     */
    public function test_without_a_declared_root_it_refuses_instead_of_guessing(): void
    {
        putenv('MILPA_ADMIN_SETTINGS_PATH');
        SettingsStore::bindStorageRoot(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/StorageRootSource|MILPA_ADMIN_SETTINGS_PATH/');
        SettingsStore::path();
    }

    public function test_the_environment_override_still_wins(): void
    {
        SettingsStore::bindStorageRoot(new class () implements StorageRootSource {
            public function storageRoot(): string
            {
                return '/var/lib/otro-host/storage';
            }
        });
        putenv('MILPA_ADMIN_SETTINGS_PATH=/tmp/mandado-por-entorno.json');

        self::assertSame('/tmp/mandado-por-entorno.json', SettingsStore::path());
    }
}
