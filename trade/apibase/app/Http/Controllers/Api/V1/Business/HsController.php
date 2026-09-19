<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Models\HsCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * HS codes, looked up and loaded.
 *
 * Lookup is public: a visitor filling the guest enquiry or reading a page
 * may want to know what 8302 is. Loading the table is a super admin's
 * job, by CSV, and never by hand.
 */
class HsController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        abort_if(mb_strlen($q) < 2, 422, 'Type at least two characters.');

        return response()->json([
            'data' => HsCode::lookup($q)->limit(20)->get(['code', 'description', 'level'])->map(fn ($h) => ['code' => $h->code, 'description' => $h->description, 'level' => $h->level]),
            'loaded' => HsCode::query()->exists(),
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json(['data' => ['count' => HsCode::count(), 'loaded_at' => HsCode::max('updated_at')]]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $path = $request->file('file')->store('hs-imports', 'local');
        $code = Artisan::call('trade:import-hs', ['file' => storage_path('app/private/' . $path), '--truncate' => $request->boolean('truncate')]);
        if ($code !== 0) {
            $code = Artisan::call('trade:import-hs', ['file' => storage_path('app/' . $path), '--truncate' => $request->boolean('truncate')]);
        }
        abort_unless($code === 0, 422, 'The file could not be read.');

        return response()->json(['message' => trim(Artisan::output()), 'data' => ['count' => HsCode::count()]]);
    }
}
