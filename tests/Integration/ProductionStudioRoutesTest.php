<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

final class ProductionStudioRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.env', 'production');
        $app['config']->set('raceproof.studio.enabled', true);
    }

    public function test_studio_routes_are_never_registered_in_production(): void
    {
        $this->get('/raceproof')->assertNotFound();
        $this->get('/raceproof/runs/'.str_repeat('a', 32))->assertNotFound();
    }
}
