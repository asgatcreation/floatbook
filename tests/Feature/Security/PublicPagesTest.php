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

// The host terminates TLS and forwards the request over plain HTTP. If the forwarded
// headers are ignored, every asset tag says http:// on an https:// page and the browser
// blocks them all, leaving a blank screen.
test('behind a TLS proxy, links and asset tags are https', function () {
    $response = $this->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'floatbook.test',
    ])->get('/');

    $response->assertOk();
    expect($response->getContent())->not->toContain('http://floatbook.test');
    expect(url('/login'))->toStartWith('https://');
});

// The theme script has to run before the first paint, so it is written into the page.
// Without a matching nonce the policy blocks it and the page flashes the wrong theme.
test('the inline theme script carries the nonce the policy allows', function () {
    $response = $this->get('/');

    $policy = $response->headers->get('Content-Security-Policy');
    expect($policy)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/");

    preg_match("/'nonce-([^']+)'/", (string) $policy, $fromHeader);
    expect($response->getContent())->toContain('<script nonce="'.$fromHeader[1].'">');
});
