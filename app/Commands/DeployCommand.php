<?php

namespace App\Commands;

use App\Commands\Concerns\FindsSiteResources;
use App\Commands\Concerns\GeneratesDatabaseInfo;
use App\Commands\Concerns\GeneratesSiteInfo;
use App\Commands\Concerns\ResolvesOrganization;
use App\Exceptions\ProvisioningFailedException;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\File;
use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Laravel\Forge\Exceptions\FailedActionException;
use Laravel\Forge\Exceptions\ForbiddenException;
use Laravel\Forge\Exceptions\NotFoundException;
use Laravel\Forge\Exceptions\RateLimitExceededException;
use Laravel\Forge\Exceptions\TimeoutException;
use Laravel\Forge\Exceptions\ValidationException;
use Laravel\Forge\Resources\Deployment;
use Laravel\Forge\Resources\Site;
use Laravel\Forge\Resources\Server;
use App\Commands\Concerns\HandlesOutput;
use App\Commands\Concerns\InteractsWithEnv;
use LaravelZero\Framework\Commands\Command;

class DeployCommand extends Command
{
    use FindsSiteResources;
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
        {--route-53-key= : Deprecated; no longer sent to Forge. Configure DNS provider credentials in Forge.}
        {--route-53-secret= : Deprecated; no longer sent to Forge. Configure DNS provider credentials in Forge.}
        {--nginx-template= : The nginx template ID to use on your Laravel Forge website.}
        {--timeout= : Change default timeout (120). In seconds.}
    ';

    protected $description = 'Deploy a branch / pull request to Laravel Forge.';

    protected Forge $forge;

    protected string $org;

    public function handle(Forge $forge)
    {
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

                $this->withProvisioningRetry(function () use ($server, $site, $deploymentScript) {
                    $this->forge->updateDeploymentScript($this->org, $server->id, $site->id, [
                        'content' => $this->replaceVariables($deploymentScript),
                    ]);
                });
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

            if (! $this->option('no-deploy')) {
                $this->information('Deploying');

                $deployment = $this->forge->createDeployment($this->org, $server->id, $site->id);

                $this->waitForDeployment(
                    $server,
                    $site,
                    $deployment,
                    (int) ($this->option('timeout') ?? config('app.timeout')),
                );
            }

            // Now that the site is configured with the correct script + environment,
            // turn on push-to-deploy so future pushes to the branch auto-deploy.
            // (Deferred from site creation to avoid a premature default-script deploy.)
            // Converge from the site's actual state rather than remembering whether
            // THIS run created it: a first run that bailed midway must still gain
            // push-to-deploy when retried.
            if ($site->quickDeploy !== true && ! $this->option('no-quick-deploy')) {
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
        } catch (TimeoutException $_) {
            return $this->bail('Timed out waiting for Forge to finish provisioning a resource. Increase --timeout and retry.');
        } catch (NotFoundException $_) {
            return $this->bail('A Forge resource disappeared mid-deploy (the site or server may have been deleted concurrently). Retry the deployment.');
        } catch (ForbiddenException $_) {
            return $this->bail('Forge denied a request mid-deploy (403). Check the API token\'s scopes for this organization.');
        } catch (RateLimitExceededException $_) {
            return $this->bail('The Forge API rate limit was exceeded. Wait a moment and retry.');
        } catch (FailedActionException $exception) {
            return $this->bail('Forge could not perform an action: ' . $exception->getMessage());
        } catch (ProvisioningFailedException $exception) {
            return $this->bail($exception->getMessage());
        }
    }

    /**
     * Block until the deployment created by this command reaches a terminal
     * state. Waiting on the exact deployment ID prevents an older healthy
     * release from making the preview look ready while the new release is
     * still queued or running.
     *
     * @throws ProvisioningFailedException when deployment fails, is cancelled,
     *                                     returns an unexpected status, or times out.
     */
    protected function waitForDeployment(
        Server $server,
        Site $site,
        Deployment $deployment,
        int $timeout,
    ): void {
        if ($deployment->id === null) {
            throw new ProvisioningFailedException('Forge accepted the deployment but returned no deployment ID.');
        }

        $deadline = time() + max($timeout, 120);
        $status = $deployment->status ?? 'unknown';

        while (time() < $deadline) {
            try {
                $deployment = $this->forge->deployment(
                    $this->org,
                    $server->id,
                    $site->id,
                    $deployment->id,
                );
            } catch (NotFoundException | ConnectException $_) {
                // The deployment can briefly be unavailable immediately after
                // creation, and transient connection failures are safe to retry.
                sleep(5);
                continue;
            }

            $status = $deployment->status ?? 'unknown';

            if ($status === 'finished') {
                return;
            }

            if (in_array($status, ['cancelled', 'failed', 'failed-build'], true)) {
                throw new ProvisioningFailedException("Deployment {$deployment->id} did not succeed (status: {$status}).");
            }

            if (! in_array($status, ['deploying', 'pending', 'queued'], true)) {
                throw new ProvisioningFailedException("Deployment {$deployment->id} returned an unexpected status: {$status}.");
            }

            $this->information("Waiting for deployment {$deployment->id} to finish (status: {$status})...");
            sleep(8);
        }

        throw new ProvisioningFailedException("Timed out waiting for deployment {$deployment->id} to finish (last status: {$status}). Increase --timeout and retry.");
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

        foreach ($this->safelyIterate(fn () => $this->forge->scheduledJobs($this->org, $server->id)) as $job) {
            if ($job->command === $command) {
                $this->information('Scheduler job already exists');
                return;
            }
        }

        $this->information('Creating scheduler job');

        try {
            $this->forge->createScheduledJob($this->org, $server->id, [
                'command' => $command,
                'frequency' => 'minutely',
                'user' => 'forge',
            ]);
        } catch (NotFoundException $_) {
            throw new ProvisioningFailedException('Scheduled jobs are unavailable on this server. Rerun without --scheduler.');
        }
    }

    protected function buildScheduledJobCommand(): string
    {
        return sprintf("php /home/forge/%s/artisan schedule:run", $this->generateSiteDomain());
    }

    /**
     * Forge requires isolated_user to match ^[a-z][-a-z0-9_]*$ (max 32 chars).
     * A slugged branch name can start with a digit (e.g. "123-fix-thing"), so
     * prefix it when needed and clamp the length.
     */
    protected function generateIsolatedUser(): string
    {
        $user = str($this->getBranchName())->slug()->toString();

        if (! preg_match('/^[a-z]/', $user)) {
            $user = 'br-' . $user;
        }

        // Keep long names unique when clamping: a plain cut would collide for
        // branches that share their first 32 slug characters.
        if (strlen($user) > 32) {
            $user = substr($user, 0, 25) . '-' . substr(md5($user), 0, 6);
        }

        return $user;
    }

    protected function maybeCreateDatabase(Server $server, Site $site)
    {
        $name = $this->getDatabaseName();

        foreach ($this->safelyIterate(fn () => $this->forge->databases($this->org, $server->id)) as $database) {
            if ($database->name === $name) {
                $this->information('Database already exists.');

                return;
            }
        }

        $this->information('Creating database');

        // API v2 accepts the user/password alongside the schema, creating both in one call.
        try {
            $this->forge->createDatabase($this->org, $server->id, [
                'name' => $this->getDatabaseName(),
                'user' => $this->getDatabaseUserName(),
                'password' => $this->getDatabasePassword(),
            ]);
        } catch (NotFoundException $_) {
            throw new ProvisioningFailedException('Managed databases are unavailable on this server. Rerun with --no-db.');
        }

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

    /**
     * Matched against the 422 Forge returns while a fresh site is provisioning,
     * observed live as: "The .env file could not be updated. If the site was
     * recently created, please wait at least 60 seconds and try again."
     * Match only the phrases specific to that provisioning race — anything more
     * generic risks retrying a permanent validation error for the full timeout.
     */
    protected function siteStillProvisioning(ValidationException $exception): bool
    {
        foreach ($this->flattenValidationMessages($exception->errors()) as $message) {
            $message = strtolower($message);

            if (str_contains($message, 'recently created') || str_contains($message, 'please wait')) {
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

            // A found site can still be mid-install (a concurrent deploy) or
            // mid-teardown (a concurrent destroy) — same rules as a fresh create.
            $this->waitForSiteInstalled($site, tolerateFailed: true);

            return $site;
        }

        $this->information('Creating site with domain ' . $domain);

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
            $data['isolated_user'] = $this->generateIsolatedUser();
        }

        if ($this->option('nginx-template')) {
            $this->information('Using custom nginx template');

            $data['nginx_template_id'] = (int) $this->option('nginx-template');
        }

        $reused = false;

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

            $reused = true;
            $this->information('A site for this domain already exists; reusing it and continuing setup.');
        }

        // Site creation (repo clone + directory setup) is asynchronous. Wait for it
        // to finish before running commands/certs/deployments — otherwise those race
        // the install and fail (e.g. the deploy script's `cd <site dir>` runs before
        // the directory exists).
        $this->waitForSiteInstalled($site, tolerateFailed: $reused);

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
            throw new ProvisioningFailedException('Could not find the primary domain record for ' . $domain . '; unable to request its SSL certificate.');
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
            // A reused site may already have a certificate — tolerate only that
            // case. Any other validation failure (unconfigured DNS provider for
            // dns-01, invalid domain, rate limit, bad payload) must fail the
            // deploy loudly rather than exit 0 without HTTPS.
            if (! $this->certificateAlreadyExists($exception)) {
                throw $exception;
            }

            $this->information('Skipping SSL certificate (already present): ' . implode(' | ', $this->flattenValidationMessages($exception->errors())));
        }
    }

    protected function certificateAlreadyExists(ValidationException $exception): bool
    {
        foreach ($this->flattenValidationMessages($exception->errors()) as $message) {
            $message = strtolower($message);

            if (str_contains($message, 'certificate')
                && (str_contains($message, 'already exists')
                    || str_contains($message, 'already present')
                    || str_contains($message, 'already active')
                    || str_contains($message, 'already been issued'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Block until a freshly-created site has finished installing (repo clone +
     * directory setup). The SiteStatus enum is: installed, creating, removing,
     * installing, uninstalling, deployed, never-deployed, deploying, failed,
     * maintenance. Only creating/installing mean the install is still running —
     * a reused site is typically already deployed/never-deployed, and waiting
     * for a literal "installed" would spin until the deadline.
     *
     * A reused site may report "failed" from an earlier run — re-provisioning
     * it is the recovery path, so callers on a reuse path pass $tolerateFailed.
     * For a fresh create, "failed" is fatal.
     *
     * @throws ProvisioningFailedException when installation fails or misses the deadline.
     */
    protected function waitForSiteInstalled(Site $site, bool $tolerateFailed = false): void
    {
        $inProgress = ['creating', 'installing'];
        $deadline = time() + max((int) ($this->option('timeout') ?? config('app.timeout')), 120);
        $status = 'unknown';

        while (time() < $deadline) {
            try {
                $status = $this->forge->organizationSite($this->org, $site->id)->status;
            } catch (NotFoundException | ConnectException $_) {
                // The site record can briefly 404 right after creation, and a
                // connection can fail transiently. Auth/validation errors surface.
                sleep(5);
                continue;
            }

            if ($status === 'failed') {
                if (! $tolerateFailed) {
                    throw new ProvisioningFailedException('Site installation failed (status: failed).');
                }

                $this->information('Site status is "failed" from an earlier run; attempting to re-provision it.');

                return;
            }

            if (in_array($status, ['removing', 'uninstalling'], true)) {
                throw new ProvisioningFailedException("The site is being removed (status: {$status}) — a concurrent destroy may be running. Retry once it finishes.");
            }

            if (! in_array($status, $inProgress, true)) {
                return;
            }

            $this->information("Waiting for the site to finish installing (status: {$status})...");
            sleep(8);
        }

        throw new ProvisioningFailedException("Timed out waiting for the site to finish installing (last status: {$status}). Increase --timeout or retry once the install completes.");
    }

    /**
     * Matched against the 422 observed live from createSite when the domain
     * already exists: "That domain has already been added to a site on this server."
     */
    protected function domainAlreadyTaken(ValidationException $exception): bool
    {
        foreach ($this->flattenValidationMessages($exception->errors()) as $message) {
            if (str_contains(strtolower($message), 'already been added')) {
                return true;
            }
        }

        return false;
    }

    protected function replaceVariables(string $string): string
    {
        $branch = $this->getBranchName();
        $domain = $this->generateSiteDomain();

        return str_replace(['{domain}', '{branch}', '{branch_snake_case}'], [$domain, $branch, Str::replace('-', '_', $branch)], $string);
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
