<?php

use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['floatbook.demo.enabled' => true]);
    // Three days of trading is enough to prove the seeder; fourteen is slow.
    DemoSeeder::$days = 3;
});

afterEach(function () {
    DemoSeeder::$days = 14;
});

test('the landing page offers the demo only while demo mode is on', function () {
    $this->get(route('home'))->assertInertia(fn ($page) => $page->has('demoRoles', 4)
        ->where('demoRoles.0.role', 'Owner'));

    config(['floatbook.demo.enabled' => false]);
    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('demoRoles', []));
});

test('one click signs you in as a demo person', function () {
    $this->seed(DemoSeeder::class);

    $this->post(route('demo.login', 'agent'))->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', DemoSeeder::PEOPLE['agent'][0])->sole();
    $this->assertAuthenticatedAs($user);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('tenant.business.name', 'Ade & Sons POS')
        ->where('tenant.business.is_demo', true)
        ->where('tenant.membership.role', 'agent'));
})->group('slow');

test('it is refused when demo mode is off, or the role is made up', function () {
    config(['floatbook.demo.enabled' => false]);
    $this->post(route('demo.login', 'owner'))->assertNotFound();

    config(['floatbook.demo.enabled' => true]);
    $this->post(route('demo.login', 'manager'))->assertNotFound();
    $this->assertGuest();
});

test('it will not sign anyone in if the demo business is missing', function () {
    $this->from(route('home'))->post(route('demo.login', 'owner'))
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors('demo');

    $this->assertGuest();
});

test('a demo account that is no longer part of the demo business cannot be used', function () {
    $this->seed(DemoSeeder::class);

    // Somebody kept the email but the business is no longer a demo.
    Business::query()->where('slug', DemoSeeder::SLUG)->update(['is_demo' => false]);

    $this->from(route('home'))->post(route('demo.login', 'owner'))->assertSessionHasErrors('demo');
    $this->assertGuest();
})->group('slow');

test('the nightly reset only runs after its time, and once a day', function () {
    config(['floatbook.demo.reset_at' => '02:30']);
    Cache::flush();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 01:00', 'Africa/Lagos'));
    $this->artisan('floatbook:reset-demo')->assertSuccessful();
    expect(Business::query()->where('slug', DemoSeeder::SLUG)->exists())->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 02:45', 'Africa/Lagos'));
    $this->artisan('floatbook:reset-demo')->assertSuccessful();
    expect(Business::query()->where('slug', DemoSeeder::SLUG)->exists())->toBeTrue();

    $before = Business::query()->where('slug', DemoSeeder::SLUG)->value('id');
    $this->artisan('floatbook:reset-demo')->assertSuccessful();
    expect(Business::query()->where('slug', DemoSeeder::SLUG)->value('id'))->toBe($before);
})->group('slow');

test('the reset does nothing when demo mode is off', function () {
    config(['floatbook.demo.enabled' => false]);

    $this->artisan('floatbook:reset-demo')->expectsOutputToContain('Demo mode is off')->assertSuccessful();
    expect(Business::query()->where('slug', DemoSeeder::SLUG)->exists())->toBeFalse();
});
