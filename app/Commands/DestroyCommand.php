<?php

namespace App\Commands;

use App\Exceptions\ProvisioningFailedException;
use Exception;
use Illuminate\Support\Str;
use Laravel\Forge\Forge;
use App\Commands\Concerns\FindsSiteResources;
use App\Commands\Concerns\HandlesOutput;
use App\Commands\Concerns\InteractsWithEnv;
use App\Commands\Concerns\ResolvesOrganization;
use App\Commands\Concerns\GeneratesSiteInfo;
use App\Commands\Concerns\GeneratesDatabaseInfo;
use Laravel\Forge\Exceptions\ForbiddenException;
use Laravel\Forge\Exceptions\NotFoundException;
use Laravel\Forge\Exceptions\ValidationException;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\Site;
use LaravelZero\Framework\Commands\Command;

class DestroyCommand extends Command
{
    use FindsSiteResources;
    use HandlesOutput;
    use InteractsWithEnv;
    use ResolvesOrganization;
    use GeneratesDatabaseInfo;
    use GeneratesSiteInfo;

    protected $signature = 'destroy
        {--token=  : The Forge API token.}
        {--org= : The slug of the Forge organization. Required for API v2 (or set FORGE_ORG).}
        {--server= : The ID of the target server.}
        {--repo= : The name of the repository being deployed.}
        {--branch= : The name of the branch being deployed.}
        {--domain= : The domain you\'d like to use for deployments.}
        {--pre-destroy-command=* : Command to run before destroying the site.}';

    protected $description = 'Destroy a previously created preview site.';

    protected Forge $forge;

    protected string $org;

    public function handle(Forge $forge)
    {
        $this->forge = $forge->setApiKey($this->getForgeToken());

        if (! $org = $this->getOrganization()) {
            return $this->bail('The --org option (or FORGE_ORG environment variable) is required.' . $this->availableOrganizationsHint());
        }

        $this->org = $org;
        $serverId = (int) $this->getForgeServer();

        try {
            $server = $this->forge->server($this->org, $serverId);
        } catch (NotFoundException | ForbiddenException $_) {
            return $this->bail("Failed to find server {$serverId} in organization '{$this->org}'." . $this->availableOrganizationsHint());
        } catch (Exception $_) {
            return $this->bail("Failed to find server.");
        }

        $site = $this->findSiteByName($server, $this->generateSiteDomain());

        if (! $site) {
            return $this->bail('Failed to find site.');
        }

        $this->information('Found site.');

        try {
            foreach ($this->option('pre-destroy-command') as $i => $command) {
                if ($i === 0) {
                    $this->information('Executing pre-destroy command(s)');
                }

                $command = $this->replaceVariables($command);

                $this->information('Executing: ' . $command);

                $this->forge->createCommand($this->org, $server->id, $site->id, [
                    'command' => $command,
                ]);
            }

            $this->deleteCertificates($server, $site);

            foreach ($this->safelyIterate(fn () => $this->forge->scheduledJobs($this->org, $server->id)) as $job) {
                if ($job->command === sprintf("php /home/forge/%s/artisan schedule:run", $this->generateSiteDomain())) {
                    $this->information('Removing scheduled command.');
                    $this->ignoreMissing(fn () => $this->forge->deleteScheduledJob($this->org, $server->id, $job->id));
                }
            }

            foreach ($this->safelyIterate(fn () => $this->forge->databases($this->org, $server->id)) as $database) {
                if ($database->name === $this->getDatabaseName()) {
                    $this->information('Removing database.');
                    $this->ignoreMissing(fn () => $this->forge->deleteDatabase($this->org, $server->id, $database->id));
                }
            }

            foreach ($this->safelyIterate(fn () => $this->forge->databaseUsers($this->org, $server->id)) as $databaseUser) {
                if ($databaseUser->name === $this->getDatabaseUserName()) {
                    $this->information('Removing database user.');
                    $this->ignoreMissing(fn () => $this->forge->deleteDatabaseUser($this->org, $server->id, $databaseUser->id));
                }
            }

            $this->information('Deleting site.');

            $this->ignoreMissing(fn () => $this->forge->deleteSite($this->org, $server->id, $site->id));
        } catch (ValidationException $exception) {
            return $this->bailValidation($exception->errors());
        } catch (NotFoundException $_) {
            return $this->bail('A Forge resource disappeared mid-destroy (another cleanup may be running). Retry to verify everything is gone.');
        } catch (ProvisioningFailedException $exception) {
            return $this->bail($exception->getMessage());
        }

        $this->success('All done!');
    }

    /**
     * Delete the Let's Encrypt certificate(s) for the site's primary domain.
     *
     * Certificates are per-domain in API v2 (the Certificate resource no longer
     * carries a `domain` or a `delete()` method), so we resolve the domain record
     * first and delete its certificates by id.
     */
    protected function deleteCertificates(Server $server, Site $site): void
    {
        $domainId = $this->findPrimaryDomainId($server, $site, $this->generateSiteDomain());

        if ($domainId === null) {
            $this->information('Skipping SSL certificate cleanup: could not find the primary domain record. Remove any leftover certificate in Forge manually.');

            return;
        }

        foreach ($this->safelyIterate(fn () => $this->forge->domainCertificates($this->org, $server->id, $site->id, $domainId)) as $certificate) {
            $this->information('Deleting SSL certificate.');
            $this->ignoreMissing(fn () => $this->forge->deleteCertificate($this->org, $server->id, $site->id, $domainId, $certificate->id));
        }
    }

    /**
     * Run a delete call, treating a 404 as already-removed: a retried destroy
     * or concurrent cleanup may have deleted the resource after we listed it.
     */
    protected function ignoreMissing(\Closure $delete): void
    {
        try {
            $delete();
        } catch (NotFoundException $_) {
            $this->information('Already removed.');
        }
    }

    protected function replaceVariables(string $string): string
    {
        $branch = $this->getBranchName();
        $domain = $this->generateSiteDomain();

        return str_replace(['{domain}', '{branch}', '{branch_snake_case}'], [$domain, $branch, Str::replace('-', '_', $branch)], $string);
    }
}
