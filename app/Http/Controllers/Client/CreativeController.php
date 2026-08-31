<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Campaign\Actions\UploadCreative;
use App\Domains\Campaign\Enums\CreativeMediaType;
use App\Domains\Campaign\Enums\CreativeType;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Client\Exceptions\RejectedUpload;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The client's creative library (spec §23).
 *
 * Uploads are validated twice: Laravel checks the shape of the request, and
 * CreativeStorage checks the bytes. The second check is the one that matters,
 * because the first only knows what the uploader claimed.
 */
final class CreativeController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Creative::class);

        $organization = $this->context->requireOrganization();

        $creatives = Creative::query()
            ->where('organization_id', $organization->getKey())
            ->withCount('ads')
            ->orderByDesc('created_at')
            ->paginate(24);

        return Inertia::render('Client/Creatives/Index', [
            'creatives' => $creatives->through(static fn (Creative $creative): array => [
                'id' => $creative->public_id,
                'name' => $creative->name,
                'type' => $creative->type->value,
                'typeLabel' => $creative->type->label(),
                'dimensions' => $creative->dimensions(),
                'sizeLabel' => round($creative->byte_size / 1024).' KB',
                'usedByAds' => $creative->ads_count,
                'uploadedAt' => $creative->created_at?->toIso8601String(),
                'can' => ['delete' => Gate::allows('delete', $creative)],
            ]),
            'upload' => [
                'acceptedExtensions' => CreativeMediaType::allowedExtensions(),
                'maxImageBytes' => CreativeType::Image->maximumBytes(),
                'maxVideoBytes' => CreativeType::Video->maximumBytes(),
                'minimumDimension' => CreativeType::Image->minimumDimension(),
            ],
            'can' => ['create' => Gate::allows('create', Creative::class)],
        ]);
    }

    public function store(Request $request, UploadCreative $upload): RedirectResponse
    {
        Gate::authorize('create', Creative::class);

        $request->validate([
            // The largest type's ceiling; the storage service applies the
            // per-type limit once it knows what the file actually is.
            'file' => [
                'required',
                'file',
                'max:'.(int) (CreativeType::Video->maximumBytes() / 1024),
                'mimes:'.implode(',', CreativeMediaType::allowedExtensions()),
            ],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $upload->handle(
                file: $request->file('file'),
                organization: $this->context->requireOrganization(),
                actor: $request->user(),
                name: $request->string('name')->toString() ?: null,
            );
        } catch (RejectedUpload $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'File added to your library.');
    }

    public function destroy(Request $request, Creative $creative, UploadCreative $upload): RedirectResponse
    {
        Gate::authorize('delete', $creative);

        try {
            $upload->delete($creative, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'File removed.');
    }
}
