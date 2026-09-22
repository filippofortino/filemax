<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\U2FPublicKey;

/** @return array{Passkey, OpenSSLAsymmetricKey} */
function filemaxPasskey(User $user): array
{
    $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $details = openssl_pkey_get_details($privateKey);
    $credentialId = random_bytes(32);
    $record = CredentialRecord::create(
        publicKeyCredentialId: $credentialId,
        type: 'public-key',
        transports: ['internal'],
        attestationType: 'none',
        trustPath: EmptyTrustPath::create(),
        aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
        credentialPublicKey: U2FPublicKey::convertToCoseKey("\x04".$details['ec']['x'].$details['ec']['y']),
        userHandle: $user->getPasskeyUserHandle(),
        counter: 0,
    );

    $passkey = $user->passkeys()->create([
        'name' => 'My laptop',
        'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
        'credential' => json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR),
    ]);

    return [$passkey, $privateKey];
}

/** @return array<string, mixed> */
function filemaxPasskeyAssertion(Passkey $passkey, OpenSSLAsymmetricKey $privateKey, string $challenge, ?string $origin = null): array
{
    $clientData = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $challenge,
        'origin' => $origin ?? config('passkeys.allowed_origins.0'),
        'crossOrigin' => false,
    ], JSON_THROW_ON_ERROR);
    $authenticatorData = hash('sha256', config('passkeys.relying_party_id'), true)."\x05".pack('N', 1);
    openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return [
        'id' => $passkey->credential_id,
        'rawId' => $passkey->credential_id,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
            'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
            'signature' => Base64UrlSafe::encodeUnpadded($signature),
            'userHandle' => $passkey->credential['userHandle'],
        ],
    ];
}

test('passkey relying party and allowed origins come from the configured application URL', function (): void {
    expect(config('passkeys.relying_party_id'))->toBe(parse_url(config('app.url'), PHP_URL_HOST))
        ->and(config('passkeys.allowed_origins'))->toBe([config('app.url')])
        ->and(config('auth.password_timeout'))->toBe(10800);
});

test('guests cannot manage passkeys or confirm their identity', function (): void {
    [$passkey] = filemaxPasskey(User::factory()->create());

    $this->get(route('account.passkeys'))->assertRedirect(route('login'));
    $this->getJson(route('passkey.registration-options'))->assertUnauthorized();
    $this->postJson(route('passkey.store'), [])->assertUnauthorized();
    $this->deleteJson(route('passkey.destroy', $passkey))->assertUnauthorized();
    $this->getJson(route('passkey.confirm-options'))->assertUnauthorized();
    $this->postJson(route('passkey.confirm'), [])->assertUnauthorized();
    $this->assertModelExists($passkey);
});

test('passkey management requires a currently eligible verified account', function (bool $verified): void {
    $user = User::factory()->create($verified
        ? ['email' => 'former@example.com']
        : ['email_verified_at' => null]);
    [$passkey] = filemaxPasskey($user);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $this->getJson(route('account.passkeys'))->assertForbidden();
    $this->getJson(route('passkey.registration-options'))->assertForbidden();
    $this->postJson(route('passkey.store'), [])->assertForbidden();
    $this->deleteJson(route('passkey.destroy', $passkey))->assertForbidden();
    $this->getJson(route('passkey.confirm-options'))->assertForbidden();
    $this->postJson(route('passkey.confirm'), [])->assertForbidden();
    $this->assertModelExists($passkey);
})->with(['ineligible' => true, 'unverified' => false]);

test('password confirmation permits management for three hours and lists only safe account metadata', function (): void {
    $this->withoutVite();
    $user = User::factory()->create();
    [$passkey] = filemaxPasskey($user);
    filemaxPasskey(User::factory()->create());
    $this->actingAs($user);

    $this->get(route('account.passkeys'))->assertRedirect(route('password.confirm'));
    $this->getJson(route('passkey.registration-options'))->assertStatus(423);
    $this->postJson(route('passkey.store'), [])->assertStatus(423);
    $this->deleteJson(route('passkey.destroy', $passkey))->assertStatus(423);
    $this->postJson(route('password.confirm.store'), ['password' => 'incorrect'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertCreated()->assertSessionHas('auth.password_confirmed_at', now()->timestamp);

    $this->get(route('account.passkeys'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/passkeys')
        ->has('passkeys', 1)
        ->where('passkeys.0.id', $passkey->id)
        ->where('passkeys.0.name', 'My laptop')
        ->missing('passkeys.0.credential')
        ->missing('passkeys.0.credential_id'));
    $this->getJson(route('passkey.registration-options'))->assertOk()
        ->assertJsonPath('options.rp.id', config('passkeys.relying_party_id'))
        ->assertSessionHas('passkey.registration_options');
    $this->postJson(route('passkey.store'), ['name' => 'Invalid key', 'credential' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('credential');
    expect($user->passkeys()->count())->toBe(1);

    $this->travel(3)->hours();
    $this->getJson(route('passkey.registration-options'))->assertOk();
    $this->travel(1)->seconds();
    $this->getJson(route('passkey.registration-options'))->assertStatus(423);
    $this->postJson(route('passkey.store'), [])->assertStatus(423);
    $this->deleteJson(route('passkey.destroy', $passkey))->assertStatus(423);
    $this->assertModelExists($passkey);
});

test('an existing passkey can confirm identity for management', function (): void {
    $user = User::factory()->create();
    [$passkey, $privateKey] = filemaxPasskey($user);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->subHours(4)->timestamp]);
    $options = $this->getJson(route('passkey.confirm-options'))->assertOk()
        ->assertJsonPath('options.allowCredentials.0.id', $passkey->credential_id)->json('options');

    $this->postJson(route('passkey.confirm'), [
        'credential' => filemaxPasskeyAssertion($passkey, $privateKey, $options['challenge']),
    ])->assertOk()->assertSessionHas('auth.password_confirmed_at', now()->timestamp)
        ->assertSessionMissing('passkey.verification_options');

    expect($passkey->refresh()->last_used_at?->timestamp)->toBe(now()->timestamp);
    $this->getJson(route('passkey.registration-options'))->assertOk();
    $this->postJson(route('passkey.confirm'), [
        'credential' => filemaxPasskeyAssertion($passkey, $privateKey, $options['challenge']),
    ])->assertUnprocessable()->assertJsonValidationErrors('credential');
});

test('another accounts passkey cannot confirm identity', function (): void {
    $user = User::factory()->create();
    [$passkey, $privateKey] = filemaxPasskey(User::factory()->create());
    $this->actingAs($user);
    $options = $this->getJson(route('passkey.confirm-options'))->assertOk()->json('options');

    $this->postJson(route('passkey.confirm'), [
        'credential' => filemaxPasskeyAssertion($passkey, $privateKey, $options['challenge']),
    ])->assertUnprocessable()->assertJsonValidationErrors('credential')
        ->assertSessionMissing('auth.password_confirmed_at');

    expect($passkey->refresh()->last_used_at)->toBeNull();
});

test('passkey deletion checks ownership and allows deleting the last key', function (): void {
    $user = User::factory()->create();
    [$ownPasskey] = filemaxPasskey($user);
    [$otherPasskey] = filemaxPasskey(User::factory()->create());
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $this->deleteJson(route('passkey.destroy', $otherPasskey))->assertForbidden();
    $this->assertModelExists($otherPasskey);
    $this->deleteJson(route('passkey.destroy', $ownPasskey))->assertOk();
    $this->assertModelMissing($ownPasskey);
    expect($user->passkeys()->exists())->toBeFalse();
    expect(Hash::check('password', $user->password))->toBeTrue();
});

test('password reset retains existing passkeys', function (): void {
    $user = User::factory()->create();
    [$passkey] = filemaxPasskey($user);
    $credential = $passkey->credential;

    $this->post(route('password.update'), [
        'email' => $user->email,
        'token' => Password::createToken($user),
        'password' => 'changed-password',
        'password_confirmation' => 'changed-password',
    ])->assertRedirect(route('login'));

    expect(Hash::check('changed-password', $user->refresh()->password))->toBeTrue()
        ->and($passkey->refresh()->credential)->toBe($credential);
    $this->assertModelExists($passkey);
});

test('passkey login uses the intended destination and consumes its challenge once', function (): void {
    $user = User::factory()->create();
    [$passkey, $privateKey] = filemaxPasskey($user);
    $this->withSession(['url.intended' => '/t/shared-transfer']);
    $sessionId = session()->getId();
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    $credential = filemaxPasskeyAssertion($passkey, $privateKey, $options['challenge']);

    $this->postJson(route('passkey.login'), ['credential' => $credential])
        ->assertOk()->assertJsonPath('redirect', url('/t/shared-transfer'))
        ->assertSessionMissing('passkey.verification_options');
    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionId)
        ->and($passkey->refresh()->last_used_at?->timestamp)->toBe(now()->timestamp);

    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->postJson(route('passkey.login'), ['credential' => $credential])
        ->assertUnprocessable()->assertJsonValidationErrors('credential');
    $this->assertGuest();
});

test('a signed assertion for a different challenge is rejected and cannot be retried', function (): void {
    [$passkey, $privateKey] = filemaxPasskey(User::factory()->create());
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');

    $this->postJson(route('passkey.login'), [
        'credential' => filemaxPasskeyAssertion($passkey, $privateKey, Base64UrlSafe::encodeUnpadded(random_bytes(32))),
    ])->assertUnprocessable()->assertJsonValidationErrors('credential')
        ->assertSessionMissing('passkey.verification_options');

    $this->postJson(route('passkey.login'), [
        'credential' => filemaxPasskeyAssertion($passkey, $privateKey, $options['challenge']),
    ])->assertUnprocessable()->assertJsonValidationErrors('credential');
    expect($passkey->refresh()->last_used_at)->toBeNull();
    $this->assertGuest();
});

test('signed assertions must match the configured origin including scheme and subdomain', function (string $origin): void {
    config(['passkeys.relying_party_id' => 'filemax.test', 'passkeys.allowed_origins' => ['https://filemax.test']]);
    [$passkey, $privateKey] = filemaxPasskey(User::factory()->create());
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');

    $this->postJson(route('passkey.login'), [
        'credential' => filemaxPasskeyAssertion($passkey, $privateKey, $options['challenge'], $origin),
    ])->assertUnprocessable()->assertJsonValidationErrors('credential');

    expect($passkey->refresh()->last_used_at)->toBeNull();
    $this->assertGuest();
})->with(['https://attacker.example', 'http://filemax.test', 'https://sub.filemax.test']);

test('a valid passkey cannot sign in an account that is no longer eligible', function (): void {
    $user = User::factory()->create();
    [$passkey, $privateKey] = filemaxPasskey($user);
    $user->update(['email' => 'former@example.com']);
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');

    $this->postJson(route('passkey.login'), [
        'credential' => filemaxPasskeyAssertion($passkey, $privateKey, $options['challenge']),
    ])->assertUnprocessable()->assertJsonValidationErrors('credential');

    expect($passkey->refresh()->last_used_at?->timestamp)->toBe(now()->timestamp);
    $this->assertGuest();
});
