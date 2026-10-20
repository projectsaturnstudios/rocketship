<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Servers\ServerRegistry;
use ProjectSaturnStudios\Rocketship\Tests\Fixtures\FakeServer;
use ProjectSaturnStudios\Rocketship\Tests\Fixtures\OtherFakeServer;

it("returns a registered server's driver", function () {
    $registry = new ServerRegistry;
    $registry->register('fake', FakeServer::class);

    expect($registry->has('fake'))->toBeTrue()
        ->and($registry->driver('fake'))->toBeInstanceOf(FakeServer::class);
});

it('lists names in registration order', function () {
    $registry = new ServerRegistry;
    $registry->register('b', FakeServer::class);
    $registry->register('a', FakeServer::class);

    expect($registry->names())->toBe(['b', 'a']);
});

it('lets a later registration replace a server', function () {
    $registry = new ServerRegistry;
    $registry->register('fake', FakeServer::class);
    $registry->register('fake', OtherFakeServer::class);

    expect($registry->driver('fake'))->toBeInstanceOf(OtherFakeServer::class)
        ->and($registry->names())->toBe(['fake']);
});

it('refuses a class that is not a server driver', function () {
    (new ServerRegistry)->register('bad', stdClass::class);
})->throws(InvalidArgumentException::class, 'Server driver [stdClass] must implement ProjectSaturnStudios\Rocketship\Servers\ServerDriver.');

it('matches names exactly', function () {
    $registry = new ServerRegistry;
    $registry->register('swoole', FakeServer::class);

    expect($registry->has('Swoole'))->toBeFalse();
});

it('refuses an unknown server', function () {
    (new ServerRegistry)->driver('nope');
})->throws(InvalidArgumentException::class, 'Server [nope] is not registered.');

it('registers deferred servers after the ones already there, once', function () {
    $registry = new ServerRegistry;
    $registry->register('swoole', FakeServer::class);
    ServerRegistry::defer('swoole', OtherFakeServer::class);
    ServerRegistry::defer('extra', FakeServer::class);

    $registry->registerDeferred();
    $again = new ServerRegistry;
    $again->registerDeferred();

    expect($registry->driver('swoole'))->toBeInstanceOf(OtherFakeServer::class)
        ->and($registry->names())->toBe(['swoole', 'extra'])
        ->and($again->names())->toBe([]);
});

it('refuses to defer a class that is not a server driver', function () {
    ServerRegistry::defer('bad', stdClass::class);
})->throws(InvalidArgumentException::class, 'Server driver [stdClass] must implement ProjectSaturnStudios\Rocketship\Servers\ServerDriver.');
