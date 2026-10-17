<?php

use App\Models\User;

test('privacy and terms are readable without an account', function (string $route) {
    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('legal/Legal')->where('page', str_replace('terms', 'terms', $route)));
})->with(['privacy', 'terms']);

test('every page carries the security headers', function () {
    $response = $this->get(route('home'));

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("default-src 'self'");

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

test('signed-in pages carry them too', function () {
    [, $owner] = businessWithOwner();

    $this->actingAs($owner)->get(route('dashboard'))->assertHeader('X-Frame-Options', 'DENY');
});

test('the health check answers for the hosting platform', function () {
    $this->get('/up')->assertOk();
});

test('a signed-out visitor is sent to the login page, not shown a stack trace', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertRedirect(route('no-business'));
});
