<?php

use App\Commands\DeployCommand;
use Laravel\Forge\Forge;
use Laravel\Forge\Exceptions\NotFoundException;
use Laravel\Forge\Exceptions\ValidationException;
use Laravel\Forge\Resources\Deployment;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\Site;
use Mockery\MockInterface;

function reviewableDeployCommand(?int $primaryDomainId = 1): DeployCommand
{
    return new class($primaryDomainId) extends DeployCommand
    {
        public function __construct(private readonly ?int $primaryDomainId)
        {
            parent::__construct();
        }

        public function safelyCollect(Closure $fetch): array
        {
            return iterator_to_array($this->safelyIterate($fetch), false);
        }

        public function reportsExistingCertificate(array $errors): bool
        {
            return $this->certificateAlreadyExists(new ValidationException($errors));
        }

        public function requestCertificate(Server $server, Site $site, string $domain): void
        {
            $this->obtainCertificate($server, $site, $domain);
        }

        public function awaitDeployment(Forge $forge, Server $server, Site $site, Deployment $deployment): void
        {
            $this->forge = $forge;
            $this->org = 'test-org';

            $this->waitForDeployment($server, $site, $deployment, 120);
        }

        protected function findPrimaryDomainId(Server $server, Site $site, string $domain): ?int
        {
            return $this->primaryDomainId;
        }
    };
}

it('treats a 404 from a later pagination request as the end of an optional collection', function () {
    $paginator = new class
    {
        public function lazy(): Generator
        {
            yield 'first-page-resource';

            throw new NotFoundException;
        }
    };

    expect(reviewableDeployCommand()->safelyCollect(fn () => $paginator))
        ->toBe(['first-page-resource']);
});

it('only classifies explicit certificate conflicts as already existing', function () {
    $command = reviewableDeployCommand();

    expect($command->reportsExistingCertificate([
        'errors' => ['certificate' => ['A certificate already exists for this domain.']],
    ]))->toBeTrue()
        ->and($command->reportsExistingCertificate([
            'errors' => ['domain' => ['That domain has already been added to another site.']],
        ]))->toBeFalse()
        ->and($command->reportsExistingCertificate([
            'errors' => ['dns' => ['The DNS provider is already configured incorrectly.']],
        ]))->toBeFalse();
});

it('fails certificate setup when the primary domain cannot be resolved', function () {
    $server = new Server(['id' => 10]);
    $site = new Site(['id' => 20]);

    expect(fn () => reviewableDeployCommand(null)->requestCertificate($server, $site, 'preview.example.com'))
        ->toThrow(App\Exceptions\ProvisioningFailedException::class, 'Could not find the primary domain record for preview.example.com');
});

it('waits for the exact deployment created by the command', function () {
    $server = new Server(['id' => 10]);
    $site = new Site(['id' => 20]);
    $deployment = new Deployment(['id' => 30, 'status' => 'pending']);
    $forge = Mockery::mock(Forge::class, function (MockInterface $mock) {
        $mock->shouldReceive('deployment')
            ->once()
            ->with('test-org', 10, 20, 30)
            ->andReturn(new Deployment(['id' => 30, 'status' => 'finished']));
    });

    reviewableDeployCommand()->awaitDeployment($forge, $server, $site, $deployment);

    expect(true)->toBeTrue();
});

it('fails when the exact deployment does not finish successfully', function (string $status) {
    $server = new Server(['id' => 10]);
    $site = new Site(['id' => 20]);
    $deployment = new Deployment(['id' => 30, 'status' => 'pending']);
    $forge = Mockery::mock(Forge::class, function (MockInterface $mock) use ($status) {
        $mock->shouldReceive('deployment')
            ->once()
            ->andReturn(new Deployment(['id' => 30, 'status' => $status]));
    });

    expect(fn () => reviewableDeployCommand()->awaitDeployment($forge, $server, $site, $deployment))
        ->toThrow(
            App\Exceptions\ProvisioningFailedException::class,
            "Deployment 30 did not succeed (status: {$status}).",
        );
})->with(['cancelled', 'failed', 'failed-build']);
