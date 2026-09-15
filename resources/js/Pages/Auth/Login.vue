<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Login.vue
//  Location: resources/js/Pages/Auth/Login.vue
//
//  Layout:
//    Mobile  → full-screen background photo + dark overlay,
//              form card floats over it
//    Desktop → two columns: photo left (60%) | form right (40%)
//
//  Wired to Laravel Breeze's existing auth routes.
//  No Socialite — standard email/password only.
// ══════════════════════════════════════════════════════════════════

// 1. Imports
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useAuthStore }             from '@/Stores/useAuthStore';
import { useAuthTranslations }      from '@/Composables/useAuthTranslations';
import PasswordInput                from '@/Components/PasswordInput.vue';

// 2. No layout — this page manages its own full-screen shell
//    so the background photo can fill the entire viewport

// 3. Props from Laravel Breeze
const props = defineProps({
    canResetPassword: {
        type: Boolean,
        default: true,
    },
    status: {
        type: String,
        default: null,
    },
});

// 4. Store
const authStore = useAuthStore();
const { t } = useAuthTranslations();

// 5. Reactive state (nothing extra needed here right now)

// 6. Inertia form
const form = useForm({
    email:    '',
    password: '',
    remember: false,
    locale:   authStore.locale,
});

// 7. Computed
const isDarkTheme = computed(() => authStore.theme === 'dark'); // driven only by our own reactive state — never a stale DOM read
const isRtl   = computed(() => authStore.isRtl);
const locale  = computed(() => authStore.locale);

const verificationMessage = computed(() => {
    if (!form.errors.needs_verification) {
        return '';
    }

    return form.errors.verification_code_sent === '1'
        ? t('verification_required_code_sent')
        : t('verification_required');
});

// 8. Methods
function toggleTheme() {
    const next = authStore.theme === 'light' ? 'dark' : 'light';
    authStore.setThemeLocal(next);
    localStorage.setItem('ip_theme', next);
}

function toggleLocale() {
    const next = locale.value === 'en' ? 'ar' : 'en';
    authStore.setLocaleLocal(next);
    router.post(route('guest.locale'), { locale: next });
}

function submit() {
    form.locale = authStore.locale;
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}

// 9. Lifecycle
onMounted(() => {
    authStore.setThemeLocal(localStorage.getItem('ip_theme') ?? 'light');
    authStore.setLocaleLocal(localStorage.getItem('ip_locale') ?? 'en');
});
</script>

<template>
    <Head :title="locale === 'ar' ? 'تسجيل الدخول - ماليات دوكس' : 'Sign In - Maliyat Docs'" />

    <div class="ip-login" :data-theme="authStore.theme" :dir="isRtl ? 'rtl' : 'ltr'">

        <!-- ═══════════════════════════════════════════════════════
             BACKGROUND PHOTO — fills entire page
        ════════════════════════════════════════════════════════════ -->
        <div class="ip-login__bg">
            <!-- Dark overlay so text is readable -->
            <div class="ip-login__bg-overlay" />
        </div>

        <!-- ═══════════════════════════════════════════════════════
             CONTROLS — top right (theme + locale)
        ════════════════════════════════════════════════════════════ -->
        <div class="ip-login__controls">
            <button class="ip-login__ctrl-btn" @click="toggleLocale" :title="locale === 'en' ? 'العربية' : 'English'">
                <span class="ip-login__ctrl-label" lang="ar" dir="ltr">{{ locale === 'en' ? 'ع' : 'EN' }}</span>
            </button>
            <button class="ip-login__ctrl-btn" @click="toggleTheme" :title="isDarkTheme ? (locale === 'ar' ? 'الوضع الفاتح' : 'Light theme') : (locale === 'ar' ? 'الوضع الداكن' : 'Dark theme')">
                <!-- Dark mode active → show a sun (tap to switch to light) -->
                <svg v-if="isDarkTheme" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="4.5"/><line x1="12" y1="2" x2="12" y2="4.5"/><line x1="12" y1="19.5" x2="12" y2="22"/><line x1="4.22" y1="4.22" x2="5.9" y2="5.9"/><line x1="18.1" y1="18.1" x2="19.78" y2="19.78"/><line x1="2" y1="12" x2="4.5" y2="12"/><line x1="19.5" y1="12" x2="22" y2="12"/><line x1="4.22" y1="19.78" x2="5.9" y2="18.1"/><line x1="18.1" y1="5.9" x2="19.78" y2="4.22"/>
                </svg>
                <!-- Light mode active → show a moon (tap to switch to dark) -->
                <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/>
                </svg>
            </button>
        </div>

        <!-- ═══════════════════════════════════════════════════════
             LEFT PANEL — brand + tagline (desktop only)
        ════════════════════════════════════════════════════════════ -->
        <div class="ip-login__left">

            <!-- Brand — logo-light for dark photo background -->
            <div class="ip-login__brand">
                <img
                    src="/images/logo-light.png"
                    alt="Maliyat Docs"
                    class="ip-login__brand-img"
                />
            </div>

            <!-- Headline -->
            <div class="ip-login__hero">
                <p class="ip-login__hero-label">
                    {{ locale === 'ar' ? 'محاسبة · بسيطة · لأصحاب الأعمال' : 'BOOKKEEPING · MADE SIMPLE' }}
                </p>
                <h1 class="ip-login__hero-title">
                    {{ locale === 'ar' ? 'حساباتك، منظّمة ببساطة.' : 'Your books, sorted in minutes.' }}
                </h1>
                <p class="ip-login__hero-sub">
                    {{ locale === 'ar'
                        ? 'ماليات دوكس يساعدك على تسجيل المبيعات والمصروفات والمخزون بلغة بسيطة — بدون خبرة محاسبية.'
                        : 'Maliyat Docs helps micro-business owners record sales, expenses, and inventory in plain language — no accounting background needed.' }}
                </p>
                <p class="ip-login__hero-badge">
                    {{ locale === 'ar' ? '٦٠ يوماً مجاناً — بدون بطاقة ائتمان' : '60 days free — no card required' }}
                </p>
            </div>

            <!-- Feature cards — what the product actually does -->
            <div class="ip-login__feature-grid">

                <div class="ip-login__feature-card">
                    <div class="ip-login__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                    </div>
                    <div>
                        <p class="ip-login__feature-name">{{ locale === 'ar' ? 'المبيعات والفواتير' : 'Sales & Invoices' }}</p>
                        <p class="ip-login__feature-desc">{{ locale === 'ar' ? 'سجّل كل عملية بيع في ثوانٍ' : 'Record every sale in seconds' }}</p>
                    </div>
                </div>

                <div class="ip-login__feature-card">
                    <div class="ip-login__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="12" height="18" rx="1.5"/><line x1="8" y1="8" x2="14" y2="8"/><line x1="8" y1="12" x2="14" y2="12"/><line x1="8" y1="16" x2="12" y2="16"/></svg>
                    </div>
                    <div>
                        <p class="ip-login__feature-name">{{ locale === 'ar' ? 'المصروفات والمستحقات' : 'Expenses & Bills' }}</p>
                        <p class="ip-login__feature-desc">{{ locale === 'ar' ? 'تابع ما عليك وما دفعته' : 'Track what you owe and pay' }}</p>
                    </div>
                </div>

                <div class="ip-login__feature-card">
                    <div class="ip-login__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l7-4 7 4-7 4-7-4z"/><path d="M3 7v9l7 4 7-4V7"/><line x1="10" y1="11" x2="10" y2="20"/></svg>
                    </div>
                    <div>
                        <p class="ip-login__feature-name">{{ locale === 'ar' ? 'المخزون' : 'Inventory' }}</p>
                        <p class="ip-login__feature-desc">{{ locale === 'ar' ? 'اعرف رصيدك بسهولة' : 'Know your stock at a glance' }}</p>
                    </div>
                </div>

                <div class="ip-login__feature-card">
                    <div class="ip-login__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="19" x2="4" y2="12" stroke-width="3"/><line x1="9" y1="19" x2="9" y2="6" stroke-width="3"/><line x1="14" y1="19" x2="14" y2="14" stroke-width="3"/><line x1="19" y1="19" x2="19" y2="3" stroke-width="3"/></svg>
                    </div>
                    <div>
                        <p class="ip-login__feature-name">{{ locale === 'ar' ? 'التقارير' : 'Reports' }}</p>
                        <p class="ip-login__feature-desc">{{ locale === 'ar' ? 'أرباح وخسائر محدّثة دائماً' : 'Profit & loss, always up to date' }}</p>
                    </div>
                </div>

                <div class="ip-login__feature-card">
                    <div class="ip-login__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="16" height="11" rx="2"/><path d="M13 11.5h3"/><path d="M2 9.5h16"/></svg>
                    </div>
                    <div>
                        <p class="ip-login__feature-name">{{ locale === 'ar' ? 'العُهد' : 'Custody' }}</p>
                        <p class="ip-login__feature-desc">{{ locale === 'ar' ? 'تابع العُهد المالية للموظفين' : 'Track cash given to employees' }}</p>
                    </div>
                </div>

                <div class="ip-login__feature-card">
                    <div class="ip-login__feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div>
                        <p class="ip-login__feature-name">{{ locale === 'ar' ? 'فريقك بالكامل' : 'Your Whole Team' }}</p>
                        <p class="ip-login__feature-desc">{{ locale === 'ar' ? 'دفتر واحد مشترك للجميع' : 'One shared ledger for everyone' }}</p>
                    </div>
                </div>

            </div>

            <!-- Bottom tagline -->
            <p class="ip-login__tagline">{{ locale === 'ar' ? 'محاسبة، ببساطة.' : 'Bookkeeping, simplified.' }}</p>

        </div>

        <!-- ═══════════════════════════════════════════════════════
             RIGHT PANEL — the actual login form
        ════════════════════════════════════════════════════════════ -->
        <div class="ip-login__right">
            <div class="ip-login__card">

                <!-- Mobile brand — logo-dark on white card background -->
                <div class="ip-login__mobile-brand">
                    <img
                        src="/images/logo-dark.png"
                        alt="Maliyat Docs"
                        class="ip-login__mobile-brand-img"
                    />
                </div>

                <!-- Card heading -->
                <div class="ip-login__card-header">
                    <h2 class="ip-login__card-title">
                        {{ locale === 'ar' ? 'أهلاً بعودتك!' : 'Welcome Back!' }}
                    </h2>
                    <p class="ip-login__card-sub">
                        {{ locale === 'ar' ? 'سجّل دخولك للمتابعة' : 'Sign in to continue' }}
                    </p>
                </div>

                <!-- Status message (password reset success etc.) -->
                <div v-if="status" class="ip-login__status">
                    {{ status }}
                </div>

                <!-- ── FORM ─────────────────────────────────── -->
                <form @submit.prevent="submit" class="ip-login__form" novalidate>

                    <!-- Email -->
                    <div class="ip-form-group">
                        <label class="ip-login__label" for="email">
                            {{ locale === 'ar' ? 'البريد الإلكتروني' : 'Email Address' }}
                        </label>
                        <div class="ip-login__input-wrap">
                            <svg class="ip-login__input-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                            </svg>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="ip-login__input"
                                :class="{ 'ip-login__input--error': form.errors.email }"
                                :placeholder="locale === 'ar' ? 'بريدك الإلكتروني' : 'your@email.com'"
                                autocomplete="username"
                                required
                                autofocus
                            />
                        </div>
                        <p v-if="form.errors.email && !form.errors.needs_verification" class="ip-login__error">{{ form.errors.email }}</p>
                        <div
                            v-if="form.errors.needs_verification"
                            class="ip-login__status ip-login__status--warn"
                        >
                            <p>{{ verificationMessage }}</p>
                            <Link
                                :href="route('verification.notice', { email: form.email })"
                                class="ip-login__forgot"
                                style="display:inline-block;margin-top:8px;"
                            >
                                {{ t('go_to_verification') }}
                            </Link>
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="ip-form-group">
                        <label class="ip-login__label" for="password">
                            {{ locale === 'ar' ? 'كلمة المرور' : 'Password' }}
                        </label>
                        <PasswordInput
                            id="password"
                            v-model="form.password"
                            :has-error="!!form.errors.password"
                            :placeholder="locale === 'ar' ? 'كلمة المرور' : 'Enter password'"
                            autocomplete="current-password"
                            required
                        />
                        <p v-if="form.errors.password" class="ip-login__error">{{ form.errors.password }}</p>
                    </div>

                    <!-- Remember me + Forgot password -->
                    <div class="ip-login__row">
                        <label class="ip-login__remember">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="ip-login__checkbox"
                            />
                            <span>{{ locale === 'ar' ? 'تذكّرني' : 'Remember me' }}</span>
                        </label>

                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="ip-login__forgot"
                        >
                            {{ locale === 'ar' ? 'نسيت كلمة المرور؟' : 'Forgot password?' }}
                        </Link>
                    </div>

                    <!-- Submit button -->
                    <button
                        type="submit"
                        class="ip-btn ip-btn--primary ip-btn--full ip-login__submit"
                        :disabled="form.processing"
                    >
                        <svg v-if="form.processing" class="ip-login__spinner" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>
                        </svg>
                        <span>{{ locale === 'ar' ? 'تسجيل الدخول' : 'Sign In' }}</span>
                    </button>

                </form>

                <!-- Divider -->
                <div class="ip-login__divider">
                    <span>{{ locale === 'ar' ? 'ليس لديك حساب؟' : "Don't have an account?" }}</span>
                </div>

                <!-- Register link -->
                <Link :href="route('register')" class="ip-btn ip-btn--outline ip-btn--full">
                    {{ locale === 'ar' ? 'أنشئ حساب شركتك مجاناً' : 'Create your company account' }}
                </Link>

            </div>
        </div>

    </div>
</template>

<style scoped>
/* ══════════════════════════════════════════════════════════════
   LOGIN PAGE — full-screen, two-column on desktop
══════════════════════════════════════════════════════════════ */

/* ── Root shell ──────────────────────────────────────────────── */
.ip-login {
    position: relative;
    /* 100vh on mobile browsers measures the viewport WITHOUT the
       collapsing URL bar, so the shell ends up taller than what's
       actually on screen and the document itself scrolls — the
       overflow:hidden below only clips children, it can't stop that.
       100dvh tracks the visible viewport instead; the 100vh line
       stays first as the fallback for browsers without dvh. */
    min-height: 100vh;
    min-height: 100dvh;
    width: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Background photo ────────────────────────────────────────── */
.ip-login__bg {
    position: fixed;
    inset: 0;
    z-index: 0;
    background-image: url('/images/login-bg.jpg');
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
    background-color: #0F2044;

}

.ip-login__bg-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        105deg,
        rgba(15, 32, 68, 0.91) 0%,
        rgba(15, 32, 68, 0.82) 40%,
        rgba(15, 32, 68, 0.55) 65%,
        rgba(15, 32, 68, 0.30) 100%
    );
}

/* Dark theme overlay is deeper */
[data-theme="dark"] .ip-login__bg-overlay {
    background: linear-gradient(
        105deg,
        rgba(20, 29, 43, 0.93) 0%,
        rgba(20, 29, 43, 0.85) 40%,
        rgba(20, 29, 43, 0.58) 65%,
        rgba(20, 29, 43, 0.32) 100%
    );
}

/* ── Top-right controls ──────────────────────────────────────── */
.ip-login__controls {
    position: fixed;
    top: var(--space-4);
    right: var(--space-4);
    z-index: 10;
    display: flex;
    gap: var(--space-2);
}

.ip-login[dir="rtl"] .ip-login__controls {
    right: auto;
    left: var(--space-4);
}

.ip-login__ctrl-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-md);
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.20);
    color: #FFFFFF;
    cursor: pointer;
    transition: background var(--transition-fast);
    backdrop-filter: blur(8px);
}

.ip-login__ctrl-btn:hover {
    background: rgba(255, 255, 255, 0.22);
}

.ip-login__ctrl-label {
    font-family: 'Cairo', 'IBM Plex Sans', sans-serif; /* always has Arabic glyphs, regardless of current locale/theme fonts */
    font-size: 14px;
    font-weight: var(--fw-bold);
    line-height: 1;
}

/* ── Left panel — desktop only ───────────────────────────────── */
.ip-login__left {
    display: none; /* hidden on mobile */
}

/* ── Right panel — the form ──────────────────────────────────── */
.ip-login__right {
    position: relative;
    z-index: 1;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-6) var(--space-4);
    /* On mobile add safe area at top for notch */
    padding-top: max(var(--space-12), env(safe-area-inset-top));
    padding-bottom: max(var(--space-6), env(safe-area-inset-bottom));
    
}

/* ── Login card ──────────────────────────────────────────────── */
.ip-login__card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: var(--space-6);
    width: 100%;
    max-width: 400px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(10px);
}

[data-theme="dark"] .ip-login__card {
    background: rgba(27, 37, 55, 0.95);
    border-color: rgba(45, 62, 87, 0.80);
}

/* ── Mobile brand (shows inside card on mobile) ──────────────── */
.ip-login__mobile-brand {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-bottom: var(--space-5);
    justify-content: center;
}

/* ── Brand logo — desktop left panel ─────────────────────────── */
.ip-login__brand {
    display: flex;
    align-items: flex-start;
    
}

.ip-login__brand-img {
    /* Scales with the window rather than sitting at a fixed 150px.
       The logo was the single largest block in the panel, so on a
       short screen it alone decided whether anything scrolled. */
    height: clamp(200px, 15vh, 150px);
    width: auto;
    object-fit: contain;
    margin-bottom: 0px;
    filter: drop-shadow(0 2px 8px rgba(0,0,0,0.25));
}

/* ── Mobile brand inside card ────────────────────────────────── */
.ip-login__mobile-brand {
    display: flex;
    justify-content: center;
    margin-bottom: var(--space-5);
}

.ip-login__mobile-brand-img {
    height: 150px;
    width: auto;
    object-fit: contain;
}

/* ── Card header ─────────────────────────────────────────────── */
.ip-login__card-header {
    margin-bottom: var(--space-5);
    text-align: center;
}

.ip-login__card-title {
    font-size: var(--text-xl);
    font-weight: var(--fw-bold);
    color: var(--color-text-primary);
    margin-bottom: var(--space-1);
    letter-spacing: -0.01em;
}

.ip-login__card-sub {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.ip-login__about-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: var(--space-3);
    padding: 0;
    border: none;
    background: none;
    font-size: var(--text-sm);
    font-weight: var(--fw-semibold);
    color: var(--color-primary);
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 3px;
    transition: opacity var(--transition-fast);
}

.ip-login__about-trigger:hover {
    opacity: 0.8;
}

.ip-login__about-trigger i {
    font-size: 1rem;
    text-decoration: none;
}

/* ── Status bar ──────────────────────────────────────────────── */
.ip-login__status {
    background: var(--color-success-soft);
    color: var(--color-success);
    border-radius: var(--radius-md);
    padding: var(--space-3) var(--space-4);
    font-size: var(--text-sm);
    font-weight: var(--fw-medium);
    margin-bottom: var(--space-4);
    text-align: center;
}

/* ── Form ────────────────────────────────────────────────────── */
.ip-login__form {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
}

/* ── Label ───────────────────────────────────────────────────── */
.ip-login__label {
    display: block;
    font-size: var(--text-xs);
    font-weight: var(--fw-semibold);
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--color-text-muted);
    margin-bottom: var(--space-2);
}

/* ── Input wrapper ───────────────────────────────────────────── */
.ip-login__input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.ip-login__input-icon {
    position: absolute;
    left: var(--space-3);
    color: var(--color-text-muted);
    pointer-events: none;
    flex-shrink: 0;
}

.ip-login[dir="rtl"] .ip-login__input-icon {
    left: auto;
    right: var(--space-3);
}

/* ── Input ───────────────────────────────────────────────────── */
.ip-login__input {
    width: 100%;
    min-height: var(--touch-target);
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border-input);
    border-radius: var(--radius-md);
    padding: 0 var(--space-4) 0 calc(var(--space-3) + 16px + var(--space-3));
    font-family: inherit;
    font-size: var(--text-base);
    color: var(--color-text-primary);
    outline: none;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.ip-login[dir="rtl"] .ip-login__input {
    padding: 0 calc(var(--space-3) + 16px + var(--space-3)) 0 var(--space-4);
}

.ip-login__input--with-toggle {
    padding-right: calc(var(--space-3) + 16px + var(--space-3));
}

.ip-login[dir="rtl"] .ip-login__input--with-toggle {
    padding-right: calc(var(--space-3) + 16px + var(--space-3));
    padding-left: calc(var(--space-3) + 16px + var(--space-3));
}

.ip-login__input:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}

.ip-login__input--error {
    border-color: var(--color-danger);
}

.ip-login__input--error:focus {
    box-shadow: 0 0 0 3px var(--color-danger-soft);
}

.ip-login__input::placeholder {
    color: var(--color-text-muted);
}

/* ── Password toggle ─────────────────────────────────────────── */
.ip-login__toggle-pw {
    position: absolute;
    right: var(--space-3);
    color: var(--color-text-muted);
    background: none;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    padding: 4px;
    border-radius: var(--radius-sm);
    transition: color var(--transition-fast);
}

.ip-login[dir="rtl"] .ip-login__toggle-pw {
    right: auto;
    left: var(--space-3);
}

.ip-login__toggle-pw:hover {
    color: var(--color-text-primary);
}

/* ── Error text ──────────────────────────────────────────────── */
.ip-login__error {
    font-size: var(--text-xs);
    color: var(--color-danger);
    margin-top: var(--space-1);
}

/* ── Remember + forgot row ───────────────────────────────────── */
.ip-login__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: var(--space-2) 0 var(--space-4);
    gap: var(--space-3);
}

.ip-login__remember {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    cursor: pointer;
}

.ip-login__checkbox {
    width: 16px;
    height: 16px;
    accent-color: var(--color-primary);
    cursor: pointer;
    flex-shrink: 0;
}

.ip-login__forgot {
    font-size: var(--text-sm);
    color: var(--color-primary);
    font-weight: var(--fw-medium);
    text-decoration: none;
    white-space: nowrap;
    transition: opacity var(--transition-fast);
}

.ip-login__forgot:hover {
    opacity: 0.75;
}

/* ── Submit button ───────────────────────────────────────────── */
.ip-login__submit {
    font-size: var(--text-md);
    letter-spacing: 0.02em;
    gap: var(--space-2);
}

/* ── Spinner ─────────────────────────────────────────────────── */
.ip-login__spinner {
    animation: ip-spin 0.8s linear infinite;
}

@keyframes ip-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

/* ── Divider ─────────────────────────────────────────────────── */
.ip-login__divider {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin: var(--space-5) 0 var(--space-4);
}

.ip-login__divider::before,
.ip-login__divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--color-border);
}

.ip-login__divider span {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    white-space: nowrap;
}

/* ══════════════════════════════════════════════════════════════
   DESKTOP LAYOUT — two columns at 768px+
══════════════════════════════════════════════════════════════ */
@media (min-width: 768px) {

    /* Switch to row layout, locked to the viewport height — this is
       what keeps the login card vertically centered on the visible
       screen. Without a hard height here, a tall left-panel (lots of
       feature cards) would stretch the whole page past 100vh, and
       "centered" would mean centered in that taller page, not in
       what's actually on screen. */
    .ip-login {
        flex-direction: row;
        height: 100vh;
        height: 100dvh;
        overflow: hidden;
    }

    /* Show the left panel — scrolls internally if its content is
       ever taller than the viewport, rather than pushing the page. */
    .ip-login__left {
        display: flex;
        flex-direction: column;
        justify-content: center;
        /* Fluid rhythm: on a tall screen this is the full gap, on a
           short one it closes up instead of forcing a scrollbar. */
        gap: clamp(10px, 2.2vh, 24px);
        position: relative;
        z-index: 1;
        flex: 0 0 55%;
        height: 100%;
        /* Kept as a floor, not as the plan. Everything above is sized
           so the content fits; this only catches a genuinely tiny
           window, where clipping the panel would lose content. */
        overflow-y: auto;
        padding: clamp(16px, 3vh, 32px) var(--space-10);
    }

    /* Right panel becomes fixed width */
    .ip-login__right {
        flex: 0 0 45%;
        height: 100%;
        overflow-y: auto;
        background: transparent;
        backdrop-filter: none;
        border-left: none;
        padding: var(--space-8) var(--space-8);
    }

    [data-theme="dark"] .ip-login__right {
        background: transparent;
    }

    /* Hide mobile brand inside card */
    .ip-login__mobile-brand {
        display: none;
    }

    /* Card on desktop — no card shadow needed, panel provides depth */
    .ip-login__card {
        background: var(--color-surface);
        max-width: 380px;
        box-shadow: var(--shadow-float);
    }
}

/* Shorter laptop screens. This used to cut off at 760px, so a
   768px-tall window — one of the most common laptop sizes there is —
   missed it by eight pixels and scrolled. The sizes above are fluid
   now, so this only has to trim the things that don't scale: line
   heights and card padding. */
@media (min-width: 768px) and (max-height: 860px) {
    .ip-login__hero-sub {
        line-height: 1.5;
    }

    .ip-login__feature-card {
        padding: 9px 10px 8px;
    }

}

/* Only on a genuinely short window does the card caption go. Most
   laptops are 768–900 tall and keep it — dropping the descriptions
   across that whole range would strip the panel of most of what it
   actually says. */
@media (min-width: 768px) and (max-height: 660px) {
    .ip-login__feature-desc {
        display: none;
    }

    .ip-login__hero-sub {
        display: none;
    }
}

/* ── Brand block ─────────────────────────────────────────────── */
.ip-login__brand {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

/* ── Hero text ───────────────────────────────────────────────── */
.ip-login__hero {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.ip-login__hero-label {
    font-size: 13px;
    font-weight: var(--fw-semibold);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #6FA3F5;
    margin-bottom: var(--space-4);
    margin-top: 0px;
}

.ip-login__hero-title {
    font-size: clamp(30px, 4.4vh, 46px);
    font-weight: var(--fw-bold);
    color: #FFFFFF;
    line-height: 1.02;
    letter-spacing: -0.03em;
    margin-bottom: var(--space-4);
}

.ip-login__hero-sub {
    font-size: var(--text-md);
    color: rgba(255, 255, 255, 0.72);
    line-height: 1.7;
    max-width: 440px;
}

.ip-login__hero-badge {
    display: inline-flex;
    align-items: center;
    margin-top: var(--space-4);
    padding: 0px 6px;
    border-radius: 999px;
    background: rgba(45, 108, 223, 0.18);
    border: 1px solid rgba(111, 163, 245, 0.45);
    color: #BBD3FA;
    font-size: 12.5px;
    font-weight: var(--fw-semibold);
    width: fit-content;
}

/* ── Feature card grid ───────────────────────────────────────── */
.ip-login__feature-grid {
    display: grid;
    /* auto-fit, not a fixed 3, because the column COUNT is what
       decides the panel's height: squeeze three cards into a narrow
       panel and every caption wraps to three lines, which is what
       pushed the panel past the viewport at 1024px wide. At a 200px
       floor this settles on three columns when there is room and two
       when there isn't. */
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
}

.ip-login__feature-card {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px 12px 10px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.13);
    background: transparent;
    backdrop-filter: blur(1px);
    -webkit-backdrop-filter: blur(1px);
    transition: border-color 0.2s ease, background 0.2s ease;
}

.ip-login__feature-card:hover {
    border-color: rgba(45, 108, 223, 0.55);
    background: rgba(45, 108, 223, 0.08);
}

.ip-login__feature-icon {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    background: rgba(45, 108, 223, 0.20);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6FA3F5;
    flex-shrink: 0;
}

.ip-login__feature-name {
    font-size: 12px;
    font-weight: var(--fw-semibold);
    color: #FFFFFF;
    line-height: 1.3;
    margin: 0;
}

.ip-login__feature-desc {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.50);
    line-height: 1.4;
    margin: 0;
}

/* ── Bottom tagline ──────────────────────────────────────────── */
.ip-login__tagline {
    font-size: var(--text-lg);
    font-weight: var(--fw-bold);
    color: #FFFFFF;
    letter-spacing: 0.02em;
    opacity: 0.90;
}
</style>