<script setup>
// ══════════════════════════════════════════════════════════════════
//  InPractice — Register.vue
//  Location: resources/js/Pages/Auth/Register.vue
// ══════════════════════════════════════════════════════════════════

import { ref, computed, onMounted } from 'vue';
import { Head, Link, useForm }      from '@inertiajs/vue3';
import { useAuthStore }             from '@/Stores/useAuthStore';
import PasswordInput                from '@/Components/PasswordInput.vue';
import { EXPERIENCE_LEVELS }        from '@/constants/experienceLevels';

const authStore = useAuthStore();

const hubs = [
    { slug: 'finance',   label_en: 'Finance',   label_ar: 'المالية',   icon: '📊' },
    { slug: 'marketing', label_en: 'Marketing',  label_ar: 'التسويق',   icon: '📢' },
    { slug: 'sales',     label_en: 'Sales',      label_ar: 'المبيعات',  icon: '🎯' },
];

const experienceLevels = EXPERIENCE_LEVELS;

const form = useForm({
    name:                 '',
    nickname:             '',
    email:                '',
    profession:           '',
    experience_level:     'fresh',
    hubs:                 [],
    language:             'en',
    theme:                'navy',
    password:             '',
    password_confirmation:'',
    _hp: '',
    _ft: 0,
});

const isDark  = computed(() => authStore.isDark);
const isRtl   = computed(() => authStore.isRtl);
const locale  = computed(() => authStore.locale);

const hubLabel = (hub)   => locale.value === 'ar' ? hub.label_ar   : hub.label_en;
const expLabel = (level) => locale.value === 'ar' ? level.label_ar : level.label_en;

function toggleTheme() {
    const next = authStore.theme === 'navy' ? 'dark' : 'navy';
    authStore.setThemeLocal(next);
    localStorage.setItem('ip_theme', next);
    form.theme = next;
}

function toggleLocale() {
    const next = locale.value === 'en' ? 'ar' : 'en';
    document.documentElement.setAttribute('lang', next);
    document.documentElement.setAttribute('dir', next === 'ar' ? 'rtl' : 'ltr');
    authStore.locale = next;
    form.language = next;
}

function toggleHub(slug) {
    const idx = form.hubs.indexOf(slug);
    if (idx === -1) { form.hubs.push(slug); }
    else            { form.hubs.splice(idx, 1); }
}

function isHubSelected(slug) {
    return form.hubs.includes(slug);
}

function submit() {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

onMounted(() => {
    const savedTheme  = localStorage.getItem('ip_theme') ?? 'navy';
    authStore.setThemeLocal(savedTheme);
    form.theme    = savedTheme;
    form.language = locale.value;
    form._ft      = Date.now();
});
</script>

<template>
    <Head title="Create Account" />

    <div class="ip-login" :data-theme="authStore.theme" :dir="isRtl ? 'rtl' : 'ltr'">

        <!-- Background photo -->
        <div class="ip-login__bg">
            <img src="/images/login-bg.jpg" alt="" class="ip-login__bg-img" loading="eager" />
            <div class="ip-login__bg-overlay" />
        </div>

        <!-- Controls -->
        <div class="ip-login__controls">
            <button class="ip-login__ctrl-btn" @click="toggleLocale">
                <span class="ip-login__ctrl-label">{{ locale === 'en' ? 'ع' : 'EN' }}</span>
            </button>
            <button class="ip-login__ctrl-btn" @click="toggleTheme">
                <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>
        </div>

        <!-- Left panel — desktop -->
        <div class="ip-login__left">
            <div class="ip-login__brand">
                <img src="/images/logo-light.png" alt="InPractice" class="ip-login__brand-img" />
            </div>
            <div class="ip-login__hero">
                <p class="ip-login__hero-label">Real Cases. Real Mastery.</p>
                <h1 class="ip-login__hero-title">Join the<br/>Club.</h1>
                <p class="ip-login__hero-sub">
                    InPractice is a professional club for Finance, Marketing, and Sales practitioners in Egypt and the GCC.
                    Free to join. Built for the real world.
                </p>
            </div>
            <ul class="ip-login__bullets">
                <li class="ip-login__bullet"><span class="ip-login__bullet-dot" />200+ practical business cases</li>
                <li class="ip-login__bullet"><span class="ip-login__bullet-dot" />Anonymous CV pool and job matching</li>
                <li class="ip-login__bullet"><span class="ip-login__bullet-dot" />Freelance matchmaking with privacy</li>
                <li class="ip-login__bullet"><span class="ip-login__bullet-dot" />Community forum — real problems, real answers</li>
            </ul>
            <p class="ip-login__tagline">Practice, Connect & Earn.</p>
        </div>

        <!-- Right panel — form -->
        <div class="ip-login__right">
            <div class="ip-login__card ip-login__card--register">

                <!-- Mobile brand -->
                <div class="ip-login__mobile-brand">
                    <img src="/images/logo-dark.png" alt="InPractice" class="ip-login__mobile-brand-img" />
                </div>

                <!-- Card heading -->
                <div class="ip-login__card-header">
                    <h2 class="ip-login__card-title">
                        {{ locale === 'ar' ? 'إنشاء حساب' : 'Create Account' }}
                    </h2>
                    <p class="ip-login__card-sub">
                        {{ locale === 'ar' ? 'مجاني تماماً — انضم الآن' : 'Completely free — join now' }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="ip-register__form" novalidate>

                    <!-- Full name -->
                    <div class="ip-form-group">
                        <label class="ip-login__label" for="name">
                            {{ locale === 'ar' ? 'الاسم الكامل' : 'Full Name' }}
                            <span class="ip-register__private">{{ locale === 'ar' ? '(خاص)' : '(private)' }}</span>
                        </label>
                        <div class="ip-login__input-wrap">
                            <svg class="ip-login__input-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                            <input id="name" v-model="form.name" type="text"
                                class="ip-login__input"
                                :class="{ 'ip-login__input--error': form.errors.name }"
                                :placeholder="locale === 'ar' ? 'اسمك الكامل' : 'Your full name'"
                                autocomplete="name" required />
                        </div>
                        <p v-if="form.errors.name" class="ip-login__error">{{ form.errors.name }}</p>
                    </div>

                    <!-- Nickname -->
                    <div class="ip-form-group">
                        <label class="ip-login__label" for="nickname">
                            {{ locale === 'ar' ? 'الاسم المستعار' : 'Nickname' }}
                            <span class="ip-register__public">{{ locale === 'ar' ? '(عام)' : '(public)' }}</span>
                        </label>
                        <div class="ip-login__input-wrap">
                            <svg class="ip-login__input-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                            </svg>
                            <input id="nickname" v-model="form.nickname" type="text"
                                class="ip-login__input"
                                :class="{ 'ip-login__input--error': form.errors.nickname }"
                                :placeholder="locale === 'ar' ? 'مثال: FinanceGuru' : 'e.g. FinanceGuru'"
                                autocomplete="off" required />
                        </div>
                        <p class="ip-register__hint">
                            {{ locale === 'ar' ? 'هذا الاسم يظهر في المنتدى والحالات بدلاً من اسمك الحقيقي.' : 'This appears in the forum and cases instead of your real name.' }}
                        </p>
                        <p v-if="form.errors.nickname" class="ip-login__error">{{ form.errors.nickname }}</p>
                    </div>

                    <!-- Email -->
                    <div class="ip-form-group">
                        <label class="ip-login__label" for="reg-email">
                            {{ locale === 'ar' ? 'البريد الإلكتروني' : 'Email Address' }}
                        </label>
                        <div class="ip-login__input-wrap">
                            <svg class="ip-login__input-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                            </svg>
                            <input id="reg-email" v-model="form.email" type="email"
                                class="ip-login__input"
                                :class="{ 'ip-login__input--error': form.errors.email }"
                                :placeholder="locale === 'ar' ? 'بريدك الإلكتروني' : 'your@email.com'"
                                autocomplete="username" required />
                        </div>
                        <p v-if="form.errors.email" class="ip-login__error">{{ form.errors.email }}</p>
                    </div>

                    <!-- Profession + Experience -->
                    <div class="ip-register__row-2">
                        <div class="ip-form-group">
                            <label class="ip-login__label" for="profession">
                                {{ locale === 'ar' ? 'المسمى الوظيفي' : 'Profession' }}
                            </label>
                            <input id="profession" v-model="form.profession" type="text"
                                class="ip-login__input ip-login__input--no-icon"
                                :class="{ 'ip-login__input--error': form.errors.profession }"
                                :placeholder="locale === 'ar' ? 'مثال: محلل مالي' : 'e.g. Financial Analyst'" />
                            <p v-if="form.errors.profession" class="ip-login__error">{{ form.errors.profession }}</p>
                        </div>
                        <div class="ip-form-group">
                            <label class="ip-login__label" for="experience_level">
                                {{ locale === 'ar' ? 'مستوى الخبرة' : 'Experience' }}
                            </label>
                            <select id="experience_level" v-model="form.experience_level"
                                class="ip-login__input ip-login__input--no-icon ip-select"
                                :class="{ 'ip-login__input--error': form.errors.experience_level }">
                                <option v-for="level in experienceLevels" :key="level.value" :value="level.value">
                                    {{ expLabel(level) }}
                                </option>
                            </select>
                            <p v-if="form.errors.experience_level" class="ip-login__error">{{ form.errors.experience_level }}</p>
                        </div>
                    </div>

                    <!-- Hub selection -->
                    <div class="ip-form-group">
                        <label class="ip-login__label">
                            {{ locale === 'ar' ? 'اختر مجالك (أو أكثر)' : 'Select Your Hub(s)' }}
                        </label>
                        <div class="ip-register__hubs">
                            <button
                                v-for="hub in hubs"
                                :key="hub.slug"
                                type="button"
                                class="ip-register__hub-btn"
                                :class="{
                                    'ip-register__hub-btn--active': isHubSelected(hub.slug),
                                    [`ip-register__hub-btn--${hub.slug}`]: true,
                                }"
                                @click="toggleHub(hub.slug)"
                            >
                                <span class="ip-register__hub-icon">{{ hub.icon }}</span>
                                <span class="ip-register__hub-name">{{ hubLabel(hub) }}</span>
                                <span v-if="isHubSelected(hub.slug)" class="ip-register__hub-check">✓</span>
                            </button>
                        </div>
                        <p v-if="form.errors.hubs" class="ip-login__error">{{ form.errors.hubs }}</p>
                    </div>

                    <!-- Password -->
                    <div class="ip-form-group">
                        <label class="ip-login__label" for="reg-password">
                            {{ locale === 'ar' ? 'كلمة المرور' : 'Password' }}
                        </label>
                        <PasswordInput
                            id="reg-password"
                            v-model="form.password"
                            :has-error="!!form.errors.password"
                            :placeholder="locale === 'ar' ? 'كلمة المرور' : 'Min 8 characters'"
                            autocomplete="new-password"
                            required
                        />
                        <p v-if="form.errors.password" class="ip-login__error">{{ form.errors.password }}</p>
                    </div>

                    <!-- Confirm password -->
                    <div class="ip-form-group">
                        <label class="ip-login__label" for="reg-password-confirm">
                            {{ locale === 'ar' ? 'تأكيد كلمة المرور' : 'Confirm Password' }}
                        </label>
                        <PasswordInput
                            id="reg-password-confirm"
                            v-model="form.password_confirmation"
                            :has-error="!!form.errors.password_confirmation"
                            :placeholder="locale === 'ar' ? 'أعد كتابة كلمة المرور' : 'Repeat password'"
                            autocomplete="new-password"
                            required
                        />
                        <p v-if="form.errors.password_confirmation" class="ip-login__error">{{ form.errors.password_confirmation }}</p>
                    </div>

                    <!-- Honeypot -->
                    <div class="ip-register__hp-wrap" aria-hidden="true">
                        <input v-model="form._hp" type="text" name="website" tabindex="-1" autocomplete="off" class="ip-register__hp-field" />
                    </div>
                    <input type="hidden" v-model="form._ft" />

                    <!-- Email verification notice -->
                    <div class="ip-register__verify-note">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                        </svg>
                        <span>
                            {{ locale === 'ar'
                                ? 'ستتلقى رسالة تحقق على بريدك الإلكتروني بعد التسجيل — يرجى تأكيد بريدك للوصول إلى المنصة.'
                                : 'After registering you will receive a verification email. Please confirm your email to access the platform.'
                            }}
                        </span>
                    </div>

                    <input type="hidden" v-model="form.theme" />
                    <input type="hidden" v-model="form.language" />

                    <!-- Submit -->
                    <button type="submit"
                        class="ip-btn ip-btn--primary ip-btn--full ip-login__submit"
                        :disabled="form.processing || form.hubs.length === 0">
                        <svg v-if="form.processing" class="ip-login__spinner" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>
                        </svg>
                        <span>{{ locale === 'ar' ? 'إنشاء الحساب' : 'Create Account' }}</span>
                    </button>

                </form>

                <div class="ip-login__divider">
                    <span>{{ locale === 'ar' ? 'لديك حساب بالفعل؟' : 'Already have an account?' }}</span>
                </div>

                <Link :href="route('login')" class="ip-btn ip-btn--outline ip-btn--full">
                    {{ locale === 'ar' ? 'تسجيل الدخول' : 'Sign In' }}
                </Link>

            </div>
        </div>

    </div>
</template>

<style scoped>
/* ══════════════════════════════════════════════════════════════
   ROOT SHELL
══════════════════════════════════════════════════════════════ */
.ip-login {
    position: relative;
    min-height: 100vh;
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
}
.ip-login__bg-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
}
.ip-login__bg-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(15,32,68,0.88) 0%, rgba(15,32,68,0.72) 50%, rgba(15,32,68,0.60) 100%);
}
[data-theme="dark"] .ip-login__bg-overlay {
    background: linear-gradient(135deg, rgba(20,29,43,0.92) 0%, rgba(20,29,43,0.80) 50%, rgba(20,29,43,0.65) 100%);
}

/* ── Controls ────────────────────────────────────────────────── */
.ip-login__controls {
    position: fixed;
    top: var(--space-4);
    right: var(--space-4);
    z-index: 10;
    display: flex;
    gap: var(--space-2);
}
[dir="rtl"] .ip-login__controls { right: auto; left: var(--space-4); }
.ip-login__ctrl-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-md);
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.20);
    color: #fff;
    cursor: pointer;
    transition: background var(--transition-fast);
    backdrop-filter: blur(8px);
}
.ip-login__ctrl-btn:hover { background: rgba(255,255,255,0.22); }
.ip-login__ctrl-label { font-size: 13px; font-weight: var(--fw-bold); }

/* ── Left panel — hidden on mobile ──────────────────────────── */
.ip-login__left { display: none; }

/* ── Right panel ─────────────────────────────────────────────── */
.ip-login__right {
    position: relative;
    z-index: 1;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-6) var(--space-4);
    padding-top: max(var(--space-12), env(safe-area-inset-top));
    padding-bottom: max(var(--space-6), env(safe-area-inset-bottom));
}

/* ── Card ────────────────────────────────────────────────────── */
.ip-login__card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: var(--space-6);
    width: 100%;
    max-width: 400px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.35);
    backdrop-filter: blur(10px);
}
[data-theme="dark"] .ip-login__card {
    background: rgba(27,37,55,0.95);
    border-color: rgba(45,62,87,0.80);
}
.ip-login__card--register {
    max-height: 92vh;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--color-border) transparent;
}

/* ── Mobile brand ────────────────────────────────────────────── */
.ip-login__mobile-brand {
    display: flex;
    justify-content: center;
    margin-bottom: var(--space-5);
}
.ip-login__mobile-brand-img { height: 52px; width: auto; object-fit: contain; }

/* ── Brand — desktop ─────────────────────────────────────────── */
.ip-login__brand { display: flex; align-items: flex-start; }
.ip-login__brand-img { height: 64px; width: auto; object-fit: contain; }

/* ── Card header ─────────────────────────────────────────────── */
.ip-login__card-header { margin-bottom: var(--space-5); text-align: center; }
.ip-login__card-title {
    font-size: var(--text-xl);
    font-weight: var(--fw-bold);
    color: var(--color-text-primary);
    margin-bottom: var(--space-1);
    letter-spacing: -0.01em;
}
.ip-login__card-sub { font-size: var(--text-sm); color: var(--color-text-muted); }

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
.ip-login__input-wrap { position: relative; display: flex; align-items: center; }
.ip-login__input-icon {
    position: absolute;
    left: var(--space-3);
    color: var(--color-text-muted);
    pointer-events: none;
    flex-shrink: 0;
}
[dir="rtl"] .ip-login__input-icon { left: auto; right: var(--space-3); }

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
[dir="rtl"] .ip-login__input {
    padding: 0 calc(var(--space-3) + 16px + var(--space-3)) 0 var(--space-4);
}
.ip-login__input--no-icon { padding-left: var(--space-4); padding-right: var(--space-4); }
.ip-login__input--with-toggle { padding-right: calc(var(--space-3) + 16px + var(--space-3)); }
[dir="rtl"] .ip-login__input--with-toggle {
    padding-right: calc(var(--space-3) + 16px + var(--space-3));
    padding-left: calc(var(--space-3) + 16px + var(--space-3));
}
.ip-login__input:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}
.ip-login__input--error { border-color: var(--color-danger); }
.ip-login__input--error:focus { box-shadow: 0 0 0 3px var(--color-danger-soft); }
.ip-login__input::placeholder { color: var(--color-text-muted); }

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
[dir="rtl"] .ip-login__toggle-pw { right: auto; left: var(--space-3); }
.ip-login__toggle-pw:hover { color: var(--color-text-primary); }

/* ── Error ───────────────────────────────────────────────────── */
.ip-login__error { font-size: var(--text-xs); color: var(--color-danger); margin-top: var(--space-1); }

/* ── Submit ──────────────────────────────────────────────────── */
.ip-login__submit { font-size: var(--text-md); letter-spacing: 0.02em; gap: var(--space-2); }

/* ── Spinner ─────────────────────────────────────────────────── */
.ip-login__spinner { animation: ip-spin 0.8s linear infinite; }
@keyframes ip-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* ── Divider ─────────────────────────────────────────────────── */
.ip-login__divider {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin: var(--space-5) 0 var(--space-4);
}
.ip-login__divider::before,
.ip-login__divider::after { content: ''; flex: 1; height: 1px; background: var(--color-border); }
.ip-login__divider span { font-size: var(--text-xs); color: var(--color-text-muted); white-space: nowrap; }

/* ── Hero text (desktop left panel) ─────────────────────────── */
.ip-login__hero {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: var(--space-8) 0 var(--space-6);
}
.ip-login__hero-label {
    font-size: var(--text-sm);
    font-weight: var(--fw-semibold);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #1D9E75;
    margin-bottom: var(--space-4);
}
.ip-login__hero-title {
    font-size: 52px;
    font-weight: var(--fw-bold);
    color: #fff;
    line-height: 1.05;
    letter-spacing: -0.03em;
    margin-bottom: var(--space-5);
}
.ip-login__hero-sub { font-size: var(--text-md); color: rgba(255,255,255,0.72); line-height: 1.7; max-width: 440px; }
.ip-login__bullets { list-style: none; display: flex; flex-direction: column; gap: var(--space-3); margin-bottom: var(--space-6); }
.ip-login__bullet { display: flex; align-items: center; gap: var(--space-3); font-size: var(--text-base); color: rgba(255,255,255,0.82); font-weight: var(--fw-medium); }
.ip-login__bullet-dot { width: 8px; height: 8px; border-radius: 50%; background: #1D9E75; flex-shrink: 0; }
.ip-login__tagline { font-size: var(--text-lg); font-weight: var(--fw-bold); color: #fff; letter-spacing: 0.02em; opacity: 0.90; }

/* ══════════════════════════════════════════════════════════════
   REGISTER-SPECIFIC STYLES
══════════════════════════════════════════════════════════════ */
.ip-register__form {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    position: relative;
}
.ip-register__row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-3);
}
.ip-register__hubs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-2);
}
.ip-register__hub-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-1);
    padding: var(--space-3) var(--space-2);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-alt);
    cursor: pointer;
    transition: all var(--transition-base);
    position: relative;
}
.ip-register__hub-btn:hover { border-color: var(--color-primary); background: var(--color-primary-soft); }
.ip-register__hub-btn--finance.ip-register__hub-btn--active  { border-color: var(--color-hub-finance);   background: rgba(29,158,117,0.10); }
.ip-register__hub-btn--marketing.ip-register__hub-btn--active { border-color: var(--color-hub-marketing); background: rgba(55,138,221,0.10); }
.ip-register__hub-btn--sales.ip-register__hub-btn--active    { border-color: var(--color-hub-sales);     background: rgba(216,90,48,0.10); }
.ip-register__hub-icon { font-size: 20px; line-height: 1; }
.ip-register__hub-name { font-size: var(--text-xs); font-weight: var(--fw-semibold); color: var(--color-text-primary); }
.ip-register__hub-check { position: absolute; top: 4px; right: 6px; font-size: 10px; color: var(--color-primary); font-weight: var(--fw-bold); }
.ip-register__private { color: var(--color-text-muted); font-weight: var(--fw-regular); text-transform: none; letter-spacing: 0; margin-left: var(--space-1); font-size: 10px; }
.ip-register__public  { color: var(--color-primary);    font-weight: var(--fw-regular); text-transform: none; letter-spacing: 0; margin-left: var(--space-1); font-size: 10px; }
.ip-register__hint { font-size: var(--text-xs); color: var(--color-text-muted); margin-top: var(--space-1); line-height: 1.5; }
.ip-register__verify-note {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    background: var(--color-info-soft);
    border: 1px solid var(--color-info);
    border-radius: var(--radius-md);
    padding: var(--space-3);
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    line-height: 1.5;
    margin-bottom: var(--space-2);
}
.ip-register__verify-note svg { color: var(--color-info); margin-top: 1px; }
.ip-register__hp-wrap {
    position: absolute;
    left: -9999px;
    top: -9999px;
    width: 1px;
    height: 1px;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
}
.ip-register__hp-field { width: 1px; height: 1px; border: none; padding: 0; background: transparent; }

/* ══════════════════════════════════════════════════════════════
   DESKTOP LAYOUT
══════════════════════════════════════════════════════════════ */
@media (min-width: 768px) {
    .ip-login { flex-direction: row; }
    .ip-login__left {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        z-index: 1;
        flex: 0 0 55%;
        padding: var(--space-8) var(--space-10);
    }
    .ip-login__right {
        flex: 0 0 45%;
        background: rgba(255,255,255,0.04);
        backdrop-filter: blur(20px);
        border-left: 1px solid rgba(255,255,255,0.08);
        padding: var(--space-8);
    }
    [data-theme="dark"] .ip-login__right { background: rgba(20,29,43,0.55); }
    .ip-login__mobile-brand { display: none; }
    .ip-login__card { background: var(--color-surface); max-width: 380px; box-shadow: var(--shadow-float); }
    .ip-login__card--register { max-height: 88vh; }
}
</style>