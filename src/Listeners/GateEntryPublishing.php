<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Listeners;

use Bpmore\A11yGate\Panel\PanelExtensions;
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
    /**
     * Named in GatePanelBlock as well, where it is handed to the gate. Both
     * have to say the same thing or the refusal is sent to a key nothing
     * reads, so neither writes it out.
     */
    public const REFUSAL_KEY = 'a11y_docs';

    /** A11y Gate's panel field, by the handle Statamic derives from its class. */
    private const PANEL_FIELDTYPE = 'accessibility_panel';

    public function __construct(private readonly PublishGate $gate) {}

    /**
     * The key this refusal is sent under, which decides where it is read.
     *
     * Two answers, and which one applies is about what is on the screen.
     *
     * A11y Gate's panel draws whatever it finds under a block's `refusalKey`,
     * in the block this addon already registers. That is the right place: the
     * refusal is about the documents on this page, and the block above it is
     * about the documents on this page.
     *
     * Without that panel there is nowhere of our own to draw, and Statamic
     * only displays a validation error next to a field from the entry's
     * blueprint. Keyed to anything else the error is carried in the response
     * and dropped on the floor: the publish button does nothing and says
     * nothing, which is the exact failure this listener's docblock warns
     * about. So the fallback keys to a real field and accepts that "this
     * entry links to a document nobody can read" reads as a fault in the
     * title. Misplaced beats invisible.
     */
    private function keyToAttachTo($entry): string
    {
        if ($this->panelWillDrawIt($entry)) {
            return self::REFUSAL_KEY;
        }

        $fields = $entry->blueprint()?->fields()->all();

        if ($fields === null || $fields->isEmpty()) {
            return 'published';
        }

        return $fields->has('title') ? 'title' : (string) $fields->keys()->first();
    }

    /**
     * Whether A11y Gate's panel is on this entry's screen and able to draw the
     * refusal. Two separate questions, and a no to either means the fallback.
     *
     * **Can it draw one at all.** The gate ships on its own release cycle, and
     * a version before `refusalKey` existed ignores the key silently: the save
     * is still refused and nothing anywhere says why. `supports()` is how the
     * two versions are told apart; a gate without the method is one of the old
     * ones.
     *
     * **Is it on this screen.** The gate adds its panel to blueprints for
     * collections that have routes, and an author may have removed it. Asking
     * the blueprint is asking what is actually in front of the person about to
     * be stopped.
     */
    private function panelWillDrawIt($entry): bool
    {
        if (! class_exists(PanelExtensions::class)
            || ! method_exists(PanelExtensions::class, 'supports')
            || ! PanelExtensions::supports('refusalKey')) {
            return false;
        }

        $fields = $entry->blueprint()?->fields()->all();

        if ($fields === null) {
            return false;
        }

        return $fields->contains(fn ($field): bool => $field->type() === self::PANEL_FIELDTYPE);
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
            $this->keyToAttachTo($entry) => array_merge(
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
