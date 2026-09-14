import { computed } from 'vue';
import { useAuthStore } from '@/Stores/useAuthStore';
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
            value = value.replace(`:${placeholder}`, String(replacement));
        });

        return value;
    }

    return { t, locale };
}
