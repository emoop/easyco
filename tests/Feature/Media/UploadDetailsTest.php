<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Exceptions\ImageNotCleanableException;
use EasyCo\Media\MediaDetails;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ML-1a: a damaged picture is refused at upload (it would otherwise be stored WITH its metadata), and every new upload records the facts of the STORED original: client name (plain text),
 * mime, size, pixels and the signed-in staff member.
 */
class UploadDetailsTest extends TestCase
{
    use BuildsImageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
    }

    private function truncated(): string
    {
        $dirty = $this->jpeg($this->dirtyJpegSegments());
        $this->assertStringContainsString(self::MARKER, $dirty);

        return substr($dirty, 0, -2);
    }

    private function panelAdministrator(): \App\Filament\StaffPanelUser
    {
        $staff = \App\Filament\StaffPanelUser::find($this->actingAsAdministrator()->id);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public function test_the_columns_and_indexes_exist(): void
    {
        foreach (['original_name', 'mime', 'size_bytes', 'width', 'height', 'uploaded_by', 'missing_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('catalog_media', $column), $column);
        }

        $indexes = collect(Schema::getIndexes('catalog_media'))->keyBy('name');
        $this->assertSame(['type', 'created_at'], $indexes['cm_type_created_idx']['columns']);
        $this->assertSame(['original_name'], $indexes['cm_original_name_idx']['columns']);
    }

    // ---- §1 ----------------------------------------------------------------------------------------------------------------------

    public function test_a_truncated_jpeg_is_refused_by_the_adapter_and_nothing_is_stored(): void
    {
        try {
            app(MediaStorageAdapter::class)->store($this->truncated(), 'phone.jpg');
            $this->fail('stored');
        } catch (ImageNotCleanableException $e) {
            $this->assertSame(trans('media.malformed'), $e->getMessage());
            $this->assertSame('Файлът е повреден или непълен — качете го отново.', ImageNotCleanableException::MALFORMED_BG);
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_truncated_jpeg_through_the_api_is_a_422_and_nothing_is_stored(): void
    {
        $this->actingAsAdministrator();
        config(['services.media.min_image_dimension_px' => 10]);

        $this->postJson('/api/media', ['file' => UploadedFile::fake()->createWithContent('phone.jpg', $this->truncated())])->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, MediaAssetModel::query()->count());
    }

    public function test_a_truncated_jpeg_in_the_picker_upload_is_refused_by_name_and_nothing_is_stored(): void
    {
        $this->panelAdministrator();

        $component = Livewire::test(\App\Livewire\MediaPickerBrowser::class, ['field' => 'data.logo_media_asset_id'])
            ->set('files', [UploadedFile::fake()->createWithContent('logo.jpg', $this->truncated())]);

        $this->assertSame(['logo.jpg'], array_column($component->get('refused'), 'name'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_video_and_an_unknown_file_are_not_taken_for_a_damaged_picture(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x05", 100);
        $adapter = app(MediaStorageAdapter::class);

        $this->assertStringEndsWith('.mp4', $adapter->store($mp4, 'clip.mp4')->path);
        $this->assertStringEndsWith('.bin', $adapter->store('plain bytes', 'notes.bin')->path);
    }

    // ---- §2 ----------------------------------------------------------------------------------------------------------------------

    public function test_the_api_records_the_facts_of_the_stored_original(): void
    {
        $staff = $this->actingAsAdministrator();
        config(['services.media.min_image_dimension_px' => 10]);
        $dirty = $this->jpeg($this->dirtyJpegSegments());

        $response = $this->postJson('/api/media', ['file' => UploadedFile::fake()->createWithContent("phon\x07e\u{202E}-IMG.jpg", $dirty)]);
        $response->assertCreated();

        $row = MediaAssetModel::query()->findOrFail($response->json('id'));
        $stored = Storage::disk('public')->get($row->path);

        $this->assertSame('phone-IMG.jpg', $row->original_name, 'control and bidi characters are removed');
        $this->assertSame('image/jpeg', $row->mime);
        $this->assertSame(strlen($stored), (int) $row->size_bytes, 'the size of the STORED bytes');
        $this->assertLessThan(strlen($dirty), (int) $row->size_bytes, 'not the size of the upload as it came');
        $this->assertSame([40, 30], [(int) $row->width, (int) $row->height]);
        $this->assertSame((int) $staff->id, (int) $row->uploaded_by);
    }

    public function test_a_picker_upload_records_the_name_and_the_signed_in_staff_member(): void
    {
        $staff = $this->panelAdministrator();

        Livewire::test(\App\Livewire\MediaPickerBrowser::class, ['field' => 'data.logo_media_asset_id'])
            ->set('files', [UploadedFile::fake()->createWithContent('Nike logo.jpg', $this->jpeg($this->dirtyJpegSegments()))]);

        $row = MediaAssetModel::query()->firstOrFail();
        $this->assertSame('Nike logo.jpg', $row->original_name);
        $this->assertSame('image/jpeg', $row->mime);
        $this->assertSame([40, 30], [(int) $row->width, (int) $row->height]);
        $this->assertSame((int) $staff->id, (int) $row->uploaded_by);
    }

    public function test_an_upload_without_a_signed_in_staff_member_has_no_uploader(): void
    {
        $stored = app(MediaStorageAdapter::class)->store($this->jpeg(), 'a.jpg');

        $this->assertNull($stored->details->uploadedBy);
        $this->assertSame('a.jpg', $stored->details->originalName);
    }

    public function test_the_name_is_cut_to_200_characters_and_a_path_in_it_is_dropped(): void
    {
        $this->assertSame(200, mb_strlen(MediaDetails::cleanName(str_repeat('я', 300))));
        $this->assertNull(MediaDetails::cleanName("\x01\x02 "));

        $stored = app(MediaStorageAdapter::class)->store($this->jpeg(), 'C:'.chr(92).'Users'.chr(92).'me'.chr(92).'holiday.jpg');
        $this->assertSame('holiday.jpg', $stored->details->originalName);
    }
}
