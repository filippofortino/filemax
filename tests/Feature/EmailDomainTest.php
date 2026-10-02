<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\Team;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('email domain configuration normalizes a comma separated list and defaults to open', function (?string $value, array $expected): void {
    $key = 'FILEMAX_ALLOWED_EMAIL_DOMAINS';
    $environment = RepositoryBuilder::createWithDefaultAdapters()->addAdapter(PutenvAdapter::class)->make();
    $original = $environment->get($key);

    try {
        $environment->clear($key);
        if ($value !== null) {
            $environment->set($key, $value);
        }

        $configuration = require config_path('filemax.php');

        expect($configuration['allowed_email_domains'])->toBe($expected);
        config(['filemax.allowed_email_domains' => $configuration['allowed_email_domains']]);
        expect(AllowedEmailDomain::allows('person@example.com'))->toBe($expected === [] || in_array('example.com', $expected, true));
    } finally {
        $environment->clear($key);
        if ($original !== null) {
            $environment->set($key, $original);
        }
    }
})->with([
    'unset' => [null, []],
    'blank' => ['', []],
    'whitespace' => ['   ', []],
    'single' => ['EXAMPLE.COM', ['example.com']],
    'multiple' => [' Example.COM, , Another.COM, ', ['example.com', 'another.com']],
]);

test('validation and account eligibility share exact normalized domain matching', function (array $domains, string $email, bool $allowed): void {
    config(['filemax.allowed_email_domains' => $domains]);
    $user = User::factory()->make(['email' => $email]);

    expect($user->isEligible())->toBe($allowed)
        ->and(Validator::make(['email' => $email], ['email' => [new AllowedEmailDomain]])->passes())->toBe($allowed);
})->with([
    'open' => [[], 'person@anywhere.com', true],
    'single allowed' => [['example.com'], 'person@example.com', true],
    'multiple allowed' => [['another.com', 'example.com'], 'person@example.com', true],
    'normalized email' => [['example.com'], ' PERSON@EXAMPLE.COM ', true],
    'unlisted' => [['example.com'], 'person@another.com', false],
    'subdomain' => [['example.com'], 'person@sub.example.com', false],
    'explicit subdomain' => [['sub.example.com'], 'person@sub.example.com', true],
    'lookalike suffix' => [['example.com'], 'person@example.com.evil.com', false],
    'lookalike prefix' => [['example.com'], 'person@notexample.com', false],
]);

test('registration login recovery and sender access accept configured or unrestricted domains', function (array $domains): void {
    config(['filemax.allowed_email_domains' => $domains]);
    $this->withoutVite();
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Alice', 'email' => ' ALICE@EXAMPLE.COM ',
        'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
        'is_admin' => true,
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->sole();
    expect($user->email)->toBe('alice@example.com')->and($user->is_admin)->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get(route('home'))->assertRedirect(route('verification.notice'));
    $user->markEmailAsVerified();
    $this->actingAs($user)->get(route('home'))->assertOk();
    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => ' ALICE@EXAMPLE.COM ', 'password' => 'a-secure-password'])
        ->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
    $this->post(route('logout'));

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    $notification = Notification::sent($user, ResetPassword::class)->sole();
    $this->post(route('password.update'), [
        'email' => $user->email, 'token' => $notification->token,
        'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ])->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'changed-password'])
        ->assertRedirect(route('home'));
})->with([
    'open' => [[]],
    'single' => [['example.com']],
    'multiple' => [['mediamaxcommunication.it', 'example.com']],
]);

test('registration action independently enforces allowed domains', function (): void {
    config(['filemax.allowed_email_domains' => ['example.com']]);

    expect(fn (): User => (new CreateNewUser)->create([
        'name' => 'Alice', 'email' => 'alice@another.com',
        'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
    ]))->toThrow(ValidationException::class);
    expect(User::query()->count())->toBe(0);
});

test('open registration still rejects malformed email on every password endpoint', function (string $route, mixed $email): void {
    config(['filemax.allowed_email_domains' => []]);

    $this->post(route($route), [
        'name' => 'Alice', 'email' => $email, 'token' => 'token',
        'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
    ])->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with(['register.store', 'login.store', 'password.email', 'password.update'])
    ->with(['not-an-email', 'person@@example.com', ['email' => ['person@example.com']]]);

test('team eligibility and admin bootstrap follow configured or unrestricted domains', function (array $domains): void {
    config(['filemax.allowed_email_domains' => $domains]);
    $this->withoutVite();
    $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = Team::factory()->create();

    $this->actingAs($admin)->get(route('teams.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->has('users', 2));
    $this->post(route('teams.members.store', $team), ['user_id' => $member->id])->assertSessionHasNoErrors()->assertRedirect();
    expect($member->teams()->whereKey($team->id)->exists())->toBeTrue();
    $this->flushSession();
    $this->actingAs($member)->get(route('teams.index'))->assertForbidden();
    $this->artisan('filemax:admin', ['email' => $member->email])->assertSuccessful();
    expect($member->refresh()->is_admin)->toBeTrue();

    config(['filemax.allowed_email_domains' => ['another.com']]);
    $this->get(route('home'))->assertForbidden();
    $this->get(route('teams.index'))->assertForbidden();
    $this->artisan('filemax:admin', ['email' => $member->email])->assertFailed();
})->with(['open' => [[]], 'restricted' => [['mediamaxcommunication.it', 'example.com']]]);
