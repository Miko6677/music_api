<?php

namespace App\Http\Controllers;

use App\Jobs;
use App\Services;
use Illuminate\Http\Request;

class MetadataController extends Controller
{
    public function search(Request $r, string $type, Services\Catalog $catalog, Services\Todb $todb)
    {
        $catalog->model($type);
        $d = $r->validate(['q' => 'required|string|max:100']);

        return $todb->search($type, $d['q']);
    }

    public function preview(Request $r, string $type, string $id, Services\Catalog $catalog, Services\Todb $todb)
    {
        $class = $catalog->model($type);
        $class::select(['id'])->findOrFail($id);
        $d = $r->validate(['todb_id' => 'required|regex:/^[0-9]{1,20}$/']);

        return [...$todb->info($type, $d['todb_id']), 'conflict' => $class::select(['id', 'name', 'version'])->where('todb_id', $d['todb_id'])->whereKeyNot($id)->first()];
    }

    public function apply(Request $r, string $type, string $id, Services\Catalog $catalog)
    {
        $class = $catalog->model($type);
        $class::select(['id'])->findOrFail($id);
        $d = $r->validate(['todb_id' => 'required|regex:/^[0-9]{1,20}$/', 'version' => 'required|integer|min:1', 'fields' => 'present|array', 'fields.*' => 'in:name,description,image_url,release_date']);
        Jobs\RefreshMetadata::dispatch($r->user()->id, $type, $id, $d['todb_id'], $d['version'], $d['fields']);

        return ['message' => '已提交更新，完成后可在消息中查看结果。'];
    }
}
