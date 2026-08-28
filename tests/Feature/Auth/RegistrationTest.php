<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_registration_routes_are_disabled(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('register'));
        $this->assertNull(app('router')->getRoutes()->getByName('register.store'));
    }
}
