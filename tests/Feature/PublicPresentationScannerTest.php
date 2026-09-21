<?php

namespace Tests\Feature;

use App\Models\PresentationSource;
use App\Models\User;
use App\Services\PublicPresentationScanner;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PublicPresentationScannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function scan(string $url): array
    {
        return app(PublicPresentationScanner::class)->scan(new PresentationSource(['url' => $url]));
    }

    public function test_directory_relative_links_keep_the_event_path_and_fragments_are_deduplicated(): void
    {
        Http::fake([
            'https://conference.example/a/2026-1/' => Http::response(
                '<a href="slides/cio.pdf#page=1">Доклад CIO</a>'
                .'<a href="slides/cio.pdf#page=2">Доклад CIO</a>'
                .'<a href="../shared/talk.pptx?download=1&amp;lang=ru">Общий доклад</a>'
                .'<a href="//cdn.example/files/talk.ppt">CDN</a>'
                .'<a href="mailto:speaker@example.com">Email</a>',
                200, ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://conference.example/sitemap.xml' => Http::response('', 404),
        ]);

        $candidates = $this->scan('https://conference.example/a/2026-1/');

        $this->assertSame([
            'https://conference.example/a/2026-1/slides/cio.pdf',
            'https://conference.example/a/shared/talk.pptx?download=1&lang=ru',
            'https://cdn.example/files/talk.ppt',
        ], array_column($candidates, 'file_url'));
        $this->assertSame('Доклад CIO', $candidates[0]['title']);
        Http::assertSentCount(2);
    }

    public function test_query_only_redirect_keeps_the_current_path(): void
    {
        Http::fake([
            'https://conference.example/events/?page=1' => Http::response('', 302, ['Location' => '?page=2']),
            'https://conference.example/events/?page=2' => Http::response(
                '<a href="talk.pdf">Доклад</a>', 200, ['Content-Type' => 'text/html'],
            ),
            'https://conference.example/sitemap.xml' => Http::response('', 404),
        ]);

        $candidates = $this->scan('https://conference.example/events/?page=1');

        $this->assertSame('https://conference.example/events/talk.pdf', $candidates[0]['file_url']);
        $this->assertSame('https://conference.example/events/?page=2', $candidates[0]['source_page_url']);
    }

    public function test_direct_presentation_is_catalogued_without_an_http_request(): void
    {
        Http::fake();
        $candidates = $this->scan('https://conference.example/talk.pdf');
        $this->assertSame('https://conference.example/talk.pdf', $candidates[0]['file_url']);
        Http::assertNothingSent();
    }

    public function test_redirect_to_presentation_is_catalogued_without_downloading_it(): void
    {
        Http::fake([
            'https://conference.example/download' => Http::response('', 302, ['Location' => '/files/talk.pptx']),
        ]);

        $candidates = $this->scan('https://conference.example/download');

        $this->assertSame('https://conference.example/files/talk.pptx', $candidates[0]['file_url']);
        $this->assertSame('pptx', $candidates[0]['file_type']);
        $this->assertSame('https://conference.example/download', $candidates[0]['source_page_url']);
        Http::assertSentCount(1);
    }

    public function test_redirect_to_private_address_is_rejected_before_requesting_it(): void
    {
        Http::fake([
            'https://conference.example/redirect' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private']),
        ]);

        try {
            $this->scan('https://conference.example/redirect');
            $this->fail('Private redirect was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('IP-адрес', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_optional_sitemap_timeout_does_not_discard_page_candidates(): void
    {
        Http::fake([
            'https://conference.example/materials' => Http::response(
                '<a href="/talk.pdf">Доклад</a>', 200, ['Content-Type' => 'text/html'],
            ),
            'https://conference.example/sitemap.xml' => fn () => throw new ConnectionException('Timed out'),
        ]);

        $candidates = $this->scan('https://conference.example/materials');
        $this->assertSame('https://conference.example/talk.pdf', $candidates[0]['file_url']);
    }

    public function test_source_timeout_is_reported_in_the_ui_and_scan_history(): void
    {
        Http::fake(fn () => throw new ConnectionException('Timed out'));
        $admin = User::query()->create([
            'username' => 'scanner-admin',
            'password' => bcrypt('test-password'),
            'role' => 'admin',
        ]);
        $source = PresentationSource::query()->create([
            'name' => 'Unavailable source',
            'url' => 'https://conference.example/materials',
            'domain' => 'conference.example',
        ]);

        $this->actingAs($admin)
            ->from('/projects/cio-presentations')
            ->post('/projects/cio-presentations/sources/'.$source->id.'/scan')
            ->assertRedirect('/projects/cio-presentations')
            ->assertSessionHasErrors('scan');

        $source->refresh();
        $this->assertNotNull($source->last_scanned_at);
        $this->assertSame(0, $source->last_scan_found);
        $this->assertStringContainsString('Не удалось подключиться', $source->last_error);
    }

    public function test_non_html_response_is_rejected_without_reading_its_body(): void
    {
        $bytesRead = 0;
        $body = new PumpStream(function ($length) use (&$bytesRead) {
            $bytesRead += $length;
            return str_repeat('x', $length);
        });
        Http::fake(fn () => Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'application/pdf'], $body)));

        try {
            $this->scan('https://conference.example/download');
            $this->fail('Binary response was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTML/XML', $exception->getMessage());
        }
        $this->assertSame(0, $bytesRead);
        $this->assertFalse($body->isReadable());
    }

    public function test_oversized_content_length_is_rejected_before_reading_the_body(): void
    {
        $bytesRead = 0;
        $body = new PumpStream(function ($length) use (&$bytesRead) {
            $bytesRead += $length;
            return str_repeat('x', $length);
        });
        Http::fake(fn () => Create::promiseFor(new PsrResponse(200, [
            'Content-Type' => 'text/html', 'Content-Length' => '9000000',
        ], $body)));

        try {
            $this->scan('https://conference.example/large');
            $this->fail('Oversized response was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('слишком большая', $exception->getMessage());
        }
        $this->assertSame(0, $bytesRead);
        $this->assertFalse($body->isReadable());
    }

    public function test_stream_without_content_length_stops_at_the_byte_limit(): void
    {
        $bytesRead = 0;
        $body = new PumpStream(function ($length) use (&$bytesRead) {
            $bytesRead += $length;
            return str_repeat('x', $length);
        });
        Http::fake(function ($request, $options) use ($body) {
            $this->assertTrue($options['stream']);
            return Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'text/html'], $body));
        });

        try {
            $this->scan('https://conference.example/large');
            $this->fail('Unbounded response was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('слишком большая', $exception->getMessage());
        }
        $this->assertSame(2_500_001, $bytesRead);
        $this->assertFalse($body->isReadable());
    }
}
