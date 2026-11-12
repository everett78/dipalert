<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DailyReport;
use App\Dto\FetchContext;
use App\Enums\FetchOutcome;
use App\Enums\FetchSource;
use App\Jobs\UpdateProductPricesJob;
use App\Models\FetchAttempt;
use App\Models\FetchPacing;
use App\Models\Url;
use App\Models\User;
use App\Notifications\DailyStatusNotification;
use App\Services\DailyStatusReport;
use App\Services\Helpers\SettingsHelper;
use App\Services\PriceFetcherService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    protected const string NOW = '2026-11-12 07:30:00';

    protected string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::setSetting('scrape_schedule', '0 7,13,19 * * *');
        SettingsHelper::setSetting('notification_services', ['mail' => ['enabled' => true]]);
        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->backupDir = storage_path('framework/testing/backups-'.uniqid());
        File::ensureDirectoryExists($this->backupDir);
        File::put($this->backupDir.'/dipalert-20261112-0300-nightly.sql.gz', str_repeat('x', 2048));

        config([
            'dipalert.backup_path' => $this->backupDir,
            'dipalert.flaresolverr_url' => 'http://192.168.0.21:30098',
            'dipalert.allowed_internal_hosts' => ['192.168.0.21:30098'],
            'app.version' => 'v0.3.0',
        ]);
        Http::fake(['192.168.0.21:30098/*' => Http::response('FlareSolverr is ready!', 200)]);

        User::factory()->admin()->create(['email' => 'admin@example.com']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Runs scheduled at 13:00 and 19:00 yesterday are judged; 07:00 today is in progress.
     */
    protected function batch(string $at, bool $finished = true, int $total = 5, int $failed = 0): void
    {
        $start = Carbon::parse($at);

        DB::table('job_batches')->insert([
            'id' => (string) Str::uuid(),
            'name' => PriceFetcherService::runName($start),
            'total_jobs' => $total,
            'pending_jobs' => $finished ? 0 : $total,
            'failed_jobs' => $failed,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => null,
            'created_at' => $start->getTimestamp(),
            'finished_at' => $finished ? $start->copy()->addMinutes(30)->getTimestamp() : null,
        ]);
    }

    protected function bothRunsFinished(): void
    {
        $this->batch('2026-11-11 13:00');
        $this->batch('2026-11-11 19:00');
    }

    protected function attempt(Url $url, FetchOutcome $outcome, string $at): void
    {
        $attempt = FetchAttempt::query()->create([
            'url_id' => $url->id,
            'store_id' => $url->store_id,
            'outcome' => $outcome,
            'source' => FetchSource::Run,
        ]);
        $attempt->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    protected function assertSubject(string $subject): void
    {
        Notification::assertSentOnDemand(
            DailyStatusNotification::class,
            fn (DailyStatusNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->report['subject'] === $subject
                && $notifiable->routes['mail'] === 'admin@example.com',
        );
    }

    public function test_ok_e_mail_when_nothing_failed(): void
    {
        $this->bothRunsFinished();
        $url = Url::factory()->create();
        $this->attempt($url, FetchOutcome::Ok, '2026-11-11 13:05');

        $this->artisan(DailyReport::COMMAND)->assertExitCode(0);

        $this->assertSubject(DailyStatusReport::SUBJECT_OK);
    }

    public function test_failure_when_a_run_did_not_start(): void
    {
        $this->batch('2026-11-11 13:00');

        $this->artisan(DailyReport::COMMAND)->assertExitCode(0);

        $this->assertSubject(DailyStatusReport::SUBJECT_FAILURE);
    }

    public function test_failure_when_a_run_did_not_finish(): void
    {
        $this->batch('2026-11-11 13:00');
        $this->batch('2026-11-11 19:00', finished: false);

        $this->artisan(DailyReport::COMMAND)->assertExitCode(0);

        $this->assertSubject(DailyStatusReport::SUBJECT_FAILURE);
    }

    public function test_failure_when_flaresolverr_does_not_answer(): void
    {
        $this->bothRunsFinished();
        Http::fake(['192.168.0.21:30098/*' => Http::failedConnection()]);

        $report = (new DailyStatusReport)->build();

        $this->assertSame(DailyStatusReport::SUBJECT_FAILURE, $report['subject']);
        $this->assertSame('down', $report['flareSolverr']['status']);
    }

    public function test_notes_count_problem_links_and_paused_stores(): void
    {
        $this->bothRunsFinished();
        $blocked = Url::factory()->create();
        $recovered = Url::factory()->create();
        $this->attempt($blocked, FetchOutcome::Blocked, '2026-11-11 19:02');
        $this->attempt($blocked, FetchOutcome::Blocked, '2026-11-11 19:03');
        // Failed earlier, fine on the last read: not a problem now.
        $this->attempt($recovered, FetchOutcome::NetworkError, '2026-11-11 13:02');
        $this->attempt($recovered, FetchOutcome::Ok, '2026-11-11 19:02');
        FetchPacing::query()->create(['host' => 'allegro.pl', 'paused_until' => now()->addHours(5), 'pause_level' => 1]);

        $report = (new DailyStatusReport)->build();

        $this->assertSame('DipAlert: uwagi (2)', $report['subject']);
        $this->assertSame('allegro.pl', $report['paused'][0]['host']);
    }

    public function test_recent_run_is_in_progress_and_not_judged(): void
    {
        $this->bothRunsFinished();

        $runs = (new DailyStatusReport)->runs();

        $this->assertSame(
            [DailyStatusReport::RUN_OK, DailyStatusReport::RUN_OK, DailyStatusReport::RUN_IN_PROGRESS],
            array_column($runs, 'status'),
        );
        $this->assertSame('2026-11-12 07:00', $runs[2]['at']->format('Y-m-d H:i'));
    }

    public function test_empty_run_counts_as_finished(): void
    {
        // A run with no product to check still creates and finishes its batch (NoopJob).
        foreach (['2026-11-11 13:00', '2026-11-11 19:00'] as $at) {
            Carbon::setTestNow(Carbon::parse($at));
            PriceFetcherService::new()->updateAllPrices();
        }
        Carbon::setTestNow(Carbon::parse(self::NOW));

        $report = (new DailyStatusReport)->build();

        $this->assertSame(DailyStatusReport::SUBJECT_OK, $report['subject']);
        $this->assertSame(1, $report['runs'][0]['total']);
    }

    public function test_product_deleted_during_a_run_is_not_a_failure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 13:00'));
        Bus::batch([new UpdateProductPricesJob(999999, FetchContext::run())])
            ->name(PriceFetcherService::runName(now()))
            ->allowFailures()
            ->dispatch();
        $this->batch('2026-11-11 19:00');
        Carbon::setTestNow(Carbon::parse(self::NOW));

        $runs = (new DailyStatusReport)->runs();

        $this->assertSame(DailyStatusReport::RUN_OK, $runs[0]['status']);
        $this->assertSame(0, $runs[0]['failed']);
    }

    public function test_mail_server_error_is_tried_three_times(): void
    {
        $this->bothRunsFinished();
        // On-demand notifications go through the notification dispatcher.
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('sendNow')->times(3)->andThrow(new RuntimeException('SMTP down'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->artisan(DailyReport::COMMAND)->assertExitCode(1);

        Sleep::assertSleptTimes(2);
    }

    public function test_command_fails_when_e_mail_is_turned_off(): void
    {
        SettingsHelper::setSetting('notification_services', ['mail' => ['enabled' => false]]);

        $this->artisan(DailyReport::COMMAND)->assertExitCode(1);

        Notification::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_daily_e_mail_is_turned_off(): void
    {
        SettingsHelper::setSetting('daily_report_enabled', false);

        $this->artisan(DailyReport::COMMAND)->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_mail_renders(): void
    {
        $this->bothRunsFinished();
        $url = Url::factory()->create();
        $this->attempt($url, FetchOutcome::Blocked, '2026-11-11 19:02');

        $mail = (new DailyStatusNotification((new DailyStatusReport)->build()))->toMail(new AnonymousNotifiable);
        $html = (string) $mail->render();

        $this->assertStringContainsString('DipAlert: uwagi (1)', $html);
        $this->assertStringContainsString('blokada', $html);
        $this->assertStringContainsString('v0.3.0', $html);
    }
}
