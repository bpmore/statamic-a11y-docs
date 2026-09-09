<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Listeners;

use Bpmore\StatamicA11yDocs\Gate\PublishGate;
use Illuminate\Validation\ValidationException;
use Statamic\Events\EntrySaving;

/**
 * Stops an entry being published while it links to a document nobody can read.
 *
 * Statamic halts a save when a listener returns false, but a save that fails
 * without saying why is worse than no gate at all — the person is left guessing
 * and the first thing they try is turning it off. So this throws a validation
 * error instead, which the control panel shows in the words spec §8 asks for.
 */
class GateEntryPublishing
{
    public function __construct(private readonly PublishGate $gate) {}

    /**
     * A field handle the control panel will actually render the error against.
     *
     * Statamic only displays validation errors next to a field from the entry's
     * blueprint. Keyed to anything else — 'published', say — the error is
     * carried in the response and dropped on the floor: the publish button does
     * nothing and says nothing, which is the exact failure this listener's
     * docblock warns about.
     */
    private function fieldToAttachTo($entry): string
    {
        $fields = $entry->blueprint()?->fields()->all();

        if ($fields === null || $fields->isEmpty()) {
            return 'published';
        }

        return $fields->has('title') ? 'title' : (string) $fields->keys()->first();
    }

    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        // The gate protects publication. A draft with a bad PDF in it is
        // somebody's work in progress, and blocking that would be officious.
        if (! $entry->published()) {
            return;
        }

        $blockers = $this->gate->blockers($entry);

        if ($blockers->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            $this->fieldToAttachTo($entry) => array_merge(
                [sprintf(
                    'This entry links to %d document%s that people using a screen reader cannot read:',
                    $blockers->count(),
                    $blockers->count() === 1 ? '' : 's',
                )],
                $this->gate->explain($blockers),
            ),
        ]);
    }
}
