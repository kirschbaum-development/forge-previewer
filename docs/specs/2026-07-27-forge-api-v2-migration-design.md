# forge-previewer v2: Forge API v2 migration + GitHub-built binaries

## Context

`forge-previewer` is a Laravel Zero CLI (deploy/destroy Forge preview sites for PRs) currently built on `laravel/forge-sdk ^3.13`, which targets **Forge API v1 — deprecated and shut down on July 31, 2026**. The tool must migrate to Forge API v2 via `laravel/forge-sdk ^4.1`, which is a ground-up rewrite: org-scoped endpoints, JSON:API, paginated lists, and several endpoints this tool relies on were removed or reshaped.

Distribution today is a manually Box-compiled PHAR committed to `builds/` (requires PHP on the host); there is no CI. The goal is GitHub Actions builds self-contained static binaries (PHP embedded via static-php-cli) supporting Ubuntu 20.04 and 24.04, attached to GitHub Releases on version tags. Other platforms keep building manually.

## Decisions

- **Binary**: self-contained static binary (static-php-cli micro.sfx + PHAR), no PHP required on host.
- **Storage**: GitHub Releases, on `v*` tags. Stop committing binaries to `builds/`.
- **Org slug**: required — new `--org` option (with `FORGE_ORG` env fallback, matching the existing `InteractsWithEnv` pattern). No auto-detection; fail with a clear error listing orgs if missing/wrong.
- **Version**: tag as `v1.0.0`.
- **Targets**: Ubuntu 20.04 + 24.04 binaries from CI; other platforms build manually (document in README).
- **Dependencies**: all bumped to latest (PHP ^8.5, Laravel Zero 12, forge-sdk ^4.1, Pest 4, termwind latest). **PHP 8.5+ (current stable) is required to build/run from source**; the floor is our choice (LZ12/SDK v4 only need 8.2) so that source builds, CI, and the embedded binary PHP are all the same version. Servers with older default PHP use the prebuilt static binary, which brings its own PHP.
- **Upstream sync**: merge the 2 commits from `ryangjchandler/forge-previewer` main that this fork is missing (lock file + InspireCommand removal); `composer.lock` becomes committed from now on (Part 0).

## Part 0 — Pull in missing upstream commits

The upstream repo (`ryangjchandler/forge-previewer`, main) is 2 commits ahead of this fork ([compare view](https://github.com/kirschbaum-development/forge-previewer/compare/main...ryangjchandler%3Aforge-previewer%3Amain)); merge/cherry-pick these **first**, before the dependency upgrade:

- `e046817` "chore: add lock file" — re-adds `composer.lock`. Safe to take: a dependency's lock file is ignored by `composer global require`, so the original reason for deleting it (commit 19ec04e) doesn't hold. The lock gets regenerated in Part 2 anyway; the lasting change is removing `composer.lock` from `.gitignore` and committing it (which also gives CI lockfile-based caching and reproducible release builds).
- `0eafac6` "Remove inspire command and update build to latest version" — removes `InspireCommand` (aligns with Part 2 cleanup), bumps `php ^8.1` / `laravel-zero ^10` (superseded by Part 2's ^8.5/^12 targets), and adds a `--timeout` option to DeployCommand. **Conflict**: this fork already added its own `--timeout` (with `config('app.timeout')` default) — keep the fork's version. Note upstream removed the command but not `tests/Feature/InspireCommandTest.php`, so delete that test in the same merge commit to keep the suite green.

Expect merge conflicts on `composer.json`, `DeployCommand.php`, and `builds/forge-previewer` (binary — resolution is moot since Part 3 deletes it). A `git merge` of upstream/main with manual resolution keeps history connected; cherry-picking is the fallback.

## Part 1 — Forge SDK v3 → v4 (API v1 → v2) migration

### Global changes

- `composer.json`: `laravel/forge-sdk: ^4.1`, `php: ^8.5` (LZ12 and SDK v4 only require ^8.2; 8.5 is our chosen floor — see Decisions).
- Every SDK call gains `string $organizationSlug` as first arg.
- New `--org=` option on **both** commands; `InteractsWithEnv` gets `getOrganization()` reading `FORGE_ORG` env → `--org` flag; fail via `HandlesOutput::fail()` when absent. On a `NotFoundException`/`ForbiddenException` for the org, surface available slugs from `$forge->organizations()` in the error message to aid migration.
- List endpoints return `CursorPaginator` (iterable/countable — existing `foreach` loops keep working), but paginated: loops that scan for an existing site/job/database must iterate **all pages** via `->lazy()`, not just page 1.
- Mutation endpoints answering 202 return `void`; certificate objects no longer have `->delete()` etc.

### Call-by-call mapping

`app/Commands/DeployCommand.php`:

| Current (v3) | New (v4) |
|---|---|
| `$forge->server($serverId)` | `$forge->server($org, (int) $serverId)` |
| `$forge->sites($server->id)` (per-server) | `$forge->serverSites($org, $serverId)` — iterate `->lazy()` |
| `$forge->createSite($server->id, $data)` | `$forge->createSite($org, $serverId, $data)` with **new payload shape** (below) |
| `$site->installGitRepository([...])` | **Removed** — repo config moves into `createSite` payload (`source_control_provider`, `repository`, `branch`, `install_composer_dependencies`) |
| `$site->enableQuickDeploy()` | **Removed** — `push_to_deploy: true` in `createSite` payload (or `$forge->enablePushToDeploy($org, $serverId, $siteId, $data)` after) |
| `$site->updateDeploymentScript($content)` | `$site->updateDeploymentScript(['content' => $content])` (now takes array) or `$forge->updateDeploymentScript($org, $serverId, $siteId, ['content' => ...])` |
| `$forge->siteEnvironmentFile($serverId, $siteId)` | `$forge->siteEnvironment($org, $serverId, $siteId)` |
| `$forge->updateSiteEnvironmentFile(...)` | `$forge->updateSiteEnvironment($org, $serverId, $siteId, $content)` |
| `$site->deploySite()` | `$forge->createDeployment($org, $serverId, $siteId)` (returns `Deployment`) |
| `$forge->executeSiteCommand($serverId, $siteId, ['command' => ...])` | `$forge->createCommand($org, $serverId, $siteId, ['command' => ...])` (returns void) |
| `$forge->jobs($server->id)` | `$forge->scheduledJobs($org, $serverId)` — iterate `->lazy()` |
| `$forge->createJob($serverId, [...])` | `$forge->createScheduledJob($org, $serverId, [...])` (verify payload keys `command`/`frequency`/`user` against v2 schema) |
| `$forge->databases($server->id)` | `$forge->databases($org, $serverId)` — iterate `->lazy()` |
| `$forge->createDatabase($serverId, ['name','user','password'])` | `$forge->createDatabase($org, $serverId, $data)` — verify v2 payload (user creation may be separate `createDatabaseUser`) |
| `$forge->obtainLetsEncryptCertificate($serverId, $siteId, $data)` | **Per-domain now**: get domain via `$forge->domains($org, $serverId, $siteId)` (site creation creates the primary domain), then `$forge->createCertificate($org, $serverId, $siteId, $domainId, ['type' => 'letsencrypt', ...])` |

New v2 `createSite` payload (verified against Forge API v2 OpenAPI docs):

```php
[
    'type' => 'laravel',                    // was project_type: 'php'
    'domain_mode' => 'custom',
    'name' => $domain,
    'php_version' => $this->option('php-version'),   // php82…php85 accepted
    'web_directory' => '/public',                    // was directory
    'allow_wildcard_subdomains' => (bool) $this->option('wildcard'), // was wildcards
    'is_isolated' => true, 'isolated_user' => $slug, // was isolation/username
    'nginx_template_id' => (int) $this->option('nginx-template'),   // was nginx_template
    // repo install folded in (replaces installGitRepository):
    'source_control_provider' => $this->option('provider'),
    'repository' => $this->getRepoName(),
    'branch' => $this->getBranchName(),
    'install_composer_dependencies' => true,         // was composer: true
    'push_to_deploy' => ! $this->option('no-quick-deploy'), // replaces enableQuickDeploy
]
```

`app/Commands/DestroyCommand.php`:

| Current (v3) | New (v4) |
|---|---|
| `$forge->certificates($server->id, $site->id)` + `$certificate->delete()` | `$forge->certificates($org, $serverId, $siteId)` (v4.1+) then `$forge->deleteCertificate($org, $serverId, $siteId, $domainId, $certId)` — needs the domain id (from `$forge->domains(...)` or the certificate resource) |
| `$forge->jobs(...)` + `$job->delete()` | `$forge->scheduledJobs($org, $serverId)->lazy()` + `$forge->deleteScheduledJob($org, $serverId, $job->id)` (or `siteScheduledJobs`) |
| `$forge->databases(...)` + `$database->delete()` | `$forge->databases($org, $serverId)->lazy()` + `$forge->deleteDatabase($org, $serverId, $database->id)` |
| `$forge->databaseUsers(...)` | `$forge->databaseUsers($org, $serverId)->lazy()` + `$forge->deleteDatabaseUser($org, $serverId, $user->id)` |
| `$site->delete()` | `$forge->deleteSite($org, $serverId, $site->id)` |

**Existing bug to fix while here** — `DestroyCommand.php:91`: the database-users loop deletes `$database` (stale variable from the previous loop) instead of `$databaseUser`.

### Behavioural details to preserve/verify

- `--wildcard` + Route 53: v2 site creation takes `allow_wildcard_subdomains`; the DNS-challenge credentials for the wildcard LE cert are **not yet verified** against the v2 certificate schema (`createCertificate` data). Must check https://forge.laravel.com/docs/api-reference during implementation; recent commit fe077f8 relies on cert covering root + `*.domain`.
- `--ci` output uses deprecated `::set-output` (DeployCommand.php:196) — switch to appending to `$GITHUB_OUTPUT`.
- Default `--php-version=php81` → bump default to a current version (`php84`).
- `Site`/`Server` typed resources: `$site->name` etc. still exist; `repository` is now a nested array on the resource.
- Timeout handling (`setTimeout`) and `TimeoutException` semantics unchanged in v4.

## Part 2 — Dependency upgrades (Laravel Zero 9 → 12)

Target `composer.json` versions (verified against current packages):

```json
"require": {
    "php": "^8.5",
    "laravel-zero/framework": "^12.1",
    "laravel/forge-sdk": "^4.1",
    "nunomaduro/termwind": "^2.4"
},
"require-dev": {
    "mockery/mockery": "^1.6.12",
    "pestphp/pest": "^4.7"
}
```

- **Pest ^4.7** (requires PHP ^8.4, which permits 8.5).
- `config.platform.php`: bump `"8.1"` → `"8.5"` (or remove it; the `^8.5` constraint already gates resolution — pinning keeps lock regeneration deterministic).

File-by-file (aligning with the current laravel-zero v12 skeleton):

1. `bootstrap/app.php` — replace manual singleton bindings with the fluent builder: `return Application::configure(basePath: dirname(__DIR__))->create();` (undocumented-but-real breaking change between LZ9 and LZ12).
2. `phpunit.xml.dist` — modernize to the current PHPUnit schema pulled in by Pest 4 (drop `backupGlobals`, `convert*ToExceptions`, etc.; `<coverage><include>` → `<source><include>`).
3. `tests/CreatesApplication.php` — **delete**; Illuminate's `TestCase::createApplication()` now loads `bootstrap/app.php` itself. Simplify `tests/TestCase.php` to `abstract class TestCase extends LaravelZero\Framework\Testing\TestCase {}`.
4. `config/app.php` — keep as-is; **must preserve the custom `'timeout' => 120` key** (read at `DeployCommand.php:59`).
5. `config/commands.php`, entry script `forge-previewer`, `stubs/console.stub` — no functional changes needed. `box.json` is already byte-identical to the v12 skeleton's — no changes.
6. Remove remaining scaffold cruft: `tests/Feature/InspireCommandTest.php` and `tests/Unit/ExampleTest.php` (`InspireCommand` itself is removed by the Part 0 upstream merge).

## Part 3 — GitHub Actions: build + release binaries

**Key finding**: static-php-cli's default Linux build is **musl fully-static** — zero libc dependency, so **one x86_64 binary runs on Ubuntu 20.04, 24.04, and everything else**. No 20.04 build leg needed (the retired `ubuntu-20.04` runner is moot); `ubuntu:20.04` is used only as a docker image to smoke-test the finished binary.

### `.github/workflows/tests.yml` (new — repo has no CI today)

Push/PR → PHP 8.5 (current stable; extend the matrix when 8.6 lands) → `shivammathur/setup-php@v2` → `ramsey/composer-install@v3` (caches on the committed `composer.lock`) → `vendor/bin/pest`.

### `.github/workflows/release.yml` (new)

On `v*` tag push, three jobs:

1. **build-phar** (ubuntu-24.04): setup PHP 8.5 with `phar.readonly=0` → `composer install --no-dev` → Box 4.x `compile` using the existing `box.json` → upload PHAR artifact.
2. **build-static-binary**: pin `crazywhalecc/static-php-cli` at tag **2.8.5** (never `main`; the v3 rebrand is unreleased) → `spc doctor --auto-fix` → `spc download --for-extensions=...` (cached) → `spc build ... --build-micro` → `spc micro:combine <phar> --output=forge-previewer-linux-x86_64`.
   - Embedded PHP: **8.5** — same version as the build toolchain and the source floor, so one PHP version runs everywhere (host PHP is never used by the binary). static-php-cli 2.8.5 supports PHP 8.5 (verified against its README).
   - Extension list (justified against actual dependency require blocks; **`zlib` is mandatory** because box.json uses GZ phar compression):
     `bcmath,ctype,curl,dom,fileinfo,filter,hash,libxml,mbstring,openssl,pcntl,phar,posix,session,simplexml,sockets,tokenizer,xml,xmlreader,xmlwriter,zlib`
   - One-time check during implementation: `spc dump-extensions` against the built vendor/ to confirm the list.
   - Smoke tests: run `--version` and `list` natively on the ubuntu-24.04 runner **and** inside `docker run ubuntu:20.04` (proves 20.04 compat), plus `deploy --help`.
3. **release**: `softprops/action-gh-release@v2` attaches: `forge-previewer-linux-x86_64` (canonical), `forge-previewer-ubuntu-20.04` + `forge-previewer-ubuntu-24.04` (byte-identical copies so the release page explicitly shows both supported targets), and `SHA256SUMS.txt`. The PHAR is a build-time intermediate only (consumed by `micro:combine`) — **not** published as a release asset; users who don't want the binary install via composer/manual build.

### Distribution changes

- Delete `builds/forge-previewer` from git; add `/builds` to `.gitignore`.
- `composer.json` `"bin"`: point at the source entry script (`"bin": ["forge-previewer"]`) so `composer global require` keeps working without a committed PHAR ("built normally" path for non-Ubuntu users).
- README: document the two install paths — download binary from GitHub Releases (Ubuntu 20.04/24.04, no PHP needed) vs composer/manual build (**requires PHP 8.5+**); update the example GitHub Actions snippets (currently `actions/checkout@v1`, `::set-output`) and document the new required `--org`/`FORGE_ORG`.

## Risks / open items

- **Wildcard + Route 53 cert flow under API v2** is the main unverified area — must be confirmed against https://forge.laravel.com/docs/api-reference during implementation (recent work, commit fe077f8, depends on it).
- `createDatabase`/`createScheduledJob` v2 payload key names need verification against the v2 OpenAPI schema (e.g. whether db `user`/`password` creation is still a single call).
- Laravel Zero Box PHAR + `spc micro:combine` is a proven pipeline generally, but not first-party-documented for Laravel Zero specifically — the workflow's smoke tests gate this; fallback is PHPacker (now Laravel Zero's officially documented binary tool) if micro.sfx proves fragile.
## Implementation order

1. Part 0 (merge the two upstream commits, resolve conflicts, commit `composer.lock` going forward).
2. Part 2 (deps + LZ12 scaffolding) — get `./forge-previewer list` and Pest green on PHP 8.5, regenerate `composer.lock`.
3. Part 1 (SDK v4 / API v2 migration of both commands + `--org`), verifying the open payload questions against the v2 API reference as they come up.
4. Part 3 (workflows + distribution changes + README).
5. Tag `v1.0.0-beta.1` to exercise the release pipeline end-to-end, then `v1.0.0`.

## Verification

- `composer install` on PHP 8.5+; `./forge-previewer list` boots.
- Pest suite passes.
- Manual end-to-end against a real Forge org/server: `deploy` a test branch (site + db + env + cert + scheduler), then `destroy` it — confirm all resources cleaned up.
- Tag a pre-release (e.g. `v1.0.0-beta.1`) → workflow produces release assets; download each binary onto ubuntu:20.04 and ubuntu:24.04 containers and run `deploy --help`.
