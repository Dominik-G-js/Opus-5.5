<?php
/** @var App\Kernel\ViewHelpers $v */
/** @var App\Service\Period $period */
/** @var array<string, int> $summary */
/** @var array<string, float|null> $deltas */
/** @var array<string, string> $sparklines */
/** @var array<string, mixed> $revenue */
/** @var list<array<string, mixed>> $groups */
/** @var array<string, mixed> $platforms */
/** @var list<array<string, mixed>> $modelCards */
/** @var list<array<string, mixed>> $payments */
/** @var array<string, mixed> $goal */
/** @var array<string, mixed> $monthly */
$isMonth = $period->kind === 'month';
$ranges = ['7d' => '7 dní', '30d' => '30 dní', 'ytd' => 'Od začátku roku'];
$roi = $summary['costs'] > 0 ? $summary['profit'] / $summary['costs'] * 100 : null;
$groupIcons = ['subscription' => 'crown', 'tip' => 'gift', 'ppv' => 'message-square-lock', 'other' => 'coins'];
$statusTone = ['active' => 'good', 'building' => 'accent', 'paused' => 'warn', 'concept' => 'neutral', 'retired' => 'neutral'];
$basisLabel = $goal['basis'] === 'net' ? 'příjmy po poplatcích' : 'čistý zisk (příjmy − náklady)';
?>
<div class="dashboard">
<div class="page-head">
  <div>
    <p class="eyebrow">Přehled studia</p>
    <h1>Přehled <span class="h1-period">· <?= $v->e($period->label()) ?></span></h1>
  </div>
  <div class="actions">
    <a class="btn btn-sm btn-primary" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a>
    <a class="btn btn-sm" href="<?= $v->url('/earnings/new') ?>">+ Příjem</a>
    <a class="btn btn-sm" href="<?= $v->url('/costs/new') ?>">+ Náklad</a>
  </div>
</div>

<nav class="period-bar" aria-label="Období přehledu">
  <div class="segmented">
    <a href="<?= $v->url('', ['month' => $isMonth ? (string) $period->month : $currentMonth]) ?>"<?= $isMonth ? ' aria-current="page"' : '' ?>>Měsíc</a>
    <?php foreach ($ranges as $range => $rangeLabel): ?>
      <a href="<?= $v->url('', ['range' => $range]) ?>"<?= $period->kind === $range ? ' aria-current="page"' : '' ?>><?= $v->e($rangeLabel) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($isMonth): ?>
    <div class="month-nav">
      <a class="btn btn-sm btn-icon" href="<?= $v->url('', ['month' => (string) $prevMonth]) ?>" aria-label="Předchozí měsíc (<?= $v->e($prevMonth) ?>)"><?= $v->icon('chevron-left') ?></a>
      <span class="month-current"><?= $v->e($period->label()) ?></span>
      <?php if ($nextMonth !== null): ?>
        <a class="btn btn-sm btn-icon" href="<?= $v->url('', ['month' => $nextMonth]) ?>" aria-label="Další měsíc (<?= $v->e($nextMonth) ?>)"><?= $v->icon('chevron-right') ?></a>
      <?php else: ?>
        <span class="btn btn-sm btn-icon is-disabled" aria-hidden="true"><?= $v->icon('chevron-right') ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</nav>

<?php foreach ($syncErrors as $error): ?>
  <div class="callout callout-bad callout-row">
    <?= $v->icon('triangle-alert', 'icon icon-sm') ?>
    <span>Synchronizace <a href="<?= $v->url('/accounts/' . $error['id']) ?>"><?= $v->e($error['platform']) ?> @<?= $v->e($error['handle']) ?></a> selhala:
    <?= $v->e($error['last_sync_error']) ?></span>
  </div>
<?php endforeach; ?>

<?php if ($modelCount === 0): ?>
  <section class="card empty-start glow-pink">
    <h2>Začni první AI modelkou</h2>
    <p>Vyplň její vzhled a povahu (character bible), ulož master prompt, přidej účty na platformách a zapisuj náklady i příjmy. Přehled se pak začne plnit sám.</p>
    <p><a class="btn btn-primary" href="<?= $v->url('/models/new') ?>">+ Přidat AI modelku</a></p>
    <p class="small muted">Koncepty modelek, prompty a strategie najdeš v <code>docs/</code>.</p>
  </section>
<?php endif; ?>

<section class="card card-hero glow-pink glow-violet" aria-labelledby="hero-label">
  <div class="hero-grid">
    <div class="hero-main">
      <p class="eyebrow" id="hero-label"><?= $v->icon('wallet', 'icon icon-xs') ?> Čistý příjem · <?= $v->e($period->label()) ?></p>
      <p class="hero-value"><?= $v->money($summary['net']) ?></p>
      <p class="hero-meta">
        <?= $v->delta($deltas['net']) ?>
        <span><?= $v->e($period->compareLabel()) ?></span>
        <span class="sep" aria-hidden="true">·</span>
        <span>hrubě <strong><?= $v->money($summary['gross']) ?></strong>, <?= (int) $summary['count'] ?> plateb</span>
      </p>
    </div>
    <dl class="hero-side">
      <div>
        <dt><?= $v->icon('user-plus', 'icon icon-xs') ?> Noví předplatitelé</dt>
        <dd><?= (int) $summary['new_subs'] ?></dd>
      </div>
      <div>
        <dt><?= $v->icon('user-minus', 'icon icon-xs') ?> Zrušilo</dt>
        <dd><?= (int) $summary['cancelled'] ?></dd>
      </div>
      <p class="hero-note">Předplatitelé jen z účtů napojených přes API.</p>
    </dl>
  </div>
</section>

<div class="kpi-grid">
  <section class="card kpi" aria-label="Hrubé tržby">
    <div class="kpi-head"><p class="eyebrow">Hrubé tržby</p><span class="kpi-icon"><?= $v->icon('coins') ?></span></div>
    <p class="kpi-value"><?= $v->money($summary['gross']) ?></p>
    <p class="kpi-note"><?= $v->delta($deltas['gross']) ?> <span><?= (int) $summary['count'] ?> plateb</span></p>
    <?= $sparklines['gross'] ?>
  </section>
  <section class="card kpi" aria-label="Poplatky platforem">
    <div class="kpi-head"><p class="eyebrow">Poplatky platforem</p><span class="kpi-icon"><?= $v->icon('layers') ?></span></div>
    <p class="kpi-value"><?= $v->money($summary['fees']) ?></p>
    <p class="kpi-note"><?= $v->delta($deltas['fees'], false, true) ?> <span><?= $summary['gross'] > 0 ? $v->percent($summary['fees'] / $summary['gross'] * 100) . ' z hrubých tržeb' : 'bez plateb' ?></span></p>
    <?= $sparklines['fees'] ?>
  </section>
  <section class="card kpi" aria-label="Náklady">
    <div class="kpi-head"><p class="eyebrow">Náklady</p><span class="kpi-icon"><?= $v->icon('receipt') ?></span></div>
    <p class="kpi-value"><?= $v->money($summary['costs']) ?></p>
    <p class="kpi-note"><?= $v->delta($deltas['costs'], true) ?> <span>z toho společné <?= $v->money($summary['shared_costs']) ?></span></p>
    <?= $sparklines['costs'] ?>
  </section>
  <section class="card kpi" aria-label="Zisk">
    <div class="kpi-head"><p class="eyebrow">Zisk</p><span class="kpi-icon"><?= $v->icon('trending-up') ?></span></div>
    <p class="kpi-value <?= $summary['profit'] < 0 ? 'neg' : '' ?>"><?= $v->money($summary['profit']) ?></p>
    <p class="kpi-note"><?= $v->delta($deltas['profit']) ?> <span><?= $roi === null ? 'bez nákladů' : 'ROI ' . $v->percent($roi) ?></span></p>
    <?= $sparklines['profit'] ?>
  </section>
</div>

<div class="dash-grid">
  <section class="card glow-violet" aria-labelledby="revenue-title">
    <div class="card-head">
      <div>
        <p class="eyebrow">Vývoj příjmů</p>
        <h2 id="revenue-title">Čisté příjmy podle typu plateb</h2>
      </div>
      <p class="card-head-value"><?= $v->money($summary['net']) ?></p>
    </div>
    <ul class="legend-tiles">
      <?php foreach ($groups as $group): ?>
        <li class="legend-tile<?= $group['net'] === 0 ? ' is-empty' : '' ?>">
          <span class="swatch fill-bg-<?= $v->e($group['key']) ?>"><?= $v->icon($groupIcons[$group['group']] ?? 'coins', 'icon icon-sm') ?></span>
          <span>
            <span class="legend-label"><?= $v->e($group['label']) ?></span>
            <span class="legend-value"><?= $v->money($group['net']) ?> <span class="muted">× <?= (int) $group['count'] ?></span></span>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($summary['count'] === 0): ?>
      <p class="empty">Za <?= $v->e($period->label()) ?> zatím žádné platby. Zapiš <a href="<?= $v->url('/earnings/new') ?>">příjem</a> nebo naimportuj CSV.</p>
    <?php else: ?>
      <div class="chart-scroll"><?= $revenue['svg'] ?></div>
      <?php foreach ($revenue['points'] as $i => $point): ?>
        <div class="viz-tip" id="rev-tip-<?= (int) $i ?>" role="tooltip" hidden>
          <p class="viz-tip-head"><?= $v->e($point['heading']) ?></p>
          <p class="viz-tip-total"><?= $v->money($point['net']) ?> <span>čistě</span></p>
          <ul class="viz-tip-rows">
            <?php foreach ($point['groups'] as $group): ?>
              <?php if ($group['net'] !== 0): ?>
                <li><span><span class="dot fill-bg-<?= $v->e($group['key']) ?>"></span><?= $v->e($group['label']) ?></span><strong><?= $v->money($group['net']) ?></strong></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
          <?php if ($point['platforms'] !== []): ?>
            <p class="viz-tip-sub">Podle platformy</p>
            <ul class="viz-tip-rows">
              <?php foreach ($point['platforms'] as $platform): ?>
                <li class="viz-tip-bar">
                  <span><span class="dot fill-bg-<?= $v->e($platform['key']) ?>"></span><?= $v->e($platform['name']) ?></span><strong><?= $v->money($platform['net']) ?></strong>
                  <?= $platform['bar'] ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <details class="table-view">
        <summary>Zobrazit jako tabulku</summary>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Období</th><?php foreach ($groups as $group): ?><th class="num"><?= $v->e($group['label']) ?></th><?php endforeach; ?><th class="num">Celkem čistě</th></tr></thead>
            <tbody>
            <?php foreach ($revenue['points'] as $point): ?>
              <tr>
                <td><?= $v->e($point['heading']) ?></td>
                <?php foreach ($point['groups'] as $group): ?><td class="num"><?= $v->money($group['net']) ?></td><?php endforeach; ?>
                <td class="num"><strong><?= $v->money($point['net']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>
  </section>

  <section class="card glow-rose" aria-labelledby="platforms-title">
    <div class="card-head">
      <div>
        <p class="eyebrow">Distribuce</p>
        <h2 id="platforms-title">Podíl platforem</h2>
      </div>
    </div>
    <?php if ($platforms['items'] === []): ?>
      <p class="empty">Žádné tržby na platformách za <?= $v->e($period->label()) ?>.</p>
    <?php else: ?>
      <div class="donut-wrap">
        <?= $platforms['svg'] ?>
        <div class="donut-center" aria-hidden="true">
          <span class="eyebrow">Hrubě</span>
          <strong><?= $v->money($platforms['total']) ?></strong>
        </div>
      </div>
      <ul class="platform-list">
        <?php foreach ($platforms['items'] as $item): ?>
          <li>
            <div class="platform-row">
              <span class="platform-name"><span class="dot fill-bg-<?= $v->e($item['key']) ?>"></span><?= $v->e($item['name']) ?> <span class="muted small"><?= $v->percent($item['share'] * 100) ?></span></span>
              <strong class="num"><?= $v->money($item['gross']) ?></strong>
            </div>
            <div class="platform-meta">
              <span class="badge badge-<?= $v->e($item['policy_tone']) ?>"><?= $v->icon($item['policy_tone'] === 'good' ? 'shield-check' : ($item['policy_tone'] === 'bad' ? 'shield-alert' : ($item['policy_tone'] === 'warn' ? 'shield' : 'shield-question-mark')), 'icon icon-xs') ?><?= $v->e($item['policy_label']) ?></span>
              <span class="small muted">čistě <?= $v->money($item['net']) ?> · poplatky <?= $v->percent($item['fee_percent']) ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="section" aria-labelledby="models-title">
  <div class="section-head">
    <div>
      <p class="eyebrow">Výkon modelek</p>
      <h2 id="models-title">AI modelky · <?= $v->e($period->label()) ?></h2>
    </div>
    <a class="small" href="<?= $v->url('/models') ?>">Všechny modelky</a>
  </div>
  <?php if ($modelCards === []): ?>
    <div class="card"><p class="empty">Zatím žádná modelka.</p></div>
  <?php else: ?>
    <div class="model-grid">
      <?php foreach ($modelCards as $card): ?>
        <a class="card model-card" href="<?= $v->url('/models/' . $card['id']) ?>">
          <div class="model-top">
            <span class="model-avatar">
              <?php if ($card['avatar_image_id'] !== null): ?>
                <img src="<?= $v->url('/images/' . $card['avatar_image_id']) ?>" alt="" loading="lazy" width="56" height="56">
              <?php else: ?>
                <span class="model-initials" aria-hidden="true"><?= $v->e(mb_strtoupper(mb_substr((string) $card['name'], 0, 1))) ?></span>
              <?php endif; ?>
            </span>
            <span class="model-id">
              <span class="model-name"><?= $v->e($card['name']) ?></span>
              <?php if (($card['niche'] ?? '') !== ''): ?><span class="model-niche"><?= $v->e($card['niche']) ?></span><?php endif; ?>
              <span class="badge badge-<?= $v->e($statusTone[$card['status']] ?? 'neutral') ?>"><?= $v->label('model_status', $card['status']) ?></span>
            </span>
          </div>
          <div class="model-revenue">
            <span>
              <span class="eyebrow">Čistě</span>
              <span class="model-value"><?= $v->money($card['net']) ?></span>
            </span>
            <?= $v->delta($card['delta']) ?>
          </div>
          <?= $card['sparkline'] ?>
          <div class="model-split">
            <span class="eyebrow">Podle platforem (hrubě)
              <?php if ($card['risky']): ?><span class="risk" title="Běží i na platformě, která AI persony zakazuje"><?= $v->icon('shield-alert', 'icon icon-xs') ?><span class="visually-hidden">Běží i na platformě, která AI persony zakazuje</span></span><?php endif; ?>
            </span>
            <?php if ($card['legend'] === []): ?>
              <span class="small muted">Žádné platby.</span>
            <?php else: ?>
              <?= $card['split'] ?>
              <span class="split-legend">
                <?php foreach ($card['legend'] as $entry): ?>
                  <span><span class="dot fill-bg-<?= $v->e($entry['key']) ?>"></span><?= $v->e($entry['name']) ?> <strong><?= $v->percent($entry['share'] * 100) ?></strong></span>
                <?php endforeach; ?>
              </span>
            <?php endif; ?>
          </div>
          <span class="model-foot">
            <?php if ($card['top_group'] !== null): ?>
              <span class="top-channel">
                <span class="swatch fill-bg-<?= $v->e($card['top_group']['key']) ?>"><?= $v->icon($groupIcons[$card['top_group']['group']] ?? 'coins', 'icon icon-sm') ?></span>
                <span><span class="small muted">Nejziskovější</span><strong><?= $v->e($card['top_group']['label']) ?></strong></span>
                <?php if ($card['top_group']['share'] !== null): ?><strong class="num"><?= $v->percent($card['top_group']['share'] * 100) ?></strong><?php endif; ?>
              </span>
            <?php endif; ?>
            <span class="model-stats small">
              <span>zisk <strong class="<?= $card['profit'] < 0 ? 'neg' : 'pos' ?>"><?= $v->money($card['profit']) ?></strong></span>
              <span>ROI <strong><?= $card['roi'] === null ? '—' : $v->percent($card['roi']) ?></strong></span>
              <span><?= (int) $card['payments'] ?> plateb</span>
            </span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="dash-grid">
  <section class="card card-flush" aria-labelledby="payments-title">
    <div class="card-head card-pad">
      <div>
        <p class="eyebrow">Transakce</p>
        <h2 id="payments-title">Nejvyšší platby</h2>
      </div>
      <a class="small" href="<?= $v->url('/earnings', $isMonth ? ['month' => (string) $period->month] : []) ?>">Všechny příjmy</a>
    </div>
    <?php if ($payments === []): ?>
      <p class="empty card-pad">Za <?= $v->e($period->label()) ?> žádné platby.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table-lux">
          <thead><tr><th>Fanoušek</th><th>Modelka</th><th>Platforma</th><th>Typ</th><th>Datum</th><th class="num">Hrubě</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $payment): ?>
            <tr>
              <td>
                <?php if ($payment['fan_id'] !== null): ?>
                  <a href="<?= $v->url('/fans/' . $payment['fan_id']) ?>"><?= $v->e($payment['fan_name'] ?? $payment['fan_handle'] ?? '—') ?></a><?= (int) $payment['is_top_spender'] === 1 ? ' <span class="badge badge-warn">' . $v->icon('crown', 'icon icon-xs') . 'top</span>' : '' ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= $v->e($payment['model']) ?></td>
              <td><span class="chip"><span class="dot fill-bg-<?= $v->e($payment['key']) ?>"></span><?= $v->e($payment['platform']) ?></span></td>
              <td><?= $v->label('tx_type', $payment['type']) ?></td>
              <td><?= $v->date($payment['occurred_on']) ?></td>
              <td class="num"><strong class="pos"><?= $v->money((int) $payment['gross']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card glow-pink" aria-labelledby="goal-title">
    <div class="card-head">
      <div>
        <p class="eyebrow">Měsíční cíl · <?= $v->e($goal['monthName']) ?></p>
        <h2 id="goal-title"><?= $goal['basis'] === 'net' ? 'Příjmy po poplatcích' : 'Čistý zisk studia' ?></h2>
      </div>
    </div>
    <div class="goal">
      <div class="ring-wrap">
        <?= $goal['ring'] ?>
        <div class="ring-center" aria-hidden="true"><strong><?= $v->percent($goal['percent']) ?></strong><span>z cíle</span></div>
      </div>
      <dl class="goal-list">
        <div><dt>Zatím</dt><dd><strong><?= $v->money($goal['value']) ?></strong></dd></div>
        <div><dt>Cíl</dt><dd><?= $v->money($goal['goal']) ?></dd></div>
        <?php if ($goal['isCurrent']): ?>
          <div><dt>Odhad za měsíc</dt><dd><strong><?= $v->money($goal['projected']) ?></strong></dd></div>
          <div><dt>Tempo</dt><dd><?= $v->money($goal['pace']) ?> / den</dd></div>
          <div><dt>Potřeba do konce</dt><dd class="<?= $goal['onTrack'] ? 'pos' : 'warn-text' ?>"><?= $v->money($goal['needed']) ?> / den</dd></div>
        <?php endif; ?>
      </dl>
    </div>
    <?php if ($goal['isCurrent']): ?>
      <p class="goal-callout <?= $goal['onTrack'] ? 'is-good' : 'is-warn' ?>">
        <?= $v->icon($goal['onTrack'] ? 'trending-up' : 'target', 'icon icon-sm') ?>
        <span><?= $goal['onTrack']
            ? 'Při současném tempu cíl splníš.'
            : 'Při současném tempu chybí ' . $v->money($goal['goal'] - $goal['projected']) . '. Zbývá ' . (int) $goal['remainingDays'] . ' dní.' ?></span>
      </p>
    <?php endif; ?>
    <p class="small muted">Splněno <?= $v->percent($goal['percent']) ?> z cíle <?= $v->money($goal['goal']) ?> · počítáno jako <?= $v->e($basisLabel) ?>, celé studio. Cíl změníš v <a href="<?= $v->url('/settings') ?>">Nastavení</a>.</p>
  </section>
</div>

<div class="grid grid-2">
  <section class="card card-flush" aria-labelledby="fans-title">
    <div class="card-head card-pad">
      <div>
        <p class="eyebrow">Fanoušci</p>
        <h2 id="fans-title">Kdo mi nejvíc vydělal</h2>
      </div>
      <a class="small" href="<?= $v->url('/fans', $isMonth ? ['month' => (string) $period->month] : []) ?>">Všichni</a>
    </div>
    <?php if ($topFans === []): ?>
      <p class="empty card-pad">Zatím žádné platby s přiřazeným fanouškem.</p>
    <?php else: ?>
      <div class="table-wrap"><table class="table-lux">
        <thead><tr><th>Fanoušek</th><th>Modelka</th><th class="num">Plateb</th><th class="num">Čistě</th></tr></thead>
        <tbody>
        <?php foreach ($topFans as $fan): ?>
          <tr>
            <td><a href="<?= $v->url('/fans/' . $fan['id']) ?>"><?= $v->e($fan['display_name'] ?? $fan['handle'] ?? '—') ?></a><?= (int) $fan['is_top_spender'] === 1 ? ' <span class="badge badge-warn">' . $v->icon('crown', 'icon icon-xs') . 'top</span>' : '' ?></td>
            <td><?= $v->e($fan['model']) ?> <span class="muted small"><?= $v->e($fan['platform']) ?></span></td>
            <td class="num"><?= (int) $fan['payments'] ?></td>
            <td class="num"><?= $v->money((int) $fan['net']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="monthly-title">
    <div class="card-head">
      <div>
        <p class="eyebrow">Dlouhodobě</p>
        <h2 id="monthly-title">Posledních 12 měsíců</h2>
      </div>
    </div>
    <div class="legend" aria-hidden="true">
      <span><span class="key key-1"></span>Příjmy po poplatcích</span>
      <span><span class="key key-2"></span>Náklady</span>
    </div>
    <div class="chart-scroll chart-scroll-narrow"><?= $monthly['svg'] ?></div>
    <details class="table-view">
      <summary>Zobrazit jako tabulku</summary>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Měsíc</th><th class="num">Příjmy</th><th class="num">Náklady</th><th class="num">Zisk</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($monthly['series']) as $point): ?>
            <tr>
              <td><?= $v->e($point['month']) ?></td>
              <td class="num"><?= $v->money($point['net']) ?></td>
              <td class="num"><?= $v->money($point['costs']) ?></td>
              <td class="num"><?= $v->money($point['net'] - $point['costs']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </section>
</div>

<div class="grid grid-2">
  <section class="card card-flush" aria-labelledby="accounts-title">
    <div class="card-head card-pad">
      <div>
        <p class="eyebrow">Účty</p>
        <h2 id="accounts-title">Podle účtu</h2>
      </div>
    </div>
    <?php if ($accounts === []): ?>
      <p class="empty card-pad">Žádné platby na výdělečných účtech.</p>
    <?php else: ?>
      <div class="table-wrap"><table class="table-lux">
        <thead><tr><th>Účet</th><th class="num">Plateb</th><th class="num">Čistě</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $account): ?>
          <tr>
            <td><a href="<?= $v->url('/accounts/' . $account['id']) ?>"><?= $v->e($account['platform']) ?></a> <span class="muted small"><?= $v->e($account['model']) ?> · @<?= $v->e($account['handle']) ?></span></td>
            <td class="num"><?= (int) $account['count'] ?></td>
            <td class="num"><?= $v->money((int) $account['net']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>

  <section class="card card-flush" aria-labelledby="clicks-title">
    <div class="card-head card-pad">
      <div>
        <p class="eyebrow">Návštěvnost</p>
        <h2 id="clicks-title">Prokliky podle zdroje</h2>
      </div>
      <a class="small" href="<?= $v->url('/links') ?>">Odkazy</a>
    </div>
    <?php if ($clicks === []): ?>
      <p class="empty card-pad">Žádné prokliky. Vytvoř <a href="<?= $v->url('/links/new') ?>">sledovací odkaz</a> do bia.</p>
    <?php else: ?>
      <div class="table-wrap"><table class="table-lux">
        <thead><tr><th>Zdroj</th><th class="num">Prokliky</th></tr></thead>
        <tbody>
        <?php foreach ($clicks as $click): ?>
          <tr><td><?= $v->icon('mouse-pointer-click', 'icon icon-xs muted') ?> <?= $v->label('link_source', $click['source']) ?></td><td class="num"><?= (int) $click['clicks'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>
</div>
</div>
