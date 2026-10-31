<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Extensions\ExtensionAssets;
use Maxcxam\Larsadmin\Larsadmin;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the built files of registered extensions to panel users (ADR-031). Only regular files inside the
 * registered directory with an allow-listed type are served; anything else does not exist (404).
 *
 * @internal Not part of the public API.
 */
class ExtensionAssetController
{
    public function show(Request $request, string $vendor, string $package, string $version, string $file): Response
    {
        $extension = Larsadmin::extensionFor($vendor.'/'.$package) ?? abort(404);

        $path = ExtensionAssets::resolve($extension, $file) ?? abort(404);
        $type = ExtensionAssets::contentType($path) ?? abort(404);

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], false, null, true, true);

        if (str_starts_with($type, 'image/svg+xml')) {
            $response->headers->set('Content-Security-Policy', 'sandbox');
        }

        // A matching version is immutable; an old one (a tab open during a deploy) is served but revalidated.
        if (hash_equals($extension->version(), $version)) {
            $response->headers->set('Cache-Control', 'private, max-age='.max(0, (int) config('larsadmin.extensions.max_age', 31536000)).', immutable');
        } else {
            $response->headers->set('Cache-Control', 'no-cache, private');
        }

        $response->isNotModified($request);

        return $response;
    }
}
