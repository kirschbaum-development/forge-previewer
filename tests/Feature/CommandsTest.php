<?php

use Illuminate\Contracts\Console\Kernel;

it('boots and registers the deploy and destroy commands', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)->toHaveKeys(['deploy', 'destroy']);
});

it('exposes the org option on both commands', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands['deploy']->getDefinition()->hasOption('org'))->toBeTrue()
        ->and($commands['destroy']->getDefinition()->hasOption('org'))->toBeTrue();
});

it('exposes the core options on the deploy command', function () {
    $definition = $this->app->make(Kernel::class)->all()['deploy']->getDefinition();

    expect($definition->hasOption('server'))->toBeTrue()
        ->and($definition->hasOption('token'))->toBeTrue()
        ->and($definition->hasOption('timeout'))->toBeTrue();
});
