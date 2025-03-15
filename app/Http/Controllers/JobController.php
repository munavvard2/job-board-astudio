<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Services\JobFilterService;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request): \Illuminate\Database\Eloquent\Collection
    {
        $jobs = Job::query()
            ->with(['languages','locations','categories','jobAttributes','jobAttributes.attribute']);
        $reqData = request()->all();
//        if(isset($reqData['title'])){
//            $jobs = $jobs->where("title", "like", "%".$reqData['title']."%");
//        }
        $jobs = JobFilterService::applyFilters($jobs, $request->input('filter'));
        return $jobs->get();
    }
}
