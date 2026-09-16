<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ReportToolbar.vue
//  Location: resources/js/Components/App/ReportToolbar.vue
//
//  Print / Export Excel / Export PDF — the same three actions on
//  every report page, shared by all 8 reports so this one component
//  is the only place that behavior has to be right.
//
//  SHARING — on a phone whose browser supports the Web Share API's
//  file sharing (canShare({ files })), tapping Excel/PDF now:
//    1. Fetches the exact same file the download would produce
//       (same colored spreadsheet/PDF the export routes already
//       build — nothing generated client-side, no separate
//       "share version").
//    2. Hands it to navigator.share() so the OS's own share sheet
//       opens — WhatsApp, Mail, Drive, whatever the person has.
//  Anywhere that isn't supported (most desktop browsers, older
//  phones), the button falls back to a completely normal download —
//  nothing about existing behavior changes there.
//
//  Excel and PDF hrefs should already include whatever filter
//  querystring the page is currently showing, so the shared/
//  downloaded file matches what's on screen.
// ══════════════════════════════════════════════════════════════════

import { ref, onMounted } from 'vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    excelHref: { type: String, required: true },
    pdfHref: { type: String, required: true },
});

const { t } = useAppTranslations();

// Feature-detected once on mount rather than assumed — Web Share
// with file support is phone-and-browser dependent (good coverage
// on Android Chrome/Samsung Internet and recent iOS Safari; not on
// most desktop browsers at all).
const canShareFiles = ref(false);
const busy = ref(null); // 'excel' | 'pdf' | null — which button is mid-fetch

onMounted(() => {
    try {
        const probe = new File(['x'], 'probe.pdf', { type: 'application/pdf' });
        canShareFiles.value = typeof navigator.share === 'function'
            && typeof navigator.canShare === 'function'
            && navigator.canShare({ files: [probe] });
    } catch {
        canShareFiles.value = false;
    }
});

function printPage() {
    window.print();
}

const MIME = {
    excel: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    pdf: 'application/pdf',
};

function filenameFrom(response, fallback) {
    const disposition = response.headers.get('content-disposition') || '';
    const match = disposition.match(/filename="?([^"]+)"?/i);
    return match ? match[1] : fallback;
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

async function handleExport(kind) {
    const href = kind === 'excel' ? props.excelHref : props.pdfHref;

    // Desktop / unsupported browsers — exactly the old behavior.
    if (!canShareFiles.value) {
        window.location.href = href;
        return;
    }

    busy.value = kind;

    try {
        const response = await fetch(href, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('export failed');

        const blob = await response.blob();
        const filename = filenameFrom(response, `report.${kind === 'excel' ? 'xlsx' : 'pdf'}`);
        const file = new File([blob], filename, { type: MIME[kind] });

        if (navigator.canShare({ files: [file] })) {
            await navigator.share({ files: [file] });
        } else {
            downloadBlob(blob, filename);
        }
    } catch (error) {
        // AbortError = the person closed the share sheet without
        // picking anything — not a failure, nothing to fall back to.
        if (error?.name !== 'AbortError') {
            window.location.href = href;
        }
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div class="report-toolbar no-print">
        <button type="button" class="report-toolbar__btn" @click="printPage">
            <AppIcon name="print" />
            <span>{{ t('printLbl') }}</span>
        </button>
        <button
            type="button"
            class="report-toolbar__btn report-toolbar__btn--excel"
            :disabled="busy === 'excel'"
            @click="handleExport('excel')"
        >
            <AppIcon name="file-excel" />
            <span>{{ canShareFiles ? t('shareExcelLbl') : t('exportExcelLbl') }}</span>
        </button>
        <button
            type="button"
            class="report-toolbar__btn report-toolbar__btn--pdf"
            :disabled="busy === 'pdf'"
            @click="handleExport('pdf')"
        >
            <AppIcon name="file-pdf" />
            <span>{{ canShareFiles ? t('sharePdfLbl') : t('exportPdfLbl') }}</span>
        </button>
    </div>
</template>
