<?php

declare(strict_types=1);

use App\Models\Drop;
use App\Models\Organization;
use App\Models\Secret;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('admins save the brand color, welcome note and footer', function (): void {
    $admin = User::factory()->create();
    $organization = joinOrganization($admin, 'admin');

    $this->actingAs($admin)->post(route('organization.branding.update'), [
        'organization_id' => $organization->id,
        'brand_color' => '#be185d',
        'welcome_message' => 'Ciao! Ecco i file dal team.',
        'footer_text' => 'Studio Conti, Milano',
        'footer_url' => 'https://example.com',
    ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.title', 'Branding saved');

    expect($organization->refresh()->only(['brand_color', 'welcome_message', 'footer_text', 'footer_url']))->toBe([
        'brand_color' => '#BE185D',
        'welcome_message' => 'Ciao! Ecco i file dal team.',
        'footer_text' => 'Studio Conti, Milano',
        'footer_url' => 'https://example.com',
    ]);

    $this->get(route('organization.branding'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('section', 'branding')
        ->where('branding.brand_color', '#BE185D')
        ->where('branding.wordmark_url', null));

    // Empty fields fall back to the Filemax defaults.
    $this->post(route('organization.branding.update'), [
        'organization_id' => $organization->id,
        'brand_color' => '',
        'welcome_message' => '',
        'footer_text' => '',
        'footer_url' => '',
    ])->assertSessionHasNoErrors();

    expect($organization->refresh()->only(['brand_color', 'welcome_message', 'footer_text', 'footer_url']))
        ->each->toBeNull();
});

test('branding rejects colors too light to read, malformed values and non-https links', function (array $input, string $field): void {
    $admin = User::factory()->create();
    $organization = joinOrganization($admin, 'admin');

    $this->actingAs($admin)->post(route('organization.branding.update'), ['organization_id' => $organization->id, ...$input])
        ->assertSessionHasErrors($field);

    expect($organization->refresh()->brand_color)->toBeNull();
})->with([
    'too light' => [['brand_color' => '#F5A623'], 'brand_color'],
    'not hex' => [['brand_color' => 'red'], 'brand_color'],
    'short hex' => [['brand_color' => '#123'], 'brand_color'],
    'http link' => [['footer_url' => 'http://example.com'], 'footer_url'],
    'not a link' => [['footer_url' => 'example'], 'footer_url'],
    'long footer' => [['footer_text' => str_repeat('a', 121)], 'footer_text'],
    'long welcome note' => [['welcome_message' => str_repeat('a', 1001)], 'welcome_message'],
]);

test('contrast with white follows WCAG, and every preset swatch passes 4.5:1', function (): void {
    expect(Organization::contrastWithWhite('#FFFFFF'))->toEqualWithDelta(1.0, 0.001)
        ->and(Organization::contrastWithWhite('#000000'))->toEqualWithDelta(21.0, 0.001)
        ->and(Organization::contrastWithWhite('#2140E0'))->toEqualWithDelta(7.3, 0.05);

    // The swatches in the Branding settings (resources/scripts/pages/organization/settings.tsx).
    foreach (['#2140E0', '#6D28D9', '#BE185D', '#B91C1C', '#C2410C', '#15803D', '#0F766E', '#0F172B'] as $preset) {
        expect(Organization::contrastWithWhite($preset))->toBeGreaterThanOrEqual(4.5);
    }
});

test('admins upload, replace and remove a full logo', function (): void {
    config(['filesystems.default' => 'logos']);
    Storage::fake('logos');
    $admin = User::factory()->create();
    $organization = joinOrganization($admin, 'admin');
    $form = ['organization_id' => $organization->id];

    $this->actingAs($admin)->post(route('organization.branding.update'), $form + ['wordmark' => UploadedFile::fake()->image('logo.jpg', 800, 120)])
        ->assertSessionHasErrors('wordmark');
    $this->post(route('organization.branding.update'), $form + ['wordmark' => UploadedFile::fake()->image('logo.png', 400, 80)])
        ->assertSessionHasErrors('wordmark');
    $this->post(route('organization.branding.update'), $form + ['wordmark' => UploadedFile::fake()->image('logo.png', 800, 120)])
        ->assertSessionHasNoErrors();

    $first = $organization->refresh()->wordmark_path;
    expect($first)->toStartWith('logos/');
    Storage::assertExists($first);

    $this->post(route('organization.branding.update'), $form + ['wordmark' => UploadedFile::fake()->image('logo.png', 1600, 240)])
        ->assertSessionHasNoErrors();
    $second = $organization->refresh()->wordmark_path;
    expect($second)->not->toBe($first);
    Storage::assertMissing($first);
    Storage::assertExists($second);

    $this->post(route('organization.branding.update'), $form + ['remove_wordmark' => true])->assertSessionHasNoErrors();
    expect($organization->refresh()->wordmark_path)->toBeNull();
    Storage::assertMissing($second);
});

test('every recipient page carries the branding of the organization the link was sent from', function (): void {
    $user = User::factory()->create();
    $personalTransfer = Transfer::factory()->for($user)->create();
    TransferFile::factory()->for($personalTransfer)->create();
    $organization = joinOrganization($user, 'admin');
    $organization->forceFill(['brand_color' => '#0F766E', 'welcome_message' => 'Benvenuti', 'footer_text' => 'Studio Conti', 'footer_url' => 'https://example.com'])->save();
    $transfer = Transfer::factory()->for($user)->create(['message' => null]);
    TransferFile::factory()->for($transfer)->create();
    $locked = Transfer::factory()->for($user)->create(['password_hash' => Hash::make('Transfer123')]);
    $expired = Transfer::factory()->for($user)->expired()->create();
    $secret = Secret::factory()->for($user)->create();
    $drop = Drop::factory()->for($user)->create();
    $branding = [
        'name' => $organization->name,
        'logo_url' => null,
        'wordmark_url' => null,
        'color' => '#0F766E',
        'welcome_message' => 'Benvenuti',
        'footer_text' => 'Studio Conti',
        'footer_url' => 'https://example.com',
    ];

    foreach ([
        route('shared.show', $transfer->token),
        route('shared.show', $locked->token),
        route('shared.show', $expired->token),
        route('shared.secrets.show', $secret->token),
        route('shared.drops.show', $drop->token),
    ] as $url) {
        $this->get($url)->assertInertia(fn (Assert $page): Assert => $page->where('branding', $branding));
    }

    $this->get(route('shared.show', $personalTransfer->token))->assertInertia(fn (Assert $page): Assert => $page->where('branding', null));
});
