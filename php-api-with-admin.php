<?php

// app/Http/Controllers/API/TaskController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use App\Http\Resources\TaskResource;
use App\Http\Requests\TaskRequest;

class TaskController extends Controller
{
    public function index()
    {
        $tasks = Task::paginate(config('app.page_size'));
        return TaskResource::collection($tasks);
    }

    public function store(TaskRequest $request)
    {
        $task = Task::create($request->validated());
        return new TaskResource($task);
    }

    public function show(Task $task)
    {
        return new TaskResource($task);
    }

    public function update(TaskRequest $request, Task $task)
    {
        $task->update($request->validated());
        return new TaskResource($task);
    }

    public function destroy(Task $task)
    {
        $task->delete();
        return response()->json(null, 204);
    }
}

// app/Http/Controllers/API/WebScraperController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\WebScraperService;

class WebScraperController extends Controller
{
    protected $scraperService;

    public function __construct(WebScraperService $scraperService)
    {
        $this->scraperService = $scraperService;
    }

    public function scrape(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
            'max_pages' => 'integer|min:1|max:100',
        ]);

        $url = $request->input('url');
        $maxPages = $request->input('max_pages', 10);

        try {
            $scrapedData = $this->scraperService->scrapeWebsite($url, $maxPages);
            return response()->json($scrapedData);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Scraping failed', 'message' => $e->getMessage()], 500);
        }
    }
}

// app/Http/Controllers/Admin/DashboardController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Task;
use App\Models\ScrapedData;

class DashboardController extends Controller
{
    public function index()
    {
        $taskCount = Task::count();
        $scrapedDataCount = ScrapedData::count();

        return view('admin.dashboard', compact('taskCount', 'scrapedDataCount'));
    }
}

// app/Http/Controllers/Admin/ConfigController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ConfigController extends Controller
{
    public function show()
    {
        $config = [
            'page_size' => config('app.page_size'),
            'rate_limit' => config('app.rate_limit'),
            'api_key' => config('app.api_key'),
        ];

        return view('admin.config', compact('config'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'page_size' => 'required|integer|min:1|max:100',
            'rate_limit' => 'required|string',
            'api_key' => 'required|string|min:32',
        ]);

        $this->updateEnvFile('APP_PAGE_SIZE', $request->page_size);
        $this->updateEnvFile('APP_RATE_LIMIT', $request->rate_limit);
        $this->updateEnvFile('APP_API_KEY', $request->api_key);

        Artisan::call('config:clear');

        return redirect()->route('admin.config')->with('success', 'Configuration updated successfully.');
    }

    private function updateEnvFile($key, $value)
    {
        $path = base_path('.env');
        $content = file_get_contents($path);

        $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);

        file_put_contents($path, $content);
    }
}

// app/Models/Task.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'status'];
}

// app/Models/ScrapedData.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScrapedData extends Model
{
    use HasFactory;

    protected $fillable = ['url', 'text_file'];

    public function images()
    {
        return $this->hasMany(ScrapedImage::class);
    }
}

// app/Models/ScrapedImage.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScrapedImage extends Model
{
    use HasFactory;

    protected $fillable = ['scraped_data_id', 'url', 'filename'];

    public function scrapedData()
    {
        return $this->belongsTo(ScrapedData::class);
    }
}

// app/Services/WebScraperService.php

namespace App\Services;

use Goutte\Client;
use App\Models\ScrapedData;
use App\Models\ScrapedImage;
use Illuminate\Support\Facades\Storage;

class WebScraperService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function scrapeWebsite($url, $maxPages)
    {
        $visited = [];
        $toVisit = [$url];
        $scrapedData = [];

        while (!empty($toVisit) && count($visited) < $maxPages) {
            $url = array_shift($toVisit);
            if (in_array($url, $visited)) {
                continue;
            }

            try {
                $crawler = $this->client->request('GET', $url);

                $text = $crawler->filter('body')->text();
                $textFilename = $this->saveText($url, $text);

                $scrapedPage = ScrapedData::create([
                    'url' => $url,
                    'text_file' => $textFilename,
                ]);

                $images = [];
                $crawler->filter('img')->each(function ($node) use ($scrapedPage, &$images) {
                    $imgUrl = $node->attr('src');
                    $imgFilename = $this->saveImage($imgUrl);
                    if ($imgFilename) {
                        ScrapedImage::create([
                            'scraped_data_id' => $scrapedPage->id,
                            'url' => $imgUrl,
                            'filename' => $imgFilename,
                        ]);
                        $images[] = ['url' => $imgUrl, 'filename' => $imgFilename];
                    }
                });

                $scrapedData[] = [
                    'url' => $url,
                    'text_file' => $textFilename,
                    'images' => $images,
                ];

                $visited[] = $url;

                $crawler->filter('a')->each(function ($node) use (&$toVisit, $url) {
                    $href = $node->attr('href');
                    if ($this->isSameDomain($url, $href) && !in_array($href, $toVisit)) {
                        $toVisit[] = $href;
                    }
                });
            } catch (\Exception $e) {
                \Log::error("Error scraping {$url}: " . $e->getMessage());
            }
        }

        return [
            'scraped_pages' => count($scrapedData),
            'data' => $scrapedData,
        ];
    }

    protected function saveText($url, $text)
    {
        $filename = md5($url) . '.txt';
        Storage::put("scraped_files/{$filename}", $text);
        return $filename;
    }

    protected function saveImage($imgUrl)
    {
        try {
            $content = file_get_contents($imgUrl);
            $filename = md5($imgUrl) . '.' . pathinfo($imgUrl, PATHINFO_EXTENSION);
            Storage::put("scraped_files/{$filename}", $content);
            return $filename;
        } catch (\Exception $e) {
            \Log::error("Error saving image {$imgUrl}: " . $e->getMessage());
            return null;
        }
    }

    protected function isSameDomain($url1, $url2)
    {
        return parse_url($url1, PHP_URL_HOST) === parse_url($url2, PHP_URL_HOST);
    }
}

// routes/api.php

use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\API\WebScraperController;

Route::middleware('auth:api')->group(function () {
    Route::apiResource('tasks', TaskController::class);
    Route::post('scrape', [WebScraperController::class, 'scrape']);
});

// routes/web.php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ConfigController;

Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/config', [ConfigController::class, 'show'])->name('admin.config');
    Route::post('/config', [ConfigController::class, 'update'])->name('admin.config.update');
});

// resources/views/admin/dashboard.blade.php

@extends('layouts.admin')

@section('content')
<div class="container">
    <h1>Admin Dashboard</h1>
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Tasks</h5>
                    <p class="card-text">Total Tasks: {{ $taskCount }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Scraped Data</h5>
                    <p class="card-text">Total Scraped Pages: {{ $scrapedDataCount }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

// resources/views/admin/config.blade.php

@extends('layouts.admin')

@section('content')
<div class="container">
    <h1>API Configuration</h1>
    <form method="POST" action="{{ route('admin.config.update') }}">
        @csrf
        <div class="form-group">
            <label for="page_size">Page Size</label>
            <input type="number" class="form-control" id="page_size" name="page_size" value="{{ $config['page_size'] }}">
        </div>
        <div class="form-group">
            <label for="rate_limit">Rate Limit</label>
            <input type="text" class="form-control" id="rate_limit" name="rate_limit" value="{{ $config['rate_limit'] }}">
        </div>
        <div class="form-group">
            <label for="api_key">API Key</label>
            <input type="text" class="form-control" id="api_key" name="api_key" value="{{ $config['api_key'] }}">
        </div>
        <button type="submit" class="btn btn-primary">Update Configuration</button>
    </form>
</div>
@endsection
