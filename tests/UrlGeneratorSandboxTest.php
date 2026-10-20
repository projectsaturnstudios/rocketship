<?php

namespace Laravel\Octane\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

class UrlGeneratorSandboxTest extends TestCase
{
    public function test_url_is_reset_between_requests()
    {
        [$app, $worker, $client] = $this->createOctaneContext([
            Request::create('/first', 'GET'),
            Request::create('/second', 'GET'),
        ]);

        $app['url']->defaults(['param' => 'original']);
        $app['url']->forceRootUrl('http://original');

        $app['router']->get('/first', function (Application $app) {
            $app['url']->defaults(['param' => 'changed']);
            $app['url']->forceRootUrl('http://changed');

            return [
                'default' => $app['url']->getDefaultParameters(),
                'url' => $app['url']->to('/'),
            ];
        });

        $app['router']->get('/second', function (Application $app) {
            return [
                'default' => $app['url']->getDefaultParameters(),
                'url' => $app['url']->to('/'),
            ];
        });

        $worker->run();

        $this->assertEquals([
            'default' => ['param' => 'changed'],
            'url' => 'http://changed',
        ], $client->responses[0]->getData(true));

        $this->assertEquals([
            'default' => ['param' => 'original'],
            'url' => 'http://original',
        ], $client->responses[1]->getData(true));
    }
}
