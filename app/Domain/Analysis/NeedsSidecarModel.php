<?php

namespace App\Domain\Analysis;

/**
 * An on-demand stage that runs on the Essentia sidecar and needs one of its ML models: `DeepAnalysis::available()` then
 * also requires that model in the sidecar's `/health` `models` list, so a sidecar built without the ML models does not
 * offer the kind.
 */
interface NeedsSidecarModel
{
    /** The model key of the sidecar's /health `models` (`deam`, `effnet`). */
    public function sidecarModel(): string;
}
