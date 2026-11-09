<?php

namespace Tests\Feature;

use App\Services\News\PublicPageFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class NewsAnalysisTest extends TestCase
{
    private const ORIGINAL = 'https://medio.com.ar/noticias/feria';

    private const SOURCE = 'https://otro-medio.com.ar/feria-banda';

    private const FIRST_EXCERPT = 'La feria se realizó el sábado en La Banda';

    private const SECOND_EXCERPT = 'El sábado tuvo lugar la feria en La Banda';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'clave-ficticia']);
        Http::preventStrayRequests();
    }

    public function test_it_extracts_contrasts_and_renders_a_local_article_with_real_citations(): void
    {
        $this->fakePages();
        $this->fakeOpenAi($this->localResult());

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertOk()
            ->assertSee('Feria comunitaria en La Banda')
            ->assertSee('Hechos corroborados')
            ->assertSee('Afirmaciones no verificadas')
            ->assertSee('Lenguaje sensacionalista')
            ->assertSee('Fuentes consultadas')
            ->assertSee('Copiar redacción')
            ->assertSee(self::SOURCE)
            ->assertDontSee('enlace-fabricado.com.ar')
            ->assertDontSee('ENCABEZADO PUBLICITARIO');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer clave-ficticia')
            && $request['store'] === false
            && ($request->data()['tools'] ?? null) === [['type' => 'web_search']]);
        Http::assertSent(fn (Request $request) => ($request->data()['text']['format']['strict'] ?? false)
            && str_contains($request['input'], self::SECOND_EXCERPT)
            && ! str_contains($request['input'], 'ENCABEZADO PUBLICITARIO'));
    }

    public function test_it_shows_the_claims_but_withholds_a_draft_without_corroboration(): void
    {
        $this->fakePages(false);
        $this->fakeOpenAi(array_replace($this->localResult(), ['facts' => []]), false);

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertOk()
            ->assertSee('No pudimos generar una redacción verificable')
            ->assertSee('El medio estimó una asistencia sin aportar un recuento.')
            ->assertDontSee('Copiar redacción');
    }

    public function test_it_rejects_made_up_evidence(): void
    {
        $this->fakePages();
        $result = $this->localResult();
        $result['facts'][0]['evidence'][1]['excerpt'] = 'Fragmento inventado que no aparece en la fuente';
        $this->fakeOpenAi($result);

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertUnprocessable()
            ->assertSee('No pudimos validar la evidencia')
            ->assertDontSee('Copiar redacción');
    }

    public function test_it_suppresses_a_draft_that_copies_the_original(): void
    {
        $this->fakePages();
        $this->fakeOpenAi(array_replace($this->localResult(), [
            'draft' => self::FIRST_EXCERPT.' con puestos organizados por comerciantes y familias de distintos barrios de la ciudad.',
        ]));

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertOk()
            ->assertSee('Hechos corroborados')
            ->assertDontSee('Copiar redacción');
    }

    public function test_it_explains_when_an_article_has_no_provincial_relevance(): void
    {
        $this->fakePages(false);
        $this->fakeOpenAi(array_replace($this->localResult(), [
            'relevance' => 'outside', 'location' => '', 'location_evidence' => null, 'facts' => [],
        ]), false);

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertUnprocessable()
            ->assertSee('fuera del alcance de Noticia Clara');
    }

    public function test_it_handles_inaccessible_html_and_invalid_urls(): void
    {
        $this->mock(PublicPageFetcher::class, function (MockInterface $mock) {
            $mock->expects('fetch')->with(self::ORIGINAL)->andReturn([
                'url' => self::ORIGINAL, 'contentType' => 'text/html', 'html' => '<h1>Acceso restringido</h1>',
            ]);
        });

        $this->post(route('news.analyze'), ['url' => 'no es una URL'])
            ->assertUnprocessable()
            ->assertSee('Ingresá un enlace completo');
        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertUnprocessable()
            ->assertSee('No encontramos suficiente texto');
        Http::assertNothingSent();
    }

    public function test_it_explains_when_openai_is_unavailable(): void
    {
        $this->fakePages(false);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['error' => 'internal'], 503)]);

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertUnprocessable()
            ->assertSee('OpenAI no pudo completar el análisis')
            ->assertDontSee('internal');
    }

    public function test_it_limits_repeated_submissions(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('news.analyze'), [])->assertUnprocessable();
        }
        $this->post(route('news.analyze'), [])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('Se alcanzó el límite de análisis');
        Http::assertNothingSent();
    }

    private function fakePages(bool $withSource = true): void
    {
        $this->mock(PublicPageFetcher::class, function (MockInterface $mock) use ($withSource) {
            $mock->expects('fetch')->with(self::ORIGINAL)->andReturn($this->page(self::ORIGINAL, self::FIRST_EXCERPT));
            if ($withSource) {
                $mock->expects('fetch')->with(self::SOURCE)->andReturn($this->page(self::SOURCE, self::SECOND_EXCERPT));
            }
        });
    }

    private function page(string $url, string $excerpt): array
    {
        return [
            'url' => $url, 'contentType' => 'text/html; charset=utf-8',
            'html' => <<<HTML
<html><body><nav><p>ENCABEZADO PUBLICITARIO</p></nav><main><article><h1>Una feria barrial</h1>
<p>{$excerpt} con puestos organizados por comerciantes y familias de distintos barrios de la ciudad.</p>
<p>Los vecinos recorrieron el predio durante la tarde y participaron de las actividades anunciadas por los organizadores.</p>
</article></main></body></html>
HTML,
        ];
    }

    private function fakeOpenAi(array $result, bool $withSource = true): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response('https://enlace-fabricado.com.ar/', $withSource ? [['type' => 'url_citation', 'url' => self::SOURCE]] : []))
            ->push($this->response(json_encode($result, JSON_THROW_ON_ERROR)))]);
    }

    private function response(string $text, array $annotations = []): array
    {
        return [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => $annotations]]]],
        ];
    }

    private function localResult(): array
    {
        return [
            'relevance' => 'local',
            'location' => 'La Banda',
            'location_evidence' => ['source' => 0, 'excerpt' => self::FIRST_EXCERPT],
            'facts' => [[
                'statement' => 'La feria tuvo lugar en La Banda el sábado.',
                'evidence' => [
                    ['source' => 0, 'excerpt' => self::FIRST_EXCERPT],
                    ['source' => 1, 'excerpt' => self::SECOND_EXCERPT],
                ],
            ]],
            'unverified' => ['El medio estimó una asistencia sin aportar un recuento.'],
            'language' => ['La nota describe la convocatoria con un superlativo sin respaldo.'],
            'title' => 'Feria comunitaria en La Banda',
            'draft' => 'La Banda fue sede de una feria el sábado.',
        ];
    }
}
