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

use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Controllers\AdminController;
use Milpa\Admin\Event\AdminEvents;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Admin\Tests\Fixtures\RecordingDispatcher;
use Milpa\Admin\Tests\Fixtures\ViewPlugin;
use Milpa\Container\DIContainer;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The falsifier of greenhouse decisions/0228 for this package: the panel DECLARES every event it dispatches
 * to the dispatcher it boots with — the same names, the same payload keys, the same subjects — measured by
 * execution, never by reading the source.
 *
 * A spy that implements both contracts boots the real kernel with the real plugin; the real controller renders
 * a component section and a declared view; then the spy is asked what was declared and what was dispatched —
 * and by whom, read from the frame that called `dispatch()`. The control is the same path with a dispatcher
 * that cannot be declared to: it renders the same and is told nothing.
 */
final class TheEmitterDeclaresEveryEventItDispatchesTest extends TestCase
{
    /** The exact list this package dispatches — hardcoded, so a deleted or renamed declaration goes red here. */
    private const EXPECTED = [
        'admin.section.before_render',
        'admin.section.after_render',
        'admin.shell.before_render',
        'admin.shell.after_render',
    ];

    public function testEveryDispatchedNameIsDeclaredAndEveryDeclarationMatchesItsDispatch(): void
    {
        $spy = new class () implements MilpaEventDispatcherInterface, DeclaredEvents {
            /** @var list<EventDeclaration> */
            private array $declarations = [];

            /** @var list<string> */
            private array $names = [];

            /** @var list<array{name: string, payload: array<string, mixed>, by: string}> every dispatch, with the class whose code called dispatch() */
            public array $seen = [];

            /** How many dispatches the spy had seen when THIS package's first declaration arrived; -1 while it has not declared. */
            public int $seenAtDeclare = -1;

            public function declare(EventDeclaration ...$events): void
            {
                foreach ($events as $event) {
                    if ($this->seenAtDeclare < 0 && str_starts_with($event->dispatchedBy, 'Milpa\\Admin\\')) {
                        $this->seenAtDeclare = \count($this->seen);
                    }
                    foreach ($this->declarations as $known) {
                        if ($known->name === $event->name) {
                            continue 2;
                        }
                    }
                    $this->declarations[] = $event;
                }
            }

            public function declared(): array
            {
                return $this->declarations;
            }

            public function dispatched(): array
            {
                return $this->names;
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                if (!\in_array($eventName, $this->names, true)) {
                    $this->names[] = $eventName;
                }
                $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
                $this->seen[] = ['name' => $eventName, 'payload' => $payload, 'by' => $caller['class'] ?? '(no class)'];
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };

        [$index, $view] = self::renderThrough($spy);
        self::assertSame(200, $index->getStatusCode());
        self::assertSame(200, $view->getStatusCode());

        $declared = array_map(static fn (EventDeclaration $d): string => $d->name, $spy->declared());
        $ownDeclarations = array_values(array_filter($spy->declared(), static fn (EventDeclaration $d): bool => str_starts_with($d->dispatchedBy, 'Milpa\\Admin\\')));
        $own = array_values(array_filter($spy->seen, static fn (array $call): bool => str_starts_with($call['by'], 'Milpa\\Admin\\')));
        self::assertNotSame([], $own, 'the render dispatched from this package');

        // (a) No undeclared dispatch: every name this package's code handed to dispatch() was declared.
        foreach ($own as $call) {
            self::assertContains($call['name'], $declared, sprintf('«%s» was dispatched by %s without a declaration', $call['name'], $call['by']));
        }
        // The kernel's own boot events pass through the same dispatcher before the plugin boots; those are
        // milpa/runtime's to declare. This package's first dispatch must come AFTER its declaration.
        self::assertGreaterThanOrEqual(0, $spy->seenAtDeclare, 'the plugin declared at boot');
        self::assertSame([], array_filter(array_slice($spy->seen, 0, $spy->seenAtDeclare), static fn (array $call): bool => str_starts_with($call['by'], 'Milpa\\Admin\\')), 'nothing of this package was dispatched before it declared');

        // (b) THIS package's declared set is exactly the expected list — and exactly what the render dispatched.
        // The same dispatcher also carries its neighbours' declarations (milpa/runtime declares the boot events,
        // milpa/live the component and live ones), so the panel is measured by what IT declared — by the
        // `dispatchedBy` of each declaration — never by everything the dispatcher happens to hold.
        self::assertSame(self::EXPECTED, array_map(static fn (EventDeclaration $d): string => $d->name, $ownDeclarations));
        self::assertSame(self::EXPECTED, array_map(static fn (EventDeclaration $d): string => $d->name, AdminEvents::declarations()), 'what the plugin declared is what the holder returns');
        $ownNames = array_values(array_unique(array_map(static fn (array $call): string => $call['name'], $own)));
        self::assertSame(self::EXPECTED, $ownNames, 'every declared name was dispatched, in the order a render fires them, and nothing else');
        self::assertSame(self::EXPECTED, array_values(array_intersect($spy->dispatched(), self::EXPECTED)), 'the dispatcher counted them, first occurrence first');

        // (c) Each of this package's declarations describes what the spy saw: the dispatching class, the payload
        // key, the subject. A neighbour's declaration is its own package's falsifier to make, not this one's.
        foreach ($ownDeclarations as $declaration) {
            $calls = array_values(array_filter($own, static fn (array $call): bool => $call['name'] === $declaration->name));
            self::assertNotSame([], $calls, sprintf('«%s» is declared but the render never dispatched it', $declaration->name));
            self::assertNotNull($declaration->subjectType, $declaration->name . ' names its subject type');
            foreach ($calls as $call) {
                self::assertSame($declaration->dispatchedBy, $call['by'], $declaration->name . ' is dispatched by the class it declares');
                self::assertArrayHasKey($declaration->subjectKey, $call['payload'], $declaration->name . ' carries its subject under the declared key');
                $subject = $call['payload'][$declaration->subjectKey];
                self::assertInstanceOf($declaration->subjectType, $subject, $declaration->name . ' carries the declared subject type');
                self::assertSame($declaration->mutable, self::isMutable($subject), $declaration->name . ' declares whether a subscriber may change the subject in place');
                self::assertSame($declaration->interceptable, \array_key_exists('slot', $call['payload']), $declaration->name . ' declares whether the payload carries an interception slot');
            }
        }
    }

    /** CONTROL: a dispatcher that does not implement DeclaredEvents renders the same pages and is asked nothing. */
    public function testAPlainDispatcherRendersTheSameAndIsToldNothing(): void
    {
        $plain = new RecordingDispatcher();

        [$index, $view] = self::renderThrough($plain);

        self::assertSame(200, $index->getStatusCode());
        self::assertStringContainsString('id="milpa-admin-section-', (string) $index->getBody());
        self::assertSame(200, $view->getStatusCode());
        self::assertStringContainsString('id="lab-b"', (string) $view->getBody(), 'the declared view rendered');
        self::assertFalse(method_exists($plain, 'declare'), 'the control cannot be declared to — a declaration attempted on it would have been a fatal error above');
        self::assertSame(self::EXPECTED, array_values(array_unique(array_intersect($plain->dispatched, self::EXPECTED))), 'the same four names fire without any declaration');
    }

    /**
     * The real path: the kernel boots the plugin with the given dispatcher, the plugin registers its controller,
     * and the controller renders the index (a component section, the panel's own Plugins) and a guest's
     * declared view — the two code paths that dispatch.
     *
     * @return array{0: ResponseInterface, 1: ResponseInterface}
     */
    private static function renderThrough(MilpaEventDispatcherInterface $dispatcher): array
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [AdminPlugin::class, HolaPlugin::class, ViewPlugin::class],
            'container' => $container,
            'dispatcher' => $dispatcher,
        ]);
        $container->registerService(Kernel::class, $kernel);
        $controller = $container->get(AdminController::class);
        self::assertInstanceOf(AdminController::class, $controller);

        $route = new Route(path: '/milpa/admin/s/{id}', handler: HandlerReference::method(AdminController::class, 'section'));
        $viewRequest = (new ServerRequest('GET', '/milpa/admin/s/' . ViewPlugin::SECTION))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['id' => ViewPlugin::SECTION]));

        return [
            $controller->index(new ServerRequest('GET', '/milpa/admin')),
            $controller->section($viewRequest),
        ];
    }

    /** A subject is mutable when a subscriber can assign one of its public properties — read from the object, not from prose. */
    private static function isMutable(object $subject): bool
    {
        foreach ((new \ReflectionObject($subject))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isReadOnly()) {
                return true;
            }
        }

        return false;
    }
}
