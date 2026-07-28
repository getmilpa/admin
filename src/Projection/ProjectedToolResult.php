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

use Milpa\Live\Schema\FormSubmission;
use Milpa\ToolRuntime\ToolResult;

/**
 * El resultado de proyectar una operación a esta superficie. El proyector proyecta, no navega
 * (enmienda del gate de P5.4): la SUPERFICIE decide qué hacer — PRG hoy, 200/Turbo/HX mañana.
 * Dos estados: Success (el registry aceptó y ejecutó) | Redisplay (bind inválido, o el registry
 * rechazó/falló — con el ?FormBanner correspondiente). En Redisplay la submission es la REAL del
 * bind — la validación jamás se fabrica.
 */
final readonly class ProjectedToolResult
{
    private function __construct(
        private bool $success,
        private ?ToolResult $result,
        private ?FormSubmission $submission,
        private ?FormBanner $banner,
    ) {
    }

    /** El resultado de una operación que pasó: la superficie navega (PRG). */
    public static function success(ToolResult $result): self
    {
        return new self(true, $result, null, null);
    }

    /** El resultado de una que no pasó: la superficie repinta con el banner. */
    public static function redisplay(FormSubmission $submission, ?FormBanner $banner = null): self
    {
        return new self(false, null, $submission, $banner);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** El resultado crudo de la herramienta, para quien necesite el detalle. */
    public function toolResult(): ToolResult
    {
        if ($this->result === null) {
            throw new \LogicException('toolResult() solo existe en un resultado Success; verifica isSuccess() primero.');
        }

        return $this->result;
    }

    /** Los valores que la persona envió, para repintar el formulario con ellos. */
    public function submission(): FormSubmission
    {
        if ($this->submission === null) {
            throw new \LogicException('submission() solo existe en un resultado Redisplay; verifica isSuccess() primero.');
        }

        return $this->submission;
    }

    public function banner(): ?FormBanner
    {
        return $this->banner;
    }
}
