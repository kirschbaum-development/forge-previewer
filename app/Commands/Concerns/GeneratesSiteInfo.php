<?php

namespace App\Commands\Concerns;

trait GeneratesSiteInfo
{
    protected function generateSiteDomain(): string
    {
        return str($this->getDomainName())->toString();
    }
}
