<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Kernel\Request;
use App\Kernel\Response;
use App\Support\Clock;
use App\Support\Labels;
use App\Support\Str;
use App\Support\Validator;

/**
 * Sledovací odkazy (do bia na TikTok/X/Reddit…) + odkazy na landing page modelky.
 */
final class LinkController extends Controller
{
    public function index(Request $request): Response
    {
        $since = (new \DateTimeImmutable(Clock::todayLocal()))->modify('-29 days')->format('Y-m-d');
        $links = $this->app->db->all(
            'SELECT l.*, m.name AS model_name, m.slug, m.page_domain,
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
        [$data, $v] = $this->validate($request, null);
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
        [$data, $v] = $this->validate($request, (int) $link['id']);
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
        $domain = $link['page_domain'] ?? null;

        return is_string($domain) && $domain !== ''
            ? 'https://' . $domain . '/go/' . $link['code']
            : $this->app->urls->absolutePublic('/go/' . $link['code']);
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    private function validate(Request $request, ?int $id): array
    {
        $v = new Validator();
        $modelId = $this->optionalId($request->input('model_id'), 'models');
        if ($modelId === null) {
            $v->addError('model_id', 'Vyber modelku.');
        }
        $code = $request->input('code');
        if ($code === '') {
            $code = Str::randomCode(7);
        } elseif (preg_match('/^[A-Za-z0-9_-]{3,40}$/', $code) !== 1) {
            $v->addError('code', 'Kód: 3–40 znaků, písmena, číslice, - a _.');
        }
        if ($this->app->db->scalar('SELECT 1 FROM links WHERE code = :c AND id != :id', ['c' => $code, 'id' => $id ?? 0]) !== null) {
            $v->addError('code', 'Kód už používá jiný odkaz.');
        }
        $data = [
            'model_id' => $modelId,
            'code' => $code,
            'label' => $v->required('label', $request->input('label'), 'Popisek', 80),
            'source' => $v->oneOf('source', $request->input('source'), Labels::group('link_source'), 'Zdroj'),
            'target_url' => $v->url('target_url', $request->input('target_url'), 'Cílová adresa', true),
            'is_active' => $request->checkbox('is_active') ? 1 : 0,
            'is_premium' => $request->checkbox('is_premium') ? 1 : 0,
            'show_on_page' => $request->checkbox('show_on_page') ? 1 : 0,
            'sort_order' => $v->int('sort_order', $request->input('sort_order', '0'), 'Pořadí', 0, 999) ?? 0,
        ];

        return [$data, $v];
    }
}
