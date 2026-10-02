<?php

namespace App\Providers;

use App\Contracts\PdfTextExtractor;
use App\Models\User;
use App\Support\PopplerPdfTextExtractor;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PdfTextExtractor::class, fn (): PopplerPdfTextExtractor => new PopplerPdfTextExtractor(
            pdftotextBinary: (string) config('statement-imports.pdftotext_binary'),
            pdfinfoBinary: (string) config('statement-imports.pdfinfo_binary'),
            timeoutSeconds: (float) config('statement-imports.timeout_seconds'),
            maxPages: (int) config('statement-imports.max_pages'),
            maxExtractedBytes: (int) config('statement-imports.max_extracted_bytes'),
            maxMetadataBytes: (int) config('statement-imports.max_metadata_bytes'),
            maxDiagnosticBytes: (int) config('statement-imports.max_diagnostic_bytes'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('manage-users', fn (User $user): bool => User::whereKey($user->id)
            ->where('role', UserRole::Admin)->where('is_active', true)->exists());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
