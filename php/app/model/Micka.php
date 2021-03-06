<?php

/**
 * This file is part of the Micka
 * Geospatial metadata catalogue and metadata editing tool
 */

namespace Micka;


/**
 * Micka
 */
class Micka
{
    private $mickaVersion = [
        'name' => 'Micka',
        'version' => '6.0',
        'version_id' => 60316,
        'revision' => 20190613.01
    ];

    public function getMickaVersion()
    {
        return $this->mickaVersion;
    }


}