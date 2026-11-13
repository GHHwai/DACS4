<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Files live on the private disk and are only streamed to conversation members.
     * Only verified raster images are shown inline; everything else is forced to
     * download, with a locked-down CSP, so an uploaded .html/.svg can never run script.
     */
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);

        $inline = $attachment->is_image && ! $request->boolean('download');

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->is_image ? $attachment->mime : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
            $inline ? 'inline' : 'attachment',
        );
    }
}
