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

namespace Milpa\Admin\Http;

use GuzzleHttp\Psr7\Response;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Live\Rendering\SchemaFormHtmlRenderer;
use Milpa\Live\Schema\FormDefinition;
use Milpa\Live\Schema\FormView;
use Milpa\Live\Schema\ValidationResult;
use Milpa\Admin\Projection\BannerTone;
use Milpa\Admin\Projection\FormBanner;
use Milpa\Console\Section\Section;
use Milpa\Admin\View\AdminShellRenderer;
use Milpa\Admin\View\SpanishFieldErrorMessages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Renderiza la superficie de Milpa Admin para una request ya autenticada y autorizada: el shell
 * REAL de `milpa/live-web` (Tarea 5) con el form REAL de settings inyectado en `dashboard-main`
 * (Tarea 6) — un `<form method=post action=/milpa/admin/settings>` sin JS (ADR#6: submit nativo,
 * hidden CSRF, botón real), y el sidebar `brand` reflejando el `siteName` guardado en vez del
 * literal `'Milpa Admin'` fijo de la Tarea 5.
 *
 * El hidden `csrf` del form y la cookie `Set-Cookie` que este handler emite comparten SIEMPRE el
 * mismo valor — nunca derivado de `milpa_session` — y el nombre/campo (`milpa_admin_csrf`/`csrf`)
 * es el par exacto que `Milpa\Auth\Http\CsrfGuard('milpa_admin_csrf', 'csrf')` de la Tarea 7 verifica.
 *
 * `dashboard-topbar` SÍ se compone ahora: su botón de toggle (`mui-topbar__nav-toggle`,
 * `@click="navOpen = !navOpen"`) queda respaldado por el runtime Alpine (P5.3b) y se REVELA solo
 * cuando el JS está vivo (`html.milpa-js`, ver AdminPage) — sin JS el sidebar queda visible y el
 * toggle no aparece, así que no hay control muerto (ADR#5). El contenido del topbar (title) es
 * server-truth y se ve siempre (ADR#8).
 */
final class ShellRenderHandler implements RequestHandlerInterface
{
    /**
     * El par nombre-de-cookie/campo-de-formulario del CSRF de Milpa Admin — deben coincidir
     * byte a byte con lo que `Milpa\Auth\Http\CsrfGuard` de la Tarea 7 construye para verificar.
     */
    public const CSRF_COOKIE = 'milpa_admin_csrf';
    public const CSRF_FIELD = 'csrf';

    /** TTL de la cookie CSRF: vive lo suficiente para llenar y enviar el form, no atada a la sesión. */
    private const CSRF_TTL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $values          los valores a mostrar en el form — los actuales de
     *                                              configuración en el GET
     *                                              ({@see \Milpa\Admin\Settings\SettingsStateProvider::state()}),
     *                                              o los de la SUMISIÓN cuando este handler se reusa para
     *                                              el redisplay inválido del POST de settings (Tarea 7) —
     *                                              nunca una mezcla de ambos
     * @param bool                 $secure          el flag `Secure` de la cookie CSRF, YA resuelto por el
     *                                              controller vía `Request::isSecure()` de Symfony
     *                                              (consciente de proxies de confianza); este handler no lo
     *                                              re-deriva de los headers PSR-7 crudos, que un cliente
     *                                              cualquiera podría falsificar con `X-Forwarded-Proto`
     * @param ValidationResult     $validation      el resultado de validación a mostrar — siempre-ok en el
     *                                              GET (default, ningún error que mostrar todavía), o el
     *                                              `$submission->validation` real del bind cuando el POST de
     *                                              settings (Tarea 7) reusa este handler para el redisplay
     *                                              inválido (server-truth: los errores vienen SIEMPRE de la
     *                                              sumisión, nunca inventados aquí)
     * @param ?FormBanner          $banner          el aviso form-level (P5.4) a pintar ANTES del `<form>` —
     *                                              `null` en el GET normal (default, ningún aviso todavía),
     *                                              o el `FormBanner` real que arma
     *                                              {@see \Milpa\Admin\Projection\ToolProjector}'s
     *                                              resultado de redisplay cuando una acción de tool falla
     *                                              (Tarea 6) — server-rendered (ADR#8), sin depender de JS
     *                                              para mostrarse
     * @param list<Section>        $sections        las secciones descubiertas (ADR#12), YA ordenadas
     *                                              por {@see \Milpa\Console\Section\SectionDiscovery::sections()}
     *                                              — este handler NUNCA re-ordena. `[]` (default) cae
     *                                              al único item hardcoded pre-Tarea-5 (BC de
     *                                              construcción para cualquier caller que aún no las
     *                                              pase)
     * @param string               $activeSectionId el id de la sección dueña de esta página,
     *                                              declarado por el controller — matchea por key
     *                                              estricto contra `$sections` (el componente emite
     *                                              `aria-current="page"` cuando coincide)
     * @param ?string              $brand           el nombre a pintar en el chrome (topbar title +
     *                                              sidebar brand) — SIEMPRE el `siteName` PERSISTIDO
     *                                              ({@see \Milpa\Admin\Settings\SettingsStateProvider::state()}),
     *                                              nunca el de la sumisión: un redisplay inválido
     *                                              manda `siteName=''` en `$values`, y derivar el
     *                                              brand de ahí blanquearía el chrome en cada rechazo.
     *                                              `null` (default) cae al `$values['siteName']` —
     *                                              correcto en el GET, donde `$values` YA es el
     *                                              persistido, y BC para callers que no lo pasen
     */
    public function __construct(
        private readonly FormDefinition $definition,
        private readonly array $values,
        private readonly string $csrf,
        private readonly bool $secure,
        private readonly ValidationResult $validation = new ValidationResult(true, []),
        private readonly ?FormBanner $banner = null,
        private readonly array $sections = [],
        private readonly string $activeSectionId = 'settings',
        private readonly ?string $brand = null,
    ) {
    }

    /** Pinta el shell del admin y emite la cookie CSRF que el POST verificará. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var AuthContext $context */
        $context = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
        // Not `$context->actor?->id ?? 'unknown'`: PHPStan flags that nullsafe access as
        // `nullsafe.neverNull` at this family's phpstan level even though `$context->actor`
        // genuinely defaults to `null` for an anonymous context.
        $actorId = $context->actor !== null ? $context->actor->id : 'unknown';

        // El brand del chrome sale SIEMPRE del `siteName` persistido que el controller pasa
        // (`$this->brand`), nunca de `$this->values`: en el redisplay inválido `$values` trae la
        // sumisión (`siteName=''`), y derivar el brand de ahí blanquearía topbar+sidebar en cada
        // rechazo (B3). El fallback a `$values['siteName']` sólo aplica cuando ningún brand se pasó
        // — el GET, donde `$values` YA es el persistido.
        $brand = $this->brand ?? (string) ($this->values['siteName'] ?? 'Milpa Admin');

        $page = (new AdminShellRenderer())->render(
            $this->settingsFormHtml(),
            $this->sections,
            $this->activeSectionId,
            $brand,
            $actorId,
        );

        $setCookie = (string) new Cookie(
            self::CSRF_COOKIE,
            $this->csrf,
            time() + self::CSRF_TTL_SECONDS,
            '/milpa/admin',
            null,
            $this->secure,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Set-Cookie' => $setCookie,
        ], $page);
    }

    /**
     * El `<form>` real de settings, sin JS: método+action fijos, el hidden `csrf`, los campos
     * estilizados de {@see SchemaFormHtmlRenderer} (SOLO campos, nunca el `<form>` — eso es glue
     * de este handler) sobre `$this->values`/`$this->validation` (actuales+siempre-ok en el GET,
     * o los de la sumisión inválida en el redisplay del POST — Tarea 7), y un submit nativo real.
     */
    private function settingsFormHtml(): string
    {
        $fieldsHtml = (new SchemaFormHtmlRenderer(new SpanishFieldErrorMessages()))->render(
            new FormView($this->definition, $this->values, $this->validation),
        );

        $csrfAttr = htmlspecialchars($this->csrf, \ENT_QUOTES, 'UTF-8');

        return $this->bannerHtml()
            . '<form method="post" action="/milpa/admin/settings">'
            . '<input type="hidden" name="' . self::CSRF_FIELD . '" value="' . $csrfAttr . '"/>'
            . $fieldsHtml
            . '<button type="submit" class="mui-btn mui-btn--primary mui-btn--sm">Guardar</button>'
            . '</form>';
    }

    /**
     * El aviso form-level (P5.4): server-rendered (ADR#8), tone→clase del design system, texto del
     * VO ya seguro (ToolBannerMapper) — aquí solo se escapa y se pinta, sin lógica inventada.
     */
    private function bannerHtml(): string
    {
        if ($this->banner === null) {
            return '';
        }

        $toneClass = $this->banner->tone === BannerTone::Warning ? 'mui-banner--warning' : 'mui-banner--danger';

        return '<div class="mui-banner ' . $toneClass . '" role="alert" data-banner-code="'
            . htmlspecialchars($this->banner->code, \ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($this->banner->message, \ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}
