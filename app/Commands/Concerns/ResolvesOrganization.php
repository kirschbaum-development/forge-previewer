<?php

namespace App\Commands\Concerns;

use Throwable;

trait ResolvesOrganization
{
    /**
     * Build a hint listing the organizations the API token can access.
     *
     * Used to help users migrating from API v1 discover the org slug now
     * required by every Forge API v2 endpoint.
     */
    protected function availableOrganizationsHint(): string
    {
        try {
            $slugs = [];

            foreach ($this->forge->organizations()->lazy() as $organization) {
                $slugs[] = $organization->slug;
            }

            if ($slugs === []) {
                return '';
            }

            return ' Available organizations: ' . implode(', ', $slugs) . '.';
        } catch (Throwable $e) {
            return '';
        }
    }
}
