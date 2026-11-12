<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import { Badge, Button, Card, Header, Heading, Icon, Panel, PanelHeader, Subheading, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Text } from '@statamic/cms/ui';

/**
 * The screen that describes the past.
 *
 * Spec §8: the gate protects the future, this describes the past — and on first
 * run it has to say plainly that the backlog is not blocking anything, because
 * a site that opens this, sees 890 problems and no explanation concludes the
 * addon has broken their site.
 */
const props = defineProps({
    headline: String,
    total: Number,
    statuses: Object,
    formats: Object,
    severities: Object,
    rules: Array,
    engines: Array,
    offenders: Array,
    grandfathered: Number,
    gate: Object,
    queueUrl: String,
});

const severityColor = {
    critical: 'red',
    serious: 'amber',
    moderate: 'default',
    minor: 'default',
};

const hasFindings = computed(() => Object.values(props.severities).some((count) => count > 0));

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
    <!-- The same words as the nav item that got somebody here. -->
    <Head title="Document checks" />

    <Header title="Document checks" icon="file-content-list">
        <Button :href="queueUrl" variant="primary" text="Remediation queue" />
    </Header>

    <div v-if="total === 0" class="mt-6">
        <Card>
            <Heading text="Nothing has been checked yet" />
            <Text>
                Run <code>php please docs:check</code> to look at every document in your asset library.
            </Text>
        </Card>
    </div>

    <template v-else>
        <Card class="mt-6">
            <Heading size="lg" :text="headline" />

            <!-- The sentence spec §8 asks for, with the real number in it. -->
            <Text v-if="grandfathered > 0" class="mt-2">
                We found <strong>{{ grandfathered }}</strong> existing
                {{ grandfathered === 1 ? 'document' : 'documents' }} that need attention.
                <strong>Publishing isn’t blocked for these</strong> — they were already here when
                A11y Docs was installed. Anything added from now on is checked before it can be published.
                <Link :href="queueUrl">Here’s your remediation queue.</Link>
            </Text>

            <Text v-else-if="!gate.enabled" class="mt-2">
                The publish gate is switched off, so nothing here blocks publishing.
            </Text>

            <Text v-else class="mt-2">
                New documents are checked before they can be published, at
                <strong>{{ gate.threshold }}</strong> and above.
            </Text>
        </Card>

        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <Panel>
                <PanelHeader><Subheading text="By format" /></PanelHeader>
                <!-- Every table here has a header row. A screen reader user
                     reading three unlabelled columns is the kind of thing this
                     addon exists to report. -->
                <Table>
                    <TableColumns>
                        <TableColumn scope="col" :style="cellPadding">Format</TableColumn>
                        <TableColumn scope="col" :style="cellPadding">Documents</TableColumn>
                        <TableColumn scope="col" :style="cellPadding">Outcome</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="(counts, format) in formats" :key="format">
                            <TableCell class="font-medium uppercase" :style="cellPadding">{{ format }}</TableCell>
                            <TableCell :style="cellPadding">{{ counts.total }}</TableCell>
                            <TableCell :style="cellPadding">
                                <Badge v-if="counts.fail" color="red" :text="`${counts.fail} failing`" />
                                <Badge v-if="counts.pass" color="green" :text="`${counts.pass} passing`" />
                                <Badge v-if="counts.unsupported" :text="`${counts.unsupported} not checkable`" />
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </Panel>

            <Panel v-if="hasFindings">
                <PanelHeader><Subheading text="Findings by severity" /></PanelHeader>
                <Table>
                    <TableColumns>
                        <TableColumn scope="col" :style="cellPadding">Severity</TableColumn>
                        <TableColumn scope="col" :style="cellPadding">Findings</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="(count, severity) in severities" :key="severity">
                            <TableCell :style="cellPadding">
                                <Badge :color="severityColor[severity]" :text="severity" />
                            </TableCell>
                            <TableCell :style="cellPadding">{{ count }}</TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </Panel>
        </div>

        <Panel v-if="rules.length" class="mt-6">
            <PanelHeader>
                <Subheading text="What is wrong most often" />
                <Text size="sm">Counted by document, because that is the number you act on.</Text>
            </PanelHeader>
            <Table>
                <TableColumns>
                    <TableColumn scope="col" :style="cellPadding">Rule</TableColumn>
                    <TableColumn scope="col" :style="cellPadding">Documents</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="rule in rules" :key="rule.rule">
                        <TableCell :style="cellPadding">
                            <Link :href="`${queueUrl}?rule=${rule.rule}`">{{ rule.label }}</Link>
                        </TableCell>
                        <TableCell :style="cellPadding">{{ rule.documents }} {{ rule.documents === 1 ? 'document' : 'documents' }}</TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>

        <Panel v-if="offenders.length" class="mt-6">
            <PanelHeader><Subheading text="Worst offenders" /></PanelHeader>
            <Table>
                <TableColumns>
                    <TableColumn scope="col" :style="cellPadding">Document</TableColumn>
                    <TableColumn scope="col" :style="cellPadding">Container</TableColumn>
                    <TableColumn scope="col" :style="cellPadding">Findings</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="offender in offenders" :key="offender.asset_id">
                        <TableCell class="font-mono text-xs" :style="cellPadding">{{ offender.path }}</TableCell>
                        <TableCell :style="cellPadding">{{ offender.container }}</TableCell>
                        <TableCell :style="cellPadding">
                            <Badge v-if="offender.critical" color="red" :text="`${offender.critical} critical`" />
                            <Badge :text="`${offender.findings} in total`" />
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>

        <!-- Spec §5: every report says which engine produced it, or it
             overstates itself. -->
        <Text v-if="engines.length" size="sm" class="mt-6">
            <Icon name="information-circle" class="inline" />
            <span v-for="engine in engines" :key="engine.engine">
                {{ engine.documents }} checked by <strong>{{ engine.engine }}</strong>.
            </span>
        </Text>
    </template>
</template>
