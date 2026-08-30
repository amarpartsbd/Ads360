<?php

declare(strict_types=1);

namespace Tests\Unit\Client;

use App\Domains\Client\Exceptions\RejectedUpload;
use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Upload validation (spec §55).
 *
 * The point of these is that the file's *contents* decide what it is. A
 * declared MIME type or a chosen extension is a claim by the uploader and is
 * never taken at face value.
 */
final class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    private DocumentStorage $storage;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->storage = app(DocumentStorage::class);
        $this->organization = Organization::factory()->create();
    }

    #[Test]
    public function it_stores_a_genuine_pdf(): void
    {
        $file = UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.7\nbody");

        $stored = $this->storage->store($file, $this->organization, 'TRADE_LICENSE');

        $this->assertSame('application/pdf', $stored->mediaType->value);
        $this->assertSame('licence.pdf', $stored->originalFilename);
        Storage::disk('documents')->assertExists($stored->path);
    }

    #[Test]
    public function it_stores_a_genuine_png(): void
    {
        $file = UploadedFile::fake()->image('id-card.png', 800, 600);

        $stored = $this->storage->store($file, $this->organization, 'NATIONAL_ID');

        $this->assertSame('image/png', $stored->mediaType->value);
        $this->assertSame(800, $stored->width);
        $this->assertSame(600, $stored->height);
    }

    #[Test]
    public function it_rejects_a_script_renamed_as_a_pdf(): void
    {
        // The attack this exists to stop: an executable payload with a
        // harmless-looking name and a plausible declared MIME type.
        $file = UploadedFile::fake()->createWithContent(
            'invoice.pdf',
            "<?php system(\$_GET['cmd']); ?>",
        );

        $this->expectException(RejectedUpload::class);

        $this->storage->store($file, $this->organization, 'OTHER');
    }

    #[Test]
    public function it_rejects_a_file_whose_extension_disagrees_with_its_bytes(): void
    {
        // Real PNG bytes, but named as a PDF.
        $png = UploadedFile::fake()->image('real.png', 400, 400);
        $renamed = new UploadedFile($png->getRealPath(), 'renamed.pdf', 'application/pdf', null, true);

        $this->expectException(RejectedUpload::class);

        $this->storage->store($renamed, $this->organization, 'OTHER');
    }

    #[Test]
    public function it_rejects_a_disallowed_extension(): void
    {
        $file = UploadedFile::fake()->createWithContent('macro.docx', 'PK'."\x03\x04".'content');

        $this->expectException(RejectedUpload::class);

        $this->storage->store($file, $this->organization, 'OTHER');
    }

    #[Test]
    public function it_rejects_a_file_over_the_size_limit(): void
    {
        $file = UploadedFile::fake()->create('huge.pdf', (DocumentStorage::MAX_BYTES / 1024) + 100);

        $this->expectException(RejectedUpload::class);

        $this->storage->store($file, $this->organization, 'OTHER');
    }

    #[Test]
    public function it_rejects_an_empty_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('empty.pdf', '');

        $this->expectException(RejectedUpload::class);

        $this->storage->store($file, $this->organization, 'OTHER');
    }

    #[Test]
    public function it_rejects_an_image_too_small_to_read(): void
    {
        $file = UploadedFile::fake()->image('tiny.png', 50, 50);

        $this->expectException(RejectedUpload::class);

        $this->storage->store($file, $this->organization, 'NATIONAL_ID');
    }

    #[Test]
    public function it_rejects_a_riff_container_that_is_not_a_webp(): void
    {
        // RIFF is shared by AVI and WAV; only a WEBP payload is an image.
        $file = UploadedFile::fake()->createWithContent('clip.webp', 'RIFF????AVI LIST');

        $this->expectException(RejectedUpload::class);

        $this->storage->store($file, $this->organization, 'OTHER');
    }

    #[Test]
    public function nothing_is_written_when_a_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('bad.pdf', 'not a pdf at all');

        try {
            $this->storage->store($file, $this->organization, 'OTHER');
        } catch (RejectedUpload) {
            // expected
        }

        $this->assertEmpty(
            Storage::disk('documents')->allFiles(),
            'A rejected upload left bytes on disk.'
        );
    }

    #[Test]
    public function stored_paths_are_random_and_reveal_nothing_about_the_upload(): void
    {
        $first = $this->storage->store(
            UploadedFile::fake()->createWithContent('trade-licence-acme.pdf', '%PDF-1.4 a'),
            $this->organization,
            'TRADE_LICENSE',
        );

        $second = $this->storage->store(
            UploadedFile::fake()->createWithContent('trade-licence-acme.pdf', '%PDF-1.4 b'),
            $this->organization,
            'TRADE_LICENSE',
        );

        $this->assertNotSame($first->path, $second->path, 'Two uploads shared a storage path.');
        $this->assertStringNotContainsString('trade-licence-acme', $first->path);
        $this->assertStringNotContainsString('acme', $first->path);
    }

    #[Test]
    public function a_crafted_filename_cannot_escape_the_storage_directory(): void
    {
        // Held in a variable: the temporary file is removed when the fake
        // upload it belongs to is garbage collected.
        $source = UploadedFile::fake()->createWithContent('ok.pdf', '%PDF-1.4 body');

        $file = new UploadedFile(
            $source->getRealPath(),
            '../../../../etc/passwd.pdf',
            'application/pdf',
            null,
            true,
        );

        $stored = $this->storage->store($file, $this->organization, 'OTHER');

        $this->assertStringNotContainsString('..', $stored->path);
        $this->assertStringStartsWith($this->organization->public_id.'/', $stored->path);
        $this->assertStringNotContainsString('/', $stored->originalFilename);
    }

    #[Test]
    public function the_checksum_matches_the_stored_bytes(): void
    {
        $content = '%PDF-1.4 deterministic content';
        $file = UploadedFile::fake()->createWithContent('doc.pdf', $content);

        $stored = $this->storage->store($file, $this->organization, 'OTHER');

        $this->assertSame(hash('sha256', $content), $stored->checksum);
    }
}
