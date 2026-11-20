<?php

namespace App\Integrations\Contracts;

/**
 * Marker: the device's listener reports which source or input is selected (a `Source` on its now playing), so a
 * sub device link on it can say whether it is active (App\Domain\SubDevices\LinkState). A host without it
 * (Philips TV: HDMI input unknown) keeps its links, with an unknown state.
 */
interface ReportsActiveSource {}
