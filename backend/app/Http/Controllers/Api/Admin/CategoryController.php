<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'category_name' => ['required', 'string', 'max:255'],
            'category_code' => ['required', 'string', 'max:20', 'unique:categories,category_code'],
        ]);

        $category = Category::create($data);

        AuditLog::record(
            $request->user()->id,
            'category_created',
            "Created category {$category->category_name} ({$category->category_code}).",
            Category::class,
            $category->id
        );

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'category_name' => ['sometimes', 'string', 'max:255'],
            'category_code' => ['sometimes', 'string', 'max:20', Rule::unique('categories', 'category_code')->ignore($category->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $wasActive = $category->is_active;
        $category->update($data);

        $verb = array_key_exists('is_active', $data) && $data['is_active'] !== $wasActive
            ? ($data['is_active'] ? 'Reactivated' : 'Deactivated')
            : 'Edited';

        AuditLog::record(
            $request->user()->id,
            $verb === 'Edited' ? 'category_edited' : 'category_'.strtolower($verb),
            "{$verb} category {$category->category_name} ({$category->category_code}).",
            Category::class,
            $category->id
        );

        return response()->json($category);
    }
}
