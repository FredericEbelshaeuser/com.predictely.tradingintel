<?php
declare(strict_types=1);

function fl_choice(array $q, string $name, array $options, string $default): string {
    $value = $q[$name] ?? $default;
    if (!is_string($value) || !in_array($value, $options, true)) {
        throw new InvalidArgumentException('Invalid '.$name);
    }
    return $value;
}

function fl_filters(array $q): array {
    return [
        'ob' => fl_choice($q, 'ob', ['*','BUY OB','SELL OB','No OB'], '*'),
        'color' => fl_choice($q, 'color', ['*','R','G','D'], '*')
    ];
}

function fl_reference(array $legs, array $rule): ?array {
    if (!isset($rule['sourceCandle'])) return null;
    $leg = $legs[$rule['j']];
    $candle = $leg['candles'][$rule['sourceCandle']];
    $price = $candle[$rule['field']];
    return [
        'swing' => $rule['j'] + 1, 'swing_label' => $leg['label'],
        'orderblock' => $leg['ob'], 'source_candle' => $rule['sourceCandle'],
        'candle_time_et' => $candle['time_et'], 'color' => $candle['color'],
        'field' => $rule['field'], 'source_price' => $price,
        'offset_ticks' => $rule['off'], 'tested_price' => $price + $rule['off'] * .25
    ];
}

function fl_where(array $q, ?int $rid): array {
    $filters = fl_filters($q);
    if ($filters['ob'] === '*' && $filters['color'] === '*') return ['', []];
    $rule = $rid === null ? null : (ml_rules()[$rid]['spec'] ?? null);
    if (!$rule || !isset($rule['sourceCandle'])) {
        throw new InvalidArgumentException('OB/color filters require a candle-pair rule_id from this run’s manifest.');
    }
    $base = '$['.$rule['j'].']';
    $where = '';
    $args = [];
    foreach (['ob' => $base.'.ob', 'color' => $base.'.candles.'.$rule['sourceCandle'].'.color'] as $key => $jsonPath) {
        if ($filters[$key] !== '*') {
            $where .= ' AND json_extract(o.features,?)=?';
            array_push($args, $jsonPath, $filters[$key]);
        }
    }
    return [$where, $args];
}

function fl_rank(array $q, string $start, string $end): array {
    if ((ml_meta()['version'] ?? 1) < 2) {
        return ['rows' => [], 'next_page' => null, 'needs_snapshot' => true,
            'message' => 'Upload the fill-levels v2 master.sqlite snapshot to enable this report.'];
    }
    $filters = fl_filters($q);
    $group = fl_choice($q, 'group', ['level','sequence'], 'level');
    $sort = fl_choice($q, 'sort', ['fill_rate','buy_win','sell_win','buy_net','sell_net','best_net'], 'fill_rate');
    $offset = fl_choice($q, 'offset', ['*','-1','0','1'], '0');
    $kind = fl_choice($q, 'kind', ['*','return','retest'], '*');
    $source = fl_choice($q, 'source', ['*','A','B'], '*');
    $field = fl_choice($q, 'field', ['*','open','high','low','close'], '*');
    $sid = ml_uint($q, 'sid', 0, 10000000);
    $limit = max(1, ml_uint($q, 'limit', 25, 100));
    $page = ml_uint($q, 'page', 0, 1000000);
    $minimum = ml_uint($q, 'min_fills', 1, 10000000);
    $meta = ml_meta();
    $full = $start <= substr($meta['first'],0,10) && $end >= substr($meta['last'],0,10);
    $table = $full ? 'level_totals AS t' : 'level_roll AS t INDEXED BY level_dates';
    $where = $full ? '1=1' : 'first_day>=? AND end_day<=?';
    $args = $full ? [] : [$start, $end];
    if ($sid) { $where .= ' AND t.sid=CAST(? AS INTEGER)'; $args[] = $sid; }
    foreach ($filters as $key => $value) {
        if ($value !== '*') { $where .= ' AND t.'.$key.'=?'; $args[] = $value; }
    }
    foreach (['off'=>$offset, 'kind'=>$kind, 'sourceCandle'=>$source, 'field'=>$field] as $key => $value) {
        if ($value !== '*') {
            $where .= " AND CAST(json_extract(u.spec,'$.".$key."') AS TEXT)=?";
            $args[] = $value;
        }
    }
    $sequence = $group === 'sequence' || $sid;
    $columns = 't.rid,t.ob,t.color';
    $keys = $sequence ? 't.sid,'.$columns : $columns;
    $select = $sequence ? 't.sid,s.name AS sequence,' : "0 AS sid,'All swing sequences' AS sequence,";
    $order = [
        'fill_rate'=>'fill_rate DESC,n DESC',
        'buy_win'=>'buy_win_rate DESC,n DESC',
        'sell_win'=>'sell_win_rate DESC,n DESC',
        'buy_net'=>'bnet DESC,buy_win_rate DESC',
        'sell_net'=>'snet DESC,sell_win_rate DESC',
        'best_net'=>'max(sum(t.bnet),sum(t.snet)) DESC,n DESC'
    ][$sort];
    $sql = "SELECT $select $columns,u.name AS entry_rule,u.spec,
        sum(completed) AS completed,sum(eligible) AS eligible,sum(n) AS n,
        sum(bnet) AS bnet,sum(bw) AS bw,sum(bl) AS bl,
        sum(snet) AS snet,sum(sw) AS sw,sum(sl) AS sl,
        100.0*sum(n)/nullif(sum(eligible),0) AS fill_rate,
        100.0*sum(bw)/nullif(sum(n),0) AS buy_win_rate,
        100.0*sum(sw)/nullif(sum(n),0) AS sell_win_rate
        FROM $table JOIN rules u USING(rid) JOIN seq s USING(sid)
        WHERE $where GROUP BY $keys HAVING sum(n)>=CAST(? AS INTEGER)
        ORDER BY $order,$keys LIMIT ".($limit+1)." OFFSET ".($page*$limit);
    $rows = ml_rows($sql, [...$args, $minimum]);
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    foreach ($rows as &$row) {
        $row['rule'] = json_decode($row['spec'], true);
        unset($row['spec']);
        $row['buy_average_usd'] = $row['n'] ? $row['bnet']/$row['n'] : null;
        $row['sell_average_usd'] = $row['n'] ? $row['snet']/$row['n'] : null;
        $row['unfilled'] = $row['eligible']-$row['n'];
        $row['ineligible'] = $row['completed']-$row['eligible'];
    }
    return ['rows'=>$rows, 'next_page'=>$more ? $page+1 : null, 'group'=>$group,
        'fill_definition'=>'First qualifying touch per rule after sequence confirmation, or after a new swing-level break for retests.',
        'denominator'=>'Fill rate = filled / eligible complete windows. Each level is a separate trial; level totals overlap.'];
}
