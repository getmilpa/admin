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

namespace Milpa\Admin\Section;

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;

/**
 * Who the panel asks for sections: the plugin instances the kernel booted, plus the panel itself.
 *
 * Read per request, never cached — a plugin that boots after the panel still shows up, and what the
 * sidebar lists is what booted. Without a kernel in the container (the app's `public/index.php` registers
 * it) the panel still serves its own sections.
 *
 * One reader for both surfaces (greenhouse decisions/0211): the page and the live wire discover the same
 * sections from the same place, so the registry a page compiled with is the registry the wire re-renders
 * from — an envelope signed while painting one is resolvable when it comes back.
 */
final class BootedPlugins
{
    /**
     * The booted plugin instances — from the kernel when the app registered it, else the panel alone.
     *
     * @param object $self the admin plugin instance — the one provider the panel can count on without a kernel
     *
     * @return list<object>
     */
    public static function of(DIContainerInterface $container, object $self): array
    {
        $kernel = $container->has(Kernel::class) ? $container->get(Kernel::class) : null;
        $plugins = $kernel instanceof Kernel ? $kernel->plugins() : [];

        foreach ($plugins as $plugin) {
            if ($plugin === $self) {
                return $plugins;
            }
        }
        $plugins[] = $self;

        return $plugins;
    }
}
