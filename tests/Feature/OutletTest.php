<?php

use App\Models\AuditLog;
use App\Models\Outlet;

beforeEach(function () {
    [$this->business, $this->owner] = businessWithOwner();
});

test('an owner can add an outlet and a short code is suggested', function () {
    $this->actingAs($this->owner)->post(route('outlets.store'), ['name' => 'Ketu Main']);
    $this->actingAs($this->owner)
        ->post(route('outlets.store'), ['name' => 'Mile 12 Market', 'address' => 'Mile 12, Lagos'])
        ->assertSessionHasNoErrors();

    $codes = tenancy()->runAs($this->business, fn () => Outlet::query()->orderBy('name')->pluck('code', 'name')->all());
    expect($codes)->toBe(['Ketu Main' => 'KETU', 'Mile 12 Market' => 'MILE12']);

    $logged = tenancy()->runAs($this->business, fn () => AuditLog::query()->where('action', 'outlet.created')->exists());
    expect($logged)->toBeTrue();
});

test('outlet names and codes are unique within a business but can repeat across businesses', function () {
    addOutlet($this->business, ['name' => 'Ketu Main', 'code' => 'KETU']);
    [$other] = businessWithOwner();
    addOutlet($other, ['name' => 'Ketu Main', 'code' => 'KETU']);

    $this->actingAs($this->owner)
        ->post(route('outlets.store'), ['name' => 'Ketu Main', 'code' => 'ketu'])
        ->assertSessionHasErrors(['name', 'code']);
});

test('an outlet can be renamed and deactivated, with the change audited', function () {
    $outlet = addOutlet($this->business, ['name' => 'Yaba']);

    $this->actingAs($this->owner)
        ->patch(route('outlets.update', $outlet), ['name' => 'Yaba Junction', 'is_active' => false])
        ->assertSessionHasNoErrors();

    expect($outlet->fresh()->name)->toBe('Yaba Junction')->and($outlet->fresh()->is_active)->toBeFalse();

    $log = tenancy()->runAs($this->business, fn () => AuditLog::query()->where('action', 'outlet.updated')->sole());
    expect($log->before)->toBe(['name' => 'Yaba', 'is_active' => true])
        ->and($log->after)->toBe(['name' => 'Yaba Junction', 'is_active' => false]);
});
