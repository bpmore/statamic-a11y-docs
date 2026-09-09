<script setup>
import { computed } from 'vue';
import { Badge } from '@statamic/cms/ui';

/**
 * The red/amber/green badge in the asset browser, spec §9's traffic light.
 *
 * The value comes from the DocumentStatus fieldtype, which looks it up from the
 * database rather than from the asset's meta — so what is on screen is what was
 * actually found, not what somebody last remembered to save.
 */
const props = defineProps({ value: { type: Object, default: null } });

// Badge's prop is `color`, not `variant`, and it sets inheritAttrs: false — so
// a `variant` was dropped without a word and every badge rendered default grey.
// Its palette includes red, amber and green, which is this addon's own
// vocabulary, so the mapping is a passthrough.
//
// Not checked and passing are different things, and a grey badge saying so is
// better than an empty cell somebody reads as "fine".
const color = computed(() => ({
    red: 'red',
    amber: 'amber',
    green: 'green',
}[props.value?.colour] ?? 'default'));
</script>

<template>
    <Badge v-if="value" :color="color" :text="value.label" />
</template>
