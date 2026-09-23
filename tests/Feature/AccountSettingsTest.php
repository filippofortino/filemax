<?php

declare(strict_types=1);

use App\Models\Transfer;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    config(['filesystems.default' => 'avatars', 'filemax.disk' => 'transfers']);
    Storage::fake('avatars');
    Storage::fake('transfers');
});

test('settings opens without reconfirmation and the old passkeys page is removed', function (): void {
    $this->actingAs(User::factory()->create())->get(route('account.settings'))
        ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('account/settings')->where('auth.user.avatar_url', null));

    $this->get('/account/passkeys')->assertNotFound();
});

test('guests cannot update their profile or password', function (string $route): void {
    $this->put(route($route))->assertRedirect(route('login'));
})->with(['user-profile-information.update', 'user-password.update']);

test('profile and password updates require an eligible verified account', function (string $route, bool $verified): void {
    $user = User::factory()->create([
        'email' => $verified ? 'person@example.com' : 'person@mediamaxcommunication.it',
        'email_verified_at' => $verified ? now() : null,
    ]);

    $this->actingAs($user)->putJson(route($route))->assertForbidden();
})->with(['user-profile-information.update', 'user-password.update'])->with([true, false]);

test('profile updates only the current users name and preserves immutable attributes', function (): void {
    $user = User::factory()->create(['avatar_path' => 'avatars/original.webp']);
    $other = User::factory()->create();
    Storage::put($user->avatar_path, 'original');

    $this->actingAs($user)->putJson(route('user-profile-information.update'), [
        'name' => 'Alice Updated', 'email' => 'attacker@example.com', 'is_admin' => true,
        'id' => $other->id, 'avatar_path' => 'arbitrary/path',
    ])->assertSuccessful();

    expect($user->refresh()->name)->toBe('Alice Updated')
        ->and($user->email)->not->toBe('attacker@example.com')
        ->and($user->is_admin)->toBeFalse()
        ->and($user->avatar_path)->toBe('avatars/original.webp')
        ->and($other->refresh()->name)->not->toBe('Alice Updated');
    Storage::assertExists('avatars/original.webp');
});

test('profile requires a valid name', function (mixed $name): void {
    $this->actingAs(User::factory()->create())->putJson(route('user-profile-information.update'), ['name' => $name])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
})->with(['', null, str_repeat('a', 256), [['invalid']]]);

test('avatars are processed replaced and removed only on the default disk', function (): void {
    $user = User::factory()->create(['avatar_path' => 'avatars/original.webp']);
    Storage::put($user->avatar_path, 'original');
    Storage::disk('transfers')->put('untouched.txt', 'transfer');

    $this->actingAs($user)->post(route('user-profile-information.update'), [
        '_method' => 'PUT', 'name' => 'Alice',
        'avatar' => UploadedFile::fake()->image('avatar.jpg', 400, 300),
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'profile-information-updated');

    $avatar = $user->refresh()->avatar_path;
    expect($avatar)->toStartWith('avatars/')->toEndWith('.webp');
    $dimensions = getimagesizefromstring(Storage::get($avatar));
    expect([$dimensions[0], $dimensions[1], $dimensions['mime']])->toBe([256, 256, 'image/webp']);
    Storage::assertMissing('avatars/original.webp');
    Storage::disk('transfers')->assertCount('/', 1);

    $this->putJson(route('user-profile-information.update'), ['name' => 'Alice', 'remove_avatar' => true])
        ->assertSuccessful();
    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::assertMissing($avatar);
    Storage::disk('transfers')->assertExists('untouched.txt');
});

test('avatar validation rejects unsafe unsupported oversized and invalid dimensions', function (Closure $upload): void {
    $user = User::factory()->create(['avatar_path' => 'avatars/original.webp']);
    Storage::put($user->avatar_path, 'original');

    $this->actingAs($user)->putJson(route('user-profile-information.update'), [
        'name' => 'Alice', 'avatar' => $upload(),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');

    expect($user->refresh()->avatar_path)->toBe('avatars/original.webp');
    Storage::assertCount('avatars', 1);
})->with([
    'not an image' => fn () => UploadedFile::fake()->create('photo.png', 10, 'text/plain'),
    'svg' => fn () => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
    'gif' => fn () => UploadedFile::fake()->image('photo.gif', 200, 200),
    'too large' => fn () => UploadedFile::fake()->image('photo.png', 200, 200)->size(5121),
    'too small' => fn () => UploadedFile::fake()->image('photo.png', 199, 200),
    'too wide' => fn () => UploadedFile::fake()->image('photo.png', 4097, 200),
    'too tall' => fn () => UploadedFile::fake()->image('photo.png', 200, 4097),
]);

test('avatar upload and removal cannot be submitted together', function (): void {
    $this->actingAs(User::factory()->create())->putJson(route('user-profile-information.update'), [
        'name' => 'Alice', 'avatar' => UploadedFile::fake()->image('photo.png', 200, 200), 'remove_avatar' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');
    Storage::assertCount('avatars', 0);
});

test('failed avatar storage preserves the previous profile', function (): void {
    $user = User::factory()->create(['avatar_path' => 'avatars/original.webp']);
    $disk = Storage::disk();
    $disk->put($user->avatar_path, 'original');

    $failedDisk = Mockery::mock(FilesystemAdapter::class);
    $failedDisk->shouldReceive('put')->andReturnFalse();
    Storage::partialMock()->shouldReceive('disk')->andReturn($failedDisk);

    $this->actingAs($user)->putJson(route('user-profile-information.update'), [
        'name' => 'New Name', 'avatar' => UploadedFile::fake()->image('photo.png', 200, 200),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');

    expect($user->refresh()->avatar_path)->toBe('avatars/original.webp')->and($user->name)->not->toBe('New Name');
    $disk->assertExists('avatars/original.webp');
});

test('failed image processing preserves the previous profile', function (): void {
    $user = User::factory()->create(['avatar_path' => 'avatars/original.webp']);
    Storage::put($user->avatar_path, 'original');
    Exceptions::fake();
    Image::shouldReceive('fromUpload')->andThrow(new ImageException('Invalid image data'));

    $this->actingAs($user)->putJson(route('user-profile-information.update'), [
        'name' => 'New Name', 'avatar' => UploadedFile::fake()->image('photo.png', 200, 200),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');

    expect($user->refresh()->avatar_path)->toBe('avatars/original.webp')->and($user->name)->not->toBe('New Name');
    Storage::assertExists('avatars/original.webp');
    Storage::assertCount('avatars', 1);
});

test('failed profile persistence removes the new image and keeps the old image', function (): void {
    $user = User::factory()->create(['avatar_path' => 'avatars/original.webp']);
    Storage::put($user->avatar_path, 'original');
    Exceptions::fake();
    Event::listen('eloquent.updating: '.User::class, function (): never {
        throw new RuntimeException('Simulated database failure');
    });

    $this->actingAs($user)->putJson(route('user-profile-information.update'), [
        'name' => 'New Name', 'avatar' => UploadedFile::fake()->image('photo.png', 200, 200),
    ])->assertServerError();

    expect($user->refresh()->avatar_path)->toBe('avatars/original.webp');
    Storage::assertExists('avatars/original.webp');
    Storage::assertCount('avatars', 1);
});

test('avatar URLs are shared with the owner and authorized transfer recipients', function (): void {
    $user = User::factory()->create(['avatar_path' => 'avatars/photo.webp']);
    Storage::put($user->avatar_path, 'photo');
    $transfer = Transfer::factory()->for($user)->create();
    $avatarUrl = Storage::temporaryUrl($user->avatar_path, now()->addHour());

    $this->actingAs($user)->get(route('account.settings'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('auth.user.avatar_url', $avatarUrl)->missing('auth.user.avatar_path'));

    Auth::logout();
    session()->flush();
    $this->get(route('shared.show', $transfer->token))->assertInertia(fn (Assert $page): Assert => $page
        ->where('transfer.sender.avatar_url', $avatarUrl)->missing('transfer.sender.avatar_path'));

    $transfer->update(['visibility' => 'teams']);
    $this->actingAs(User::factory()->create())->get(route('shared.show', $transfer->token))
        ->assertForbidden()->assertInertia(fn (Assert $page): Assert => $page->missing('sender.avatar_url'));
});

test('password updates apply the environment policy and verify the current password', function (string $environment, string $password, string $current, bool $valid): void {
    $this->app->instance('env', $environment);
    $this->withoutMiddleware(PreventRequestForgery::class);
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $response = $this->actingAs($user)->putJson(route('user-password.update'), [
        'current_password' => $current, 'password' => $password, 'password_confirmation' => $password,
    ]);

    if (! $valid) {
        $response->assertUnprocessable()->assertJsonValidationErrors($current === 'password' ? 'password' : 'current_password');
        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();

        return;
    }

    $response->assertSuccessful();
    expect(Hash::check($password, $user->refresh()->password))->toBeTrue()
        ->and(Password::tokenExists($user, $token))->toBeFalse();
    $this->assertAuthenticatedAs($user);
})->with([
    ['production', 'weakpassword', 'password', false],
    ['production', 'LongPassword12!', 'password', true],
    ['local', 'changed-password', 'wrong-password', false],
    ['local', 'password', 'password', true],
    ['local', 'short', 'password', false],
]);

test('password changes require matching confirmation', function (): void {
    $this->actingAs(User::factory()->create())->putJson(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'LongPassword12!', 'password_confirmation' => 'different',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

test('password changes keep the current session and invalidate another session', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $cookieName = config('session.cookie');
    $switchSession = function (string $id) use ($cookieName): void {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        session()->flush();
        $this->withCookie($cookieName, $id);
    };
    $first = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->getCookie($cookieName)->getValue();
    $switchSession(Str::random(40));
    $second = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->getCookie($cookieName)->getValue();
    $switchSession($second);

    $this->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'changed-password', 'password_confirmation' => 'changed-password',
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'password-updated');

    $switchSession($second);
    $this->get(route('account.settings'))->assertOk();
    $switchSession($first);
    $this->get(route('account.settings'))->assertRedirect(route('login'));
    $this->assertGuest();
});
