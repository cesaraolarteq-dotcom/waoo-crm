<?php
use Laravel\Fortify\Features;
return [
    'guard' => 'web', 'passwords' => 'users', 'username' => 'email', 'email' => 'email',
    'lowercase_usernames' => true, 'home' => '/admin', 'prefix' => '', 'domain' => null,
    'middleware' => ['web'], 'views' => true,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    // No public signup or outbound mail in Phase 1. Admins are provisioned through a secure CLI.
    'features' => [Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])],
];
