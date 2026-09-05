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

namespace Milpa\Admin\Tests\Fixtures;

/**
 * Reads the rendered sidebar back as facts: the `<nav>`, its group headings in order, and the hrefs under
 * one group — so a test asserts the structure, not a substring that happens to be adjacent.
 */
trait ReadsSidebar
{
    /** The sidebar's own HTML, `<nav class="mui-sidebar" …>…</nav>`. */
    private static function sidebar(string $html): string
    {
        self::assertSame(1, preg_match('~<nav class="mui-sidebar".*?</nav>~s', $html, $match), 'the sidebar is on the page');

        return $match[0];
    }

    /**
     * The group headings of a sidebar, in the order they are painted.
     *
     * @return list<string>
     */
    private static function headings(string $nav): array
    {
        preg_match_all('~<span class="mui-sidebar__section-label" id="[^"]*">([^<]*)</span>~', $nav, $matches);

        return $matches[1];
    }

    /**
     * The `href` of every item under one group, in order — an empty list when the group is not painted.
     *
     * @return list<string>
     */
    private static function itemsUnder(string $nav, string $group): array
    {
        if (preg_match('~<div class="mui-sidebar__section" role="group" aria-labelledby="[^"]*" data-group="' . preg_quote($group, '~') . '">(.*?)</div>~s', $nav, $section) !== 1) {
            return [];
        }
        preg_match_all('~<a class="mui-sidebar__item" href="([^"]*)"~', $section[1], $matches);

        return $matches[1];
    }
}
