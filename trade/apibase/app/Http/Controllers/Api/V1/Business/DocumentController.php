<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business\CompanyDocument;
use App\Models\Business\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The company's document folder.
 *
 * GST, IEC, registration, tax ID, MSME, licences: the owner or an admin
 * of the page uploads them here, one at a time, with the number on each.
 * The files stay on the private disk; the page shows only the badge they
 * earn. Two documents are the minimum to ask for it.
 */
class DocumentController extends Controller
{
    use SerializesBusiness;

    public const MAX_DOCUMENTS = 10;

    private function page(Request $request): Page
    {
        $page = $request->user()->businessPage()->live()->first();
        abort_unless($page, 422, 'Open a company page first.');
        abort_unless($page->canManage($request->user()), 403, 'Only the owner or an admin keeps the documents.');

        return $page;
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->page($request);

        return response()->json(['data' => [
            'documents' => $page->documents()->latest()->get()->map(fn ($d) => $this->documentRow($d))->values(),
            'verification' => $this->verificationBlock($page),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $page = $this->page($request);
        abort_if($page->documents()->count() >= self::MAX_DOCUMENTS, 422, 'That is enough documents. Remove one to add another.');

        $data = $request->validate([
            'kind' => ['required', Rule::in(CompanyDocument::KINDS)],
            'number' => ['nullable', 'string', 'max:80'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $file = $request->file('document');
        $doc = $page->documents()->create([
            'user_id' => $request->user()->id,
            'kind' => $data['kind'],
            'number' => isset($data['number']) ? trim($data['number']) : null,
            'path' => $file->store("business-verification/{$page->id}", 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
        ]);

        $left = max(0, VerificationController::MIN_DOCUMENTS - $page->documents()->count());
        $message = $left > 0
            ? "Added. One more document and you can ask for the verified badge."
            : 'Added. You can ask for the verified badge now.';
        if ($left > 1) {
            $message = "Added. {$left} more documents and you can ask for the verified badge.";
        }

        return response()->json(['message' => $message, 'data' => $this->documentRow($doc)], 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $page = $this->page($request);
        $doc = $page->documents()->where('uuid', $uuid)->firstOrFail();
        // While the GrapOut team is looking, or once they have said yes, the folder stands as it was judged.
        abort_if(in_array($page->verification_status, ['pending', 'verified'], true), 422, 'Documents stay as they were when the badge was asked for. Contact the GrapOut team to change them.');

        Storage::disk('local')->delete($doc->path);
        $doc->delete();

        return response()->json(['message' => 'Removed.']);
    }

    protected function documentRow(CompanyDocument $d): array
    {
        return [
            'uuid' => $d->uuid,
            'kind' => $d->kind,
            'kind_label' => CompanyDocument::KIND_LABELS[$d->kind] ?? ucfirst($d->kind),
            'number' => $d->number,
            'original_name' => $d->original_name,
            'size' => $d->size,
            'uploaded_at' => $d->created_at?->toDateTimeString(),
        ];
    }
}
