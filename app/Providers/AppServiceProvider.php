<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request (or per job), reset between them.
        $this->app->scoped(CurrentBusiness::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePermissions();
        $this->configureMail();

        Event::listen(function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $user = $event->user;
            $membership = $user->activeMemberships()->with('business')
                ->where('business_id', $user->current_business_id)->first();

            // Record the sign-in against the business (and role) the person is working in.
            $record = fn () => app(Audit::class)->record('user.signed_in', $user, actor: $user, business: $membership?->business);

            $membership !== null
                ? app(CurrentBusiness::class)->runAs($membership->business, $record, $membership)
                : $record();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Links and asset tags must say https, even if a proxy header is ever missing:
        // a page served over https cannot load a script over http.
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(10)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->uncompromised()
            : Password::min(8),
        );
    }

    /**
     * Each permission is a gate, answered by the user's membership in the current business.
     */
    protected function configurePermissions(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function (User $user) use ($permission): bool {
                $membership = app(CurrentBusiness::class)->membership();

                return $membership !== null
                    && $membership->user_id === $user->id
                    && $membership->allows($permission);
            });
        }
    }

    /**
     * Brevo's HTTP API is used in production because Render's free plan blocks SMTP.
     */
    protected function configureMail(): void
    {
        Mail::extend('brevo', fn (array $config) => (new BrevoTransportFactory)->create(
            new Dsn('brevo+api', 'default', $config['key'] ?? null),
        ));
    }
}
