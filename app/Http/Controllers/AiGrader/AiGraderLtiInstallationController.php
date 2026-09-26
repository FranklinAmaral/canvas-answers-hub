<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiGrader;

use App\Http\Controllers\Controller;
use App\Services\AiGrader\AiGraderLtiConfigService;
use Illuminate\Contracts\View\View;

class AiGraderLtiInstallationController extends Controller
{
    public function __construct(
        private readonly AiGraderLtiConfigService $ltiConfigService,
    ) {
    }

    public function index(): View
    {
        return view('ai-grader.lti-installation.index', [
            'urls' => $this->ltiConfigService->urls(),
            'configJson' => $this->ltiConfigService->prettyJson(),
        ]);
    }
}
