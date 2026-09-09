<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\StatamicA11yDocs\Actions\ExemptDocument;
use Bpmore\StatamicA11yDocs\Actions\RecheckDocument;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    foreach ([DocumentInspector::class, AssetChecker::class, DocumentScanner::class] as $binding) {
        app()->forgetInstance($binding);
    }

    Storage::fake('assets');
    $this->container = AssetContainer::make('documents')->disk('assets')->save();
    Storage::disk('assets')->put('handbook.pdf', (string) file_get_contents(corpusPath('pdf/untagged.pdf')));
    $this->artisan('docs:check --sync');

    $this->userWith = function (array $permissions) {
        $role = Role::make('tester-'.md5(implode($permissions)))->addPermission('access cp');
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
        $role->save();

        return User::make()->id('u-'.md5(implode($permissions)))->email(md5(implode($permissions)).'@example.edu')
            ->assignRole($role)->save();
    };
});

it('registers the three permissions spec §9 asks for', function () {
    $permissions = Permission::all()->map->value()->all();

    expect($permissions)->toContain('view document checks')
        ->toContain('run document scans')
        ->toContain('manage document exemptions');
});

it('nests the two doing permissions under the seeing one', function () {
    // Running a scan or granting an exemption without being able to see the
    // results is a button with no way to know what it did.
    $view = Permission::all()->first(fn ($p) => $p->value() === 'view document checks');

    expect($view->children()->map->value()->all())
        ->toBe(['run document scans', 'manage document exemptions']);
});

it('keeps the screens away from somebody without permission', function () {
    // Statamic redirects an unauthorised control panel request rather than
    // returning a bare 403, which is the better behaviour and what the
    // assertion should say.
    $user = ($this->userWith)([]);

    $this->actingAs($user)->get(cp_route('a11y-docs.dashboard'))
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->actingAs($user)->get(cp_route('a11y-docs.queue'))->assertRedirect();
});

it('lets somebody with the view permission in', function () {
    $user = ($this->userWith)(['view document checks']);

    $this->actingAs($user)->get(cp_route('a11y-docs.dashboard'))->assertOk();
    $this->actingAs($user)->get(cp_route('a11y-docs.queue'))->assertOk();
});

it('hides the re-check action from somebody who cannot run scans', function () {
    $asset = $this->container->asset('handbook.pdf');

    $this->actingAs(($this->userWith)(['view document checks']));
    expect((new RecheckDocument)->visibleTo($asset))->toBeFalse();

    $this->actingAs(($this->userWith)(['view document checks', 'run document scans']));
    expect((new RecheckDocument)->visibleTo($asset))->toBeTrue();
});

it('refuses the re-check action as well as hiding it', function () {
    // Hiding a button is a courtesy. This is the part that matters, because the
    // action endpoint is reachable by anybody who knows its name.
    $asset = $this->container->asset('handbook.pdf');
    $without = ($this->userWith)(['view document checks']);
    $with = ($this->userWith)(['view document checks', 'run document scans']);

    expect((new RecheckDocument)->authorize($without, $asset))->toBeFalse()
        ->and((new RecheckDocument)->authorize($with, $asset))->toBeTrue()
        ->and((new RecheckDocument)->authorize(null, $asset))->toBeFalse();
});

it('guards exemptions, which are the decision not to fix something', function () {
    $asset = $this->container->asset('handbook.pdf');
    $without = ($this->userWith)(['view document checks', 'run document scans']);
    $with = ($this->userWith)(['view document checks', 'manage document exemptions']);

    expect((new ExemptDocument)->authorize($without, $asset))->toBeFalse()
        ->and((new ExemptDocument)->authorize($with, $asset))->toBeTrue();

    $this->actingAs($without);
    expect((new ExemptDocument)->visibleTo($asset))->toBeFalse();

    $this->actingAs($with);
    expect((new ExemptDocument)->visibleTo($asset))->toBeTrue();
});

it('gives a super user everything without being told to', function () {
    $super = User::make()->id('boss')->email('boss@example.edu')->makeSuper()->save();
    $asset = $this->container->asset('handbook.pdf');

    $this->actingAs($super)->get(cp_route('a11y-docs.dashboard'))->assertOk();

    expect((new RecheckDocument)->authorize($super, $asset))->toBeTrue()
        ->and((new ExemptDocument)->authorize($super, $asset))->toBeTrue();
});

it('does not put a document\'s status in front of somebody who cannot see results', function () {
    Blueprint::make('documents')->setNamespace('assets')->setContents([
        'tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'a11y', 'field' => ['type' => 'a11y_document_status']],
        ]]]]],
    ])->save();

    $fieldtype = fn () => $this->container->asset('handbook.pdf')->blueprint()->field('a11y')->fieldtype();

    $this->actingAs(($this->userWith)([]));
    expect($fieldtype()->preProcess(null))->toBeNull();

    $this->actingAs(($this->userWith)(['view document checks']));
    expect($fieldtype()->preProcess(null))->not->toBeNull();
});

it('still works where there is nobody signed in', function () {
    // The command line and the queue have no user, and that is not the same as
    // somebody who has been refused.
    Blueprint::make('documents')->setNamespace('assets')->setContents([
        'tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'a11y', 'field' => ['type' => 'a11y_document_status']],
        ]]]]],
    ])->save();

    expect(User::current())->toBeNull()
        ->and($this->container->asset('handbook.pdf')->blueprint()->field('a11y')->fieldtype()->preProcess(null))
        ->not->toBeNull();
});
