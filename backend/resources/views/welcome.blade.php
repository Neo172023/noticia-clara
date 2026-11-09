<!doctype html>
<html lang="es-AR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Contrastá noticias de Santiago del Estero y obtené una redacción neutral con fuentes consultables.">
    <title>Noticia Clara · Análisis de noticias de Santiago del Estero</title>
    <link rel="stylesheet" href="{{ asset('noticia-clara.css') }}">
    <script src="{{ asset('noticia-clara.js') }}" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="shell header-inner">
            <a class="brand" href="{{ url('/') }}" aria-label="Noticia Clara, inicio"><span class="mark" aria-hidden="true">NC</span> Noticia Clara</a>
            <span class="edition">Santiago del Estero, Argentina</span>
        </div>
    </header>
    <main class="shell">
        <section class="hero" aria-labelledby="page-title">
            <div class="hero-copy">
                <p class="eyebrow"><span></span> Análisis periodístico asistido</p>
                <h1 id="page-title">De la noticia<br>a <em>los hechos.</em></h1>
                <p>Contrastá una noticia de Santiago del Estero con otras fuentes y obtené una redacción neutral basada en los datos corroborados.</p>
            </div>
            <div class="hero-art" aria-hidden="true">
                <div class="paper">
                    <span>NOTICIA CLARA</span>
                    <i></i><i></i><i></i><i></i><i></i>
                    <div class="stamp">✓</div>
                </div>
            </div>
        </section>

        <section class="panel intake" aria-labelledby="intake-title">
            <div class="section-head">
                <p class="eyebrow">Análisis individual</p>
                <h2 id="intake-title">Ingresá el enlace de la noticia</h2>
            </div>
            <form method="post" action="{{ route('news.analyze') }}" id="analysis-form">
                @csrf
                <label for="url">URL de la noticia</label>
                <div class="form-row">
                    <input id="url" name="url" type="url" required maxlength="2048" inputmode="url" spellcheck="false" autocomplete="url"
                        placeholder="https://medio.com.ar/noticia" value="{{ $url ?? '' }}" aria-describedby="url-help{{ isset($error) ? ' analysis-error' : '' }}">
                    <button type="submit">Analizar y reformular <span aria-hidden="true">↗</span></button>
                </div>
                <p class="hint" id="url-help">Usá el enlace directo a un artículo de acceso público.</p>
                <p class="hint" id="analysis-status" role="status"></p>
            </form>
            @isset($error)
                <p class="notice" role="alert" id="analysis-error" tabindex="-1">{{ $error }}</p>
            @endisset
        </section>

        @isset($result)
            <section class="result" aria-labelledby="result-title" id="resultado">
                <div class="section-head">
                    <p class="eyebrow">Resultado del análisis</p>
                    <h2 id="result-title" tabindex="-1">{{ $result['location'] }}</h2>
                </div>
                <div class="panel draft">
                    <p class="eyebrow">Redacción neutral</p>
                    @if ($result['draft'] !== '')
                        <h3 id="draft-title">{{ $result['title'] }}</h3>
                        <div id="draft-text" class="draft-text">{{ $result['draft'] }}</div>
                        <button type="button" id="copy-draft" class="secondary" hidden>Copiar redacción</button>
                        <p id="copy-status" class="hint" role="status"></p>
                    @else
                        <p class="notice">{{ $result['draft_notice'] }} Revisá el análisis a continuación.</p>
                    @endif
                </div>
                <div class="details-grid">
                    <section class="panel detail" aria-labelledby="facts-title">
                        <h3 id="facts-title">Hechos corroborados</h3>
                        @forelse ($result['facts'] as $fact)
                            <p>{{ $fact['statement'] }}
                                @foreach ($fact['sources'] as $sourceId)
                                    <a class="ref" href="{{ $result['sources'][$sourceId]['url'] }}" target="_blank" rel="noopener noreferrer"
                                        aria-label="Fuente {{ $sourceId + 1 }}: {{ $result['sources'][$sourceId]['title'] }}">[{{ $sourceId + 1 }}]</a>
                                @endforeach
                            </p>
                        @empty
                            <p>No encontramos coincidencias suficientes entre fuentes independientes.</p>
                        @endforelse
                    </section>
                    <section class="panel detail" aria-labelledby="claims-title">
                        <h3 id="claims-title">Afirmaciones no corroboradas</h3>
                        @forelse ($result['unverified'] as $claim)
                            <p>{{ $claim }}</p>
                        @empty
                            <p>El análisis no registró afirmaciones adicionales sin verificar.</p>
                        @endforelse
                    </section>
                    <section class="panel detail" aria-labelledby="contradictions-title">
                        <h3 id="contradictions-title">Afirmaciones contradictorias</h3>
                        @forelse ($result['contradictions'] as $claim)
                            <div class="contradiction">
                                @foreach (['original' => 'Versión original', 'contrast' => 'Versión de contraste'] as $version => $label)
                                    @php($sourceId = $claim['sources'][$loop->index])
                                    <p><strong>{{ $label }}</strong><br>{{ $claim[$version] }}
                                        <a class="ref" href="{{ $result['sources'][$sourceId]['url'] }}" target="_blank" rel="noopener noreferrer"
                                            aria-label="Fuente {{ $sourceId + 1 }}: {{ $result['sources'][$sourceId]['title'] }}">[{{ $sourceId + 1 }}]</a>
                                    </p>
                                @endforeach
                            </div>
                        @empty
                            <p>El análisis no identificó versiones incompatibles en las fuentes accesibles.</p>
                        @endforelse
                    </section>
                    <section class="panel detail" aria-labelledby="language-title">
                        <h3 id="language-title">Lenguaje sensacionalista</h3>
                        @forelse ($result['language'] as $finding)
                            <p>{{ $finding }}</p>
                        @empty
                            <p>El análisis no detectó expresiones sensacionalistas.</p>
                        @endforelse
                    </section>
                    <section class="panel detail sources-panel" aria-labelledby="sources-title">
                        <h3 id="sources-title">Fuentes consultadas</h3>
                        <ol>
                            @foreach ($result['sources'] as $id => $source)
                                <li><a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['title'] }}</a>
                                    <span class="source-type">{{ $id === 0 ? 'Artículo original' : 'Fuente de contraste' }}</span></li>
                            @endforeach
                        </ol>
                    </section>
                </div>
                <p class="hint">El análisis es asistido por IA. Los enlaces permiten revisar el contexto y la evidencia de cada dato.</p>
            </section>
        @else
            <section class="workflow" aria-label="Cómo funciona">
                <article><span>01</span><h2>Recuperá</h2><p>Leemos el texto accesible del artículo y comprobamos su relación con la provincia.</p></article>
                <article><span>02</span><h2>Contrastá</h2><p>Buscamos cobertura independiente y distinguimos los hechos corroborados, las afirmaciones pendientes y las versiones contradictorias.</p></article>
                <article><span>03</span><h2>Reformulá</h2><p>Generamos un título informativo y una redacción neutral cuando la evidencia lo permite.</p></article>
            </section>
        @endisset
    </main>
    <footer class="shell site-footer"><span>Noticia Clara</span><span>Capital · La Banda · Interior provincial</span></footer>
</body>
</html>
