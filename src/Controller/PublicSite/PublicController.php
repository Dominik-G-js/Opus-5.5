<?php

declare(strict_types=1);

namespace App\Controller\PublicSite;

use App\Kernel\App;
use App\Kernel\HttpException;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Kernel\Router;
use App\Kernel\Services;
use App\Service\LinkTracker;

/**
 * Veřejná část: SEO landing page modelek (na hlavní doméně /m/{slug} nebo na vlastní doméně),
 * sledovací přesměrování /go/{kód}, veřejné obrázky, robots.txt a sitemap.xml.
 * Nepoužívá session ani cookies.
 */
final class PublicController
{
    public function __construct(private readonly App $app)
    {
    }

    public function handleMainHost(Request $request): Response
    {
        $router = new Router();
        $router->get('/m/{slug}', fn (Request $q) => $this->modelBySlug($q));
        $router->get('/go/{code}', fn (Request $q) => $this->go($q, null));
        $router->get('/media/p/{publicId}.jpg', fn (Request $q) => $this->media($q, null));
        $router->get('/robots.txt', fn (Request $q) => $this->robots($this->app->urls->absolutePublic('/sitemap.xml')));
        $router->get('/sitemap.xml', fn (Request $q) => $this->sitemap(null));
        [$handler, $params] = $router->match($request->method, $request->path);

        return $handler($request->withRouteParams($params));
    }

    public function handleCustomDomain(Request $request): Response
    {
        $host = $request->host;
        if (str_starts_with($host, 'www.')) {
            $bare = substr($host, 4);
            if ($this->findPublishedByDomain($bare) !== null) {
                return Response::redirect('https://' . $bare . $request->path, 301);
            }
        }
        $model = $host !== '' ? $this->findPublishedByDomain($host) : null;
        if ($model === null) {
            throw HttpException::notFound();
        }
        $modelId = (int) $model['id'];

        $router = new Router();
        $router->get('/', fn (Request $q) => $this->renderModel($model));
        $router->get('/go/{code}', fn (Request $q) => $this->go($q, $modelId));
        $router->get('/media/p/{publicId}.jpg', fn (Request $q) => $this->media($q, $modelId));
        $router->get('/robots.txt', fn (Request $q) => $this->robots('https://' . $host . '/sitemap.xml'));
        $router->get('/sitemap.xml', fn (Request $q) => $this->sitemap($model));
        [$handler, $params] = $router->match($request->method, $request->path);

        return $handler($request->withRouteParams($params));
    }

    private function modelBySlug(Request $request): Response
    {
        $model = $this->app->db->one('SELECT * FROM models WHERE slug = :s AND page_published = 1', ['s' => $request->param('slug')]);
        if ($model === null) {
            throw HttpException::notFound();
        }
        if (!empty($model['page_domain'])) {
            return Response::redirect('https://' . $model['page_domain'] . '/', 301);
        }

        return $this->renderModel($model);
    }

    /** @param array<string, mixed> $model */
    private function renderModel(array $model): Response
    {
        $id = (int) $model['id'];
        $db = $this->app->db;
        $canonical = $this->app->urls->modelPage((string) $model['slug'], $model['page_domain']);
        $origin = !empty($model['page_domain']) ? 'https://' . $model['page_domain'] : $this->app->urls->origin() . $this->app->urls->basePath();

        $avatar = null;
        if (!empty($model['avatar_image_id'])) {
            $avatar = $db->one(
                'SELECT public_id, alt_text, width, height FROM images WHERE id = :i AND model_id = :m AND is_public = 1',
                ['i' => $model['avatar_image_id'], 'm' => $id]
            );
        }
        $gallery = $db->all(
            'SELECT id, public_id, alt_text, width, height FROM images WHERE model_id = :m AND is_public = 1 ORDER BY created_at DESC LIMIT 24',
            ['m' => $id]
        );
        $links = $db->all(
            'SELECT code, label, source, is_premium FROM links WHERE model_id = :m AND is_active = 1 AND show_on_page = 1 ORDER BY sort_order, id',
            ['m' => $id]
        );
        $profiles = $db->all(
            'SELECT a.profile_url, p.name AS platform FROM accounts a JOIN platforms p ON p.id = a.platform_id
             WHERE a.model_id = :m AND a.show_on_page = 1 AND a.profile_url IS NOT NULL AND a.status IN (\'active\', \'warming\')',
            ['m' => $id]
        );

        $mediaUrl = static fn (string $publicId): string => $origin . '/media/p/' . $publicId . '.jpg';
        $html = $this->app->view->render('public/model', [
            'model' => $model,
            'canonical' => $canonical,
            'avatar' => $avatar,
            'avatarUrl' => $avatar !== null ? $mediaUrl((string) $avatar['public_id']) : null,
            'gallery' => $gallery,
            'mediaUrl' => $mediaUrl,
            'goUrl' => static fn (string $code): string => $origin . '/go/' . $code,
            'socialLinks' => array_values(array_filter($links, static fn (array $l): bool => (int) $l['is_premium'] === 0)),
            'premiumLinks' => array_values(array_filter($links, static fn (array $l): bool => (int) $l['is_premium'] === 1)),
            'profiles' => $profiles,
            'nonce' => $this->app->cspNonce(),
            'lang' => $model['page_lang'] === 'cs' ? 'cs' : 'en',
        ], null);

        return Response::html($html)
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withHeader('Link', '<' . $canonical . '>; rel="canonical"');
    }

    private function go(Request $request, ?int $modelId): Response
    {
        $target = (new LinkTracker($this->app->db))->resolveAndCount($request->param('code'), $request->header('User-Agent'), $modelId);
        if ($target === null) {
            throw HttpException::notFound();
        }

        return Response::redirect($target, 302)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function media(Request $request, ?int $modelId): Response
    {
        $publicId = $request->param('publicId');
        $image = $this->app->db->one(
            'SELECT i.model_id FROM images i JOIN models m ON m.id = i.model_id
             WHERE i.public_id = :p AND i.is_public = 1 AND m.page_published = 1',
            ['p' => $publicId]
        );
        if ($image === null || ($modelId !== null && (int) $image['model_id'] !== $modelId)) {
            throw HttpException::notFound();
        }
        $path = Services::imageStore($this->app)->publicPath($publicId);
        if (!is_file($path)) {
            throw HttpException::notFound();
        }

        return Response::file($path, 'image/jpeg', true);
    }

    private function robots(string $sitemapUrl): Response
    {
        return Response::text("User-agent: *\nDisallow: /go/\n\nSitemap: {$sitemapUrl}\n")
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /** @param array<string, mixed>|null $onlyModel */
    private function sitemap(?array $onlyModel): Response
    {
        $models = $onlyModel !== null
            ? [$onlyModel]
            : $this->app->db->all("SELECT slug, page_domain, updated_at FROM models WHERE page_published = 1 AND (page_domain IS NULL OR page_domain = '')");
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($models as $model) {
            $loc = $this->app->urls->modelPage((string) $model['slug'], $model['page_domain'] ?? null);
            $lastmod = substr((string) $model['updated_at'], 0, 10);
            $xml .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($lastmod !== '' ? '<lastmod>' . htmlspecialchars($lastmod, ENT_XML1, 'UTF-8') . '</lastmod>' : '')
                . "</url>\n";
        }

        return Response::text($xml . "</urlset>\n", 200, 'application/xml')->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /** @return array<string, mixed>|null */
    private function findPublishedByDomain(string $domain): ?array
    {
        return $this->app->db->one('SELECT * FROM models WHERE page_domain = :d AND page_published = 1', ['d' => $domain]);
    }
}
