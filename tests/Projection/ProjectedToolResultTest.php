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

use Milpa\Live\Schema\FormSubmission;
use Milpa\Live\Schema\ValidationResult;
use Milpa\Admin\Projection\BannerTone;
use Milpa\Admin\Projection\FormBanner;
use Milpa\Admin\Projection\ProjectedToolResult;
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\TestCase;

final class ProjectedToolResultTest extends TestCase
{
    public function test_success_carries_the_tool_result_and_refuses_redisplay_accessors(): void
    {
        $result = ProjectedToolResult::success(ToolResult::success(['ok' => true]));

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->toolResult()->success);
        $this->expectException(\LogicException::class);
        $result->submission();
    }

    public function test_redisplay_carries_the_real_submission_and_optional_banner(): void
    {
        $submission = new FormSubmission(['siteName' => ''], new ValidationResult(false, []));
        $banner = new FormBanner('FORBIDDEN', BannerTone::Danger, 'No tienes permiso.');
        $result = ProjectedToolResult::redisplay($submission, $banner);

        self::assertFalse($result->isSuccess());
        self::assertSame($submission, $result->submission());
        self::assertSame($banner, $result->banner());
        $this->expectException(\LogicException::class);
        $result->toolResult();
    }
}
