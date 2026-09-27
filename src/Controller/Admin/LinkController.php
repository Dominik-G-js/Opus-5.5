<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Form\LinkForm;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Support\Clock;

/**
 * Sledovací odkazy (do bia na TikTok/X/Reddit…) + odkazy na landing page modelky.
 */
final class LinkController extends Controller
{
    public function index(Request $request): Response
    {
        $since = (new \DateTimeImmutable(Clock::todayLocal()))->modify('-29 days')->format('Y-m-d');
        $links = $this->app->db->all(
            'SELECT l.*, m.name AS model_name, m.slug, m.page_domain, m.page_published,
                (SELECT COALESCE(SUM(clicks), 0) FROM link_clicks_daily c WHERE c.link_id = l.id) AS clicks_total,
                (SELECT COALESCE(SUM(clicks), 0) FROM link_clicks_daily c WHERE c.link_id = l.id AND c.day >= :since) AS clicks_30
             FROM links l JOIN models m ON m.id = l.model_id
             ORDER BY m.name, l.sort_order, l.id',
            ['since' => $since]
        );
        foreach ($links as &$link) {
            $link['tracking_url'] = $this->trackingUrl($link);
        }
        unset($link);

        return $this->render('links/index', ['title' => 'Odkazy a prokliky', 'links' => $links]);
    }

    public function create(Request $request): Response
    {
        return $this->render('links/form', [
            'title' => 'Nový odkaz',
            'link' => null,
            'presetModel' => $this->optionalId($request->query('model'), 'models'),
            'modelOptions' => $this->modelOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        [$data, $v] = $this->form()->validate($request->form(), null);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/links/new');
        }
        $this->app->db->insert('links', $data + ['created_at' => Clock::nowUtc()]);
        $this->flash('success', 'Odkaz vytvořen.');

        return $this->redirect('/links');
    }

    public function edit(Request $request): Response
    {
        $link = $this->findOrFail('links', $request->intParam('id'));

        return $this->render('links/form', [
            'title' => 'Upravit odkaz',
            'link' => $link,
            'presetModel' => null,
            'modelOptions' => $this->modelOptions(),
        ]);
    }

    public function update(Request $request): Response
    {
        $link = $this->findOrFail('links', $request->intParam('id'));
        [$data, $v] = $this->form()->validate($request->form(), (int) $link['id']);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v, '/links/' . $link['id'] . '/edit');
        }
        $this->app->db->update('links', $data, ['id' => $link['id']]);
        $this->flash('success', 'Uloženo.');

        return $this->redirect('/links');
    }

    public function delete(Request $request): Response
    {
        $link = $this->findOrFail('links', $request->intParam('id'));
        $this->app->db->delete('links', ['id' => $link['id']]);
        $this->flash('success', 'Odkaz smazán.');

        return $this->redirect('/links');
    }

    /** @param array<string, mixed> $link */
    private function trackingUrl(array $link): string
    {
        // Vlastní doména obsluhuje jen zveřejněnou modelku; jinak by /go/ vracelo 404 → hlavní doména.
        $domain = (int) ($link['page_published'] ?? 0) === 1 ? ($link['page_domain'] ?? null) : null;

        return is_string($domain) && $domain !== ''
            ? 'https://' . $domain . '/go/' . $link['code']
            : $this->app->urls->absolutePublic('/go/' . $link['code']);
    }

    private function form(): LinkForm
    {
        return new LinkForm($this->app->db);
    }
}
