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

namespace Milpa\Admin\Http;

use Milpa\Console\Http\HttpProjector;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TAKES THE FILES A NEWER FRAMEWORK CHANGED — the panel's second governed button.
 *
 * The same shape as {@see CapabilityInstaller}, which is why both run through {@see GovernedAct}: a
 * scoped, consent-demanding operation projected over HTTP behind the panel's own door. What differs is
 * only the operation's name and the sentence it refuses with.
 *
 * ── WHAT THE OPERATION ITSELF GUARANTEES, SO THIS ROUTE NEED NOT ────────────────────────────────────
 *
 * `framework:apply` writes only what the reconciliation calls `offered` or `added` — never a file the
 * house customized, and never one whose birth bytes were not recorded. And it refuses outright unless
 * git can be the way back: not a repository, an untracked target, or an uncommitted edit each stop it
 * with a sentence. None of that is repeated here, and repeating it is exactly what would let the two
 * drift (greenhouse decisions/0295, 0297).
 *
 * So this class carries no judgement of its own. It hands the request to the ceremony and returns what
 * the ceremony answered.
 */
final class FrameworkApplier
{
    public const string OPERATION = 'framework:apply';

    public const string ROUTE_NAME = 'milpa_admin_framework_apply';

    /**
     * What this panel answers when the app has no judge for the act.
     *
     * The same shape as the installer's, and for the same reason: `framework:apply` declares a scope, so
     * an app with no `OperationHttpPolicy` has nothing that can hold it. The command is named because a
     * person with a terminal was never blocked (greenhouse decisions/0289).
     */
    public const string NO_JUDGE = 'This app cannot authorize «' . self::OPERATION . '» over HTTP: it registered no OperationHttpPolicy, so nothing here can hold the scope the act declares. Install milpa/auth and enrol a passkey, or run `coa framework:apply --sign` from a terminal.';

    public function __construct(
        private readonly HttpProjector $projector,
        private readonly ResponseFactoryInterface $responses = new Psr17Factory(),
    ) {
    }

    /** Runs the apply through the operation's own HTTP ceremony: policy, confirm token, execute. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return GovernedAct::run($this->projector, self::OPERATION, $request, $this->responses, self::NO_JUDGE);
    }
}
