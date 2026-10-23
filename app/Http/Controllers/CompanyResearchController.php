<?php

namespace App\Http\Controllers;

use App\Services\CompanyResearchRepository;
use Inertia\Inertia;
use Inertia\Response;

class CompanyResearchController extends Controller
{
    public function index(CompanyResearchRepository $reports): Response
    {
        return Inertia::render('IrlixResearch', [
            'reports' => $reports->all(),
        ]);
    }
}
