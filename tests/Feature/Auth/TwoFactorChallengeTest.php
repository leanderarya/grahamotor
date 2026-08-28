<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    public function test_two_factor_challenge_route_is_disabled(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('two-factor.login'));
    }
}
