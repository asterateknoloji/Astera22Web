<?php
declare(strict_types=1);

function report_period_filters(array $source): array
{
    return [
        'date_from' => array_key_exists('date_from', $source)
            ? trim((string) $source['date_from'])
            : date('Y-m-d'),
        'date_to' => array_key_exists('date_to', $source)
            ? trim((string) $source['date_to'])
            : date('Y-m-d'),
    ];
}

function report_period_options(): array
{
    $today = new DateTimeImmutable('today');
    $thisWeek = $today->modify('monday this week');
    $previousWeek = $thisWeek->modify('-7 days');
    $thisMonth = $today->modify('first day of this month');
    $previousMonth = $thisMonth->modify('-1 month');

    return [
        'today' => ['Bugün', $today, $today],
        'yesterday' => ['Dün', $today->modify('-1 day'), $today->modify('-1 day')],
        'this_week' => ['Bu hafta', $thisWeek, $today],
        'previous_week' => ['Geçen hafta', $previousWeek, $thisWeek->modify('-1 day')],
        'this_month' => ['Bu ay', $thisMonth, $today],
        'previous_month' => ['Geçen ay', $previousMonth, $thisMonth->modify('-1 day')],
    ];
}

function render_report_period_filter(array $filters): void
{
    $dateFrom = (string) ($filters['date_from'] ?? '');
    $dateTo = (string) ($filters['date_to'] ?? '');
    ?>
    <section class="report-period-filter" data-report-period-filter>
        <strong class="report-period-title">Rapor dönemi</strong>
        <div class="report-period-options">
            <?php $activeMatched = false; ?>
            <?php foreach (report_period_options() as [$label, $from, $to]): ?>
                <?php
                $fromValue = $from->format('Y-m-d');
                $toValue = $to->format('Y-m-d');
                $active = !$activeMatched && $dateFrom === $fromValue && $dateTo === $toValue;
                $activeMatched = $activeMatched || $active;
                ?>
                <button
                    class="report-period-option <?= $active ? 'active' : '' ?>"
                    type="button"
                    data-date-from="<?= e($fromValue) ?>"
                    data-date-to="<?= e($toValue) ?>"
                ><?= e($label) ?></button>
            <?php endforeach; ?>
        </div>
        <label class="report-period-date">Başlangıç
            <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        </label>
        <label class="report-period-date">Bitiş
            <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        </label>
    </section>
    <script>
    (() => {
        const section = document.currentScript.previousElementSibling;
        if (!section || section.dataset.reportPeriodReady === '1') return;
        section.dataset.reportPeriodReady = '1';
        const fromInput = section.querySelector('[name="date_from"]');
        const toInput = section.querySelector('[name="date_to"]');
        const options = [...section.querySelectorAll('[data-date-from]')];
        const updateActive = (preferred = null) => {
            let matched = false;
            options.forEach((option) => {
                const sameRange = option.dataset.dateFrom === fromInput.value
                    && option.dataset.dateTo === toInput.value;
                const active = sameRange && (!matched || option === preferred);
                option.classList.toggle('active', active);
                matched = matched || active;
            });
        };
        options.forEach((option) => option.addEventListener('click', () => {
            fromInput.value = option.dataset.dateFrom;
            toInput.value = option.dataset.dateTo;
            updateActive(option);
        }));
        fromInput.addEventListener('change', updateActive);
        toInput.addEventListener('change', updateActive);
    })();
    </script>
    <?php
}
