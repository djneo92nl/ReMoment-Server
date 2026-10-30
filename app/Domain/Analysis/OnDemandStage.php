<?php

namespace App\Domain\Analysis;

/**
 * A stage that is computed only when someone asks for ONE track (`AnalyzeTrackDeep`, `DeepAnalysis`), never by the
 * nightly `library:analyze`: the backlog, `/settings/analysis` and the per-file dots do not know it. Its result and
 * version live in `file_analyses` like any stage, so a changed file or a new version makes it stale.
 */
interface OnDemandStage extends AnalysisStage {}
