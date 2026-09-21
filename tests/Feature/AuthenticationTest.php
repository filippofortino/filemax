<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureEligibleEmail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    Route::middleware(['web', 'auth', EnsureEligibleEmail::class, 'verified'])
        ->get('/staff-auth-test', fn (): string => 'staff');
});

test('staff can register with normalized email but cannot assign privileges', function (): void {
    Notification::fake();
    $team = Team::factory()->create();

    $this->post(route('register.store'), [
        'name' => 'Alice',
        'email' => ' Alice@MediaMaxCommunication.IT ',
        'password' => 'a-secure-password',
        'password_confirmation' => 'a-secure-password',
        'is_admin' => true,
        'team_ids' => [$team->id],
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->sole();
    expect($user->email)->toBe('alice@mediamaxcommunication.it')
        ->and($user->is_admin)->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->teams()->count())->toBe(0);
    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get('/staff-auth-test')->assertRedirect(route('verification.notice'));
});

test('registration requires the exact company domain', function (string $email): void {
    $this->post(route('register.store'), [
        'name' => 'Alice', 'email' => $email,
        'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
    ])->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(0);
})->with(['person@example.com', 'person@mediamaxcommunication.it.evil.com', 'person@sub.mediamaxcommunication.it', 'person@mediamaxcommunicationit', 'person@@mediamaxcommunication.it']);

test('normalized duplicate email is rejected', function (): void {
    User::factory()->create(['email' => 'alice@mediamaxcommunication.it']);

    $this->post(route('register.store'), [
        'name' => 'Alice', 'email' => 'ALICE@MEDIAMAXCOMMUNICATION.IT',
        'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
    ])->assertSessionHasErrors('email');
});

test('login regenerates the session and logout invalidates it', function (): void {
    $user = User::factory()->create(['email' => 'alice@mediamaxcommunication.it']);
    $this->withSession(['marker' => 'old-session']);
    $sessionId = session()->getId();

    $this->post(route('login.store'), ['email' => 'ALICE@MEDIAMAXCOMMUNICATION.IT', 'password' => 'password'])
        ->assertRedirect(route('home'));

    expect(session()->getId())->not->toBe($sessionId);
    $this->assertAuthenticatedAs($user);
    $this->get('/staff-auth-test')->assertOk();
    $this->post(route('logout'))->assertRedirect(route('login'))->assertSessionMissing('marker');
    $this->assertGuest();
});

test('wrong credentials do not sign in and login attempts are throttled', function (): void {
    $user = User::factory()->create();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrect'])
            ->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    $this->assertGuest();
});

test('sender access requires authentication and current domain eligibility', function (): void {
    $this->get('/staff-auth-test')->assertRedirect(route('login'));
    $user = User::factory()->create(['email' => 'former@example.com']);
    $this->actingAs($user)->get('/staff-auth-test')->assertForbidden();
});

test('signed verification verifies ownership and resumes an intended transfer', function (): void {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->actingAs($user)->withSession(['url.intended' => '/t/safe-token'])->get($url)->assertRedirect('/t/safe-token');
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('invalid verification signature and mismatched mailbox are rejected', function (): void {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('other@mediamaxcommunication.it')]);
    $this->get($url)->assertForbidden();
    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('verification notifications can be resent with throttling', function (): void {
    $user = User::factory()->unverified()->create();
    Notification::fake();

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
    }

    Notification::assertSentToTimes($user, VerifyEmail::class, 6);
    $this->post(route('verification.send'))->assertTooManyRequests();
});

test('password recovery sends a reset token and consumes it once', function (): void {
    $user = User::factory()->create();
    Notification::fake();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token, 'email' => $user->email,
            'password' => 'changed-password', 'password_confirmation' => 'changed-password',
        ])->assertRedirect(route('login'));

        expect(Hash::check('changed-password', $user->refresh()->password))->toBeTrue();

        $this->post(route('password.update'), [
            'token' => $notification->token, 'email' => $user->email,
            'password' => 'second-password', 'password_confirmation' => 'second-password',
        ])->assertSessionHasErrors('email');

        return true;
    });
});

test('invalid and expired password reset tokens are rejected', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $this->travel(61)->minutes();

    $this->post(route('password.update'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ])->assertSessionHasErrors('email');
    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

test('switching accounts safely preserves the recipient path across logout and login', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user)->withSession(['private' => 'old'])->post(route('account.switch'), ['return_to' => '/t/safe_token-12'])
        ->assertRedirect(route('login'))->assertSessionMissing('private')->assertSessionHas('url.intended', '/t/safe_token-12');
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => $other->email, 'password' => 'password'])->assertRedirect('/t/safe_token-12');
    $this->assertAuthenticatedAs($other);
});

test('switching accounts rejects untrusted return locations', function (string $returnTo): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('account.switch'), ['return_to' => $returnTo])->assertSessionHasErrors('return_to');
    $this->assertAuthenticatedAs($user);
})->with(['https://evil.test/t/token', '//evil.test/t/token', '/t/token?next=https://evil.test', '/t/../login', "/t/token\nextra"]);

test('registration keeps the intended transfer until email verification is complete', function (): void {
    Notification::fake();

    $this->withSession(['url.intended' => '/t/original-transfer'])->post(route('register.store'), [
        'name' => 'Alice', 'email' => 'alice@mediamaxcommunication.it',
        'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
    ])->assertRedirect(route('verification.notice'))->assertSessionHas('url.intended', '/t/original-transfer');

    $user = User::query()->sole();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($url)->assertRedirect('/t/original-transfer');
});

test('password recovery gives the same response for existing missing and throttled accounts', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $status = 'If that account exists, a password reset link has been sent.';

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status', $status)->assertSessionHasNoErrors();
    $this->post(route('password.email'), ['email' => 'missing@mediamaxcommunication.it'])->assertSessionHas('status', $status)->assertSessionHasNoErrors();
    $this->postJson(route('password.email'), ['email' => $user->email])->assertOk()->assertExactJson(['message' => $status]);
    $this->postJson(route('password.email'), ['email' => 'missing@mediamaxcommunication.it'])->assertOk()->assertExactJson(['message' => $status]);

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('password endpoints reject malformed and ineligible email addresses', function (string $route, mixed $email): void {
    $this->post(route($route), ['email' => $email, 'password' => 'password', 'token' => 'token'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
})->with(['login.store', 'password.email', 'password.update'])->with([
    'outside@example.com', 'person@sub.mediamaxcommunication.it', [['alice@mediamaxcommunication.it']],
]);

test('registration and recovery endpoints retain request throttling', function (string $route): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route($route), [])->assertSessionHasErrors('email');
    }

    $this->post(route($route), [])->assertTooManyRequests();
})->with(['register.store', 'password.email', 'password.update']);

test('password reset validates confirmation and rotates the remember token', function (): void {
    $user = User::factory()->create();
    $rememberToken = $user->remember_token;
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'email' => $user->email, 'token' => $token,
        'password' => 'changed-password', 'password_confirmation' => 'different-password',
    ])->assertSessionHasErrors('password');
    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();

    $this->post(route('password.update'), [
        'email' => ' '.mb_strtoupper($user->email).' ', 'token' => $token,
        'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ])->assertRedirect(route('login'));
    expect($user->refresh()->remember_token)->not->toBe($rememberToken);
    $this->assertGuest();
});

test('malformed reset tokens are rejected before reaching the password broker', function (): void {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'email' => $user->email, 'token' => ['not-a-token'],
        'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ])->assertSessionHasErrors('token');
});

test('password confirmation rejects malformed input before reaching Fortify', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => ['password']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password')
        ->assertSessionMissing('auth.password_confirmed_at');
});
