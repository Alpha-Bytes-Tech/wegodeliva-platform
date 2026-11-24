<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Storage\StorageSigner;
use App\Support\UploadPaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signed upload URLs (Phase 3 plan, 3.1). The app asks for one, PUTs the file to it, then sends
 * the returned path with the profile, ticket or prescription it belongs to.
 */
class UploadController extends Controller
{
    public function store(Request $request, StorageSigner $signer): JsonResponse
    {
        /** @var array<string, array{folder: string, types: list<string>}> $kinds */
        $kinds = config('wegodeliva.storage.kinds');
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys($kinds))],
            'content_type' => ['required', 'string'],
        ]);
        if (! in_array($data['content_type'], $kinds[$data['kind']]['types'], true)) {
            throw new ApiException('validation_failed', 400, ['content_type' => ['Upload one of: '.implode(', ', $kinds[$data['kind']]['types']).'.']]);
        }

        /** @var User $user */
        $user = $request->user();
        $path = UploadPaths::make($data['kind'], $user, $data['content_type']);
        $signed = $signer->uploadUrl($path);

        return response()->json(['data' => [
            'path' => $path,
            'upload_url' => $signed['url'],
            'token' => $signed['token'],
            'content_type' => $data['content_type'],
        ]], 201);
    }
}
