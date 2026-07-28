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

namespace Milpa\Admin\State;

/**
 * El estado read-only de una sección del Milpa Admin — el contrato que CUALQUIER shell (HTML, CLI,
 * TUI, API…) consume para preguntar "¿cuál es el estado de esta sección?". El estado pertenece a la
 * SECCIÓN (dominio), no a la UI: el shell lo renderiza, nunca lo produce. Lo implementan
 * {@see \Milpa\Admin\Settings\SettingsStateProvider} (config) y
 * {@see RoutesStateProvider} (rutas). El array es section-específico: cada renderer sabe interpretar el suyo.
 */
interface AdminSectionStateProvider
{
    /**
     * El estado read-only de la sección, como array que su renderer sabe interpretar.
     *
     * @return array<string,mixed>
     */
    public function state(): array;
}
