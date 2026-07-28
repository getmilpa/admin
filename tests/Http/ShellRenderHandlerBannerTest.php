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

namespace Milpa\Admin\Tests\Http;

use GuzzleHttp\Psr7\ServerRequest;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Live\Schema\ValidationResult;
use Milpa\Admin\Http\ShellRenderHandler;
use Milpa\Admin\Projection\BannerTone;
use Milpa\Admin\Projection\FormBanner;
use Milpa\Admin\Settings\SettingsFormSchema;
use PHPUnit\Framework\TestCase;

/**
 * Task 5 (P5.4) — {@see ShellRenderHandler} gana un 6º parámetro ctor opcional `?FormBanner $banner`
 * y lo renderiza server-side (ADR#8) ANTES del `<form>` de settings. El default `null` mantiene a
 * TODOS los callers actuales (5 args, sin banner) con un HTML byte-idéntico — sin bloque
 * `mui-banner` alguno cuando no hay aviso que mostrar.
 */
final class ShellRenderHandlerBannerTest extends TestCase
{
    private function request(): ServerRequest
    {
        return (new ServerRequest('GET', '/milpa/admin'))
            ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::anonymous());
    }

    private function handler(?FormBanner $banner = null): ShellRenderHandler
    {
        return new ShellRenderHandler(
            SettingsFormSchema::definition(),
            ['siteName' => 'Acme', 'maintenance' => false, 'theme' => 'light'],
            'csrf-token-x',
            false,
            new ValidationResult(true, []),
            $banner,
        );
    }

    public function test_a_danger_banner_renders_server_side_above_the_form(): void
    {
        $banner = new FormBanner('FORBIDDEN', BannerTone::Danger, 'No tienes permiso para ejecutar esta operación.');
        $html = (string) $this->handler($banner)->handle($this->request())->getBody();

        self::assertStringContainsString('No tienes permiso para ejecutar esta operación.', $html);
        self::assertStringContainsString('mui-banner--danger', $html);
        self::assertStringContainsString('role="alert"', $html);

        // El banner va ANTES del <form> — server-truth (ADR#8), nunca detrás de un reveal JS.
        self::assertLessThan(strpos($html, '<form method="post"'), strpos($html, 'mui-banner--danger'));
    }

    public function test_a_warning_banner_renders_with_the_warning_tone_class(): void
    {
        $banner = new FormBanner('STALE_STATE', BannerTone::Warning, 'La operación pudo haberse aplicado ya.');
        $html = (string) $this->handler($banner)->handle($this->request())->getBody();

        self::assertStringContainsString('mui-banner--warning', $html);
        self::assertStringContainsString('data-banner-code="STALE_STATE"', $html);
        self::assertStringNotContainsString('mui-banner--danger', $html);
    }

    public function test_without_banner_no_alert_block_renders(): void
    {
        $html = (string) $this->handler()->handle($this->request())->getBody();

        self::assertStringNotContainsString('mui-banner', $html);
    }

    public function test_banner_code_and_message_are_escaped(): void
    {
        $banner = new FormBanner('CODE_<script>', BannerTone::Danger, 'Mensaje con <b>html</b> y "comillas".');
        $html = (string) $this->handler($banner)->handle($this->request())->getBody();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('CODE_&lt;script&gt;', $html);
        self::assertStringContainsString('Mensaje con &lt;b&gt;html&lt;/b&gt; y &quot;comillas&quot;.', $html);
    }
}
