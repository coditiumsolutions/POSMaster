<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProductCategoriesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $categories = ProductCategory::orderBy('category_name')->paginate(50);

        return view('product-categories.index', compact('categories'));
    }

    public function create()
    {
        return view('product-categories.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_code' => 'required|string|max:50|unique:product_categories',
            'category_name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'category_image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:2048',
        ]);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['created_date'] = now();
        unset($validated['category_image']);

        $category = ProductCategory::create($validated);

        if ($request->hasFile('category_image')) {
            $this->storeCategoryImage($category, $request->file('category_image'));
        }

        return redirect()->route('product-categories.index')->with('success', 'Category created!');
    }

    public function edit($uid)
    {
        $category = ProductCategory::findOrFail($uid);

        return view('product-categories.edit', compact('category'));
    }

    public function update(Request $request, $uid)
    {
        $category = ProductCategory::findOrFail($uid);
        $validated = $request->validate([
            'category_code' => 'required|string|max:50|unique:product_categories,category_code,' . $uid . ',uid',
            'category_name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'category_image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:2048',
            'remove_category_image' => 'nullable|boolean',
        ]);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['updated_date'] = now();
        unset($validated['category_image'], $validated['remove_category_image']);

        if ($request->boolean('remove_category_image') && !empty($category->image_path)) {
            $this->deleteCategoryImageFile($category->image_path);
            $validated['image_path'] = null;
            $validated['image_name'] = null;
        }

        $category->update($validated);

        if ($request->hasFile('category_image')) {
            $this->storeCategoryImage($category, $request->file('category_image'));
        }

        return redirect()->route('product-categories.index')->with('success', 'Category updated!');
    }

    public function destroy($uid)
    {
        $category = ProductCategory::findOrFail($uid);
        $label = trim(($category->category_code ? $category->category_code . ' - ' : '') . ($category->category_name ?? 'Category'));
        $productCount = Product::where('category_uid', $uid)->count();

        if ($productCount > 0) {
            return back()->with(
                'error',
                "Cannot delete \"{$label}\" because it has {$productCount} product(s). Move or delete those products first."
            );
        }

        try {
            $imagePath = $category->image_path;
            $category->delete();
            $this->deleteCategoryImageFile($imagePath);
        } catch (QueryException $e) {
            return back()->with(
                'error',
                "Could not delete \"{$label}\" because it is still referenced by other records in the database."
            );
        }

        return back()->with('success', "Category \"{$label}\" deleted successfully!");
    }

    private function storeCategoryImage(ProductCategory $category, UploadedFile $file): void
    {
        $dir = public_path('uploads/categories');
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (!empty($category->image_path)) {
            $this->deleteCategoryImageFile($category->image_path);
        }

        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $storedName = 'category-' . $category->uid . '-' . Str::lower(Str::random(8)) . '.' . $ext;
        $file->move($dir, $storedName);

        $category->update([
            'image_path' => 'uploads/categories/' . $storedName,
            'image_name' => $originalName,
            'updated_date' => now(),
        ]);

        $publicHtmlDir = base_path('public_html/uploads/categories');
        if (File::isDirectory(base_path('public_html'))) {
            if (!File::isDirectory($publicHtmlDir)) {
                File::makeDirectory($publicHtmlDir, 0755, true);
            }
            $src = $dir . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($src)) {
                @copy($src, $publicHtmlDir . DIRECTORY_SEPARATOR . $storedName);
            }
        }
    }

    private function deleteCategoryImageFile(?string $relativePath): void
    {
        $relativePath = trim((string) $relativePath);
        if ($relativePath === '' || preg_match('#^https?://#i', $relativePath)) {
            return;
        }

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        foreach ([public_path($relativePath), base_path('public_html/' . $relativePath)] as $full) {
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }
}
