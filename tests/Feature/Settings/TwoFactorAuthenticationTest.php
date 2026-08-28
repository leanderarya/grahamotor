<?php

namespace Tests\Feature\Settings;

use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    public function test_two_factor_settings_route_is_disabled(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('two-factor.show'));
    }
}
