<?php

declare(strict_types=1);

test('pages include the default social preview image in the initial html', function (string $path, int $status): void {
    $imageUrl = asset('og-image.png');

    $this->get($path)
        ->assertStatus($status)
        ->assertSee('<meta property="og:image" content="'.$imageUrl.'">', false)
        ->assertSee('<meta property="og:image:type" content="image/png">', false)
        ->assertSee('<meta property="og:image:width" content="2400">', false)
        ->assertSee('<meta property="og:image:height" content="1260">', false)
        ->assertSee('<meta property="og:image:alt" content="Filemax — Large files, one link.">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<meta name="twitter:image" content="'.$imageUrl.'">', false);
})->with([
    'sign in' => ['/login', 200],
    'unavailable shared link' => ['/t/missing-transfer', 404],
]);

test('the social preview image matches its declared dimensions and format', function (): void {
    $image = getimagesize(public_path('og-image.png'));

    expect($image)->not->toBeFalse()
        ->and($image[0])->toBe(2400)
        ->and($image[1])->toBe(1260)
        ->and($image['mime'])->toBe('image/png');
});
