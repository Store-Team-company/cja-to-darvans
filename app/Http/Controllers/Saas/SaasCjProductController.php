<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasCjApiException;
use App\Services\Saas\SaasCjProductDraftImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class SaasCjProductController extends Controller
{
    public function fetch(Request $request, SaasWorkspace $workspace, SaasCjProductDraftImporter $importer): RedirectResponse
    {
        // Keep all operations inside the existing authenticated workspace route.
        // No provider HTTP call happens for unauthorized or disabled workspaces.
        abort_unless($importer->canImport($request->user(), $workspace), 403);
        $validator = Validator::make($request->all(), [
            'keyword' => ['required', 'string', 'min:2', 'max:100'],
            'page' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->only('keyword', 'page'));
        }
        $data = $validator->validated();
        try {
            $stats = $importer->fetchAndStore(
                $request->user(), $workspace, trim($data['keyword']), (int) $data['page']
            );
        } catch (SaasCjApiException $e) {
            $message = match ($e->reason) {
                'product_cooldown' => 'A CJ product search was made recently. Wait one minute before searching again.',
                'token_expired' => 'CJ rejected the saved access token. Return to Connections and use Test connection to refresh it.',
                'connection_changed' => 'CJ connection changed during the request. Please try again after verifying it.',
                'rate_limited' => 'CJ is limiting product searches. Retry later to avoid wasting API points.',
                'invalid_response' => 'CJ returned an unexpected product list. No drafts were saved.',
                default => 'Could not retrieve products from CJ. No drafts were saved.',
            };
            return back()->withErrors(['cj_product' => $message]);
        }

        return back()->with('cj_product_import', sprintf(
            'CJ search completed: %d new drafts, %d updated drafts, %d skipped. Nothing sent to Botble.',
            $stats['created'], $stats['updated'], $stats['skipped']
        ));
    }
}
