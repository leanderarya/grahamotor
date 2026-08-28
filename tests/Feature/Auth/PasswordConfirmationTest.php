<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    public function test_password_confirmation_is_disabled(): void
    {
        $this->assertFalse(
            in_array('Laravel\\Fortify\\Features::confirmPasswords()', config('fortify.features', []), true)
        );
    }
}
