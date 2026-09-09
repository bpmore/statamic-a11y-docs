<script setup>
import { Badge, Text } from '@statamic/cms/ui';

/**
 * The accessibility panel on an asset's own screen: what is wrong with this
 * document, and where in it — spec §9's detail panel.
 *
 * Read-only. The field stores nothing; everything here comes from the last
 * check.
 */
defineProps({ value: { type: Object, default: null } });

const severityColor = {
    critical: 'red',
    serious: 'amber',
    moderate: 'default',
    minor: 'default',
};
</script>

<template>
    <div v-if="!value || !value.status">
        <Text>This document has not been checked yet.</Text>
    </div>

    <div v-else class="space-y-3">
        <Badge :color="{ red: 'red', amber: 'amber', green: 'green' }[value.colour] ?? 'default'"
               :text="value.label" />

        <Text v-if="value.error" size="sm">{{ value.error }}</Text>

        <ul v-if="value.problems.length" class="space-y-2">
            <li v-for="problem in value.problems" :key="`${problem.rule}-${problem.where}`">
                <Badge :color="severityColor[problem.severity]" :text="problem.severity" />
                <span class="ms-2">{{ problem.message }}</span>
                <!-- The location is what turns "890 untagged PDFs" into
                     something a person can actually go and fix. -->
                <Text v-if="problem.where" as="div" size="sm" variant="subtle">{{ problem.where }}</Text>
            </li>
        </ul>

        <!-- "We could not look" is not "we found nothing". -->
        <div v-if="value.unchecked.length">
            <Text size="sm" class="font-medium">Could not be checked</Text>
            <ul>
                <li v-for="rule in value.unchecked" :key="rule.rule">
                    <Text size="sm">{{ rule.reason }}</Text>
                </li>
            </ul>
        </div>

        <Text v-if="value.engine" as="div" size="sm" variant="subtle">
            Checked by {{ value.engine }}.
        </Text>
    </div>
</template>
