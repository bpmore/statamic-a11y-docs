<script setup>
import { reactive, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Badge, Button, Field, Header, Pagination, Panel, PanelHeader, Select, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Text } from '@statamic/cms/ui';

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

// Only the filters with a value, so the URL says what is filtered and nothing
// else.
function activeFilters() {
    return Object.fromEntries(Object.entries(filters).filter(([, v]) => v));
}

// A changed filter starts again from page one: page four of "critical" is not
// page four of "serious".
watch(filters, () => {
    router.get(window.location.pathname, activeFilters(), { preserveState: true, replace: true });
});

// The queue is fifty findings a page, and a library with 890 untagged PDFs
// has far more than fifty. Without this the rest were unreachable from the
// screen; the only way to page two was to type it into the address bar.
function goToPage(page) {
    router.get(window.location.pathname, { ...activeFilters(), page }, { preserveState: true, replace: true });
}

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
        <!-- Each filter under a visible label. The placeholder said what a
             filter was until something was chosen, and then "critical" sat
             in a box with nothing to say it was the severity.

             Visible only: Statamic's Select renders its trigger as a div
             that reka-ui labels "Show popup", and it forwards attributes to
             a wrapper rather than the trigger, so there is no way to hand it
             an accessible name from here. No `id`, then, because a label
             whose `for` points at nothing is a finding of its own. -->
        <PanelHeader class="flex flex-wrap gap-3">
            <Field label="Severity">
                <Select v-model="filters.severity" :options="options.severities" placeholder="Any severity" clearable />
            </Field>
            <Field label="Rule">
                <Select v-model="filters.rule" :options="options.rules" placeholder="Any rule" clearable />
            </Field>
            <Field label="Format">
                <Select v-model="filters.format" :options="options.formats" placeholder="Any format" clearable />
            </Field>
            <Field label="Container">
                <Select v-model="filters.container" :options="options.containers" placeholder="Any container" clearable />
            </Field>
        </PanelHeader>

        <Text v-if="!findings.data.length" class="p-4">
            Nothing matches those filters. That may be good news.
        </Text>

        <!-- Headed, because a table of four unlabelled columns is the kind of
             thing this addon exists to report. -->
        <Table v-else>
            <TableColumns>
                <TableColumn scope="col" :style="cellPadding">Severity</TableColumn>
                <TableColumn scope="col" :style="cellPadding">Rule</TableColumn>
                <TableColumn scope="col" :style="cellPadding">Document</TableColumn>
                <TableColumn scope="col" :style="cellPadding">Container</TableColumn>
            </TableColumns>
            <TableRows>
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
            </TableRows>
        </Table>
    </Panel>

    <!-- Laravel's paginator carries current_page, last_page, total, from and
         to at the top level, which is the meta Statamic's Pagination reads. -->
    <Pagination
        v-if="findings.last_page > 1"
        class="mt-4"
        :resource-meta="findings"
        :show-per-page-selector="false"
        @page-selected="goToPage"
    />

    <Text v-else-if="findings.total" size="sm" class="mt-4">
        {{ findings.total }} {{ findings.total === 1 ? 'finding' : 'findings' }}.
    </Text>
</template>
