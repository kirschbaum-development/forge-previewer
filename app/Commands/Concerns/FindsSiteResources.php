<?php

namespace App\Commands\Concerns;

use Laravel\Forge\Exceptions\NotFoundException;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\Site;

trait FindsSiteResources
{
    /**
     * Iterate a paginated Forge collection, treating a 404 — a resource type not
     * present on this server (e.g. no managed databases) — as an empty result
     * rather than a fatal error.
     */
    protected function safelyIterate(\Closure $fetch): iterable
    {
        try {
            foreach ($fetch()->lazy() as $resource) {
                yield $resource;
            }
        } catch (NotFoundException $_) {
            return;
        }
    }

    protected function findSiteByName(Server $server, string $domain): ?Site
    {
        foreach ($this->forge->serverSites($this->org, $server->id)->lazy() as $site) {
            if ($site->name === $domain) {
                return $site;
            }
        }

        return null;
    }

    protected function findPrimaryDomainId(Server $server, Site $site, string $domain): ?int
    {
        foreach ($this->safelyIterate(fn () => $this->forge->domains($this->org, $server->id, $site->id)) as $siteDomain) {
            if ($siteDomain->name === $domain) {
                return $siteDomain->id;
            }
        }

        return null;
    }
}
