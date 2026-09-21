<?php

declare(strict_types=1);

use App\Models\User;

it('opens passkey management from the account menu with keyboard and confirms the password', function (): void {
    $user = User::factory()->create();

    visit('/login')->resize(1280, 940)
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('form button[data-slot="button"]')
        ->assertSee('Transfer details')
        ->keys('[aria-label="Account menu"]', 'Enter')
        ->keys('a[href$="/account/passkeys"]', 'Enter')
        ->assertSee('Confirm it’s you')
        ->fill('password', 'password')
        ->keys('#password', 'Enter')
        ->assertSee('You haven’t added any passkeys yet.')
        ->assertSee('Add a passkey')
        ->assertDisabled('form button[data-slot="button"]')
        ->assertNoJavascriptErrors();
});

it('keeps a cancelled named passkey registration recoverable on mobile', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $page = visit('/account/passkeys')->resize(390, 844)
        ->assertSee('You haven’t added any passkeys yet.');
    $page->script(<<<'JS'
() => {
    window.passkeyCreateCalls = 0;
    Object.defineProperty(navigator.credentials, 'create', {
        configurable: true,
        value: async () => {
            window.passkeyCreateCalls++;
            throw new DOMException('User cancelled the prompt', 'NotAllowedError');
        },
    });
}
JS);

    $page->fill('#passkey-name', 'Work MacBook')
        ->assertEnabled('form button[data-slot="button"]')
        ->keys('#passkey-name', 'Enter')
        ->assertSee('The passkey operation was cancelled.')
        ->assertSee('You haven’t added any passkeys yet.')
        ->assertEnabled('form button[data-slot="button"]')
        ->assertNoJavascriptErrors();

    expect($page->text('[role="alert"]'))->toContain('The passkey operation was cancelled.');
    expect($page->script('() => window.passkeyCreateCalls'))->toBe(1);
    expect($page->script('() => document.querySelector("#passkey-name").value'))->toBe('Work MacBook');
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($user->passkeys()->count())->toBe(0);
});

it('explains unsupported passkeys and leaves password sign in available on mobile', function (): void {
    $page = visit('/register')->resize(390, 844);
    $page->script(<<<'JS'
() => Object.defineProperty(window, 'PublicKeyCredential', {
    configurable: true,
    value: undefined,
})
JS);

    $page->click('Sign in')
        ->assertSee('Passkeys aren’t supported in this browser. You can use your password.')
        ->assertDisabled('button:has-text("Sign in with passkey")')
        ->assertEnabled('form button[data-slot="button"]')
        ->assertNoJavascriptErrors();

    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

it('offers a working reload link when a passkey sign in session expires', function (): void {
    $page = visit('/login');
    $page->script(<<<'JS_WRAP'
    () => {
        const fetch = window.fetch;
        window.fetch = (url, options) => {
            if (new URL(url, location.href).pathname === '/passkeys/login/options') {
                return Promise.resolve(new Response(JSON.stringify({message: 'CSRF token mismatch.'}), {
                    status: 419,
                    headers: {'Content-Type': 'application/json'},
                }));
            }
            return fetch(url, options);
        };
    }
    JS_WRAP);

    $page->press('Sign in with passkey')
        ->assertSee('Your session expired.')
        ->assertEnabled('form button[data-slot="button"]')
        ->assertNoJavascriptErrors();

    expect($page->text('[role="alert"]'))->toContain('Your session expired.');
    expect($page->attribute('a:has-text("Reload this page")', 'href'))->toBe('/login');

    $page->click('Reload this page')
        ->assertSee('Forgot password?')
        ->assertDontSee('Your session expired.')
        ->assertNoJavascriptErrors();
});
