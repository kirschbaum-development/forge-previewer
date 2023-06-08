<?php

namespace App\Commands;

use App\Commands\Concerns\GeneratesDatabaseInfo;
use App\Commands\Concerns\GeneratesSiteInfo;
use Exception;
use Illuminate\Support\Facades\File;
use Laravel\Forge\Forge;
use Illuminate\Support\Str;
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
    use GeneratesSiteInfo;
    use GeneratesDatabaseInfo;

    protected $signature = 'deploy
        {--token=  : The Forge API token.}
        {--server= : The ID of the target server.}
        {--provider=github : The Git provider.}
        {--repo= : The name of the repository being deployed.}
        {--branch= : The name of the branch being deployed.}
        {--domain= : The domain you\'d like to use for deployments.}
        {--php-version=php81 : The version of PHP the site should use, e.g. php81, php80, ...}
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
    ';

    protected $description = 'Deploy a branch / pull request to Laravel Forge.';

    protected Forge $forge;

    public function handle(Forge $forge)
    {
        $this->validateOptions();

        $this->forge = $forge->setApiKey($this->getForgeToken())
            ->setTimeout(config('app.timeout'));

        try {
            $server = $forge->server($this->getForgeServer());
        } catch (Exception $exception) {
            return $this->fail("Failed to find server. Exception: " . $exception->getMessage());
        }

        $site = $this->findOrCreateSite($server);

        if ($this->option('deployment-script')) {
            $this->information('Updating deployment script');

            $deploymentScript = str_contains($this->option('deployment-script'), '@')
                ? file_get_contents(str_replace('@', '', $this->option('deployment-script')))
                : $this->option('deployment-script');

            $site->updateDeploymentScript($this->replaceVariables($deploymentScript));
        }

        if (! $this->option('no-db')) {
            $this->maybeCreateDatabase($server, $site);
        }

        if (!empty($this->getEnvOverrides())) {
            $this->information('Updating environment variables');

            $envSource = $forge->siteEnvironmentFile($server->id, $site->id);

            foreach ($this->getEnvOverrides() as $env) {
                [$key, $value] = explode(':', $env, 2);

                $envSource = $this->updateEnvVariable($key, $value, $envSource);
            }

            $forge->updateSiteEnvironmentFile($server->id, $site->id, $envSource);
        }

        $this->information('Deploying');

        $site->deploySite();

        foreach ($this->option('command') as $i => $command) {
            if ($i === 0) {
                $this->information('Executing site command(s)');
            }

            $forge->executeSiteCommand($server->id, $site->id, [
                'command' => $command,
            ]);
        }

        $this->maybeCreateScheduledJob($server);
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

        foreach ($this->forge->jobs($server->id) as $job) {
            if ($job->command === $command) {
                $this->information('Scheduler job already exists');
                return;
            }
        }

        $this->information('Creating scheduler job');

        $this->forge->createJob($server->id, [
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

        foreach ($this->forge->databases($server->id) as $database) {
            if ($database->name === $name) {
                $this->information('Database already exists.');

                return;
            }
        }

        $this->information('Creating database');

        $this->forge->createDatabase($server->id, [
            'name' => $this->getDatabaseName(),
            'user' => $this->getDatabaseUserName(),
            'password' => $this->getDatabasePassword(),
        ]);

        $this->information('Updating site environment variables');

        $env = $this->forge->siteEnvironmentFile($server->id, $site->id);
        $env = preg_replace([
            "/DB_DATABASE=.*/",
            "/DB_USERNAME=.*/",
            "/DB_PASSWORD=.*/",
        ], [
            "DB_DATABASE={$this->getDatabaseName()}",
            "DB_USERNAME={$this->getDatabaseUserName()}",
            "DB_PASSWORD={$this->getDatabasePassword()}"
        ], $env);

        $this->forge->updateSiteEnvironmentFile($server->id, $site->id, $env);
    }

    protected function maybeOutput(string $key, string $value): void
    {
        if ($this->option('ci')) {
            // @TODO: Support different providers, (currently outputing in GitHub format)
            $this->line("::set-output name=forge_previewer_{$key}::$value");
        }
    }

    protected function findOrCreateSite(Server $server): Site
    {
        $sites = $this->forge->sites($server->id);
        $domain = $this->generateSiteDomain();

        $this->maybeOutput('domain', $domain);

        foreach ($sites as $site) {
            if ($site->name === $domain) {
                $this->information('Found existing site.');

                return $site;
            }
        }

        $this->information('Creating site with domain ' . $domain);

        $data = [
            'domain' => $domain,
            'project_type' => 'php',
            'php_version' => $this->option('php-version'),
            'directory' => '/public',
            'wildcards' => $this->option('wildcard')
        ];

        if ($this->option('isolate')) {
            $this->information('Enabling site isolation');

            $data['isolation'] = true;
            $data['username'] = str($this->getBranchName())->slug();
        }

        if ($this->option('nginx-template')) {
            $this->information('Using custom nginx template');

            $data['nginx_template'] = $this->option('nginx-template');
        }

        $site = $this->forge->createSite($server->id, $data);

        $this->information('Installing Git repository');

        $site->installGitRepository([
            'provider' => $this->option('provider'),
            'repository' => $this->getRepoName(),
            'branch' => $this->getBranchName(),
            'composer' => true,
        ]);

        if (! $this->option('no-quick-deploy')) {
            $this->information('Enabling quick deploy');

            $site->enableQuickDeploy();
        }

        foreach ($this->option('setup-command') as $i => $command) {
            if ($i === 0) {
                $this->information('Executing set up command(s)');
            }

            $this->information('Executing: ' . $command);

            $this->forge->executeSiteCommand($server->id, $site->id, [
                'command' => $command,
            ]);
        }

        $this->information('Generating SSL certificate');

        $letsEncryptCertificateData = [
            'domains' => [$this->generateSiteDomain()],
        ];

        if ($this->option('wildcard')) {
            $letsEncryptCertificateData['domains'] = ['*.' . $this->generateSiteDomain()];
            $letsEncryptCertificateData['dns_provider'] = [
                'type' => 'route53',
                'route53_key' => $this->option('route-53-key'),
                'route53_secret' => $this->option('route-53-secret'),
            ];
        }

        $this->forge->obtainLetsEncryptCertificate($server->id, $site->id, $letsEncryptCertificateData);

        return $site;
    }

    protected function replaceVariables(string $string): string
    {
        $branch = $this->getBranchName();
        $domain = $this->generateSiteDomain();

        return str_replace(['{domain}', '{branch}'], [$domain, $branch], $string);
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
