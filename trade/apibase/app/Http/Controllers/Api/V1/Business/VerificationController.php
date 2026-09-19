<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business\CompanyDocument;
use App\Models\Business\Page;
use App\Notifications\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The verified badge.
 *
 * A page keeps a folder of documents — GST, IEC, a company registration,
 * a tax ID — and asks once at least two are in it. A super admin looks
 * at each and decides. The files live on the private disk and are
 * streamed to the admin only; they never appear on the page. What the
 * page shows is the badge and what kind of document earned it.
 */
class VerificationController extends Controller
{
    use SerializesBusiness;

    public const KINDS = CompanyDocument::KINDS;

    /** One certificate proves a number; two prove a company. */
    public const MIN_DOCUMENTS = 2;

    /** The owner asks, on the folder as it stands. */
    public function request(Request $request): JsonResponse
    {
        $page = $request->user()->businessPage()->live()->first();
        abort_unless($page, 422, 'Open a company page first.');
        abort_unless($page->canManage($request->user()), 403, 'Only the owner or an admin asks for verification.');
        abort_if($page->verification_status === 'verified', 422, 'This page is already verified.');
        abort_if($page->verification_status === 'pending', 422, 'Your request is with the GrapOut team.');

        $docs = $page->documents()->oldest()->get();
        $short = self::MIN_DOCUMENTS - $docs->count();
        abort_if($short > 0, 422, $short === 1 ? 'Add one more document first — the badge needs at least two.' : "Add {$short} more documents first — the badge needs at least " . self::MIN_DOCUMENTS . '.');

        $first = $docs->first();
        $page->update([
            'verification_status' => 'pending',
            'verification_kind' => $first->kind,
            'verification_number' => $first->number,
            'verification_document_path' => $first->path,
            'verification_note' => null,
            'verification_requested_at' => now(),
        ]);

        return response()->json(['message' => 'Sent. The GrapOut team will look at your documents and you will be told.', 'data' => $this->verificationBlock($page->fresh())]);
    }

    // --- Super admin -----------------------------------------------------------------

    public function pending(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $rows = Page::with(['owner', 'documents'])
            ->when($status !== 'all', fn ($b) => $b->where('verification_status', $status))
            ->when($status === 'all', fn ($b) => $b->where('verification_status', '!=', 'none'))
            ->orderBy('verification_requested_at')
            ->paginate(30);
        $rows->getCollection()->transform(fn (Page $p) => [
            'uuid' => $p->uuid,
            'slug' => $p->slug,
            'name' => $p->name,
            'country' => $p->country,
            'owner' => ['name' => $p->owner?->name, 'email' => $p->owner?->email, 'uuid' => $p->owner?->uuid],
            'verification' => $this->verificationBlock($p),
            'has_document' => $p->documents->isNotEmpty() || (bool) $p->verification_document_path,
            'documents' => $p->documents->map(fn (CompanyDocument $d) => [
                'uuid' => $d->uuid, 'kind' => $d->kind, 'kind_label' => CompanyDocument::KIND_LABELS[$d->kind] ?? ucfirst($d->kind),
                'number' => $d->number, 'original_name' => $d->original_name, 'size' => $d->size, 'uploaded_at' => $d->created_at?->toDateTimeString(),
            ])->values(),
        ]);

        return response()->json($rows);
    }

    /** One document from the folder, for the admin's eyes. Without a document id, the first. */
    public function document(Request $request, string $uuid, ?string $doc = null)
    {
        $page = Page::where('uuid', $uuid)->firstOrFail();
        $path = $doc
            ? $page->documents()->where('uuid', $doc)->firstOrFail()->path
            : ($page->documents()->oldest()->value('path') ?? $page->verification_document_path);
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'No document on file.');

        return Storage::disk('local')->response($path);
    }

    public function decide(Request $request, string $uuid): JsonResponse
    {
        $page = Page::with('owner')->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected', 'none'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $page->update([
            'verification_status' => $data['decision'],
            'verification_note' => $data['note'] ?? null,
            'verified_at' => $data['decision'] === 'verified' ? now() : null,
        ]);
        AuditLog::record($request->user(), 'business.verification.' . $data['decision'], $page->owner, ['page' => $page->slug, 'note' => $data['note'] ?? null]);

        $page->notifyTeam(new SocialNotification(
            'business_verification',
            $data['decision'] === 'verified'
                ? "{$page->name} is now verified on GrapOut Trade."
                : "Verification for {$page->name} was not approved." . (! empty($data['note']) ? " Note: {$data['note']}" : ''),
            ['page_slug' => $page->slug, 'decision' => $data['decision']],
            '/business?tab=about',
            'business-verification-' . $page->uuid,
        ));

        return response()->json(['message' => 'Done.', 'data' => $this->verificationBlock($page->fresh())]);
    }
}
