import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/useAuthStore';
import { authTranslations } from '@/lang/authTranslations';

export function useAuthTranslations() {
    const page = usePage();
    const authStore = useAuthStore();

    const locale = computed(() => authStore.locale);

    function t(key, replacements = {}) {
        const loc = locale.value === 'ar' ? 'ar' : 'en';
        const serverStrings = page.props.translations?.auth ?? {};

        let value =
            authTranslations[loc]?.[key]
            ?? serverStrings[key]
            ?? authTranslations.en[key]
            ?? key;

        Object.entries(replacements).forEach(([placeholder, replacement]) => {
            value = value.replace(`:${placeholder}`, String(replacement));
        });

        return value;
    }

    return { t, locale };
}
