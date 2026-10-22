<?php

namespace App\Http\Controllers;

use App\Models\BdpBalencoCategory;
use App\Models\BdpCategoryMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BdpCategoryMappingController extends Controller
{
    public function index(Request $request)
    {
        $balencoCategories = $this->balencoCategories();
        $mappings = Schema::hasTable('bdp_category_mappings') ? BdpCategoryMapping::orderBy('source_category')->get() : collect();
        $sourceCategories = $this->sourceCategories();

        return view('balenco-data-pilot.category-mapping', compact('balencoCategories', 'mappings', 'sourceCategories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'source_category' => ['required', 'string', 'max:500'],
            'balenco_category_id' => ['required'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $category = $this->balencoCategories()->firstWhere('balenco_category_id', (string) $data['balenco_category_id']);

        if (! $category) {
            return back()->with('error', 'Selected Balenco category was not found. Please fetch categories again.');
        }

        BdpCategoryMapping::updateOrCreate(
            ['source_category' => trim($data['source_category'])],
            $this->mappingAttributes($category, $data['notes'] ?? null)
        );

        return back()->with('success', 'Category mapping saved.');
    }

    public function autoMap(Request $request)
    {
        $balencoCategories = $this->balencoCategories();
        $created = 0;

        foreach ($this->sourceCategories() as $sourceCategory) {
            if (BdpCategoryMapping::where('source_category', $sourceCategory)->exists()) {
                continue;
            }

            $sourceLast = Str::of($sourceCategory)->replace(['>', '/', '&'], ' ')->lower()->squish()->explode(' ')->filter()->last();

            $best = $balencoCategories->first(function ($cat) use ($sourceLast) {
                $haystack = Str::of(($cat->path ?: $cat->name) . ' ' . $cat->name)->lower();
                return $sourceLast && $haystack->contains((string) $sourceLast);
            });

            if ($best) {
                BdpCategoryMapping::create(array_merge(
                    ['source_category' => $sourceCategory],
                    $this->mappingAttributes($best, 'Auto-mapped by similar category name.')
                ));
                $created++;
            }
        }

        return back()->with('success', "Auto-map complete. {$created} mappings created.");
    }

    public function fetchBalencoCategories(Request $request, \App\Services\BdpShofyCategoryService $categoryService)
    {
        try {
            // Reuse the same controlled Botble connection as other legacy
            // store operations. Never use a requester-selected DB connection
            // or fall back to the Data Pilot application's own database.
            $rows = collect($categoryService->balencoCategories())
                ->map(fn (array $item) => (object) $item);

            if ($rows->isEmpty()) {
                return back()->with('error', 'The connected Botble store has no categories to import.');
            }

            $byId = $rows->keyBy('id');

            foreach ($rows as $row) {
                $parts = [trim((string) $row->name)];
                $parentId = $row->parent_id ?? null;
                $guard = 0;

                while ($parentId && $byId->has($parentId) && $guard < 20) {
                    $parent = $byId->get($parentId);
                    array_unshift($parts, trim((string) $parent->name));
                    $parentId = $parent->parent_id ?? null;
                    $guard++;
                }

                BdpBalencoCategory::updateOrCreate(
                    ['balenco_category_id' => (string) $row->id],
                    [
                        'name' => $row->name,
                        'path' => collect($parts)->filter()->implode(' > '),
                        'parent_id' => $row->parent_id ?? null,
                        'status' => $row->status ?? null,
                        'raw_json' => json_encode($row),
                    ]
                );
            }

            return back()->with('success', 'Imported '.$rows->count().' categories from your Botble store.');
        } catch (\Throwable $e) {
            // Do not display raw SQLSTATE exceptions, remote DB usernames or
            // hostnames; they are private installation configuration details.
            \Illuminate\Support\Facades\Log::warning('Botble category fetch failed', [
                'error_type' => get_class($e),
            ]);

            return back()->with(
                'error',
                'Could not fetch Botble categories. Check the connected store database '.
                'configuration (SHOFY_DB_*) or use the Botble Connector when available. '.
                'No saved category mappings were changed.'
            );
        }
    }

    /**
     * Older installations created bdp_category_mappings without optional
     * path/notes columns. Keep existing stores fully functional without a
     * destructive schema migration. Newer schemas retain rich metadata.
     */
    private function mappingAttributes(BdpBalencoCategory $category, ?string $notes): array
    {
        $attributes = [
            'balenco_category_id' => $category->balenco_category_id,
            'balenco_category_name' => $category->name,
        ];

        if (Schema::hasColumn('bdp_category_mappings', 'balenco_category_path')) {
            $attributes['balenco_category_path'] = $category->path ?: $category->name;
        }
        if (Schema::hasColumn('bdp_category_mappings', 'notes')) {
            $attributes['notes'] = $notes;
        }

        return $attributes;
    }

    private function balencoCategories()
    {
        if (! Schema::hasTable('bdp_balenco_categories')) {
            return collect();
        }

        return BdpBalencoCategory::query()->orderBy('path')->orderBy('name')->get();
    }

    private function sourceCategories()
    {
        if (! Schema::hasTable('bdp_products')) {
            return collect();
        }

        return DB::table('bdp_products')
            ->whereNotNull('categories')
            ->pluck('categories')
            ->flatMap(fn ($value) => collect(explode(',', (string) $value))->map(fn ($item) => trim($item))->filter())
            ->unique()
            ->sort()
            ->values();
    }

    private function setting(string $key, $default = null)
    {
        try {
            if (Schema::hasTable('bdp_settings')) {
                return DB::table('bdp_settings')->where('key', $key)->value('value') ?? $default;
            }
        } catch (\Throwable $e) {
            return $default;
        }

        return $default;
    }
}
