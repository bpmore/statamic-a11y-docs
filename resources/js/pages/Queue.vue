<script setup>
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Badge, Button, Header, Panel, PanelHeader, Select, Subheading, Table, TableCell, TableRow, Text } from '@statamic/cms/ui';

/**
 * The list of things to fix, worst first.
 *
 * Findings rather than documents, because the unit of work is "this figure has
 * no alternative text" — and filtering by rule is how somebody fixes fifty
 * documents in an afternoon instead of one at a time.
 */
const props = defineProps({
    findings: Object,
    filters: Object,
    options: Object,
    dashboardUrl: String,
});

const filters = reactive({ ...props.filters });

watch(filters, (value) => {
    const query = Object.fromEntries(Object.entries(value).filter(([, v]) => v));
    router.get(window.location.pathname, query, { preserveState: true, replace: true });
});

const severityColor = {
    critical: 'red',
    serious: 'amber',
    moderate: 'default',
    minor: 'default',
};

function describeLocation(location) {
    if (!location) return null;
    const parts = [];
    if (location.page) parts.push(`page ${location.page}`);
    if (location.slide) parts.push(`slide ${location.slide}`);
    if (location.sheet) parts.push(`sheet ${location.sheet}`);
    if (location.element) parts.push(location.element);
    return parts.join(', ');
}

// Statamic's PanelHeader is px-4.5 (18px); its table cells carry no horizontal
// padding, so every row sat 18px left of the heading above it.
//
// An inline style rather than a class: Statamic sets cell padding through a
// descendant selector ([&_td]:px-N td, specificity 0,1,1) which beats a plain
// utility on the td (0,1,0), and this addon's templates are not in Statamic's
// Tailwind scan, so a utility we pick may not be in the built CSS at all. An
// inline style depends on neither.
const cellPadding = { paddingInline: '1.125rem' }; // = px-4.5
</script>

<template>
    <Head title="Remediation queue" />

    <Header title="Remediation queue" icon="file-content-list">
        <Button :href="dashboardUrl" text="Back to dashboard" />
    </Header>

    <Panel class="mt-6">
        <PanelHeader class="flex flex-wrap gap-3">
            <Select v-model="filters.severity" :options="options.severities" placeholder="Any severity" clearable />
            <Select v-model="filters.rule" :options="options.rules" placeholder="Any rule" clearable />
            <Select v-model="filters.format" :options="options.formats" placeholder="Any format" clearable />
            <Select v-model="filters.container" :options="options.containers" placeholder="Any container" clearable />
        </PanelHeader>

        <Text v-if="!findings.data.length" class="p-4">
            Nothing matches those filters. That may be good news.
        </Text>

        <Table v-else>
            <TableRow v-for="finding in findings.data" :key="finding.id">
                <TableCell :style="cellPadding">
                    <Badge :color="severityColor[finding.severity]" :text="finding.severity" />
                </TableCell>
                <TableCell :style="cellPadding">{{ finding.rule_label }}</TableCell>
                <TableCell :style="cellPadding">
                    <div class="font-medium">{{ finding.path }}</div>
                    <Text as="div" size="sm">{{ finding.message }}</Text>
                    <Text v-if="describeLocation(finding.location)" as="div" size="sm" variant="subtle">
                        {{ describeLocation(finding.location) }}
                    </Text>
                </TableCell>
                <TableCell :style="cellPadding">{{ finding.container }}</TableCell>
            </TableRow>
        </Table>
    </Panel>

    <Text v-if="findings.total" size="sm" class="mt-4">
        {{ findings.total }} {{ findings.total === 1 ? 'finding' : 'findings' }}.
    </Text>
</template>
