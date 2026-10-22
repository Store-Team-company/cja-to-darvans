<?php

namespace Botble\DatapilotConnector\Http\Controllers;

use Botble\DatapilotConnector\Models\IncomingDraft;
use Botble\DatapilotConnector\Models\ProductMapping;
use Botble\DatapilotConnector\Services\CatalogImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class IncomingProductsController extends Controller
{
    public function index(): View
    {
        $drafts = IncomingDraft::query()
            ->with('pairing')
            ->latest('id')
            ->paginate(20);

        $mappings = ProductMapping::query()
            ->whereIn('source_product_id', $drafts->pluck('source_product_id')->all())
            ->get()->keyBy(fn ($mapping) => $mapping->pairing_id.'|'.$mapping->source_product_id);

        return view('plugins/datapilot-connector::incoming-products', compact('drafts', 'mappings'));
    }

    public function approve(Request $request, IncomingDraft $draft, CatalogImportService $importer): RedirectResponse
    {
        if (!config('datapilot-connector.catalog_import_enabled', false)) {
            return back()->withErrors(['catalog' => 'Native catalog imports are disabled until Botble compatibility QA passes.']);
        }

        try {
            $result = $importer->approve($draft);
            return back()->with('status', sprintf(
                'Saved native Botble draft product #%d with %d variations.',
                $result['product_id'],
                $result['variation_count']
            ));
        } catch (RuntimeException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Data Pilot native catalog import failed', [
                'draft_id' => $draft->getKey(),
                'exception_type' => get_class($e),
            ]);

            return back()->withErrors(['catalog' => 'Native import failed without committing the catalog changes. Inspect the private server logs and verify the installed Botble version.']);
        }
    }
}
