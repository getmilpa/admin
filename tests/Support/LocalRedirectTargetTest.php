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

namespace Milpa\Admin\Tests\Support;

use Milpa\Admin\Support\LocalRedirectTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalRedirectTargetTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> [candidate, expected] with default '/milpa/admin' */
    public static function cases(): array
    {
        return [
            ['/milpa/admin', '/milpa/admin'],           // local absolute → kept
            ['/agency/x?y=1', '/agency/x?y=1'],         // local with query → kept
            ['//evil.com', '/milpa/admin'],             // protocol-relative → rejected
            ['https://evil.com', '/milpa/admin'],       // absolute URL → rejected
            ['http://evil.com/x', '/milpa/admin'],
            ['\\evil.com', '/milpa/admin'],             // backslash host → rejected
            ['/\\evil.com', '/milpa/admin'],            // /\ → rejected
            ['/%2f%2fevil.com', '/milpa/admin'],        // encoded // → rejected
            ['/%5cevil.com', '/milpa/admin'],           // encoded backslash → rejected
            ["/\t/evil.com", '/milpa/admin'],           // tab stripped by browser → //evil.com
            ["/\r/evil.com", '/milpa/admin'],           // CR
            ["/\n/evil.com", '/milpa/admin'],           // LF
            ["/x\r\nSet-Cookie: y", '/milpa/admin'],    // CRLF header injection
            ['milpa/admin', '/milpa/admin'],            // relative (no leading /) → rejected
            ['', '/milpa/admin'],                       // empty → default
        ];
    }

    #[DataProvider('cases')]
    public function test_resolve(string $candidate, string $expected): void
    {
        self::assertSame($expected, LocalRedirectTarget::resolve($candidate, '/milpa/admin'));
    }

    public function test_null_candidate_uses_default(): void
    {
        self::assertSame('/agency', LocalRedirectTarget::resolve(null, '/agency'));
    }
}
