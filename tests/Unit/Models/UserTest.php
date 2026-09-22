<?php

declare(strict_types=1);

use App\Models\User;

test('normalizes email when persisting a user', function (): void {
    $user = User::factory()->create(['email' => ' ALICE@MEDIAMAXCOMMUNICATION.IT ']);

    expect($user->refresh()->email)->toBe('alice@mediamaxcommunication.it');
});

test('to array', function (): void {
    $user = User::factory()->create()->refresh();

    expect(array_keys($user->toArray()))
        ->toBe([
            'id',
            'name',
            'email',
            'email_verified_at',
            'created_at',
            'updated_at',
            'is_admin',
        ]);
});
