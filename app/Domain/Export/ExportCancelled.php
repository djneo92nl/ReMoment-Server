<?php

namespace App\Domain\Export;

/** The export row was removed or is no longer running: stop and clean up. */
final class ExportCancelled extends \RuntimeException {}
