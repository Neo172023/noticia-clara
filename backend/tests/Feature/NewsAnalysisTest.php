<?php

namespace Tests\Feature;

use App\Services\News\PublicPageFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class NewsAnalysisTest extends TestCase
{
    private const ORIGINAL = 'https://www.medio.com.ar/noticias/feria';

    private const SOURCE = 'https://otro-medio.com.ar/feria-banda';

    private const FIRST_EXCERPT = 'La feria se realizó el sábado en La Banda';

    private const SECOND_EXCERPT = 'El sábado tuvo lugar la feria en La Banda';

    private const ORIGINAL_ESTIMATE = 'El municipio informó que se registraron 800 asistentes';

    private const CONTRAST_ESTIMATE = 'El informe del medio registró 400 asistentes';

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
            ->assertSee('Afirmaciones no corroboradas')
            ->assertSee('Afirmaciones contradictorias')
            ->assertSee('Lenguaje sensacionalista')
            ->assertSee('Fuentes consultadas')
            ->assertSee('Copiar redacción')
            ->assertSee(self::SOURCE)
            ->assertDontSee('enlace-fabricado.com.ar')
            ->assertDontSee('ENCABEZADO PUBLICITARIO');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer clave-ficticia')
            && $request['store'] === false
            && ($request->data()['tools'] ?? null) === [['type' => 'web_search']]);
        Http::assertSent(fn (Request $request) => ($request->data()['text']['format']['strict'] ?? false)
            && ($request->data()['text']['format']['name'] ?? null) === 'contraste_noticia'
            && str_contains($request['input'], self::SECOND_EXCERPT)
            && ! str_contains($request['input'], 'ENCABEZADO PUBLICITARIO'));
    }

    public function test_it_shows_the_claims_but_withholds_a_draft_without_corroboration(): void
    {
        $this->fakePages(false);
        $this->fakeOpenAi(array_replace($this->localResult(), ['facts' => []]), false);

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertOk()
            ->assertSee('No pudimos acceder a otras fuentes para verificar la noticia')
            ->assertSee('El original atribuyó un aumento de ventas a la feria.')
            ->assertDontSee('Copiar redacción');
        Http::assertSentCount(2);
    }

    public function test_it_displays_conflicting_versions_with_citations_and_excludes_them_from_the_rewrite_input(): void
    {
        $this->fakePages();
        $result = $this->localResult();
        $result['contradictions'] = [$this->conflictingVersions()];
        $this->fakeOpenAi($result);

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertOk()
            ->assertSeeInOrder([
                'Hechos corroborados', 'La feria tuvo lugar en La Banda el sábado.',
                'Afirmaciones no corroboradas', $result['unverified'][0],
                'Afirmaciones contradictorias', 'Versión original', $result['contradictions'][0]['original'], self::ORIGINAL,
                'Versión de contraste', $result['contradictions'][0]['contrast'], self::SOURCE,
            ])
            ->assertSee('Copiar redacción');

        Http::assertSent(fn (Request $request) => ($request->data()['text']['format']['name'] ?? null) === 'redaccion_noticia'
            && json_decode($request['input'], true) === ['La feria tuvo lugar en La Banda el sábado.']);
    }

    public function test_it_keeps_the_contrast_available_when_the_central_claim_is_disputed(): void
    {
        $this->fakePages();
        $this->fakeOpenAi(array_replace($this->localResult(), [
            'contradictions' => [$this->conflictingVersions()],
            'can_rewrite' => false,
        ]));

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertOk()
            ->assertSee('No pudimos generar una redacción verificable')
            ->assertSee('Versión de contraste')
            ->assertDontSee('Copiar redacción');
        Http::assertSentCount(2);
    }

    public function test_it_rejects_a_contradiction_without_a_fragment_in_the_cited_source(): void
    {
        $this->fakePages();
        $contradiction = $this->conflictingVersions();
        $contradiction['evidence'][0]['excerpt'] = 'Un fragmento ausente del artículo';
        $this->fakeOpenAi(array_replace($this->localResult(), ['contradictions' => [$contradiction]]));

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertUnprocessable()
            ->assertSee('No pudimos validar la evidencia')
            ->assertDontSee('Copiar redacción');
        Http::assertSentCount(2);
    }

    public function test_it_preserves_the_analysis_if_the_rewrite_service_fails(): void
    {
        $this->fakePages();
        $this->fakeOpenAi($this->localResult(), rewriteStatus: 503);

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertOk()
            ->assertSee('El contraste está disponible, pero el servicio no pudo generar la redacción')
            ->assertSee('La feria tuvo lugar en La Banda el sábado.')
            ->assertDontSee('Copiar redacción');
        Http::assertSentCount(3);
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
        $this->fakeOpenAi($this->localResult(), rewrite: [
            'title' => 'Feria comunitaria en La Banda',
            'draft' => self::FIRST_EXCERPT.' con puestos organizados por comerciantes y familias de distintos barrios de la ciudad.',
        ]);

        $this->post(route('news.analyze'), ['url' => self::ORIGINAL])
            ->assertOk()
            ->assertSee('Hechos corroborados')
            ->assertDontSee('Copiar redacción');
    }

    public function test_it_explains_when_an_article_has_no_provincial_relevance(): void
    {
        $this->fakePages(false);
        $this->fakeOpenAi(array_replace($this->localResult(), [
            'relevance' => 'outside', 'location' => '', 'location_evidence' => null, 'facts' => [], 'can_rewrite' => false,
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
        $estimate = $url === self::ORIGINAL ? self::ORIGINAL_ESTIMATE : self::CONTRAST_ESTIMATE;
        $extra = $url === self::ORIGINAL ? '<p>El medio señaló que la feria generó un aumento en las ventas del barrio.</p>' : '';

        return [
            'url' => $url, 'contentType' => 'text/html; charset=utf-8',
            'html' => <<<HTML
<html><body><nav><p>ENCABEZADO PUBLICITARIO</p></nav><main><article><h1>Una feria barrial</h1>
<p>{$excerpt} con puestos organizados por comerciantes y familias de distintos barrios de la ciudad.</p>
<p>Los vecinos recorrieron el predio durante la tarde y participaron de las actividades anunciadas por los organizadores.</p>
<p>{$estimate} al cierre del evento.</p>
{$extra}
</article></main></body></html>
HTML,
        ];
    }

    private function fakeOpenAi(array $result, bool $withSource = true, ?array $rewrite = null, int $rewriteStatus = 200): void
    {
        $annotations = $withSource ? [
            ['type' => 'url_citation', 'url' => 'https://m.medio.com.ar/noticias/feria'],
            ['type' => 'url_citation', 'url' => self::SOURCE],
        ] : [];
        $rewrite ??= ['title' => 'Feria comunitaria en La Banda', 'draft' => 'La Banda fue sede de una feria el sábado.'];

        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response('https://enlace-fabricado.com.ar/', $annotations))
            ->push($this->response(json_encode($result, JSON_THROW_ON_ERROR)))
            ->push($this->response(json_encode($rewrite, JSON_THROW_ON_ERROR)), $rewriteStatus)]);
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
            'contradictions' => [],
            'unverified' => ['El original atribuyó un aumento de ventas a la feria.'],
            'language' => ['La nota describe la convocatoria con un superlativo sin respaldo.'],
            'can_rewrite' => true,
        ];
    }

    private function conflictingVersions(): array
    {
        return [
            'original' => 'El municipio contabilizó 800 asistentes.',
            'contrast' => 'El otro medio contabilizó 400 asistentes.',
            'evidence' => [
                ['source' => 1, 'excerpt' => self::CONTRAST_ESTIMATE],
                ['source' => 0, 'excerpt' => self::ORIGINAL_ESTIMATE],
            ],
        ];
    }
}
