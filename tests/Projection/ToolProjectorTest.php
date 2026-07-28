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

namespace Milpa\Admin\Tests\Projection;

use Milpa\Live\Schema\SchemaForm;
use Milpa\Admin\Projection\BannerTone;
use Milpa\Admin\Projection\ToolBannerMapper;
use Milpa\Admin\Projection\ToolProjector;
use Milpa\Admin\Projection\WebConfirmationUnsupportedException;
use Milpa\ToolRuntime\Attributes\Param;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolScanner;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

// ── tools sintéticos ─────────────────────────────────────────────────────────
final class RecordingProjectionTool
{
    public int $calls = 0;

    #[Tool(name: 'projection_record', description: 'Registra una llamada.')]
    public function run(#[Param(description: 'Un valor', required: true)] string $value): array
    {
        ++$this->calls;

        return ['stored' => $value];
    }
}

final class ScopedProjectionTool
{
    #[Tool(name: 'projection_scoped', description: 'Exige scope.', scopes: ['milpa.admin'])]
    public function run(#[Param(description: 'Un valor', required: true)] string $value): array
    {
        return ['ok' => true];
    }
}

final class ThrowingProjectionTool
{
    #[Tool(name: 'projection_boom', description: 'Lanza.')]
    public function run(#[Param(description: 'Un valor', required: true)] string $value): array
    {
        throw new \RuntimeException('detalle-interno-que-no-debe-filtrarse');
    }
}

final class ConfirmingProjectionTool
{
    public int $calls = 0;

    #[Tool(name: 'projection_confirm', description: 'Requiere confirmación.', confirm: true)]
    public function run(#[Param(description: 'Un valor', required: true)] string $value): array
    {
        ++$this->calls;

        return ['ok' => true];
    }
}

final class ConfirmingScopedProjectionTool
{
    public int $calls = 0;

    #[Tool(name: 'projection_confirm_scoped', description: 'Exige scope y confirmación.', scopes: ['milpa.superadmin'], confirm: true)]
    public function run(#[Param(description: 'Un valor', required: true)] string $value): array
    {
        ++$this->calls;

        return ['ok' => true];
    }
}

// ── logger spy ───────────────────────────────────────────────────────────────
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }

    public function messagesContaining(string $needle): int
    {
        return \count(array_filter($this->records, static fn (array $r): bool => str_contains($r['message'], $needle)));
    }
}

final class ToolProjectorTest extends TestCase
{
    private SpyLogger $logger;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new SpyLogger();
        $this->registry = new ToolRegistry($this->logger);
    }

    /** @return array{0: ToolProjector, 1: \Milpa\Live\Schema\FormDefinition} */
    private function projectorFor(object $service, string $toolName): array
    {
        (new ToolScanner($this->registry))->scan($service);
        $tool = $this->registry->getDefinition($toolName);
        self::assertNotNull($tool);
        $definition = (new SchemaForm())->fromSchema($toolName, $tool->inputSchema);

        return [new ToolProjector($this->registry, new SchemaForm(), new ToolBannerMapper()), $definition];
    }

    public function test_invalid_bind_redisplays_and_never_reaches_the_registry(): void
    {
        $service = new RecordingProjectionTool();
        [$projector, $definition] = $this->projectorFor($service, 'projection_record');

        $result = $projector->dispatch('projection_record', $definition, ['value' => ''], ToolContext::web('e2e-admin', ['milpa.admin']));

        self::assertFalse($result->isSuccess());
        self::assertFalse($result->submission()->validation->ok);
        self::assertNull($result->banner());
        self::assertSame(0, $service->calls, 'el bind inválido jamás llega al registry');
        self::assertSame([], $this->logger->records, 'sin llamada al registry no hay audit');
    }

    public function test_valid_dispatch_succeeds_and_leaves_audit_evidence(): void
    {
        $service = new RecordingProjectionTool();
        [$projector, $definition] = $this->projectorFor($service, 'projection_record');

        $result = $projector->dispatch('projection_record', $definition, ['value' => 'hola'], ToolContext::web('e2e-admin', ['milpa.admin']));

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $service->calls);
        self::assertGreaterThan(0, $this->logger->messagesContaining('projection_record'), 'tool.executed deja evidencia de audit');
    }

    public function test_forbidden_maps_to_a_danger_banner_and_audits_the_denial(): void
    {
        [$projector, $definition] = $this->projectorFor(new ScopedProjectionTool(), 'projection_scoped');

        // actor SIN el scope que el tool exige → PolicyGate deniega
        $result = $projector->dispatch('projection_scoped', $definition, ['value' => 'x'], ToolContext::web('intruso', []));

        self::assertFalse($result->isSuccess());
        self::assertNotNull($result->banner());
        self::assertSame(BannerTone::Danger, $result->banner()->tone);
        self::assertGreaterThan(0, \count($this->logger->records), 'la denegación deja evidencia de audit');
    }

    public function test_a_throwing_tool_becomes_a_generic_banner_without_leaking(): void
    {
        [$projector, $definition] = $this->projectorFor(new ThrowingProjectionTool(), 'projection_boom');

        $result = $projector->dispatch('projection_boom', $definition, ['value' => 'x'], ToolContext::web('e2e-admin', ['milpa.admin']));

        self::assertFalse($result->isSuccess());
        self::assertNotNull($result->banner());
        self::assertStringNotContainsString('detalle-interno-que-no-debe-filtrarse', $result->banner()->message);
    }

    public function test_confirm_required_fails_as_surface_incompatibility_before_the_call(): void
    {
        $service = new ConfirmingProjectionTool();
        [$projector, $definition] = $this->projectorFor($service, 'projection_confirm');

        try {
            $projector->dispatch('projection_confirm', $definition, ['value' => 'x'], ToolContext::web('e2e-admin', ['milpa.admin']));
            self::fail('debió lanzar WebConfirmationUnsupportedException');
        } catch (WebConfirmationUnsupportedException $e) {
            self::assertStringContainsString('MILPA_WEB_CONFIRMATION_UNSUPPORTED', $e->getMessage());
        }

        self::assertSame(0, $service->calls, 'el registry nunca ejecuta un tool con confirm en esta superficie');
    }

    public function test_authorization_wins_over_the_confirm_guard_for_an_unauthorized_actor(): void
    {
        $service = new ConfirmingScopedProjectionTool();
        [$projector, $definition] = $this->projectorFor($service, 'projection_confirm_scoped');

        // actor SIN el scope que el tool exige, aunque el tool TAMBIÉN requiera confirmación: la
        // autorización corre primero — jamás debe filtrarse la incompatibilidad de superficie a un
        // actor que ni siquiera tiene permiso de llamar al tool.
        $result = $projector->dispatch('projection_confirm_scoped', $definition, ['value' => 'x'], ToolContext::web('intruso', []));

        self::assertFalse($result->isSuccess());
        self::assertNotNull($result->banner());
        self::assertSame(BannerTone::Danger, $result->banner()->tone);
        self::assertSame(0, $service->calls, 'el tool jamás ejecuta: ni por confirm-guard ni por FORBIDDEN');
        self::assertGreaterThan(0, \count($this->logger->records), 'la denegación de autorización deja evidencia de audit');
    }
}
