<?php

declare(strict_types=1);

uses(ProjectSaturnStudios\Rocketship\Tests\TestCase::class)->in('Rocketship');

// Every test boots a Laravel app, which is all reference cycles, and PHPUnit keeps each test case for the run:
// collect after each test so the suite fits 128 MB.
uses()->afterEach(fn () => gc_collect_cycles())->in('Rocketship');

dataset('modes', [
    'threads' => ['threads'],
    'processes' => ['processes'],
]);
