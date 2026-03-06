<?php

namespace Plugin\TutorialImporter\Services;

use App\Models\Knowledge;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\PluginConfigService;

class TutorialImportService
{
    private string $basePath;
    private string $summaryFile;
    private array $supportedLangs = ['en-US' => 'en-US', 'zh-CN' => 'zh-CN'];
    private ?array $config = null;

    public function __construct()
    {
        $this->basePath = base_path('plugins/TutorialImporter/ppanel-tutorial');
        $this->summaryFile = $this->basePath . '/SUMMARY.md';

        // Load plugin config
        try {
            $configService = app(PluginConfigService::class);
            $fullConfig = $configService->getConfig('tutorial_importer');
            // Transform config format from service (which includes metadata) to key-value pairs
            $this->config = array_map(function($item) {
                return $item['value'];
            }, $fullConfig);
        } catch (\Exception $e) {
            Log::warning('Tutorial Import: Failed to load config: ' . $e->getMessage());
        }
    }

    public function import(): array
    {
        // Check if remote sync is configured
        $this->syncFromRemote();

        if (!File::exists($this->summaryFile)) {
            throw new \Exception("SUMMARY.md not found at {$this->summaryFile}");
        }

        try {
            $content = File::get($this->summaryFile);
            $yamlContent = preg_replace('/^---\s*\n/', '', $content);
            $summary = Yaml::parse($yamlContent);
        } catch (\Exception $e) {
            throw new \Exception('Failed to parse SUMMARY.md: ' . $e->getMessage());
        }

        $results = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($summary as $langKey => $categories) {
            if (!isset($this->supportedLangs[$langKey])) {
                continue;
            }
            $dbLang = $this->supportedLangs[$langKey];

            foreach ($categories as $categoryData) {
                $categoryName = $categoryData['title'];

                if (isset($categoryData['subItems'])) {
                    foreach ($categoryData['subItems'] as $item) {
                        $results['total']++;
                        try {
                            $this->processItem($item, $dbLang, $categoryName);
                            $results['success']++;
                        } catch (\Exception $e) {
                            $results['failed']++;
                            $results['errors'][] = "Failed to import {$item['title']} ($dbLang): " . $e->getMessage();
                            Log::error("Tutorial Import Error: " . $e->getMessage());
                        }
                    }
                } elseif (isset($categoryData['path'])) {
                     $results['total']++;
                     try {
                         $this->processItem($categoryData, $dbLang, $categoryName);
                         $results['success']++;
                     } catch (\Exception $e) {
                         $results['failed']++;
                         $results['errors'][] = "Failed to import {$categoryData['title']} ($dbLang): " . $e->getMessage();
                     }
                }
            }
        }

        return $results;
    }

    private function syncFromRemote(): void
    {
        $repoUrl = $this->config['repository_url'] ?? '';
        if (empty($repoUrl)) {
            return;
        }

        $branch = $this->config['branch'] ?? 'main';
        $targetDir = $this->basePath;

        // Check if git is installed
        exec('git --version', $output, $returnVar);
        if ($returnVar !== 0) {
            Log::error("Tutorial Import: Git is not installed on the server.");
            return;
        }

        if (File::exists($targetDir . '/.git')) {
            // Pull changes
            Log::info("Tutorial Import: Pulling changes from $repoUrl ($branch)...");
            $command = "cd " . escapeshellarg($targetDir) . " && git fetch origin && git reset --hard origin/" . escapeshellarg($branch);
        } else {
            // Clone repo
            Log::info("Tutorial Import: Cloning $repoUrl ($branch)...");
            // Ensure directory is empty or remove it first if it's not a git repo but exists
            if (File::exists($targetDir)) {
                 File::deleteDirectory($targetDir);
            }
            $command = "git clone -b " . escapeshellarg($branch) . " " . escapeshellarg($repoUrl) . " " . escapeshellarg($targetDir);
        }

        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            $errorMsg = implode("\n", $output);
            Log::error("Tutorial Import: Git sync failed: $errorMsg");
            throw new \Exception("Git sync failed: $errorMsg");
        }

        Log::info("Tutorial Import: Git sync completed successfully.");
    }

    private function processItem(array $item, string $lang, string $category)
    {
        $mdPath = $this->basePath . '/' . $item['path'];
        if (!File::exists($mdPath)) {
            throw new \Exception("File not found: {$mdPath}");
        }

        $content = File::get($mdPath);

        // Extract body (remove frontmatter if exists)
        $body = $content;
        if (Str::startsWith($content, '---')) {
            $parts = preg_split('/^---\s*$/m', $content, 3);
            if (count($parts) === 3) {
                $body = $parts[2];
            }
        }

        // Process images
        $mdDir = dirname($mdPath);
        $body = $this->processImages($body, $mdDir, $lang, $category);

        $knowledge = Knowledge::where('title', $item['title'])
            ->where('language', $lang)
            ->where('category', $category)
            ->first();

        if ($knowledge) {
            $knowledge->update([
                'body' => $body
            ]);
        } else {
            Knowledge::create([
                'title' => $item['title'],
                'language' => $lang,
                'category' => $category,
                'body' => $body,
                'sort' => 0,
                'show' => true,
            ]);
        }
    }

    private function processImages(string $body, string $mdDir, string $lang, string $category): string
    {
        return preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function ($matches) use ($mdDir, $lang, $category) {
            $alt = $matches[1];
            $src = $matches[2];

            // If remote URL, leave as is
            if (Str::startsWith($src, ['http://', 'https://', '//'])) {
                return $matches[0];
            }

            // Local file processing
            $localPath = realpath($mdDir . '/' . $src);
            if ($localPath && File::exists($localPath)) {
                $extension = File::extension($localPath);
                $fileName = md5($localPath . time()) . '.' . $extension;

                // Define storage path: public/upload/knowledge/{lang}/{category}/
                $relativePath = "upload/knowledge/{$lang}/" . Str::slug($category);
                $storagePath = public_path($relativePath);

                if (!File::exists($storagePath)) {
                    File::makeDirectory($storagePath, 0755, true);
                }

                File::copy($localPath, $storagePath . '/' . $fileName);

                $publicUrl = "/{$relativePath}/{$fileName}";
                return "![$alt]($publicUrl)";
            }

            return $matches[0];
        }, $body);
    }
}
