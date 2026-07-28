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

use Milpa\Admin\Projection\BannerTone;
use Milpa\Admin\Projection\ToolBannerMapper;
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\TestCase;

final class ToolBannerMapperTest extends TestCase
{
    private ToolBannerMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ToolBannerMapper();
    }

    public function test_forbidden_maps_to_danger_without_leaking_the_reason(): void
    {
        $banner = $this->mapper->map(ToolResult::error('policy: actor lacks milpa.admin on rule #42', null, ['code' => ToolResult::FORBIDDEN]));

        self::assertSame(ToolResult::FORBIDDEN, $banner->code);
        self::assertSame(BannerTone::Danger, $banner->tone);
        self::assertStringNotContainsString('rule #42', $banner->message);
    }

    public function test_rate_limited_maps_to_warning_with_retry_seconds(): void
    {
        $banner = $this->mapper->map(ToolResult::error('rate limited', null, ['code' => ToolResult::RATE_LIMITED, 'retry_after_seconds' => 30]));

        self::assertSame(BannerTone::Warning, $banner->tone);
        self::assertStringContainsString('30', $banner->message);
    }

    public function test_internal_error_never_echoes_the_raw_error(): void
    {
        $banner = $this->mapper->map(ToolResult::error('PDOException: SQLSTATE[HY000] /var/secret/path', null, ['code' => ToolResult::INTERNAL_ERROR]));

        self::assertSame(BannerTone::Danger, $banner->tone);
        self::assertStringNotContainsString('SQLSTATE', $banner->message);
        self::assertStringNotContainsString('/var/secret', $banner->message);
    }

    public function test_unknown_failure_keeps_the_code_and_stays_generic(): void
    {
        $banner = $this->mapper->map(ToolResult::error('raw detail', null, ['code' => 'TOOL_NOT_FOUND']));

        self::assertSame('TOOL_NOT_FOUND', $banner->code);
        self::assertSame(BannerTone::Danger, $banner->tone);
        self::assertStringNotContainsString('raw detail', $banner->message);
    }
}
