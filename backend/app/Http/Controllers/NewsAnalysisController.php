<?php

namespace App\Http\Controllers;

use App\Services\News\AnalysisException;
use App\Services\News\ArticleExtractor;
use App\Services\News\NewsEditor;
use App\Services\News\SourceSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class NewsAnalysisController extends Controller
{
    public function __invoke(Request $request, ArticleExtractor $extractor, SourceSearch $search, NewsEditor $editor): Response
    {
        $validator = Validator::make($request->only('url'), [
            'url' => ['bail', 'required', 'string', 'max:2048', 'url:http,https'],
        ], [
            'url.required' => 'Pegá el enlace de la noticia.',
            'url.url' => 'Ingresá un enlace completo que empiece con http:// o https://.',
            'url.string' => 'Ingresá un solo enlace.',
            'url.max' => 'El enlace supera la longitud permitida.',
        ]);
        $url = $request->input('url');
        $url = is_string($url) ? mb_substr($url, 0, 2048) : '';
        if ($validator->fails()) {
            return response()->view('welcome', ['url' => $url, 'error' => $validator->errors()->first()], 422);
        }

        try {
            $article = $extractor->extract($url);
            $result = $editor->analyze([$article, ...$search->search($article)]);
        } catch (AnalysisException $exception) {
            return response()->view('welcome', ['url' => $url, 'error' => $exception->getMessage()], 422);
        }

        return response()->view('welcome', ['url' => $url, 'result' => $result]);
    }
}
