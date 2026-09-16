<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PaymentMethodField.vue
//  Location: resources/js/Components/App/PaymentMethodField.vue
//
//  <PaymentMethodField v-model="form.method" v-model:channel-id="form.payment_channel_id"
//                       :channels="channelList" :creating-channel="creatingChannel"
//                       @create-channel="createChannel" />
//
//  One shared field pair instead of copy-pasting the method <select>
//  plus its conditional "which bank/wallet" combo on every page that
//  has a payment-mode block. The parent still owns the actual axios
//  call for "+ Add new bank/operator…" (via @create-channel) and the
//  channel list itself, same division of responsibility as
//  ComboSelect — this component doesn't talk to the network.
// ══════════════════════════════════════════════════════════════════

import ComboSelect from '@/Components/App/ComboSelect.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    modelValue: { type: String, default: 'cash' },       // method
    channelId: { type: [Number, String, null], default: null },
    channels: { type: Array, default: () => [] },
    creatingChannel: { type: Boolean, default: false },
});

defineEmits(['update:modelValue', 'update:channelId', 'create-channel']);

const { t } = useAppTranslations();
</script>

<template>
    <span>{{ t('methodLbl') }}</span>
    <select :value="props.modelValue" @change="$emit('update:modelValue', $event.target.value)">
        <option value="cash">{{ t('cashLbl') }}</option>
        <option value="bank">{{ t('bankLbl') }}</option>
        <option value="visa">{{ t('visaLbl') }}</option>
        <option value="instapay">{{ t('instapayLbl') }}</option>
        <option value="wallet">{{ t('walletLbl') }}</option>
    </select>

    <template v-if="props.modelValue !== 'cash'">
        <span>{{ t('bankOperatorLbl') }}</span>
        <ComboSelect
            :model-value="props.channelId"
            :options="props.channels"
            :creating="props.creatingChannel"
            :placeholder="t('selectPlaceholder')"
            :add-new-label="t('addNewChannel')"
            :inline="false"
            @update:model-value="$emit('update:channelId', $event)"
            @create="(name) => $emit('create-channel', name)"
        />
    </template>
</template>
