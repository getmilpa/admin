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

use Milpa\Admin\Settings\SettingsFormSchema;
use Milpa\Admin\Settings\SettingsToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tarea 4 (P5.4) — {@see SettingsToolRegistry} arma el registry REAL (logger PSR-3, no desechable)
 * que sirve AMBOS `getDefinition` (el schema del form) y `call` (el dispatch gobernado, Tarea 6).
 * Este test verifica que el registry real resuelve `settings_update` Y que la `FormDefinition` que
 * produce {@see SettingsFormSchema::definition()} con ese registry es IDÉNTICA a la del throwaway
 * sin argumentos (BC del GET) — mismo schema, misma forma, un solo origen de verdad.
 *
 * `MILPA_ADMIN_SETTINGS_PATH` apunta a un archivo temporal por test (mismo idioma que
 * `MilpaAdminSettingsGetTest`) para no leer/escribir la store real de producción.
 */
final class SettingsToolRegistryTest extends TestCase
{
    private string|false $previousSettingsPath = false;
    private ?string $settingsFile = null;

    protected function setUp(): void
    {
        $this->previousSettingsPath = getenv('MILPA_ADMIN_SETTINGS_PATH');
        $this->settingsFile = sys_get_temp_dir() . '/milpa-admin-settings-registry-' . bin2hex(random_bytes(4)) . '.json';
        putenv('MILPA_ADMIN_SETTINGS_PATH=' . $this->settingsFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousSettingsPath === false) {
            putenv('MILPA_ADMIN_SETTINGS_PATH');
        } else {
            putenv('MILPA_ADMIN_SETTINGS_PATH=' . $this->previousSettingsPath);
        }

        if ($this->settingsFile !== null && is_file($this->settingsFile)) {
            unlink($this->settingsFile);
        }

        $this->settingsFile = null;
    }

    public function test_the_real_registry_serves_both_definition_and_call(): void
    {
        $registry = SettingsToolRegistry::create(new NullLogger());

        self::assertNotNull($registry->getDefinition('settings_update'));

        $viaRegistry = SettingsFormSchema::definition($registry);
        $viaThrowaway = SettingsFormSchema::definition();
        self::assertEquals($viaThrowaway, $viaRegistry, 'el registry real produce la MISMA FormDefinition que el throwaway del GET');
    }
}
