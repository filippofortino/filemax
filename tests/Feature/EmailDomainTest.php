<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

function signupPayload(string $email): array
{
    return ['name' => 'Alice', 'email' => $email, 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'];
}

beforeEach(function (): void {
    config(['filemax.signup_open' => false]);
    Notification::fake();
});

test('the sign-up flag is closed unless the environment opens it', function (): void {
    $configuration = fn (?string $value): array => (function () use ($value): array {
        $value === null ? putenv('FILEMAX_SIGNUP_OPEN') : putenv('FILEMAX_SIGNUP_OPEN='.$value);
        unset($_ENV['FILEMAX_SIGNUP_OPEN'], $_SERVER['FILEMAX_SIGNUP_OPEN']);

        return require config_path('filemax.php');
    })();

    try {
        expect($configuration(null)['signup_open'])->toBeFalse()
            ->and($configuration('true')['signup_open'])->toBeTrue()
            ->and($configuration('false')['signup_open'])->toBeFalse();
    } finally {
        putenv('FILEMAX_SIGNUP_OPEN=true');
    }
});

test('the register page says whether sign-up is open', function (bool $open): void {
    config(['filemax.signup_open' => $open]);

    $this->get(route('register'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/register', false)->where('signupOpen', $open));
})->with([true, false]);

test('the work email check only passes for a domain an organization allows', function (): void {
    Organization::factory()->create()->domains()->create(['domain' => 'example.com']);

    $this->post(route('register.check'), ['email' => 'luca@gmail.com'])
        ->assertSessionHasErrors(['email' => 'No organization on Filemax uses gmail.com. Ask an admin of yours to invite you.']);
    $this->post(route('register.check'), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    $this->post(route('register.check'), ['email' => ' Luca@EXAMPLE.com '])->assertSessionHasNoErrors();
});

test('a person on an allowed domain registers and joins that organization once verified', function (): void {
    $organization = Organization::factory()->create();
    $organization->domains()->create(['domain' => 'example.com']);
    $other = Organization::factory()->create();
    $other->domains()->create(['domain' => 'another.com']);

    $this->post(route('register.store'), signupPayload('alice@example.com'))->assertRedirect(route('verification.notice'));

    $user = User::query()->sole();
    expect($user->organizations()->count())->toBe(1)
        ->and($organization->hasMember($user))->toBeFalse();

    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]))->assertRedirect();

    expect($organization->hasMember($user))->toBeTrue()
        ->and($organization->hasAdmin($user))->toBeFalse()
        ->and($other->hasMember($user))->toBeFalse()
        ->and($user->refresh()->currentOrganization()->personal)->toBeTrue();
});

test('open sign-up accepts any email and lands people in Personal', function (): void {
    config(['filemax.signup_open' => true]);
    Organization::factory()->create()->domains()->create(['domain' => 'example.com']);

    $this->post(route('register.store'), signupPayload('luca@gmail.com'))->assertRedirect(route('verification.notice'));

    $organization = User::query()->sole()->organizations()->sole();
    expect($organization->personal)->toBeTrue()->and($organization->publicName())->toBeNull();
});

test('accounts created before sign-up closed keep working whatever their domain', function (): void {
    $user = User::factory()->create(['email' => 'outside@example.org']);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
    $this->get(route('transfers.index'))->assertOk();
});
