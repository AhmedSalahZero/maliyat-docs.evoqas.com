// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — usePermissions
//  Location: resources/js/composables/usePermissions.js
//
//  What the signed-in user is allowed to do, read from the shared
//  auth props (HandleInertiaRequests::resolveAuth).
//
//  This is presentation only. Every rule here is enforced on the
//  server as well — canDelete mirrors Controller::authorizeDelete()
//  — and the server is the one that decides. Hiding a button the
//  server would refuse is a courtesy to the user, never the
//  protection itself.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
    const page = usePage();

    const role = computed(() => page.props.auth?.user?.role ?? null);

    const isCompanyAdmin = computed(() => role.value === 'company_admin');

    /**
     * Deleting a financial record removes its payments with it, for
     * good — see the reasoning in Controller::authorizeDelete().
     */
    const canDelete = isCompanyAdmin;

    return { role, isCompanyAdmin, canDelete };
}
