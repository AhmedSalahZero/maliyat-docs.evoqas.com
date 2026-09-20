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
                    // ── HTML pages are DELIBERATELY NOT CACHED ─────────
                    //
                    // There used to be a NetworkFirst rule here caching
                    // every navigation into an 'inertia-pages' cache for
                    // 24 hours. It was a cross-tenant data leak, and it
                    // was reported from production as "I signed in as one
                    // company's admin and landed in another company".
                    //
                    // Every page this app serves carries its entire
                    // Inertia payload inline in the HTML (see the
                    // @inertia directive in resources/views/app.blade.php)
                    // — the signed-in user, their company, and all of
                    // that page's data. The Cache Storage API keys on the
                    // URL alone and knows nothing about who is signed in,
                    // so /app/dashboard was ONE entry shared by every
                    // account ever used on that device. NetworkFirst then
                    // serves that entry whenever the network is slow or
                    // briefly unreachable — handing one company's books
                    // to whoever is signed in at that moment. Nothing
                    // cleared it on logout, and the Cache Storage API
                    // ignores Cache-Control, so no response header could
                    // have prevented it either.
                    //
                    // For a multi-tenant bookkeeping app, offline page
                    // viewing is not worth that. Static assets below are
                    // safe to cache: they are identical for every tenant
                    // and contain no data.
                    //
                    // If offline support is ever wanted back, it cannot
                    // be done this way — it needs a cache partitioned per
                    // user that is cleared on logout. See
                    // resources/js/composables/usePrivateCache.js, which
                    // now clears these caches on sign-out.

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
