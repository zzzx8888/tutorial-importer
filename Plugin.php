<?php

namespace Plugin\TutorialImporter;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\TutorialImporter\Services\TutorialImportService;
use Plugin\TutorialImporter\Services\PluginUpdateService;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\PluginConfigService;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 注册后台管理菜单
        $this->listen('admin.menu', function ($menu) {
            $menu[] = [
                'title' => 'Tutorial Import',
                'icon' => 'upload',
                'path' => '/plugin/tutorial-importer',
                'component' => 'plugin.tutorial-importer.index', // 假设前端组件
                // 或者直接是一个按钮操作，这里可能需要根据前端实现来调整
            ];
            return $menu;
        });
    }

    public function install(): void
    {
        // Plugin install logic
    }

    public function schedule(Schedule $schedule): void
    {
        $config = $this->getConfig();

        // Handle Immediate Import (runs every minute if flag is set)
        if (isset($config['immediate_import']) && $config['immediate_import'] === '1') {
            $schedule->call(function () {
                try {
                    Log::info('Tutorial Importer: Starting immediate import...');
                    $service = new TutorialImportService();
                    $service->import();

                    // Reset config flag
                    $configService = app(PluginConfigService::class);
                    $fullConfig = $configService->getDbConfig('tutorial_importer');
                    $fullConfig['immediate_import'] = '0';
                    $configService->updateConfig('tutorial_importer', $fullConfig);

                    Log::info('Tutorial Importer: Immediate import completed and flag reset.');
                } catch (\Exception $e) {
                    Log::error('Tutorial Importer: Immediate import failed: ' . $e->getMessage());
                }
            })->everyMinute();
        }

        // Handle Plugin Update Actions
        if (isset($config['update_action']) && $config['update_action'] !== 'none') {
            $schedule->call(function () use ($config) {
                try {
                    $action = $config['update_action'];
                    $updateService = new PluginUpdateService();
                    $configService = app(PluginConfigService::class);
                    $fullConfig = $configService->getDbConfig('tutorial_importer');

                    if ($action === 'check') {
                        Log::info('Tutorial Importer: Checking for updates...');
                        $result = $updateService->checkUpdate();

                        $fullConfig['update_status'] = $result['message'];
                        $fullConfig['update_action'] = 'none';

                        $configService->updateConfig('tutorial_importer', $fullConfig);
                        Log::info('Tutorial Importer: Update check completed: ' . $result['message']);

                    } elseif ($action === 'update') {
                        Log::info('Tutorial Importer: Starting update process...');
                        $check = $updateService->checkUpdate();
                        if ($check['has_update']) {
                             if ($updateService->performUpdate($check['download_url'])) {
                                 $fullConfig['update_status'] = "更新成功！当前版本：" . $check['latest_version'];
                             } else {
                                 $fullConfig['update_status'] = "更新失败，请查看日志。";
                             }
                        } else {
                             $fullConfig['update_status'] = "当前已是最新版本，无需更新。";
                        }
                        $fullConfig['update_action'] = 'none';
                        $configService->updateConfig('tutorial_importer', $fullConfig);
                        Log::info('Tutorial Importer: Update process finished.');
                    }

                } catch (\Exception $e) {
                    Log::error('Tutorial Importer: Update action failed: ' . $e->getMessage());
                    // Reset action to prevent infinite loop of error
                    try {
                        $configService = app(PluginConfigService::class);
                        $fullConfig = $configService->getDbConfig('tutorial_importer');
                        $fullConfig['update_action'] = 'none';
                        $fullConfig['update_status'] = "操作失败：" . $e->getMessage();
                        $configService->updateConfig('tutorial_importer', $fullConfig);
                    } catch (\Exception $ex) {}
                }
            })->everyMinute();
        }

        // Handle Scheduled Sync
        $interval = $config['sync_interval'] ?? 'never';
        if ($interval !== 'never') {
            $task = $schedule->call(function () {
                try {
                    Log::info('Tutorial Importer: Starting scheduled sync...');
                    $service = new TutorialImportService();
                    $service->import();
                    Log::info('Tutorial Importer: Scheduled sync completed.');
                } catch (\Exception $e) {
                    Log::error('Tutorial Importer: Scheduled sync failed: ' . $e->getMessage());
                }
            });

            if ($interval === 'daily') {
                $task->daily()->at('00:00');
            } elseif ($interval === 'weekly') {
                $task->weekly()->mondays()->at('00:00');
            }
        }
    }
}
