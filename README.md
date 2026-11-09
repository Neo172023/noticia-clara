# Noticia Clara

Aplicación en español para analizar el enlace de una noticia de Santiago del Estero, contrastar su contenido con otras fuentes y obtener una redacción neutral cuando hay evidencia suficiente. El resultado se muestra en la sesión del navegador y se puede copiar.

## Instalación local

Se requiere PHP 8.2 o superior compatible con Laravel 12, Composer 2 y MySQL. Habilitá las extensiones de Laravel y comprobá que PHP tenga cURL, DOM, mbstring, OpenSSL y PDO MySQL, además de certificados CA para HTTPS.

1. Creá una base de datos MySQL llamada `noticia_clara` con `utf8mb4`. Otorgale permisos sobre esa base a un usuario de la aplicación.
2. Desde el repositorio, instalá las dependencias y creá el archivo de configuración local.

   ```sh
   cd backend
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

   En PowerShell, usá `Copy-Item .env.example .env` para copiar el archivo. Si ya tenés un `.env`, editá ese archivo.

3. Completá las variables de MySQL y la clave de OpenAI en `backend/.env`.

   | Variable | Uso |
   | --- | --- |
   | `APP_KEY` | Clave de Laravel que genera `php artisan key:generate`. |
   | `APP_URL` | URL base de la aplicación. Para el servidor local, `http://127.0.0.1:8000`. |
   | `DB_CONNECTION` | Usá `mysql`. |
   | `DB_HOST`, `DB_PORT` | Host y puerto de MySQL. |
   | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Base y credenciales del usuario de la aplicación. |
   | `OPENAI_API_KEY` | Clave del proyecto con acceso a la API de OpenAI. Se usa en el servidor. |
   | `OPENAI_SEARCH_MODEL` | Modelo de Responses con búsqueda web. Valor inicial `gpt-5.4-mini`. |
   | `OPENAI_EDITOR_MODEL` | Modelo de Responses con salidas estructuradas. Valor inicial `gpt-5.4-mini`. |
   | `CACHE_STORE`, `SESSION_DRIVER` | El ejemplo usa `database` para compartir los límites de uso y las sesiones entre procesos. |

4. Ejecutá las migraciones y el servidor de desarrollo.

   ```sh
   php artisan migrate
   php artisan serve
   ```

   Abrí `http://127.0.0.1:8000`. La interfaz usa CSS y JavaScript estáticos que Laravel sirve desde `public/`.

## Análisis y límites

- La descarga acepta artículos HTML por HTTP o HTTPS en dominios públicos con IPv4 y puertos estándar. Fija la IP validada y repite las comprobaciones en cada salto. La extracción usa el texto visible en el HTML recibido. Sitios con restricciones de acceso, renderizado exclusivo con JavaScript o marcado no reconocible pueden requerir otro enlace.
- La búsqueda web de la API Responses devuelve citas candidatas. La aplicación intenta abrir hasta seis y lee hasta tres artículos de otros dominios para contrastar la noticia. El modelo evalúa si se refieren al mismo acontecimiento y si aportan evidencia independiente.
- Cada hecho corroborado requiere fragmentos que el servidor pueda encontrar en el texto del original y en otra fuente recuperada. La interfaz enlaza ambas. El modelo evalúa si los fragmentos sostienen el hecho y si hay un vínculo provincial. Las afirmaciones pendientes y el lenguaje sensacionalista aparecen por separado.
- La redacción se limita a 1200 caracteres y requiere hechos corroborados. La aplicación suprime borradores que repiten secuencias de 15 palabras de los textos recuperados. La revisión de fuentes sigue siendo necesaria para evaluar la independencia de los medios y el significado de la evidencia.
- El análisis realiza dos llamadas a OpenAI y envía fragmentos de los artículos públicos para su procesamiento. Usa `store: false` en Responses. Las sesiones y el caché de Laravel usan MySQL. La aplicación entrega el análisis en la respuesta HTTP y carece de un historial de artículos.
- El formulario tiene protección CSRF y admite tres intentos por minuto por IP, con límites compartidos de 12 por minuto y 100 por día. Si se instala detrás de un proxy, configurá sus direcciones de confianza en Laravel. El procesamiento es síncrono. Si hay un servidor web o proxy intermedio, configurá su tiempo de espera para admitir análisis de hasta cuatro minutos.

[OpenAI Responses](https://developers.openai.com/api/reference/responses/overview) · [Búsqueda web](https://developers.openai.com/api/docs/guides/tools-web-search) · [Salidas estructuradas](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses)

## Pruebas

Desde `backend/`:

```sh
php artisan test
php vendor/bin/pint --test app/Services/News app/Http/Controllers/NewsAnalysisController.php app/Providers/AppServiceProvider.php config/services.php routes/web.php tests/Feature/NewsAnalysisTest.php tests/Unit/PublicPageFetcherTest.php
```

Las pruebas del análisis simulan la API de OpenAI y la descarga de páginas. Se ejecutan sin credenciales de OpenAI ni una instancia de MySQL. Para una prueba manual con credenciales válidas, pegá el enlace directo a un acontecimiento local que tenga cobertura en dos medios y revisá las citas junto a los hechos.
