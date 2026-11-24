'use strict';
let levelPage = 0;
let levelGeneration = 0;
const colourNames = { R: 'red', G: 'green', D: 'doji' };

async function loadFillLevels() {
    if (!$('filters').reportValidity()) return;
    const current = ++levelGeneration;
    const dateKey = $('start').value + $('end').value;
    $('levelStatus').textContent = 'Comparing candle price levels…';
    const query = {
        page: levelPage, min_fills: $('fills').value,
        ob: $('levelOB').value, color: $('levelColor').value,
        source: $('levelSource').value, field: $('levelField').value,
        kind: $('levelKind').value, offset: $('levelOffset').value,
        group: $('levelGroup').value, sort: $('levelSort').value
    };
    try {
        const data = await api('fill_levels', query);
        if (current !== levelGeneration || dateKey !== $('start').value + $('end').value) return;
        head($('levelRank'), ['Sequence / context', 'Swing', 'Candle level', 'Event / offset',
            'Fills / eligible', 'Fill rate', 'BUY wins', 'BUY net', 'BUY avg / fill',
            'SELL wins', 'SELL net', 'SELL avg / fill', '']);
        for (const row of data.rows) {
            const rule = row.rule;
            const tr = $('levelRank').insertRow();
            const percentage = value => value === null ? '—' : Number(value).toFixed(1) + '%';
            const values = [
                row.sequence + ' · ' + row.ob, '#' + (rule.j + 1),
                rule.sourceCandle + ' ' + colourNames[row.color] + ' ' + rule.field,
                rule.kind + ' / ' + (rule.off > 0 ? '+' : '') + rule.off + ' ticks',
                row.n + ' / ' + row.eligible, percentage(row.fill_rate),
                percentage(row.buy_win_rate), money(row.bnet), money(row.buy_average_usd),
                percentage(row.sell_win_rate), money(row.snet), money(row.sell_average_usd)
            ];
            values.forEach(value => cell(tr, value));
            action(cell(tr, ''), 'Inspect fills', () => inspect(row));
        }
        $('levelStatus').textContent = data.needs_snapshot ? data.message :
            (data.rows.length ? 'Page ' + (levelPage + 1) + ' · ' + data.denominator : 'No matching levels for these dates and filters.');
        $('levelPrev').disabled = levelPage === 0;
        $('levelNext').disabled = data.next_page === null;
    } catch (e) {
        if (current === levelGeneration) $('levelStatus').textContent = e.message;
    }
}

function clearFillLevels() {
    levelGeneration++;
    levelPage = 0;
    $('levelRank').replaceChildren();
    $('levelStatus').textContent = 'Dates changed. Click Compare fill levels to refresh.';
    $('levelPrev').disabled = $('levelNext').disabled = true;
}
$('levelFilters').onsubmit = event => { event.preventDefault(); levelPage = 0; loadFillLevels(); };
$('levelPrev').onclick = () => { levelPage--; loadFillLevels(); };
$('levelNext').onclick = () => { levelPage++; loadFillLevels(); };
$('start').addEventListener('change', clearFillLevels);
$('end').addEventListener('change', clearFillLevels);
$('fills').addEventListener('change', clearFillLevels);
$('all').addEventListener('click', clearFillLevels);
