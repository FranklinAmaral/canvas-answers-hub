<?php

use App\Http\Controllers\AiGrader\AiGraderAiProviderController;
use App\Http\Controllers\AiGrader\AiGraderBlueprintConfigController;
use App\Http\Controllers\AiGrader\AiGraderClientSettingsController;
use App\Http\Controllers\AiGrader\AiGraderCorrectionItemController;
use App\Http\Controllers\AiGrader\AiGraderLtiInstallationController;
use App\Http\Controllers\CanvasEnvironmentController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Lti\AiGraderLtiController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$graderAiDomain = config('graderai.domain');

if (! is_string($graderAiDomain) || trim($graderAiDomain) === '') {
    throw new LogicException('GRADERAI_DOMAIN must be configured.');
}

Route::domain($graderAiDomain)->group(function (): void {
    Route::prefix('lti')->name('lti.ai-grader.')->group(function (): void {
        Route::match(['get', 'post'], '/login', [AiGraderLtiController::class, 'login'])
            ->withoutMiddleware([PreventRequestForgery::class])
            ->name('login');
        Route::post('/launch', [AiGraderLtiController::class, 'launch'])
            ->withoutMiddleware([PreventRequestForgery::class])
            ->name('launch');
        Route::get('/jwks', [AiGraderLtiController::class, 'jwks'])->name('jwks');
        Route::get('/config', [AiGraderLtiController::class, 'config'])->name('config');
        Route::get('/install', [AiGraderLtiController::class, 'install'])->name('install');
        Route::post('/locale', [AiGraderLtiController::class, 'setLocale'])->name('locale');
        Route::get('/', [AiGraderLtiController::class, 'index'])->name('index');
        Route::get('/course-status', [AiGraderLtiController::class, 'courseStatus'])->name('course-status');
        Route::get('/course-export', [AiGraderLtiController::class, 'exportCourse'])->name('course-export');
        Route::post('/retry-failed', [AiGraderLtiController::class, 'retryFailed'])->name('retry-failed');
        Route::post('/force-refresh', [AiGraderLtiController::class, 'forceRefresh'])->name('force-refresh');
        Route::post('/blueprint/enable', [AiGraderLtiController::class, 'enableBlueprint'])->name('blueprint.enable');
        Route::post('/blueprint/{blueprintConfig}/settings', [AiGraderLtiController::class, 'updateBlueprintSettings'])->name('blueprint.settings');
        Route::post('/blueprint/{blueprintConfig}/toggle-enabled', [AiGraderLtiController::class, 'toggleBlueprintEnabled'])->name('blueprint.toggle-enabled');
        Route::post('/blueprint/{blueprintConfig}/sync-quizzes', [AiGraderLtiController::class, 'syncBlueprintQuizzes'])->name('blueprint.sync-quizzes');
        Route::get('/blueprint/{blueprintConfig}/export', [AiGraderLtiController::class, 'exportBlueprint'])->name('blueprint.export');
        Route::post('/quiz/{quizConfig}/toggle', [AiGraderLtiController::class, 'toggleQuiz'])->name('quiz.toggle');
        Route::post('/quiz/{quizConfig}/sync-essay-questions', [AiGraderLtiController::class, 'syncEssayQuestions'])->name('quiz.sync-essay-questions');
        Route::post('/question/{questionConfig}/update', [AiGraderLtiController::class, 'updateQuestion'])->name('question.update');
        Route::get('/items/{correctionItem}/status', [AiGraderLtiController::class, 'showStatus'])->name('show-status');
        Route::get('/items/{correctionItem}', [AiGraderLtiController::class, 'show'])->name('show');
        Route::post('/correction-items/{correctionItem}/retry', [AiGraderLtiController::class, 'retryCorrectionItem'])->name('correction-items.retry');
        Route::post('/items/{correctionItem}/review-draft', [AiGraderLtiController::class, 'saveReviewDraft'])->name('review.save-draft');
        Route::post('/items/{correctionItem}/publish', [AiGraderLtiController::class, 'publishReview'])->name('review.publish');
    });

    Route::any('/{any}', fn () => abort(404))->where('any', '.*');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('ai-grader.blueprints.index');
    }

    return redirect()->route('login');
})->name('root');
Route::middleware(['auth'])->get('/dashboard', fn () => redirect()->route('ai-grader.blueprints.index'))->name('dashboard');
Route::middleware(['auth'])->get('/home', fn () => redirect()->route('ai-grader.blueprints.index'))->name('home');

Route::get('/locale/{locale}', LocaleController::class)
    ->whereIn('locale', ['pt_BR', 'en'])
    ->name('locale.update');

Route::middleware(['auth'])->group(function () {
    // Organizations
    Route::prefix('organizations')->name('organizations.')->group(function (): void {
        Route::get('/', [OrganizationController::class, 'index'])->name('index');
        Route::get('/create', [OrganizationController::class, 'create'])->name('create');
        Route::post('/', [OrganizationController::class, 'store'])->name('store');
    });

    // Canvas Environments
    Route::prefix('canvas-environments')->name('canvas-environments.')->group(function (): void {
        Route::get('/', [CanvasEnvironmentController::class, 'index'])->name('index');
        Route::get('/create', [CanvasEnvironmentController::class, 'create'])->name('create');
        Route::post('/', [CanvasEnvironmentController::class, 'store'])->name('store');
        Route::get('/{canvasEnvironment}/edit', [CanvasEnvironmentController::class, 'edit'])->name('edit');
        Route::put('/{canvasEnvironment}', [CanvasEnvironmentController::class, 'update'])->name('update');
        Route::post('/{canvasEnvironment}/test-connection', [CanvasEnvironmentController::class, 'testConnection'])->name('test-connection');
    });

    Route::prefix('ai-grader/client-settings')->name('ai-grader.client-settings.')->group(function (): void {
        Route::get('/', [AiGraderClientSettingsController::class, 'index'])->name('index');
        Route::get('/{organization}', [AiGraderClientSettingsController::class, 'show'])->name('show');
        Route::get('/{organization}/edit', [AiGraderClientSettingsController::class, 'edit'])->name('edit');
        Route::put('/{organization}', [AiGraderClientSettingsController::class, 'update'])->name('update');
    });

    Route::prefix('ai-grader/blueprints')->name('ai-grader.blueprints.')->group(function (): void {
        Route::get('/', [AiGraderBlueprintConfigController::class, 'index'])->name('index');
        Route::get('/create', [AiGraderBlueprintConfigController::class, 'create'])->name('create');
        Route::post('/', [AiGraderBlueprintConfigController::class, 'store'])->name('store');
        Route::get('/{blueprintConfig}', [AiGraderBlueprintConfigController::class, 'show'])->name('show');
        Route::get('/{blueprintConfig}/edit', [AiGraderBlueprintConfigController::class, 'edit'])->name('edit');
        Route::put('/{blueprintConfig}', [AiGraderBlueprintConfigController::class, 'update'])->name('update');
        Route::post('/{blueprintConfig}/sync-quizzes', [AiGraderBlueprintConfigController::class, 'syncQuizzes'])->name('sync-quizzes');
        Route::get('/{blueprintConfig}/quizzes/{quizConfig}', [AiGraderBlueprintConfigController::class, 'showQuiz'])->name('quizzes.show');
        Route::put('/{blueprintConfig}/quizzes/{quizConfig}', [AiGraderBlueprintConfigController::class, 'updateQuiz'])->name('quizzes.update');
        Route::post('/{blueprintConfig}/quizzes/{quizConfig}/sync-questions', [AiGraderBlueprintConfigController::class, 'syncQuestions'])->name('quizzes.sync-questions');
        Route::get('/{blueprintConfig}/quizzes/{quizConfig}/questions/{questionConfig}/edit', [AiGraderBlueprintConfigController::class, 'editQuestion'])->name('questions.edit');
        Route::put('/{blueprintConfig}/quizzes/{quizConfig}/questions/{questionConfig}', [AiGraderBlueprintConfigController::class, 'updateQuestion'])->name('questions.update');
    });

    Route::prefix('ai-grader/ai-providers')->name('ai-grader.ai-providers.')->group(function (): void {
        Route::get('/', [AiGraderAiProviderController::class, 'index'])->name('index');
        Route::get('/create', [AiGraderAiProviderController::class, 'create'])->name('create');
        Route::post('/', [AiGraderAiProviderController::class, 'store'])->name('store');
        Route::get('/{provider}', [AiGraderAiProviderController::class, 'show'])->name('show');
        Route::get('/{provider}/edit', [AiGraderAiProviderController::class, 'edit'])->name('edit');
        Route::put('/{provider}', [AiGraderAiProviderController::class, 'update'])->name('update');
        Route::post('/{provider}/test', [AiGraderAiProviderController::class, 'test'])->name('test');
        Route::delete('/{provider}', [AiGraderAiProviderController::class, 'destroy'])->name('destroy');
    });

    Route::get('ai-grader/lti-installation', [AiGraderLtiInstallationController::class, 'index'])
        ->name('ai-grader.lti-installation.index');

    Route::prefix('ai-grader/correction-items')->name('ai-grader.correction-items.')->group(function (): void {
        Route::get('/', [AiGraderCorrectionItemController::class, 'index'])->name('index');
        Route::get('/{correctionItem}', [AiGraderCorrectionItemController::class, 'show'])->name('show');
    });
});

require __DIR__.'/auth.php';
