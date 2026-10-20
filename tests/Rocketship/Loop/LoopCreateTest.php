<?php

use ProjectSaturnStudios\Rocketship\Loop\Loop;

it('picks the best driver for this platform', function () {
    $expected = match (true) {
        function_exists('epoll_create1') => 'epoll',
        function_exists('kqueue') => 'kqueue',
        default => 'select',
    };

    expect(Loop::create()->driver())->toBe($expected);
});

it('honours a forced driver', function () {
    expect(Loop::create('select')->driver())->toBe('select');
});

it('rejects an unknown driver name', function () {
    Loop::create('libuv');
})->throws(InvalidArgumentException::class, 'Unknown loop driver [libuv]');
