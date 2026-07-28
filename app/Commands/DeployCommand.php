<?php

namespace App\Commands;

use App\Commands\Concerns\GeneratesDatabaseInfo;
use App\Commands\Concerns\GeneratesSiteInfo;
use App\Commands\Concerns\ResolvesOrganization;
use Exception;
use Illuminate\Support\Facades\File;
use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Laravel\Forge\Exceptions\ForbiddenException;
use Laravel\Forge\Exceptions\NotFoundException;
use Laravel\Forge\Exceptions\ValidationException;
use Laravel\Forge\Resources\Site;
use Laravel\Forge\Resources\Server;
use App\Commands\Concerns\HandlesOutput;
use App\Commands\Concerns\InteractsWithEnv;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;

class DeployCommand extends Command
{
    use HandlesOutput;
    use InteractsWithEnv;
    use ResolvesOrganization;
    use GeneratesSiteInfo;
    use GeneratesDatabaseInfo;

    protected $signature = 'deploy
        {--token=  : The Forge API token.}
        {--org= : The slug of the Forge organization. Required for API v2 (or set FORGE_ORG).}
        {--server= : The ID of the target server.}
        {--provider=github : The Git provider.}
        {--repo= : The name of the repository being deployed.}
        {--branch= : The name of the branch being deployed.}
        {--domain= : The domain you\'d like to use for deployments.}
        {--php-version=php84 : The version of PHP the site should use, e.g. php84, php83, ...}
        {--setup-command=* : A command you would like to execute after configuring the git repo.}
        {--command=* : A command you would like to execute on the site, e.g. php artisan db:seed.}
        {--edit-env=* : The colon-separated name and value that will be added/updated in the site\'s environment, e.g. "MY_API_KEY:my_api_key_value".}
        {--deployment-script= : The deployment script to replace Forge\'s deployment script.}
        {--scheduler : Setup a cronjob to run Laravel\'s scheduler.}
        {--isolate : Enable site isolation.}
        {--ci : Add additional output for your CI provider.}
        {--no-quick-deploy : Create your site without "Quick Deploy".}
        {--no-deploy : Avoid deploying the site.}
        {--no-db : Avoid creating a database.}
        {--wildcard : Create a site with wildcard subdomains.}
        {--route-53-key= : AWS Route 53 key for wildcard subdomains SSL certificate.}
        {--route-53-secret= : AWS Route 53 secret for wildcard subdomains SSL certificate.}
        {--nginx-template= : The nginx template ID to use on your Laravel Forge website.}
        {--timeout= : Change default timeout (120). In seconds.}
    ';

    protected $description = 'Deploy a branch / pull request to Laravel Forge.';

    protected Forge $forge;

    protected string $org;

    protected bool $createdSite = false;

    public function handle(Forge $forge)
    {
        $this->validateOptions();

        $this->forge = $forge->setApiKey($this->getForgeToken())
            ->setTimeout((int)($this->option('timeout') ?? config('app.timeout')));

        if (! $org = $this->getOrganization()) {
            return $this->bail('The --org option (or FORGE_ORG environment variable) is required.' . $this->availableOrganizationsHint());
        }

        $this->org = $org;
        $serverId = (int) $this->getForgeServer();

        try {
            $server = $this->forge->server($this->org, $serverId);
        } catch (NotFoundException | ForbiddenException $exception) {
            return $this->bail("Failed to find server {$serverId} in organization '{$this->org}'." . $this->availableOrganizationsHint());
        } catch (Exception $exception) {
            return $this->bail("Failed to find server. Exception: " . $exception->getMessage());
        }

        try {
            $site = $this->findOrCreateSite($server);

            if ($this->option('deployment-script')) {
                $this->information('Updating deployment script');

                $deploymentScript = str_contains($this->option('deployment-script'), '@')
                    ? file_get_contents(str_replace('@', '', $this->option('deployment-script')))
                    : $this->option('deployment-script');

                $this->forge->updateDeploymentScript($this->org, $server->id, $site->id, [
                    'content' => $this->replaceVariables($deploymentScript),
                ]);
            }

            if (! $this->option('no-db')) {
                $this->maybeCreateDatabase($server, $site);
            }

            if (!empty($this->getEnvOverrides())) {
                $this->information('Updating environment variables');

                $this->withProvisioningRetry(function () use ($server, $site) {
                    $envSource = $this->forge->siteEnvironment($this->org, $server->id, $site->id);

                    foreach ($this->getEnvOverrides() as $env) {
                        [$key, $value] = explode(':', $env, 2);

                        $envSource = $this->updateEnvVariable($key, $value, $envSource);
                    }

                    $this->forge->updateSiteEnvironment($this->org, $server->id, $site->id, $envSource);
                });
            }

            $this->information('Deploying');

            $this->forge->createDeployment($this->org, $server->id, $site->id);

            // Now that the site is deployed with the correct script + environment,
            // turn on push-to-deploy so future pushes to the branch auto-deploy.
            // (Deferred from site creation to avoid a premature default-script deploy.)
            if ($this->createdSite && ! $this->option('no-quick-deploy')) {
                $this->information('Enabling push-to-deploy');

                $this->forge->enablePushToDeploy($this->org, $server->id, $site->id, []);
            }

            foreach ($this->option('command') as $i => $command) {
                if ($i === 0) {
                    $this->information('Executing site command(s)');
                }

                $this->forge->createCommand($this->org, $server->id, $site->id, [
                    'command' => $command,
                ]);
            }

            $this->maybeCreateScheduledJob($server);
        } catch (ValidationException $exception) {
            return $this->bailValidation($exception->errors());
        }
    }

    protected function updateEnvVariable(string $name, string $value, string $source): string
    {
        $value = $this->replaceVariables($value);

        if (! str_contains($source, "{$name}=")) {
            $source .= PHP_EOL . "{$name}={$value}";
        } else {
            $source = preg_replace("/^{$name}=[^\r\n]*/m", "{$name}={$value}", $source, 1);
        }

        return $source;
    }

    protected function maybeCreateScheduledJob(Server $server)
    {
        if (! $this->option('scheduler')) {
            return;
        }

        $command = $this->buildScheduledJobCommand();

        foreach ($this->forge->scheduledJobs($this->org, $server->id)->lazy() as $job) {
            if ($job->command === $command) {
                $this->information('Scheduler job already exists');
                return;
            }
        }

        $this->information('Creating scheduler job');

        $this->forge->createScheduledJob($this->org, $server->id, [
            'command' => $command,
            'frequency' => 'minutely',
            'user' => 'forge',
        ]);
    }

    protected function buildScheduledJobCommand(): string
    {
        return sprintf("php /home/forge/%s/artisan schedule:run", $this->generateSiteDomain());
    }

    protected function maybeCreateDatabase(Server $server, Site $site)
    {
        $name = $this->getDatabaseName();

        foreach ($this->forge->databases($this->org, $server->id)->lazy() as $database) {
            if ($database->name === $name) {
                $this->information('Database already exists.');

                return;
            }
        }

        $this->information('Creating database');

        // API v2 accepts the user/password alongside the schema, creating both in one call.
        $this->forge->createDatabase($this->org, $server->id, [
            'name' => $this->getDatabaseName(),
            'user' => $this->getDatabaseUserName(),
            'password' => $this->getDatabasePassword(),
        ]);

        $this->information('Updating site environment variables');

        $this->withProvisioningRetry(function () use ($server, $site) {
            $env = $this->forge->siteEnvironment($this->org, $server->id, $site->id);
            $env = preg_replace([
                "/DB_DATABASE=.*/",
                "/DB_USERNAME=.*/",
                "/DB_PASSWORD=.*/",
            ], [
                "DB_DATABASE={$this->getDatabaseName()}",
                "DB_USERNAME={$this->getDatabaseUserName()}",
                "DB_PASSWORD={$this->getDatabasePassword()}"
            ], $env);

            $this->forge->updateSiteEnvironment($this->org, $server->id, $site->id, $env);
        });
    }

    /**
     * Retry an operation while Forge reports the site is still provisioning.
     *
     * A freshly created site can't have its .env written for ~60s; Forge returns
     * a 422 telling us to wait. Retry (bounded by --timeout) until it succeeds.
     */
    protected function withProvisioningRetry(\Closure $callback)
    {
        $deadline = time() + max((int) ($this->option('timeout') ?? config('app.timeout')), 90);

        while (true) {
            try {
                return $callback();
            } catch (ValidationException $exception) {
                if (time() >= $deadline || ! $this->siteStillProvisioning($exception)) {
                    throw $exception;
                }

                $this->information('Site is still provisioning; waiting 15s before retrying...');
                sleep(15);
            }
        }
    }

    protected function siteStillProvisioning(ValidationException $exception): bool
    {
        foreach ($this->flattenValidationMessages($exception->errors()) as $message) {
            $message = strtolower($message);

            if (str_contains($message, 'recently created')
                || str_contains($message, 'please wait')
                || str_contains($message, 'could not be updated')) {
                return true;
            }
        }

        return false;
    }

    protected function maybeOutput(string $key, string $value): void
    {
        if (! $this->option('ci')) {
            return;
        }

        // @TODO: Support different providers, (currently outputing in GitHub format)
        $line = "forge_previewer_{$key}={$value}";

        if (($githubOutput = getenv('GITHUB_OUTPUT')) && is_writable($githubOutput)) {
            file_put_contents($githubOutput, $line . PHP_EOL, FILE_APPEND);

            return;
        }

        $this->line($line);
    }

    protected function findOrCreateSite(Server $server): Site
    {
        $domain = $this->generateSiteDomain();

        $this->maybeOutput('domain', $domain);

        if ($site = $this->findSiteByName($server, $domain)) {
            $this->information('Found existing site.');

            return $site;
        }

        $this->information('Creating site with domain ' . $domain);

        $this->createdSite = true;

        // The repository install (source_control_provider/repository/branch) is
        // folded into site creation on API v2 (installGitRepository() is gone).
        //
        // push_to_deploy is intentionally left OFF here: enabling it at creation
        // makes Forge auto-deploy the moment the repo finishes cloning — before we
        // set the deployment script or environment — running Forge's default
        // script and racing/blocking our own deployment. We enable it after the
        // first explicit deployment instead (see handle()).
        $data = [
            'type' => 'laravel',
            'domain_mode' => 'custom',
            'name' => $domain,
            'www_redirect_type' => 'none',
            'php_version' => $this->option('php-version'),
            'web_directory' => '/public',
            'allow_wildcard_subdomains' => (bool) $this->option('wildcard'),
            'source_control_provider' => $this->option('provider'),
            'repository' => $this->getRepoName(),
            'branch' => $this->getBranchName(),
            // Don't let Forge run composer during site creation: we always run a
            // deployment right after (which installs dependencies), so this is
            // redundant work — and Forge's creation-time install runs in parallel,
            // which on servers prone to composer's download race wedges the install
            // and blocks our deployment.
            'install_composer_dependencies' => false,
            'push_to_deploy' => false,
        ];

        if ($this->option('isolate')) {
            $this->information('Enabling site isolation');

            $data['is_isolated'] = true;
            $data['isolated_user'] = str($this->getBranchName())->slug()->toString();
        }

        if ($this->option('nginx-template')) {
            $this->information('Using custom nginx template');

            $data['nginx_template_id'] = (int) $this->option('nginx-template');
        }

        try {
            $site = $this->forge->createSite($this->org, $server->id, $data);
        } catch (ValidationException $exception) {
            // Forge can report the domain as "already added" even when our scan
            // above didn't see the site (e.g. a prior partial/failed create).
            // Reuse the existing site and continue provisioning it rather than
            // bailing and leaving it half-configured.
            if (! $this->domainAlreadyTaken($exception) || ! ($site = $this->findSiteByName($server, $domain))) {
                throw $exception;
            }

            $this->information('A site for this domain already exists; reusing it and continuing setup.');
        }

        foreach ($this->option('setup-command') as $i => $command) {
            if ($i === 0) {
                $this->information('Executing set up command(s)');
            }

            $this->information('Executing: ' . $command);

            $this->forge->createCommand($this->org, $server->id, $site->id, [
                'command' => $command,
            ]);
        }

        $this->information('Generating SSL certificate');

        $this->obtainCertificate($server, $site, $domain);

        return $site;
    }

    /**
     * Request a Let's Encrypt certificate for the site's primary domain.
     *
     * API v2 issues certificates per-domain: we look up the domain record created
     * with the site and request the certificate against it. Wildcard coverage comes
     * from the domain's allow_wildcard_subdomains flag + a dns-01 challenge; the
     * DNS provider credentials must be configured in Forge itself (v2 has no field
     * to pass them via the API, so --route-53-key/--route-53-secret are no longer
     * forwarded — they remain accepted for CLI backwards-compatibility).
     */
    protected function obtainCertificate(Server $server, Site $site, string $domain): void
    {
        $domainId = $this->findPrimaryDomainId($server, $site, $domain);

        if ($domainId === null) {
            $this->information('Skipping SSL certificate: could not find the primary domain record for ' . $domain . '.');

            return;
        }

        if ($this->option('wildcard')) {
            $this->information('Requesting a wildcard certificate via DNS (dns-01). Ensure your DNS provider credentials are configured in Forge — --route-53-key/--route-53-secret are no longer sent to the Forge API v2.');
        }

        try {
            $this->forge->createCertificate($this->org, $server->id, $site->id, $domainId, [
                'type' => 'letsencrypt',
                'letsencrypt' => [
                    'verification_method' => $this->option('wildcard') ? 'dns-01' : 'http-01',
                    'key_type' => 'ecdsa',
                ],
            ]);
        } catch (ValidationException $exception) {
            // A reused site may already have a certificate; don't fail the deploy over it.
            $this->information('Skipping SSL certificate: ' . implode(' | ', $this->flattenValidationMessages($exception->errors())));
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

    protected function domainAlreadyTaken(ValidationException $exception): bool
    {
        foreach ($this->flattenValidationMessages($exception->errors()) as $message) {
            if (str_contains(strtolower($message), 'already been added')) {
                return true;
            }
        }

        return false;
    }

    protected function findPrimaryDomainId(Server $server, Site $site, string $domain): ?int
    {
        foreach ($this->forge->domains($this->org, $server->id, $site->id)->lazy() as $siteDomain) {
            if ($siteDomain->name === $domain) {
                return $siteDomain->id;
            }
        }

        return null;
    }

    protected function replaceVariables(string $string): string
    {
        $branch = $this->getBranchName();
        $domain = $this->generateSiteDomain();

        return str_replace(['{domain}', '{branch}', '{branch_snake_case}'], [$domain, $branch, Str::replace('-', '_', $branch)], $string);
    }

    /**
     * @throws InvalidOptionException
     */
    protected function validateOptions(): void
    {
        if ($this->option('wildcard')) {
            if (!$this->option('route-53-key') || !$this->option('route-53-secret')) {
                throw new InvalidOptionException('--route-53-key and --route-53-secret options are required when site will have wildcard subdomains.');
            }
        }
    }

    protected function getEnvOverrides(): array
    {
        return array_merge($this->getStagingOverrides(), $this->option('edit-env'));
    }

    protected function getStagingOverrides(): array
    {
        if (File::exists('.env.staging')) {
            return Str::of(File::get('.env.staging'))
                ->trim()
                ->replace('=', ':')
                ->split("/\n/")
                ->toArray();
        }

        return [];
    }
}
