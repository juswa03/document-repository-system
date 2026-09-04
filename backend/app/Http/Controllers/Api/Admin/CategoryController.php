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
        ]);

        $category->update($data);

        AuditLog::record(
            $request->user()->id,
            'category_edited',
            "Edited category {$category->category_name} ({$category->category_code}).",
            Category::class,
            $category->id
        );

        return response()->json($category);
    }
}
