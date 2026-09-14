<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreCategoryRequest;
use App\Http\Requests\App\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — CategoryController
//  Location: app/Http/Controllers/App/CategoryController.php
//
//  Backs the "Category" dropdown on Expenses (kind=expense) and
//  Equipment & Vehicles (kind=equipment) — two independent lists
//  sharing one table.
// ══════════════════════════════════════════════════════════════════
class CategoryController extends Controller
{
    /**
     * ?kind=expense|equipment filters which list to return.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()->orderBy('name');

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind'));
        }

        return response()->json($query->get(['id', 'name', 'kind']));
    }

    public function store(StoreCategoryRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        // Any equipment category the user adds themselves gets the
        // default 5-year useful life — entirely invisible to them,
        // only ever read by the depreciation command.
        if (($data['kind'] ?? null) === 'equipment') {
            $data['default_useful_life_years'] = Category::DEFAULT_EQUIPMENT_USEFUL_LIFE_YEARS;
        }

        $category = Category::create($data);

        if ($request->wantsJson()) {
            return response()->json($category, 201);
        }

        return back()->with('success', 'Category added.');
    }

    /**
     * Fix a typo without leaving the page (pencil icon next to any
     * Category combo). JSON-only, same as CustomerController::update().
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json($category);
    }
}
