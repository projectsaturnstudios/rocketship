<?php

declare(strict_types=1);

use Laravel\Octane\Commands\StartFrankenPhpCommand;

beforeEach(function () {
    $this->public = sys_get_temp_dir().'/rocketship-public-'.bin2hex(random_bytes(4));
    mkdir($this->public);
    $this->app->usePublicPath($this->public);
    $this->command = new StartFrankenPhpCommand;
});

afterEach(function () {
    @unlink($this->public.'/frankenphp-worker.php');
    @rmdir($this->public);
});

it("repoints a worker Octane published at Rocketship's worker", function () {
    $worker = $this->public.'/frankenphp-worker.php';
    file_put_contents($worker, "<?php\nini_set('memory_limit', '512M');\nrequire __DIR__.'/../vendor/laravel/octane/bin/frankenphp-worker.php';\n");

    $this->command->ensureFrankenPhpWorkerIsInstalled();

    expect(file_get_contents($worker))->toBe("<?php\nini_set('memory_limit', '512M');\nrequire __DIR__.'/../vendor/projectsaturnstudios/rocketship/bin/frankenphp-worker.php';\n");
});

it('leaves a worker that does not require Octane alone', function () {
    $worker = $this->public.'/frankenphp-worker.php';
    file_put_contents($worker, "<?php\nrequire __DIR__.'/../custom-worker.php';\n");

    $this->command->ensureFrankenPhpWorkerIsInstalled();

    expect(file_get_contents($worker))->toBe("<?php\nrequire __DIR__.'/../custom-worker.php';\n");
});

it("installs a worker requiring Rocketship's worker when none exists", function () {
    $this->command->ensureFrankenPhpWorkerIsInstalled();

    expect(file_get_contents($this->public.'/frankenphp-worker.php'))
        ->toContain("require __DIR__.'/../vendor/projectsaturnstudios/rocketship/bin/frankenphp-worker.php';")
        ->not->toContain('vendor/laravel/octane');
});
