<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $defaultCallback = PasswordRule::$defaultCallback;
    $this->beforeApplicationDestroyed(function () use ($defaultCallback): void {
        PasswordRule::$defaultCallback = $defaultCallback;
    });
    $this->withoutMiddleware(PreventRequestForgery::class)->withoutVite();
});

test('registration and reset apply the password policy for the environment', function (string $environment, string $password, bool $valid, string $route): void {
    $this->app->instance('env', $environment);
    $user = $route === 'password.update' ? User::factory()->create() : null;
    Notification::fake();

    $response = $this->post(route($route), [
        'name' => 'Alice',
        'email' => $user?->email ?? 'alice@mediamaxcommunication.it',
        'password' => $password,
        'password_confirmation' => $password,
        ...($user ? ['token' => Password::createToken($user)] : []),
    ]);

    if (! $valid) {
        $response->assertSessionHasErrors('password');

        if ($user) {
            expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
        } else {
            expect(User::query()->count())->toBe(0);
        }

        return;
    }

    $response->assertSessionHasNoErrors()->assertRedirect(route($user ? 'login' : 'verification.notice'));
    expect(Hash::check($password, ($user?->refresh() ?? User::query()->sole())->password))->toBeTrue();
})->with([
    'production minimum length' => ['production', 'Abc123!xyz', false],
    'production uppercase' => ['production', 'longpassword1!', false],
    'production lowercase' => ['production', 'LONGPASSWORD1!', false],
    'production number' => ['production', 'Longpassword!!', false],
    'production symbol' => ['production', 'LongPassword12', false],
    'production valid' => ['production', 'LongPassword12!', true],
    'local defaults' => ['local', 'password', true],
    'local minimum length' => ['local', 'short', false],
    'testing defaults' => ['testing', 'password', true],
])->with(['register.store', 'password.update']);

test('registration and reset require matching password confirmation in every environment', function (string $environment, string $route): void {
    $this->app->instance('env', $environment);
    $user = $route === 'password.update' ? User::factory()->create() : null;
    Notification::fake();

    $this->post(route($route), [
        'name' => 'Alice',
        'email' => $user?->email ?? 'alice@mediamaxcommunication.it',
        'password' => 'LongPassword12!',
        'password_confirmation' => 'DifferentPassword12!',
        ...($user ? ['token' => Password::createToken($user)] : []),
    ])->assertSessionHasErrors('password');

    if ($user) {
        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    } else {
        expect(User::query()->count())->toBe(0);
    }
})->with(['production', 'local'])->with(['register.store', 'password.update']);

test('password forms receive the active password requirements', function (string $environment, int $minimum, bool $complex): void {
    $this->app->instance('env', $environment);

    $this->get(route('register'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('passwordRequirements', [
            'min' => $minimum,
            'mixedCase' => $complex,
            'numbers' => $complex,
            'symbols' => $complex,
        ]));
})->with([
    ['production', 12, true],
    ['local', 8, false],
    ['testing', 8, false],
]);
