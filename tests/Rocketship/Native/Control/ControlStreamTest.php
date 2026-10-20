<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Loop\Drivers\SelectDriver;
use ProjectSaturnStudios\Rocketship\Loop\Loop;
use ProjectSaturnStudios\Rocketship\Native\Control\ControlStream;

it('delivers every frame whole and in order when the peer reads late', function () {
    [$ours, $theirs] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new Loop(new SelectDriver);
    $control = new ControlStream($ours);
    $control->attach($loop);
    $peer = new ControlStream($theirs);

    // Far more than a unix socket buffer holds (8 KB on macOS): the peer reads nothing until all are sent.
    for ($i = 0; $i < 5000; $i++) {
        $control->send("busy {$i} 0 127.0.0.1:5{$i}");
    }

    $received = [];
    $deadline = microtime(true) + 10;

    while (count($received) < 5000 && microtime(true) < $deadline) {
        $loop->tick(0.01);
        array_push($received, ...($peer->receive() ?? []));
    }

    expect($received)->toBe(array_map(fn (int $i) => "busy {$i} 0 127.0.0.1:5{$i}", range(0, 4999)));
});
