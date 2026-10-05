<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PimPanelProvider extends PanelProvider
{
    public const PanelColor = Color::Amber;

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('pim')
            ->path('pim')
            ->brandName('Pisa / PIM')
            ->brandLogoHeight('58px')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(MaxWidth::Full)
            ->login()
            ->colors([
                'primary' => self::PanelColor,
            ])
            ->discoverResources(in: app_path('Filament/Pim/Resources'), for: 'App\\Filament\\Pim\\Resources')
            ->discoverPages(in: app_path('Filament/Pim/Pages'), for: 'App\\Filament\\Pim\\Pages')
            ->pages([
            ])
            ->discoverWidgets(in: app_path('Filament/Pim/Widgets'), for: 'App\\Filament\\Pim\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->darkMode(false)
            ->topNavigation()
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            ->userMenuItems(FilamentPanelHelper::userMenuItems())
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
