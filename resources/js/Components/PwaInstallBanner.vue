<template>
  <!-- Samsung Browser — open in Chrome -->
  <Transition name="pwa-slide">
    <div v-if="isSamsungBrowser && !samsungDismissed" class="pwa-banner pwa-banner--chrome">
      <div class="pwa-banner__icon">🌐</div>
      <div class="pwa-banner__text">
        <div class="pwa-banner__title">
          {{ isRTL ? 'افتح في Chrome' : 'Open in Chrome' }}
        </div>
        <div class="pwa-banner__sub">
          {{ isRTL ? 'للتثبيت الصحيح بدون تحذيرات، افتح التطبيق في Chrome' : 'For safe install without warnings, open in Chrome' }}
        </div>
      </div>
      <div class="pwa-banner__actions">
        <button type="button" class="pwa-banner__btn pwa-banner__btn--install" @click="openInChrome">
          {{ isRTL ? 'افتح' : 'Open' }}
        </button>
        <button type="button" class="pwa-banner__btn pwa-banner__btn--dismiss" @click="samsungDismissed = true">
          {{ isRTL ? 'لاحقاً' : 'Later' }}
        </button>
      </div>
    </div>
  </Transition>

  <!-- Android — native install prompt -->
  <Transition name="pwa-slide">
    <div v-if="showBanner && !isSamsungBrowser" class="pwa-banner">
      <div class="pwa-banner__icon">⚡</div>
      <div class="pwa-banner__text">
        <div class="pwa-banner__title">
          {{ isRTL ? 'ثبّت ماليات دوكس' : 'Install Maliyat Docs' }}
        </div>
        <div class="pwa-banner__sub">
          {{ isRTL ? 'حساباتك معك في أي وقت — حتى بدون إنترنت' : 'Your books, anytime — even offline' }}
        </div>
      </div>
      <div class="pwa-banner__actions">
        <button type="button" class="pwa-banner__btn pwa-banner__btn--install" @click="install">
          {{ isRTL ? 'ثبّت' : 'Install' }}
        </button>
        <button type="button" class="pwa-banner__btn pwa-banner__btn--dismiss" @click="dismiss">
          {{ isRTL ? 'لاحقاً' : 'Later' }}
        </button>
      </div>
    </div>
  </Transition>

  <!-- iOS — manual install guide -->
  <Transition name="pwa-slide">
    <div v-if="showIosBanner && !isSamsungBrowser" class="pwa-banner pwa-banner--ios">
      <div class="pwa-banner__icon">📱</div>
      <div class="pwa-banner__text">
        <div class="pwa-banner__title">
          {{ isRTL ? 'أضف التطبيق للشاشة الرئيسية' : 'Add to Home Screen' }}
        </div>
        <div v-if="isRTL" class="pwa-banner__steps">
          <div class="pwa-banner__step">
            <span class="pwa-banner__step-num">١</span>
            اضغط على زر المشاركة
            <svg class="pwa-banner__share-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
              <polyline points="16 6 12 2 8 6"/>
              <line x1="12" y1="2" x2="12" y2="15"/>
            </svg>
            في شريط Safari
          </div>
          <div class="pwa-banner__step">
            <span class="pwa-banner__step-num">٢</span>
            اختر «إضافة إلى الشاشة الرئيسية»
          </div>
        </div>
        <div v-else class="pwa-banner__steps">
          <div class="pwa-banner__step">
            <span class="pwa-banner__step-num">1</span>
            Tap the Share button
            <svg class="pwa-banner__share-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
              <polyline points="16 6 12 2 8 6"/>
              <line x1="12" y1="2" x2="12" y2="15"/>
            </svg>
            in Safari
          </div>
          <div class="pwa-banner__step">
            <span class="pwa-banner__step-num">2</span>
            Choose «Add to Home Screen»
          </div>
        </div>
      </div>
      <div class="pwa-banner__actions">
        <button type="button" class="pwa-banner__btn pwa-banner__btn--dismiss" @click="dismissIos">
          {{ isRTL ? 'فهمت' : 'Got it' }}
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { initPwaInstallListener, usePwaInstall } from '@/composables/usePwaInstall'
import { useAuthStore } from '@/stores/useAuthStore'

const authStore = useAuthStore()
const isRTL = computed(() => authStore.locale === 'ar')

const { showBanner, showIosBanner, install: promptInstall, dismiss, dismissIos } = usePwaInstall()

const isSamsungBrowser = computed(() => {
  if (typeof navigator === 'undefined') return false
  return /SamsungBrowser/i.test(navigator.userAgent || '')
})

const samsungDismissed = ref(false)

function openInChrome() {
  const url = window.location.href
  const intentUrl = `intent://${url.replace(/^https?:\/\//, '')}#Intent;scheme=https;package=com.android.chrome;end`
  window.location.href = intentUrl
}

async function install() {
  const choice = await promptInstall()
  if (choice?.outcome !== 'accepted') return
  router.visit('/app/dashboard', { replace: true })
}

onMounted(() => {
  initPwaInstallListener()
})
</script>

<style scoped>
.pwa-banner,
.pwa-banner__icon,
.pwa-banner__btn--install,
.pwa-banner__btn--dismiss {
  --pwa-fill: rgba(6, 14, 30, 0.92);
}

.pwa-banner {
  position: fixed;
  /* Sits clear of whatever is already pinned to the bottom. The app
     shell sets --pwa-banner-offset to the bottom-nav height; auth
     screens have no bottom nav, so the fallback just clears the
     safe-area inset. The old hard 80px was measured for the app
     shell and, on the login page, landed squarely on top of the
     Sign In button. */
  bottom: var(--pwa-banner-offset, calc(16px + env(safe-area-inset-bottom, 0px)));
  left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 2rem);
  max-width: 480px;
  z-index: 9999;

  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.875rem 1rem;

  background: var(--pwa-fill);
  border: 1px solid var(--pwa-fill);
  border-radius: 16px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
}

.pwa-banner__icon {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  font-size: 1.35rem;
  line-height: 1;
  background: var(--pwa-fill);
  border: 1px solid var(--pwa-fill);
  border-radius: 12px;
}

.pwa-banner__text {
  flex: 1;
  min-width: 0;
}

.pwa-banner__title {
  font-family: 'Inter', 'Cairo', sans-serif;
  font-size: 1rem;
  font-weight: 800;
  color: #fff;
  margin-bottom: 2px;
}

.pwa-banner__sub {
  font-size: 0.68rem;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.5);
}

.pwa-banner__steps {
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin-top: 4px;
}

.pwa-banner__step {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 0.68rem;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.65);
  flex-wrap: wrap;
}

.pwa-banner__step-num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.15);
  color: #fff;
  font-size: 0.6rem;
  font-weight: 800;
  flex-shrink: 0;
}

.pwa-banner__share-icon {
  width: 13px;
  height: 13px;
  color: rgba(100, 180, 255, 0.9);
  flex-shrink: 0;
}

.pwa-banner__actions {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  flex-shrink: 0;
}

.pwa-banner__btn {
  padding: 5px 14px;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 800;
  cursor: pointer;
  border: none;
  white-space: nowrap;
  transition: transform 0.12s;
  font-family: 'Inter', 'Cairo', sans-serif;
}

.pwa-banner__btn:hover {
  transform: scale(1.04);
}

.pwa-banner__btn--install {
  background: var(--pwa-fill);
  border: 1px solid var(--pwa-fill);
  color: #fff;
}

.pwa-banner__btn--dismiss {
  background: var(--pwa-fill);
  color: rgba(255, 255, 255, 0.65);
  border: 1px solid var(--pwa-fill);
}

.pwa-banner--chrome {
  background: var(--pwa-fill);
  border: 1px solid var(--pwa-fill);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
}

.pwa-banner--chrome .pwa-banner__btn--install {
  background: var(--pwa-fill);
  border: 1px solid var(--pwa-fill);
  color: #fff;
}

.pwa-banner--ios {
  align-items: flex-start;
}

.pwa-banner--ios .pwa-banner__icon {
  margin-top: 2px;
}

@media (min-width: 769px) {
  .pwa-banner {
    /* No bottom nav from the tablet breakpoint up. */
    bottom: calc(24px + env(safe-area-inset-bottom, 0px));
  }
}

.pwa-slide-enter-active {
  transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.pwa-slide-leave-active {
  transition: all 0.25s ease;
}

.pwa-slide-enter-from,
.pwa-slide-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(20px);
}
</style>
