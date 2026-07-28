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

namespace Milpa\Admin\Tests;

use Milpa\Auth\ActorType;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Admin\Controllers\SettingsController;
use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Settings\SettingsEntity;
use Milpa\Admin\Settings\SettingsRepository;
use Milpa\Admin\Tests\Fixtures\NeighbourPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fake `PluginsManagerInterface` mínimo para este archivo: la Tarea 5 hace que
 * {@see SettingsController::save()} resuelva el discovery (ADR#12, mismo idioma que el Hub) ANTES
 * de que `runGated()` corra el pipeline, así que TODOS los tests de este archivo — incluidos los
 * anónimos/403/CSRF que nunca llegan al `$tip` — necesitan `PluginsManagerInterface` registrado, no
 * solo los que llegan al redisplay. Envuelve los dos providers REALES (mismo par que
 * {@see \Tests\Unit\Plugins\MilpaAdminPlugin\Section\AdminSectionProvidersTest}) para que el
 * redisplay pinte el sidebar completo, no un fixture inventado.
 */
final class SettingsPostFakePluginsManager implements PluginsManagerInterface
{
    /** @param array<string, PluginInterface> $plugins */
    public function __construct(private readonly array $plugins)
    {
    }

    public function addPluginPath(string $path): void
    {
    }

    public function loadPlugins(): void
    {
    }

    public function getToolProviderPromptSections(): array
    {
        return [];
    }

    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function getPlugin(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    public function isEnabled(string $name): bool
    {
        return true;
    }
}

/**
 * Tarea 7 — `POST /milpa/admin/settings` detrás del mismo gate compartido
 * ({@see \Milpa\Admin\Controllers\GatedAdminController}) más
 * {@see \Milpa\Auth\Http\CsrfGuard('milpa_admin_csrf', 'csrf')} como `$extraMiddleware`: CSRF fail-closed
 * ANTES de bindear/persistir, bind vía `SchemaForm`, dispatch GOBERNADO vía `ToolProjector` (P5.4,
 * Tarea 6, ADR#11 — el ceremonial de la Tarea 3 se graduó), y PRG (302 a `/milpa/admin` en éxito) o
 * redisplay-desde-la-sumisión (NUNCA la store) en error — el checkbox ausente es el caso explícito
 * que prueba que el redisplay nunca mezcla sumisión con store.
 *
 * `MILPA_ADMIN_SETTINGS_PATH` apunta a un archivo temporal por test (mismo idioma que
 * {@see MilpaAdminSettingsGetTest}) para no leer/escribir la store real de producción.
 *
 * Tarea 3 (P5.5) — el PRG pasa de 302 a `/milpa/admin` a 303 See Other a `/milpa/admin/settings`:
 * Settings ya tiene su propia ruta GET (`SettingsController::show()`), así que el refresh
 * post-POST navega ahí en vez del futuro Hub.
 */

/**
 * Spy de `LoggerInterface` para el assert de audit del dispatch gobernado (Tarea 6). Copia inline
 * del mismo patrón que {@see \Tests\Unit\Plugins\MilpaAdminPlugin\Projection\ToolProjectorTest}'s
 * `SpyLogger` — el host no tiene un `tests/Support/` compartido todavía, así que esta duplicación
 * puntual es intencional en vez de introducir esa convención en una tarea de integración.
 */
final class SettingsPostSpyLogger extends AbstractLogger
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

final class MilpaAdminSettingsPostTest extends TestCase
{
    private const COOKIE = 'milpa_session';
    private const CSRF_COOKIE = 'milpa_admin_csrf';
    private const CSRF_FIELD = 'csrf';

    private string|false $previousSettingsPath = false;
    private ?string $settingsFile = null;

    protected function setUp(): void
    {
        if (!defined('rootPath')) {
            define('rootPath', dirname(__DIR__, 4));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        $this->previousSettingsPath = getenv('MILPA_ADMIN_SETTINGS_PATH');
        $this->settingsFile = sys_get_temp_dir() . '/milpa-admin-settings-post-' . bin2hex(random_bytes(4)) . '.json';
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
    }

    // ---- fixtures -----------------------------------------------------------------------------

    private function session(string $id, array $scopes): SessionRecord
    {
        $now = new \DateTimeImmutable();

        return new SessionRecord(
            id: $id,
            actorId: 'actor-' . $id,
            actorType: ActorType::User,
            createdAt: $now,
            expiresAt: $now->modify('+1 hour'),
            scopes: $scopes,
        );
    }

    private function container(SessionStore $store, ?LoggerInterface $logger = null): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(LoggerInterface::class, $logger ?? new NullLogger());
        $container->registerService(SessionStore::class, $store);
        $container->registerService(PluginsManagerInterface::class, new SettingsPostFakePluginsManager([
            'milpa-admin' => new AdminPlugin($container),
            'neighbour' => new NeighbourPlugin($container),
        ]));

        // Ola 5b: HttpResponderInterface, misma construcción que los extintos WebManager/CliManager
        // registraban tras loadPlugins() (Ola 7c) — BaseController (el paquete) lo resuelve en el constructor.
        $container->registerService(
            \Milpa\Http\Symfony\HttpResponderInterface::class,
            new \Milpa\Admin\Tests\Fixtures\EchoResponder(),
        );

        return $container;
    }

    private function repository(): SettingsRepository
    {
        return new SettingsRepository((string) $this->settingsFile);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function authedRequest(
        array $fields,
        ?string $csrfCookie,
        ?string $csrfField,
        string $sessionId = 'admin-settings-post',
    ): Request {
        $cookies = [self::COOKIE => $sessionId];
        if ($csrfCookie !== null) {
            $cookies[self::CSRF_COOKIE] = $csrfCookie;
        }

        $params = $fields;
        if ($csrfField !== null) {
            $params[self::CSRF_FIELD] = $csrfField;
        }

        return Request::create('/milpa/admin/settings', 'POST', $params, $cookies);
    }

    // ---- 1. valid POST: persists + PRG -------------------------------------------------------

    public function testValidPostPersistsAndRedirectsToAdmin(): void
    {
        $this->repository()->save(new SettingsEntity('Old Name', false, 'light'));

        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-post', ['milpa.admin']));
        $controller = new SettingsController($this->container($store));

        $token = bin2hex(random_bytes(16));
        $request = $this->authedRequest(
            ['siteName' => 'Acme', 'maintenance' => 'on', 'theme' => 'dark'],
            $token,
            $token,
        );

        $response = $controller->save($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/milpa/admin/settings', $response->headers->get('Location'));

        $current = $this->repository()->current();
        self::assertSame('Acme', $current->siteName);
        self::assertTrue($current->maintenance);
        self::assertSame('dark', $current->theme);
    }

    // ---- 2. invalid POST: no persist + 200 redisplay with error + submitted values ------------

    public function testInvalidPostDoesNotPersistAndRedisplaysErrorAndSubmittedValues(): void
    {
        $this->repository()->save(new SettingsEntity('Existing', false, 'light'));

        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-post', ['milpa.admin']));
        $controller = new SettingsController($this->container($store));

        $token = bin2hex(random_bytes(16));
        $request = $this->authedRequest(
            ['siteName' => '', 'maintenance' => 'on', 'theme' => 'dark'],
            $token,
            $token,
        );

        $response = $controller->save($request);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        // El error `required` de siteName, con el label real del #[Param] (Nombre del sitio),
        // en español mexicano — el host traduce en el borde de render (B1), la UI ya no mezcla el
        // label traducido con el sufijo inglés del binder ("... is required.").
        self::assertStringContainsString('Nombre del sitio es obligatorio.', $html);
        self::assertStringNotContainsString('is required.', $html, 'el sufijo inglés del binder no debe llegar a la UI española (B1)');

        // El brand del chrome sale del siteName PERSISTIDO ('Existing'), NO de la sumisión inválida
        // (siteName='') — un rechazo ya no blanquea topbar/sidebar (B3).
        self::assertStringContainsString('<span class="mui-kbd">Existing</span>', $html, 'el brand del topbar usa el valor persistido, no la sumisión vacía (B3)');

        // Los valores SOMETIDOS (no la store, que sigue en 'Existing'/false/'light') se reflejan:
        // maintenance=on -> checkbox marcado, theme=dark -> option dark seleccionada.
        self::assertStringContainsString('value="1" checked', $html);
        self::assertStringContainsString('<option value="dark" selected>', $html);

        $current = $this->repository()->current();
        self::assertSame('Existing', $current->siteName, 'un POST inválido NUNCA debe persistir');
        self::assertFalse($current->maintenance);
        self::assertSame('light', $current->theme);

        // El redisplay emite una cookie CSRF FRESCA (nunca reusa el token que la request acaba de
        // consumir): el hidden `csrf` del HTML matchea la cookie recién emitida, y ninguno de los
        // dos es el token original que pasó el CsrfGuard de esta misma request.
        $csrfCookie = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'milpa_admin_csrf') {
                $csrfCookie = $cookie;
                break;
            }
        }

        self::assertNotNull($csrfCookie, 'el redisplay inválido debe traer una cookie milpa_admin_csrf fresca');
        self::assertSame(1, preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $matches));
        self::assertSame($matches[1], (string) $csrfCookie->getValue());
        self::assertNotSame($token, (string) $csrfCookie->getValue(), 'el token fresco nunca reusa el consumido por esta request');
    }

    // ---- 3. checkbox absent + redisplay-from-submission (never the stored true) ---------------

    public function testCheckboxAbsentRedisplaysUncheckedNeverTheStoredValue(): void
    {
        $this->repository()->save(new SettingsEntity('Acme Corp', true, 'light'));

        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-post', ['milpa.admin']));
        $controller = new SettingsController($this->container($store));

        $token = bin2hex(random_bytes(16));
        // Invalid: siteName vacío. Sin llave `maintenance` -> checkbox "sin marcar" nativo.
        $request = $this->authedRequest(
            ['siteName' => '', 'theme' => 'light'],
            $token,
            $token,
        );

        $response = $controller->save($request);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        // El redisplay muestra la sumisión (maintenance ausente -> false -> sin marcar), NUNCA el
        // `true` guardado en la store.
        self::assertStringNotContainsString('value="1" checked', $html);

        $current = $this->repository()->current();
        self::assertSame('Acme Corp', $current->siteName, 'un POST inválido NUNCA debe persistir');
        self::assertTrue($current->maintenance, 'la store NO cambia: sigue en true, el POST inválido nunca se despachó');
    }

    // ---- 4. missing/bad CSRF: 403, no persist --------------------------------------------------

    public function testMissingCsrfIsRejectedWithoutPersisting(): void
    {
        $this->repository()->save(new SettingsEntity('Existing', false, 'light'));

        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-post', ['milpa.admin']));
        $controller = new SettingsController($this->container($store));

        $token = bin2hex(random_bytes(16));
        // Cookie CSRF presente, pero el campo `csrf` del body NUNCA se manda.
        $request = $this->authedRequest(['siteName' => 'Attacker', 'theme' => 'dark'], $token, null);

        $response = $controller->save($request);

        self::assertSame(403, $response->getStatusCode());

        $current = $this->repository()->current();
        self::assertSame('Existing', $current->siteName, 'un CSRF ausente NUNCA debe persistir');
    }

    public function testMismatchedCsrfIsRejectedWithoutPersisting(): void
    {
        $this->repository()->save(new SettingsEntity('Existing', false, 'light'));

        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-post', ['milpa.admin']));
        $controller = new SettingsController($this->container($store));

        $request = $this->authedRequest(
            ['siteName' => 'Attacker', 'theme' => 'dark'],
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
        );

        $response = $controller->save($request);

        self::assertSame(403, $response->getStatusCode());

        // El 403 de CSRF muestra el mensaje LEARNABLE de CsrfDeniedException (qué + cómo + link),
        // discriminado por el código `MILPA_CSRF_DENIED` — no la copy genérica de scope. Nunca echa
        // de vuelta un token (el mensaje enseña el fix sin filtrar el valor esperado ni el enviado).
        $body = (string) $response->getContent();
        self::assertStringContainsString('data-error-code="MILPA_CSRF_DENIED"', $body);
        self::assertStringContainsString('CSRF check failed', $body);
        self::assertStringNotContainsString('Sin acceso', $body, 'un CSRF denegado no debe reusar la copy genérica de scope');

        $current = $this->repository()->current();
        self::assertSame('Existing', $current->siteName, 'un CSRF que no matchea NUNCA debe persistir');
    }

    // ---- 5. shared gate: no session / authed sin milpa.admin -----------------------------------

    public function testNoSessionRedirectsToLogin(): void
    {
        $controller = new SettingsController($this->container(new InMemorySessionStore()));

        // Ni cookie de sesión ni CSRF — el gate de auth corre ANTES que CsrfGuard, así que esto debe
        // ser un 401->302 de login, nunca un 403 de CSRF.
        $request = Request::create('/milpa/admin/settings', 'POST', ['siteName' => 'Attacker']);
        $response = $controller->save($request);

        self::assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith('/agency/login?next=', $location);

        // Tarea 4 (P5.5) — un POST no es un GET seguro para reusar su propio path como next; `save()`
        // declara su `returnTo` explícito (el GET equivalente, `/milpa/admin/settings`), así que el
        // next es ESE, nunca el Hub.
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('/milpa/admin/settings', $query['next'] ?? null);
    }

    public function testAuthenticatedWithoutScopeReturns403SinAcceso(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('sess-403', ['agency.sales']));
        $controller = new SettingsController($this->container($store));

        $request = Request::create(
            '/milpa/admin/settings',
            'POST',
            ['siteName' => 'Attacker'],
            [self::COOKIE => 'sess-403'],
        );
        $response = $controller->save($request);

        self::assertSame(403, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('sin acceso', strtolower($body));
        // El 403 de scope reusa la copy genérica del área — nunca el código/mensaje learnable del
        // CSRF (que es un 403 semánticamente distinto): las dos ramas divergen.
        self::assertStringNotContainsString('MILPA_CSRF_DENIED', $body);
    }

    // ---- 6. the governed dispatch leaves audit evidence (Tarea 6, ADR#11) ----------------------

    public function test_a_valid_post_leaves_audit_evidence_through_the_governed_dispatch(): void
    {
        $this->repository()->save(new SettingsEntity('Old Name', false, 'light'));

        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-post', ['milpa.admin']));

        $logger = new SettingsPostSpyLogger();
        $controller = new SettingsController($this->container($store, $logger));

        $token = bin2hex(random_bytes(16));
        $request = $this->authedRequest(
            ['siteName' => 'Acme', 'maintenance' => 'on', 'theme' => 'dark'],
            $token,
            $token,
        );

        $response = $controller->save($request);

        self::assertSame(303, $response->getStatusCode(), 'el dispatch gobernado preserva el PRG de siempre');
        self::assertGreaterThan(
            0,
            $logger->messagesContaining('settings_update'),
            'el dispatch gobernado (ToolRegistry::call vía ToolProjector) deja evidencia de audit (tool.executed)',
        );
    }
}
