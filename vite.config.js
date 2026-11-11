import { defineConfig } from 'vite'
import laravel, { refreshPaths } from 'laravel-vite-plugin'
import sassGlobImports from 'vite-plugin-sass-glob-import';
import path from 'path';

export default defineConfig({
    plugins: [
        sassGlobImports(),
        laravel({
            input: [
                'resources/scss/app.scss',
                'resources/js/app.js',
            ],
            refresh: [
                ...refreshPaths,
                'app/Filament/**',
                'app/Forms/Components/**',
                'app/Livewire/**',
                'app/Infolists/Components/**',
                'app/Providers/Filament/**',
                'app/Tables/Columns/**',
            ],
        }),
    ],
    server: {
        host: 'price-buddy.lndo.site',
        port: 3000,
        hmr: {
            host: 'price-buddy.lndo.site',
            protocol: 'ws',
            port: 3000
        }
    },
})
