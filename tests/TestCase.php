<?php

namespace Tests;

use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every media write in the app goes through Storage::disk(...) — the
     * real filesystem by default. Only some feature tests call
     * Storage::fake() themselves, so without this override the others
     * (e.g. UploadedFile::fake() driven merchant flows) write their fake
     * images into the real storage/app/public/uploads folder on every run.
     *
     * Faking every disk media can land on here closes that leak globally.
     * A test that also calls Storage::fake() itself is unaffected — it
     * simply re-fakes the same disk with its own token, exactly as before.
     *
     * 'public' is both an explicit disk and the media package's own
     * default_disk (config key 'services.media.default_disk').
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Storage::fake((string) config('services.media.default_disk', 'public'));
    }

    /** The global Storage::fake('public') in setUp() resolves the disk before a test's own config(['filesystems.disks.public.url' => ...]) override, so a test needing a custom public URL must set the config and re-fake the disk together. */
    protected function fakePublicDiskWithUrl(string $url): void
    {
        config(['filesystems.disks.public.url' => $url]);
        Storage::fake('public', ['url' => $url]);
    }

    /**
     * Вариант Б: a single shared helper, rather than hand-writing staff
     * setup in each of the ~19 pre-existing Feature tests that call a
     * merchant endpoint. Applying `staff.can:*` to every merchant route
     * (Part 3 of staff-access-domain-design.md) would otherwise break
     * every one of those tests, which were written before permissions
     * existed and are not testing permission-boundary behavior — that is
     * EnsureStaffHasPermissionTest's job (Part 2), not theirs.
     *
     * Defaults to Administrator specifically so these pre-existing tests
     * keep testing what they already test (the feature itself), rather
     * than incidentally becoming permission tests too.
     */
    protected function actingAsAdministrator(): StaffModel
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            $this->seed(StaffSystemRolesSeeder::class);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create(
            'test-administrator-'.Str::uuid().'@example.com',
            app(PasswordHasher::class)->hash('irrelevant-not-checked'),
            'Test Administrator',
            $role,
        );
        app(StaffRepository::class)->save($staff);

        $model = StaffModel::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }
}
