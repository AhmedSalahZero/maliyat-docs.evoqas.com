import { computed } from 'vue';
import { useAuthStore } from '@/stores/useAuthStore';
import { appTranslations } from '@/lang/appTranslations';

export function useAppTranslations() {
    const authStore = useAuthStore();

    const locale = computed(() => authStore.locale);

    function t(key, replacements = {}) {
        const loc = locale.value === 'ar' ? 'ar' : 'en';

        let value =
            appTranslations[loc]?.[key]
            ?? appTranslations.en[key]
            ?? key;

        Object.entries(replacements).forEach(([placeholder, replacement]) => {
            // Every occurrence, not just the first — a message may use
            // the same placeholder twice.
            value = value.split(`:${placeholder}`).join(String(replacement));
        });

        return value;
    }

    return { t, locale };
}
