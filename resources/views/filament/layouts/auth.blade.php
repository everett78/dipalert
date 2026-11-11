@php
    use Filament\Support\Enums\MaxWidth;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">

    @props([
        'after' => null,
        'heading' => null,
        'subheading' => null,
    ])

    <div class="auth-page h-full flex self-stretch w-full justify-stretch">

        <div
            class="auth-frame fi-simple-main-ctn md:w-1/2 max-w-xl flex-1 bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 md:fixed md:bottom-0 top-0 flex justify-center items-center z-50"
        >
            <main
                @class([
                    'fi-simple-main w-full sm:rounded-xl md:max-w-lg p-2 md:p-8',
                    match ($maxWidth ??= (filament()->getSimplePageMaxContentWidth() ?? MaxWidth::Large)) {
                        MaxWidth::ExtraSmall, 'xs' => 'max-w-xs',
                        MaxWidth::Small, 'sm' => 'max-w-sm',
                        MaxWidth::Medium, 'md' => 'max-w-md',
                        MaxWidth::Large, 'lg' => 'max-w-lg',
                        MaxWidth::ExtraLarge, 'xl' => 'max-w-xl',
                        MaxWidth::TwoExtraLarge, '2xl' => 'max-w-2xl',
                        MaxWidth::ThreeExtraLarge, '3xl' => 'max-w-3xl',
                        MaxWidth::FourExtraLarge, '4xl' => 'max-w-4xl',
                        MaxWidth::FiveExtraLarge, '5xl' => 'max-w-5xl',
                        MaxWidth::SixExtraLarge, '6xl' => 'max-w-6xl',
                        MaxWidth::SevenExtraLarge, '7xl' => 'max-w-7xl',
                        MaxWidth::Full, 'full' => 'max-w-full',
                        MaxWidth::MinContent, 'min' => 'max-w-min',
                        MaxWidth::MaxContent, 'max' => 'max-w-max',
                        MaxWidth::FitContent, 'fit' => 'max-w-fit',
                        MaxWidth::Prose, 'prose' => 'max-w-prose',
                        MaxWidth::ScreenSmall, 'screen-sm' => 'max-w-screen-sm',
                        MaxWidth::ScreenMedium, 'screen-md' => 'max-w-screen-md',
                        MaxWidth::ScreenLarge, 'screen-lg' => 'max-w-screen-lg',
                        MaxWidth::ScreenExtraLarge, 'screen-xl' => 'max-w-screen-xl',
                        MaxWidth::ScreenTwoExtraLarge, 'screen-2xl' => 'max-w-screen-2xl',
                        default => $maxWidth,
                    },
                ])
            >
                <div class="p-0 md:p-8">
                    {{ $slot }}
                </div>

            </main>
        </div>

        <div class="auth-bg hidden md:block fixed inset-0 z-10 bg-right bg-no-repeat bg-w bg-cover" style="background-image: url('/images/auth-bg.jpg')">
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $livewire->getRenderHookScopes()) }}
    </div>
</x-filament-panels::layout.base>
