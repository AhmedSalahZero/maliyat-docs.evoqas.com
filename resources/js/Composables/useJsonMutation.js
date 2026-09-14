import { ref } from 'vue';
import { csrfJsonHeaders } from '@/Utils/csrf';

/**
 * JSON POST/PATCH for non-Inertia endpoints with consistent CSRF and error handling.
 */
export function useJsonMutation() {
    const errorMessage = ref('');

    async function postJson(url, { body = null, method = 'POST' } = {}) {
        errorMessage.value = '';

        try {
            const res = await fetch(url, {
                method,
                headers: csrfJsonHeaders(),
                credentials: 'same-origin',
                body: body !== null ? JSON.stringify(body) : undefined,
            });

            if (res.status === 419) {
                errorMessage.value = 'Session expired — refresh the page and try again';

                return { ok: false, data: null, status: res.status };
            }

            if (!res.ok) {
                errorMessage.value = 'Could not complete the request — please try again';

                return { ok: false, data: null, status: res.status };
            }

            const data = await res.json().catch(() => null);

            return { ok: true, data, status: res.status };
        } catch {
            errorMessage.value = 'Could not reach the server — check your connection';

            return { ok: false, data: null, status: 0 };
        }
    }

    function clearError() {
        errorMessage.value = '';
    }

    return { errorMessage, postJson, clearError };
}
