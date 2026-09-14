// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — tailwind.config.js
//
//  Extends Tailwind with Maliyat Docs' design system.
//  All colors map to CSS custom properties defined in app.css.
//  This gives us both Tailwind utility classes AND CSS var theming.
//
//  Palette: Blue (primary/trust) · Green (income/success) ·
//           Amber (pending/premium) · Cyan (secondary actions) ·
//           Red (expense/unpaid)
//  Fonts:   Inter (EN) | Cairo (AR)
// ══════════════════════════════════════════════════════════════════

import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],

    // ── Theme switching is handled via data-theme on <html>
    // Tailwind dark mode is NOT used — we use CSS custom properties instead
    // darkMode: false,

    theme: {
        extend: {

            // ── FONTS ──────────────────────────────────────────────────
            // Matches app.css: Baloo 2 for headings, IBM Plex Sans for body
            // copy, IBM Plex Mono for currency/numbers, Cairo for Arabic.
            fontFamily: {
                sans: ['IBM Plex Sans', ...defaultTheme.fontFamily.sans],
                heading: ['Baloo 2', 'Cairo', ...defaultTheme.fontFamily.sans],
                mono: ['IBM Plex Mono', 'Cairo', ...defaultTheme.fontFamily.mono],
                arabic: ['Cairo', 'IBM Plex Sans', ...defaultTheme.fontFamily.sans],
            },

            // ── COLORS — mapped to CSS custom properties ───────────────
            // These let you write: text-primary, bg-surface, border-border
            // The actual hex values live in app.css and swap on theme change
            colors: {
                // ── Backgrounds
                'bg':          'var(--color-bg)',
                'surface':     'var(--color-surface)',
                'surface-alt': 'var(--color-surface-alt)',

                // ── Brand
                'primary':       'var(--color-primary)',       // blue
                'primary-light': 'var(--color-primary-light)',
                'primary-dark':  'var(--color-primary-dark)',
                'primary-soft':  'var(--color-primary-soft)',

                // ── Header
                'header-bg':     'var(--color-header-bg)',
                'header-text':   'var(--color-header-text)',
                'header-accent': 'var(--color-header-accent)',
                'header-border': 'var(--color-header-border)',

                // ── Domain accents (Maliyat Docs)
                'accent-green':  'var(--color-accent-green)',   // income / paid / settled
                'accent-amber':  'var(--color-accent-amber)',   // pending / partial / custody
                'accent-cyan':   'var(--color-accent-cyan)',    // secondary actions, transfers
                'accent-red':    'var(--color-accent-red)',     // expense / unpaid

                // ── Semantic
                'success':      'var(--color-success)',
                'success-soft': 'var(--color-success-soft)',
                'danger':       'var(--color-danger)',
                'danger-soft':  'var(--color-danger-soft)',
                'warning':      'var(--color-warning)',
                'warning-soft': 'var(--color-warning-soft)',
                'info':         'var(--color-info)',
                'info-soft':    'var(--color-info-soft)',

                // ── Text
                'text-primary':   'var(--color-text-primary)',
                'text-secondary': 'var(--color-text-secondary)',
                'text-muted':     'var(--color-text-muted)',
                'text-inverse':   'var(--color-text-inverse)',
                'text-on-primary':'var(--color-text-on-primary)',

                // ── Borders
                'border':       'var(--color-border)',
                'border-light': 'var(--color-border-light)',
                'border-input': 'var(--color-border-input)',
                'border-focus': 'var(--color-border-focus)',

                // ── Nav
                'nav-bg':           'var(--color-nav-bg)',
                'nav-icon':         'var(--color-nav-icon)',
                'nav-icon-active':  'var(--color-nav-icon-active)',
                'nav-label':        'var(--color-nav-label)',
                'nav-label-active': 'var(--color-nav-label-active)',

                // ── Raw brand values (used in theme picker preview — no CSS var)
                'brand-blue':    '#2D6CDF',
                'brand-green':   '#16A34A',
                'brand-amber':   '#F59E0B',
                'brand-cyan':    '#06AED4',
                'brand-red':     '#E23744',
            },

            // ── SPACING ────────────────────────────────────────────────
            spacing: {
                '1':  '4px',
                '2':  '8px',
                '3':  '12px',
                '4':  '16px',
                '5':  '20px',
                '6':  '24px',
                '8':  '32px',
                '10': '40px',
                '12': '48px',
                '14': '56px',
                '16': '64px',
                '20': '80px',
                '24': '96px',
            },

            // ── BORDER RADIUS ──────────────────────────────────────────
            borderRadius: {
                'sm':   '6px',
                'md':   '10px',
                'lg':   '14px',
                'xl':   '18px',
                '2xl':  '24px',
                'pill': '999px',
            },

            // ── FONT SIZES ─────────────────────────────────────────────
            fontSize: {
                'xs':   ['11px', { lineHeight: '1.4' }],
                'sm':   ['12px', { lineHeight: '1.5' }],
                'base': ['14px', { lineHeight: '1.6' }],
                'md':   ['16px', { lineHeight: '1.5' }],
                'lg':   ['18px', { lineHeight: '1.4' }],
                'xl':   ['22px', { lineHeight: '1.3' }],
                '2xl':  ['28px', { lineHeight: '1.2' }],
                '3xl':  ['36px', { lineHeight: '1.1' }],
                '4xl':  ['48px', { lineHeight: '1.0' }],
            },

            // ── FONT WEIGHTS ───────────────────────────────────────────
            fontWeight: {
                regular:  '400',
                medium:   '500',
                semibold: '600',
                bold:     '700',
            },

            // ── SHADOWS ────────────────────────────────────────────────
            boxShadow: {
                'card':   'var(--shadow-card)',
                'button': 'var(--shadow-button)',
                'float':  'var(--shadow-float)',
                'header': 'var(--shadow-header)',
            },

            // ── Z-INDEX ────────────────────────────────────────────────
            zIndex: {
                'base':     '1',
                'dropdown': '100',
                'modal':    '200',
                'toast':    '300',
                'top':      '400',
            },

            // ── MIN HEIGHT ─────────────────────────────────────────────
            minHeight: {
                'touch': '44px',  // minimum touch target
                'screen': '100vh',
            },

            // ── MAX WIDTH ──────────────────────────────────────────────
            maxWidth: {
                'mobile': '480px',
                'tablet': '768px',
                'wide':   '1024px',
            },

            // ── TRANSITIONS ────────────────────────────────────────────
            transitionDuration: {
                'fast': '150ms',
                'base': '250ms',
                'slow': '400ms',
            },

            // ── SCREENS ────────────────────────────────────────────────
            // Mobile-first breakpoints matching app.css media queries
            screens: {
                'sm':  '640px',   // tablet portrait
                'md':  '768px',   // tablet landscape — nav switches to sidebar
                'lg':  '1024px',  // desktop
                'xl':  '1280px',  // wide desktop
            },
        },
    },

    plugins: [],
};
