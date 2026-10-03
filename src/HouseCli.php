<?php

/**
 * This file is part of milpa/admin.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin;

/**
 * The CLI as a person types it to reach THIS house — asked of the house, never decided here.
 *
 * In the Desktop the house lives in a container, so `php bin/coa …` runs only after
 * `docker exec -it <container>`. The process that serves the house declares that way in and
 * `milpa/app-runtime` builds every command it hands a person from `Capabilities::cli()`
 * (greenhouse decisions/0559, E5). The panel kept its own copies of a handful of commands — the
 * *Plugins* command form, the House section's next move, the Identity note, two refusals — and they
 * went on saying `php bin/coa` (greenhouse decisions/0560).
 *
 * 🚨 ONE JUDGE. What a declared way in may be — no line break, no `;`, no `$`, 200 characters — is
 * app-runtime's judgement. This class reads no environment variable: a second reader would be a
 * second filter, and the one that forgets a character is the one somebody copies a line from.
 *
 * The panel does not depend on app-runtime, so the class is named by string, as
 * {@see Data\PluginsSource} and {@see Data\FrameworkFacts} name it. With no app-runtime, or one from
 * before it could say, the answer is `php bin/coa ` and every text is returned as it was given.
 */
final class HouseCli
{
    /** The CLI with nothing in front of it: what every command this package writes starts with. */
    public const string PLAIN = 'php bin/coa ';

    /** Who knows how this house is reached. */
    public const string SOURCE = 'Milpa\\AppRuntime\\Support\\Capabilities';

    /**
     * The CLI as a person types it here: what `$source::cli()` answers, or {@see self::PLAIN}.
     *
     * @param string $source the class asked; the default is the house's own
     */
    public static function cli(string $source = self::SOURCE): string
    {
        // No app-runtime, one from before it could say, one that fails while saying: each is an
        // `Error` or an exception here, and each means the same thing — nobody declared a way in.
        try {
            $cli = $source::cli();
        } catch (\Throwable) {
            return self::PLAIN;
        }

        return \is_string($cli) && $cli !== '' ? $cli : self::PLAIN;
    }

    /**
     * A text a person reads, with every command in it starting the way this house is reached.
     *
     * The texts stay written with {@see self::PLAIN}: a command is not translatable copy, and it is
     * the same in every locale. With no way in declared this returns `$text` byte for byte.
     *
     * @param string $source the class asked; the default is the house's own
     */
    public static function reached(string $text, string $source = self::SOURCE): string
    {
        return str_replace(self::PLAIN, self::cli($source), $text);
    }
}
