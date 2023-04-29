<?php

namespace App\Commands\Concerns;

use Illuminate\Support\Str;

trait InteractsWithEnv
{
	protected function env(string $name, mixed $or = null): mixed
	{
		if (env($name) !== null) {
			return env($name);
		}

		return $or;
	}

	protected function getDomainName()
	{
		return $this->env('FORGE_DOMAIN', or: $this->option('domain'));
	}

	protected function getForgeServer()
	{
		return $this->env('FORGE_SERVER', or: $this->option('server'));
	}

	protected function getRepoName()
	{
		return $this->env('FORGE_REPO', or: $this->option('repo'));
	}

	protected function getBranchName()
	{
		return $this->env('FORGE_BRANCH', or: $this->option('branch'));
	}

	protected function getUniqueName()
	{
		return $this->env('FORGE_NAME', or: $this->option('name') ?? $this->option('branch'));
	}

	protected function getForgeToken(): ?string
	{
		return $this->env('FORGE_TOKEN', or: $this->option('token'));
	}

	protected function getDatabasePassword(): ?string
	{
		return $this->env('FORGE_DB_PASSWORD', or: $this->option('db-password') ?? Str::random(16));
	}

	protected function getDatabaseUserName(): ?string
	{
		return $this->env('FORGE_DB_USERNAME', or: $this->option('db-username') ?? 'forge');
	}

	protected function getDatabaseName(): ?string
	{
		return $this->env('FORGE_DB_DATABASE', or: $this->option('db-database') ?? str($this->getBranchName())->slug('_')->limit(64)->toString());
	}
}