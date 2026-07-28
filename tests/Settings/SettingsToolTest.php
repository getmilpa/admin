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

use Milpa\Live\Schema\FieldType;
use Milpa\Live\Schema\FormDefinition;
use Milpa\Live\Schema\FormField;
use Milpa\Live\Schema\SchemaForm;
use Milpa\Admin\Settings\SettingsRepository;
use Milpa\Admin\Settings\SettingsTool;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolScanner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SettingsToolTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }

        $this->file = null;
    }

    public function test_tool_schema_produces_the_expected_form_definition(): void
    {
        $this->file = sys_get_temp_dir() . '/milpa-admin-settings-tool-' . bin2hex(random_bytes(4)) . '.json';

        $registry = new ToolRegistry($this->createMock(LoggerInterface::class));
        (new ToolScanner($registry))->scan(new SettingsTool(new SettingsRepository($this->file)));

        $definition = $registry->getDefinition('settings_update');
        self::assertNotNull($definition);

        $schema = $definition->inputSchema;
        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('siteName', $schema['properties']);
        self::assertArrayHasKey('maintenance', $schema['properties']);
        self::assertArrayHasKey('theme', $schema['properties']);
        self::assertSame('boolean', $schema['properties']['maintenance']['type']);
        self::assertSame(['light', 'dark'], $schema['properties']['theme']['enum']);
        self::assertContains('siteName', $schema['required']);

        $def = (new SchemaForm())->fromSchema('settings:update', $schema);
        self::assertCount(3, $def->fields);
        self::assertSame(FieldType::Boolean, $this->fieldByName($def, 'maintenance')->type);
        self::assertSame(['light', 'dark'], $this->fieldByName($def, 'theme')->constraints->enumOptions);
    }

    private function fieldByName(FormDefinition $def, string $name): FormField
    {
        foreach ($def->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        self::fail("Field {$name} not found in form definition.");
    }
}
