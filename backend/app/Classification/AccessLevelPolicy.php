<?php

namespace App\Classification;

use App\Models\Category;
use App\Models\Document;

/**
 * Step 9 of the process flow — "validate document classification and
 * access level". The reviewer checklist and the AI confidentiality hint
 * are advisory; this is the deterministic rule the system actually
 * enforces, on submission and again on approval.
 *
 * Policy lives in config/documents.php keyed by category_code, so the
 * owner can retune it without a code change. An unlisted category falls
 * back to `*` (everything allowed), so adding a category can never
 * silently start rejecting uploads.
 */
class AccessLevelPolicy
{
    /**
     * The policy row for a category id. Falls back to the `*` default
     * when the category is unknown or has no explicit entry.
     *
     * @return array{allowed: list<string>, default: string}
     */
    public function forCategoryId(?int $categoryId): array
    {
        $code = $categoryId === null
            ? null
            : Category::where('id', $categoryId)->value('category_code');

        return $this->forCategoryCode($code);
    }

    /**
     * @return array{allowed: list<string>, default: string}
     */
    public function forCategoryCode(?string $code): array
    {
        $policy = (array) config('documents.access_policy', []);
        $fallback = $policy['*'] ?? [
            'allowed' => Document::ACCESS_LEVELS,
            'default' => config('documents.default_access_level', 'internal'),
        ];

        $row = ($code !== null && is_array($policy[$code] ?? null)) ? $policy[$code] : $fallback;

        $allowed = array_values(array_intersect(
            Document::ACCESS_LEVELS,
            (array) ($row['allowed'] ?? Document::ACCESS_LEVELS),
        ));

        // A policy that allows nothing is a config mistake, not an
        // instruction to block every upload in that category.
        if ($allowed === []) {
            $allowed = Document::ACCESS_LEVELS;
        }

        $default = $row['default'] ?? $allowed[0];

        return [
            'allowed' => $allowed,
            'default' => in_array($default, $allowed, true) ? $default : $allowed[0],
        ];
    }

    public function permits(?int $categoryId, string $accessLevel): bool
    {
        return in_array($accessLevel, $this->forCategoryId($categoryId)['allowed'], true);
    }

    /**
     * A reviewer-readable explanation of why a level was refused, used as
     * the validation message so the person sees the rule, not just "no".
     */
    public function rejectionMessage(?int $categoryId, string $accessLevel): string
    {
        $allowed = $this->forCategoryId($categoryId)['allowed'];
        $name = $categoryId === null
            ? 'this category'
            : (Category::where('id', $categoryId)->value('category_name') ?: 'this category');

        return sprintf(
            '"%s" documents cannot be %s. Allowed access levels for %s: %s.',
            $name,
            $accessLevel,
            $name,
            implode(', ', $allowed),
        );
    }

    /**
     * The whole policy table, resolved to category ids, for the upload
     * form to drive its access-level dropdown off.
     *
     * @return array<int, array{allowed: list<string>, default: string}>
     */
    public function byCategoryId(): array
    {
        return Category::query()
            ->get(['id', 'category_code'])
            ->mapWithKeys(fn (Category $c) => [$c->id => $this->forCategoryCode($c->category_code)])
            ->all();
    }
}
