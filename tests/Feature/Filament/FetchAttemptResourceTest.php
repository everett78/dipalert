<?php

namespace Tests\Feature\Filament;

use App\Enums\FetchOutcome;
use App\Enums\FetchSource;
use App\Filament\Resources\FetchAttemptResource;
use App\Filament\Resources\FetchAttemptResource\Pages\ListFetchAttempts;
use App\Models\FetchAttempt;
use App\Models\Url;
use App\Models\User;
use App\Services\FailedPageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FetchAttemptResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function attempt(FetchOutcome $outcome, ?string $page = null): FetchAttempt
    {
        $url = Url::factory()->create();

        return FetchAttempt::query()->create([
            'url_id' => $url->id,
            'store_id' => $url->store_id,
            'outcome' => $outcome,
            'source' => FetchSource::Run,
            'http_status' => 403,
            'page_path' => $page !== null ? FailedPageStore::save($page) : null,
        ]);
    }

    public function test_lists_only_problems(): void
    {
        Storage::fake(FailedPageStore::DISK);
        $this->actingAs(User::factory()->admin()->create());

        $ok = $this->attempt(FetchOutcome::Ok);
        $blocked = $this->attempt(FetchOutcome::Blocked);

        Livewire::test(ListFetchAttempts::class)
            ->assertCanSeeTableRecords([$blocked])
            ->assertCanNotSeeTableRecords([$ok]);
    }

    public function test_saved_page_is_shown_in_a_sandbox_without_scripts(): void
    {
        Storage::fake(FailedPageStore::DISK);
        $this->actingAs(User::factory()->admin()->create());

        $attempt = $this->attempt(FetchOutcome::Blocked, '<html><script>alert(1)</script>blocked</html>');

        $response = $this->get(FetchAttemptResource::getUrl('view', ['record' => $attempt]))->assertOk();

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<iframe\s+sandbox\s/', $html);
        $this->assertStringNotContainsString('allow-scripts', $html);
        // The page is escaped inside srcdoc, never live in the panel.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_only_administrators_see_the_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(FetchAttemptResource::getUrl('index'))->assertForbidden();
    }
}
