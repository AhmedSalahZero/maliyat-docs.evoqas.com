import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';
import { copyFileSync, existsSync } from 'fs';
import { resolve } from 'path';
import { fileURLToPath } from 'url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Vite Configuration
//
//  Builds the frontend and configures the PWA.
//  The PWA makes Maliyat Docs installable on any mobile phone
//  without needing the App Store or Google Play.
// ══════════════════════════════════════════════════════════════════

/** Workbox precaches /manifest.webmanifest — copy from build output to public root */
function copyManifestToPublicRoot() {
    return {
        name: 'copy-manifest-to-public-root',
        closeBundle() {
            const built = resolve(__dirname, 'public/build/manifest.webmanifest');
            const root  = resolve(__dirname, 'public/manifest.webmanifest');
            if (existsSync(built)) {
                copyFileSync(built, root);
            }
        },
    };
}

export default defineConfig({
    // ── Path alias ───────────────────────────────────────────────
    // Every Vue file in this project imports via "@/..." (e.g.
    // "@/Stores/useAuthStore", "@/Components/..."). This alias was
    // referenced everywhere but never actually declared, so the
    // build could not have resolved any of those imports. Declared
    // here once, resolving to resources/js — matches jsconfig.json.
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },

    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),

        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),

        VitePWA({
            registerType: 'autoUpdate',
            strategies: 'generateSW',
            injectRegister: false,
            scope: '/',
            base: '/',
            manifestFilename: 'manifest.webmanifest',

            includeAssets: [
                'favicon.ico',
                'images/icons/icon-192.png',
                'images/icons/icon-512.png',
                'images/icons/icon-maskable-512.png',
            ],

            manifest: {
                // ── Identity ───────────────────────────────────────────
                id:          '/',
                name:        'Maliyat Docs',
                short_name:  'Maliyat',
                description: 'Talk your books. Simple bilingual bookkeeping for freelancers and micro businesses.',

                // ── Display ────────────────────────────────────────────
                theme_color:      '#2D6CDF',   // Maliyat Docs blue
                background_color: '#FFFFFF',
                display:          'standalone',
                orientation:      'portrait',
                lang:             'en',
                dir:              'ltr',

                // ── Start ──────────────────────────────────────────────
                start_url: '/?source=pwa',
                scope:     '/',

                prefer_related_applications: false,

                // ── Icons ──────────────────────────────────────────────
                icons: [
                    {
                        src:     '/images/icons/icon-192.png',
                        sizes:   '192x192',
                        type:    'image/png',
                        purpose: 'any',
                    },
                    {
                        src:     '/images/icons/icon-512.png',
                        sizes:   '512x512',
                        type:    'image/png',
                        purpose: 'any',
                    },
                    {
                        src:     '/images/icons/icon-maskable-512.png',
                        sizes:   '512x512',
                        type:    'image/png',
                        purpose: 'maskable',
                    },
                ],

                // ── Screenshots ────────────────────────────────────────
                screenshots: [
                    {
                        src:         '/images/screenshots/screen-mobile.png',
                        sizes:       '540x720',
                        type:        'image/png',
                        form_factor: 'narrow',
                        label:       'Maliyat Docs — Sign in',
                    },
                ],
            },

            // ── Workbox — caching strategy ─────────────────────────────
            workbox: {
                navigateFallback: null,
                globDirectory: resolve(__dirname, 'public/build'),
                globPatterns:  ['**/*.{js,css,woff2}'],
                globIgnores:   ['**/manifest.webmanifest'],
                swDest: resolve(__dirname, 'public/sw.js'),
                modifyURLPrefix: {
                    '': '/build/',
                },
                runtimeCaching: [
                    // ── HTML pages — network first ──────────────────────
                    // Always try to get fresh page from server
                    // Fall back to cache if offline
                    {
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler:    'NetworkFirst',
                        options: {
                            cacheName:  'inertia-pages',
                            expiration: {
                                maxEntries:    50,
                                maxAgeSeconds: 60 * 60 * 24,  // 1 day
                            },
                        },
                    },
                    // ── Images — cache first ────────────────────────────
                    // Images don't change often — serve from cache
                    {
                        urlPattern: /\/images\/.+\.(jpg|png|webp|svg)$/,
                        handler:    'CacheFirst',
                        options: {
                            cacheName:  'images-cache',
                            expiration: {
                                maxEntries:    50,
                                maxAgeSeconds: 60 * 60 * 24 * 30,  // 30 days
                            },
                        },
                    },
                    // ── Fonts — cache forever ───────────────────────────
                    {
                        urlPattern: /\.(woff|woff2|ttf|eot)$/,
                        handler:    'CacheFirst',
                        options: {
                            cacheName:  'fonts-cache',
                            expiration: {
                                maxEntries:    10,
                                maxAgeSeconds: 60 * 60 * 24 * 365,  // 1 year
                            },
                        },
                    },
                ],
            },
        }),

        copyManifestToPublicRoot(),
    ],
});
