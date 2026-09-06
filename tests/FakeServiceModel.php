<?php

namespace GameAP\Whmcs\Tests;

class FakeServiceModel
{
    public FakeServiceProperties $serviceProperties;

    public function __construct()
    {
        $this->serviceProperties = new FakeServiceProperties();
    }
}
