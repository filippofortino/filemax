<?php

declare(strict_types=1);

it('renders the home page', function (): void {
    $page = visit('/');

    $page->assertSee('Hello, world');
});
