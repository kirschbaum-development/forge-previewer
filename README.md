# Forge Previewer

This CLI is designed to create "preview" environments for pull requests and branches using Laravel Forge.

It's intended for use inside of CI tools such as GitHub Actions to automatically create a site on Forge that is connected to your pull request branch, then once the pull request is merged the CLI can be used to cleanup too.

> **Forge API v2**: As of `v1.0.0` this tool targets the **Forge API v2** (via `laravel/forge-sdk ^4`). Forge API v1 was shut down on **July 31, 2026**. Every command now requires a Forge **organization slug** — pass `--org=` or set the `FORGE_ORG` environment variable. If you omit it, the tool prints the organizations your token can access.

## Prerequisites

Before using this tool, please make sure you have the following:

* A server connected to [Forge](https://forge.laravel.com).
* The **slug of your Forge organization** (used by every API v2 endpoint). If you're not sure, run any command without `--org` and the error message lists the organizations your token can access.
* A [Forge API token](https://forge.laravel.com/docs/accounts#api-tokens).
* _(Only for `--wildcard`)_ A wildcard subdomain DNS record pointing at your Forge server, **and** your DNS provider credentials configured inside Forge. See [Wildcard subdomains](#wildcard-subdomains) — Forge API v2 no longer accepts Route 53 credentials over the API.

## Installation

There are two ways to install the tool.

### Option 1 — Prebuilt static binary (recommended, no PHP required)

The GitHub Releases page ships a self-contained, fully-static Linux binary with PHP **8.5** embedded. Because it's a musl static build with zero libc dependency, **the same x86_64 binary runs on Ubuntu 20.04, 24.04, and everything in between** — you do not need PHP installed on the host.

```sh
curl -L -o forge-previewer \
  https://github.com/kirschbaum-development/forge-previewer/releases/latest/download/forge-previewer-linux-x86_64
chmod +x forge-previewer
./forge-previewer --version
```

`forge-previewer-ubuntu-20.04` and `forge-previewer-ubuntu-24.04` are published as **byte-identical copies** of `forge-previewer-linux-x86_64` so the release page explicitly shows both supported targets — download whichever name you prefer. Verify your download against the release's `SHA256SUMS.txt`.

### Option 2 — Build from source (requires PHP 8.5+)

Non-Ubuntu platforms (macOS, Windows, ARM, …) and anyone who prefers running from source can build from the repository. **PHP 8.5+ is required** to build and run from source:

```sh
git clone https://github.com/kirschbaum-development/forge-previewer.git
cd forge-previewer
composer install
./forge-previewer --version        # run directly from source
```

To produce a distributable PHAR, compile with [Box](https://github.com/box-project/box):

```sh
composer install --no-dev
box compile                        # produces forge-previewer.phar
```

`composer.json`'s `bin` points at the source entry script, so `composer global require` also works once this fork is published to a Packagist/VCS repository you control.

## Usage

There are two commands in the application.

* `deploy` - Creates and deploys a site.
* `destroy` - Deletes and cleans up after a site.

Both commands require an organization slug (`--org=<slug>` or `FORGE_ORG`) and a Forge API token (`--token=` or `FORGE_TOKEN`). The server, repository, branch and domain may also be supplied via `FORGE_SERVER`, `FORGE_REPO`, `FORGE_BRANCH` and `FORGE_DOMAIN`.

### `deploy`

The `deploy` command is used to do the following things:

1. Create a site on Forge (with the repository and branch installed as part of creation).
2. Enable **push-to-deploy** so new commits deploy automatically (unless `--no-quick-deploy`).
3. Generate a Let's Encrypt SSL certificate for the site's primary domain.
4. Create a database for the new site.
5. Update the environment variables to point to the database.
6. Deploy your site once.
7. Run any additional commands provided.
8. Create a scheduled job if required.

The command accepts the following flags:

```
Description:
  Deploy a branch / pull request to Laravel Forge.

Usage:
  deploy [options]

Options:
    --token[=TOKEN]                          The Forge API token.
    --org[=ORG]                              The slug of the Forge organization. Required (or set FORGE_ORG).
    --server[=SERVER]                        The ID of the target server.
    --provider[=PROVIDER]                    The Git provider. [default: "github"]
    --repo[=REPO]                            The name of the repository being deployed.
    --branch[=BRANCH]                        The name of the branch being deployed.
    --domain[=DOMAIN]                        The domain you'd like to use for deployments.
    --php-version[=PHP-VERSION]              The version of PHP the site should use, e.g. php84, php83, ... [default: "php84"]
    --setup-command[=SETUP-COMMAND]          A command you would like to execute after configuring the git repo. (multiple values allowed)
    --command[=COMMAND]                      A command you would like to execute on the site, e.g. php artisan db:seed. (multiple values allowed)
    --edit-env[=EDIT-ENV]                    The colon-separated name and value that will be added/updated in the site's environment, e.g. "MY_API_KEY:my_api_key_value". (multiple values allowed)
    --deployment-script[=DEPLOYMENT-SCRIPT]  The deployment script to replace Forge's deployment script.
    --scheduler                              Setup a cronjob to run Laravel's scheduler.
    --isolate                                Enable site isolation.
    --ci                                     Add additional output for your CI provider.
    --no-quick-deploy                        Create your site without "Quick Deploy".
    --no-deploy                              Avoid deploying the site.
    --no-db                                  Avoid creating a database.
    --wildcard                               Create a site with wildcard subdomains.
    --route-53-key[=ROUTE-53-KEY]            (Deprecated — see Wildcard subdomains below.)
    --route-53-secret[=ROUTE-53-SECRET]      (Deprecated — see Wildcard subdomains below.)
    --nginx-template[=NGINX-TEMPLATE]        The nginx template ID to use on your Laravel Forge website.
    --timeout[=TIMEOUT]                      Change default timeout (120). In seconds.
```

> **Note**: the `deploy` command can be run multiple times and will skip any steps that have already been run previously.

#### Environment overrides

To add or update environment values, the `--edit-env` option may be used.
```bash
forge-previewer deploy --org=my-org --edit-env=APP_URL:staging.example.com --edit-env=APP_ENV:staging
```

If found, values will be taken from a `.env.staging` file. The values set through `--edit-env` options will take precedence.

Example `.env.staging` file:
```dotenv
APP_URL=staging.example.com
APP_ENV=staging
```

#### Wildcard subdomains

Passing `--wildcard` creates the site with wildcard subdomains enabled and requests the certificate using a **DNS (`dns-01`) challenge**.

Forge API **v2 has no field for passing DNS-provider credentials over the API** (unlike v1, which accepted Route 53 keys in the certificate request). The `--route-53-key` / `--route-53-secret` options are therefore **still accepted for backwards-compatibility but are no longer sent to Forge**. To issue a wildcard certificate you must configure your DNS provider credentials **inside Forge** so it can complete the `dns-01` challenge.

### `destroy`

The `destroy` command reverses all of the things that the `deploy` command does. It checks for the existence of certain resources in Forge and removes them if they exist.

The command accepts the following flags:

```
Description:
  Destroy a previously created preview site.

Usage:
  destroy [options]

Options:
    --token[=TOKEN]                        The Forge API token.
    --org[=ORG]                            The slug of the Forge organization. Required (or set FORGE_ORG).
    --server[=SERVER]                      The ID of the target server.
    --repo[=REPO]                          The name of the repository being deployed.
    --branch[=BRANCH]                      The name of the branch being deployed.
    --domain[=DOMAIN]                      The domain you'd like to use for deployments.
    --pre-destroy-command[=...]            Command to run before destroying the site. (multiple values allowed)
```

Since Forge Previewer is convention based, we will try to detect resources based on the names we generate for them.

## Example Workflow

These examples download the prebuilt binary (Option 1) so no PHP is needed on the runner. `FORGE_ORG` is required.

### Feature Branch Deployments
```yaml
name: Feature Branch Deployments

on:
  push:
    branches:
      - '**'      # matches every branch
      - '!main'   # excludes main

jobs:
  deploy:
    name: Feature Branch Deployment
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v4
    - name: Get branch name
      id: branch-name
      uses: tj-actions/branch-names@v8

    - name: Download forge-previewer
      run: |
        curl -L -o forge-previewer \
          https://github.com/kirschbaum-development/forge-previewer/releases/latest/download/forge-previewer-linux-x86_64
        chmod +x forge-previewer

    - name: Deploy
      run: ./forge-previewer deploy --token="${{ secrets.FORGE_API_TOKEN }}" --org="${{ secrets.FORGE_ORG }}" --server="${{ secrets.FORGE_SERVER_ID }}" --repo=example/demo --branch=${GITHUB_REF##*/} --domain=example.com --php-version=php84 --no-db --edit-env="APP_URL:https://{domain}" --wildcard
```

### Feature Branch Deployment Deletion

```yaml
name: Feature Branch Deployment Deletion

on:
  delete:
    branches:
      - '**'      # matches every branch
      - '!main'   # excludes main

jobs:
  destroy:
    name: Feature Branch Deployment Deletion
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v4

    - name: Download forge-previewer
      run: |
        curl -L -o forge-previewer \
          https://github.com/kirschbaum-development/forge-previewer/releases/latest/download/forge-previewer-linux-x86_64
        chmod +x forge-previewer

    - name: Destroy
      run: ./forge-previewer destroy --token="${{ secrets.FORGE_API_TOKEN }}" --org="${{ secrets.FORGE_ORG }}" --server="${{ secrets.FORGE_SERVER_ID }}" --repo=example/demo --branch=${GITHUB_REF##*/} --domain=example.com
```

## Contributing / building from source

* Requires **PHP 8.5+**.
* `composer install` then `./forge-previewer list` to boot the CLI.
* `vendor/bin/pest` to run the test suite.
* CI (`.github/workflows/tests.yml`) runs the suite on every push/PR.
* Tagged `v*` releases (`.github/workflows/release.yml`) build and attach the static binaries automatically.
