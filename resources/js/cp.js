import { inertia, components } from '@statamic/cms/api';

import Dashboard from './pages/Dashboard.vue';
import Queue from './pages/Queue.vue';
import DocumentStatusIndex from './fieldtypes/DocumentStatusIndex.vue';
import DocumentStatusField from './fieldtypes/DocumentStatusField.vue';

/**
 * The addon's control panel assets.
 *
 * Two Inertia pages and the badge the asset browser draws in its column.
 *
 * There is no JavaScript hook to intercept the asset browser with — the built
 * control panel bundle runs none — so the badge works the other way round: a
 * fieldtype on the asset blueprint becomes a column, and this renders its
 * value.
 */
inertia.register('a11y-docs/Dashboard', Dashboard);
inertia.register('a11y-docs/Queue', Queue);

components.register('a11y_document_status-fieldtype-index', DocumentStatusIndex);
components.register('a11y_document_status-fieldtype', DocumentStatusField);
