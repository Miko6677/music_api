<?php

namespace App\Services;

use App\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CommunityHistory
{
    public function page(Request $request, int $pageSize): array
    {
        $page = (int)$request->input('page', 1);
        $query = Models\ActivityLog::select(Models\ActivityLog::FIELDS)->where('entity_type', '!=', 'playlists')->with('user:' . implode(',', Models\User::PUBLIC_FIELDS));
        $this->filter($query, $request);
        if ($request->filled('user')) {
            $query->whereIn('user_id', Models\User::select(['id'])->named(trim($request->input('user'))));
        }
        if ($request->boolean('mine')) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->input('entity_id'));
        }
        $total = $query->count();
        $items = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $pageSize)->limit($pageSize)->get()->map(function ($log) {
            $data = $log->toArray();
            foreach (['before', 'after'] as $key) {
                if (is_array($data[$key] ?? null)) {
                    $data[$key] = array_filter($data[$key], fn($value, $field) => !str_starts_with($field, '_'), ARRAY_FILTER_USE_BOTH);
                }
            }

            return $data;
        })->all();

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    private function filter(Builder $query, Request $request): void
    {
        $category = $request->input('category') ?: Models\ActivityLog::DEFAULT_CATEGORY;
        if ($category === Models\ActivityLog::DEFAULT_CATEGORY) {
            $query->whereNotIn('action', Models\ActivityLog::CATEGORY_ACTIONS['upload']);
        } elseif ($category === 'organize') {
            $query->organized();
        } elseif ($category === 'other') {
            $query->whereNotIn('action', array_merge(...array_values(Models\ActivityLog::CATEGORY_ACTIONS)));
        } elseif (isset(Models\ActivityLog::CATEGORY_ACTIONS[$category])) {
            $query->whereIn('action', Models\ActivityLog::CATEGORY_ACTIONS[$category]);
            if ($category === 'update') {
                $query->whereNot(fn($organized) => $organized->organized());
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<', Carbon::parse($request->input('to'))->startOfDay()->addDay());
        }
    }
}
