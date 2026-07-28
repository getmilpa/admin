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

namespace Milpa\Admin\Projection;

use Milpa\Live\Schema\FormDefinition;
use Milpa\Live\Schema\SchemaForm;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * El proyector Tool→web (P5.4, ADR#11 "Projected means governed"): gradúa el dispatch ceremonial
 * de P5.3a. El formulario valida la entrada; el registry gobierna la operación — el dispatch corre
 * SIEMPRE por `ToolRegistry::call()` (policy, audit, rate-limit, contención), nunca invoke directo.
 *
 * El proyector proyecta, no navega: devuelve un {@see ProjectedToolResult} y la superficie decide
 * (PRG hoy; otros transportes mañana). Nace host-side (Almácigo) — gradúa al skeleton cuando
 * exista el segundo consumidor.
 */
final class ToolProjector
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly SchemaForm $schemaForm,
        private readonly ToolBannerMapper $bannerMapper,
    ) {
    }

    /**
     * Corre una herramienta gobernada y proyecta su resultado a la superficie web.
     *
     * @param array<string, mixed> $rawBody
     */
    public function dispatch(
        string $toolName,
        FormDefinition $definition,
        array $rawBody,
        ToolContext $context,
    ): ProjectedToolResult {
        $submission = $this->schemaForm->bind($definition, $rawBody);

        // Bind inválido: errores field-level, el usuario corrige. JAMÁS llega al registry.
        if (!$submission->validation->ok) {
            return ProjectedToolResult::redisplay($submission);
        }

        // Confirm-guard ANTES del call (Ajuste 3), pero SIN preceder a la autorización: el gate
        // viene del MISMO PolicyGate del registry (getPolicyGate() — cero acoplamiento nuevo, $registry
        // ya es dep del ctor, y así ve cualquier channel policy / rule provider que el host haya
        // personalizado). Un actor SIN los scopes del tool debe seguir a call() para que el registry
        // deniegue con FORBIDDEN auditado — el mismo orden authorize→confirm del pipeline real.
        // Invertir ese orden filtraría dos cosas a un actor no autorizado: que el tool existe con
        // ese nombre, y que requiere confirmación — sin dejar rastro de auditoría de la denegación.
        $tool = $this->registry->getDefinition($toolName);
        if ($tool !== null) {
            $gate = $this->registry->getPolicyGate();
            if ($gate->requiresConfirmation($context, $tool)) {
                // Solo el actor AUTORIZADO ve la incompatibilidad de superficie; un actor sin permiso
                // debe caer al call() para que el registry deniegue con FORBIDDEN AUDITADO (el mismo
                // orden authorize→confirm del pipeline real). En ambos casos el tool jamás ejecuta:
                // authorize deniega antes del confirm-gate, y el confirm-gate nunca corre el callback.
                if ($gate->authorize($context, $tool)->allowed) {
                    throw WebConfirmationUnsupportedException::forTool($toolName);
                }
                // no autorizado → sigue al call(): FORBIDDEN auditado → banner
            }
        }

        // Args by-name: FormBinder ya tipó los valores y ToolScanner::invokeMethod mapea por nombre
        // de parámetro — mueren los casts posicionales del dispatch ceremonial.
        $result = $this->registry->call($toolName, $submission->values, $context);

        if ($result->success) {
            // Backstop honesto: si una fuente de policy futura del registry devolviera la ceremonia
            // de confirmación como resultado, esta superficie tampoco sabe representarla.
            if ($result->meta['requires_confirmation'] ?? false) {
                throw WebConfirmationUnsupportedException::forTool($toolName);
            }

            return ProjectedToolResult::success($result);
        }

        return ProjectedToolResult::redisplay($submission, $this->bannerMapper->map($result));
    }
}
