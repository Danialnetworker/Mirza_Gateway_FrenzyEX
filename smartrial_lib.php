<?php
/**
 * [smartrial] Smart rial gateway: one buyer button, the link is made by FrenzyEx, Tronado or TetraPay.
 *
 * Choice per payment:
 *   1. eligible = in the smart pool (admin), weight > 0, the gateway itself is on and configured,
 *      the amount is inside that gateway's own min/max, not cooling down after failures;
 *   2. weighted round-robin: the eligible gateway with the lowest served/weight goes first;
 *   3. failover: if it cannot make a link the next one is tried, the buyer sees no error;
 *   4. 3 failures in a row -> that gateway sits out 15 minutes, the admin is told once.
 * Payment, short-payment and delivery stay in each gateway's own callback; this only picks who makes the link.
 *
 * Config (scope pay): statussmartrial 0/1, smartrial_cfg {gw:{on,w}}, smartrial_state {served,fails,cool,days}.
 */

if (!function_exists('smartrial_defs')) {
    define('SMARTRIAL_FAILS', 3);       // failures in a row before a gateway sits out
    define('SMARTRIAL_COOL', 900);      // seconds it sits out

    function smartrial_defs()
    {
        $d = [
            'frenzyex' => ['name' => 'FrenzyEx', 'kind' => 'کارت به کارت', 'method' => 'frenzyex', 'w' => 2],
            'tronado' => ['name' => 'ترونادو', 'kind' => 'کارت به کارت', 'method' => 'Tronado', 'w' => 2],
            'tetra98' => ['name' => 'TetraPay', 'kind' => 'پرداخت آنلاین', 'method' => 'tetra98', 'w' => 1],
        ];
        if (!function_exists('createPayTronado')) {   // builds without Tronado (Mirza_Gateway_FrenzyEX)
            unset($d['tronado']);
        }
        return $d;
    }

    function smartrial_get($name, $default = '')
    {
        $v = function_exists('getPaySettingValue') ? getPaySettingValue($name, $default) : config_get('pay', $name, $default);
        return trim((string) ($v ?? $default));
    }

    function smartrial_set($name, $value)
    {
        config_set('pay', $name, (string) $value);
    }

    function smartrial_on()
    {
        return smartrial_get('statussmartrial', '0') === '1';
    }

    /** Admin pool settings, defaults filled in. */
    function smartrial_cfg()
    {
        $c = json_decode(smartrial_get('smartrial_cfg', ''), true);
        $c = is_array($c) ? $c : [];
        $out = [];
        foreach (smartrial_defs() as $gw => $d) {
            $out[$gw] = ['on' => (int) ($c[$gw]['on'] ?? 1), 'w' => max(0, min(10, (int) ($c[$gw]['w'] ?? $d['w'])))];
        }
        return $out;
    }

    function smartrial_save_cfg(array $c)
    {
        smartrial_set('smartrial_cfg', json_encode($c));
    }

    function smartrial_state()
    {
        $s = json_decode(smartrial_get('smartrial_state', ''), true);
        $s = is_array($s) ? $s : [];
        foreach (['served', 'fails', 'cool', 'days', 'err'] as $k) {
            $s[$k] = is_array($s[$k] ?? null) ? $s[$k] : [];
        }
        return $s;
    }

    function smartrial_save_state(array $s)
    {
        $keep = [];
        foreach ($s['days'] as $day => $v) {
            if ($day >= date('Y-m-d', time() - 8 * 86400)) {
                $keep[$day] = $v;
            }
        }
        $s['days'] = $keep;
        smartrial_set('smartrial_state', json_encode($s, JSON_UNESCAPED_UNICODE));
    }

    /** The gateway itself is on and has every key it needs (same rule as its own buyer button). */
    function smartrial_gateway_ready($gw)
    {
        switch ($gw) {
            case 'frenzyex':
                require_once __DIR__ . '/frenzyex_lib.php';
                return frenzyex_enabled();
            case 'tronado':
                return smartrial_get('statustronado', 'offtronado') === 'ontronado' && function_exists('tronadoConfigured') && tronadoConfigured();
            case 'tetra98':
                require_once __DIR__ . '/tetra98_lib.php';
                return tetra98_enabled();
        }
        return false;
    }

    /** [min, max] in toman, the gateway's own limits. */
    function smartrial_range($gw)
    {
        switch ($gw) {
            case 'frenzyex':
                return [(int) smartrial_get('minbalancefrenzyex', '20000'), (int) smartrial_get('maxbalancefrenzyex', '1000000')];
            case 'tronado':
                return [(int) smartrial_get('minbalancetronado', '0'), (int) smartrial_get('maxbalancetronado', '0')];
            case 'tetra98':
                return [(int) smartrial_get('minbalancetetra98', '30000'), (int) smartrial_get('maxbalancetetra98', '10000000')];
        }
        return [1, 0];
    }

    /** Eligible gateways for this amount, best first (lowest served/weight; ties keep the defs order). */
    function smartrial_candidates($amount, $now = null)
    {
        $now = $now ?? time();
        $cfg = smartrial_cfg();
        $st = smartrial_state();
        $list = [];
        $i = 0;
        foreach (smartrial_defs() as $gw => $d) {
            $i++;
            if (!$cfg[$gw]['on'] || $cfg[$gw]['w'] <= 0 || ($st['cool'][$gw] ?? 0) > $now) {
                continue;
            }
            [$min, $max] = smartrial_range($gw);
            if ($amount < $min || $amount > $max || !smartrial_gateway_ready($gw)) {
                continue;
            }
            $list[$gw] = [((int) ($st['served'][$gw] ?? 0) + 1) / $cfg[$gw]['w'], $i];
        }
        uasort($list, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        return array_keys($list);
    }

    /** Pool usable at all (button shown before the amount is known). */
    function smartrial_any_ready()
    {
        if (!smartrial_on()) {
            return false;
        }
        foreach (smartrial_cfg() as $gw => $c) {
            if ($c['on'] && $c['w'] > 0 && smartrial_gateway_ready($gw)) {
                return true;
            }
        }
        return false;
    }

    /** Payment-list keyboard for this amount: drop the smart button when no gateway can take it. */
    function smartrial_filter($keyboard, $amount)
    {
        $k = is_string($keyboard) ? json_decode($keyboard, true) : null;
        if (!is_array($k) || empty($k['inline_keyboard']) || strpos($keyboard, '"smartrial"') === false) {
            return $keyboard;
        }
        if (smartrial_candidates((int) $amount)) {
            return $keyboard;
        }
        $rows = [];
        foreach ($k['inline_keyboard'] as $row) {
            $row = array_values(array_filter($row, fn($b) => ($b['callback_data'] ?? '') !== 'smartrial'));
            if ($row) {
                $rows[] = $row;
            }
        }
        $k['inline_keyboard'] = $rows;
        return json_encode($k);
    }

    function smartrial_lock($fn)
    {
        global $pdo;
        $pdo->query("SELECT GET_LOCK('mirza_smartrial', 10)");
        try {
            return $fn();
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('mirza_smartrial')");
        }
    }

    function smartrial_report($text)
    {
        $setting = config_all('setting');
        if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
            telegram('sendmessage', ['chat_id' => $setting['Channel_Report'], 'message_thread_id' => config_get('topic', 'errorreport'),
                'text' => $text, 'parse_mode' => 'HTML']);
        }
    }

    /** Record one attempt; returns true when this failure started a cooldown. */
    function smartrial_mark($gw, $ok, $why = '', $now = null)
    {
        $now = $now ?? time();
        return smartrial_lock(function () use ($gw, $ok, $why, $now) {
            $s = smartrial_state();
            $day = date('Y-m-d', $now);
            $s['days'][$day][$gw][$ok ? 'sent' : 'fail'] = (int) ($s['days'][$day][$gw][$ok ? 'sent' : 'fail'] ?? 0) + 1;
            $cool = false;
            if ($ok) {
                $s['served'][$gw] = (int) ($s['served'][$gw] ?? 0) + 1;
                $s['fails'][$gw] = 0;
            } else {
                $s['fails'][$gw] = (int) ($s['fails'][$gw] ?? 0) + 1;
                $s['err'][$gw] = mb_substr($why, 0, 200);
                if ($s['fails'][$gw] >= SMARTRIAL_FAILS) {
                    $s['cool'][$gw] = $now + SMARTRIAL_COOL;
                    $s['fails'][$gw] = 0;
                    $cool = true;
                }
            }
            // keep served counts small and comparable when a gateway joins later
            $min = $s['served'] ? min($s['served']) : 0;
            if ($min > 1000) {
                foreach ($s['served'] as $k => $v) {
                    $s['served'][$k] = $v - $min;
                }
            }
            smartrial_save_state($s);
            return $cool;
        });
    }

    /**
     * Make a link with one gateway. Writes the payment row exactly like the gateway's own button.
     * ['ok'=>true,'order','text','markup','help'(optional)] or ['ok'=>false,'why'].
     */
    function smartrial_create($gw, array $user, $from_id)
    {
        global $pdo, $textbotlang;
        $amount = (int) $user['Processing_value'];
        $pay_btn = $textbotlang['users']['Balance']['payments'] ?? '💳 پرداخت';
        $tpl = $textbotlang['users']['Balance']['invoiceCreated2'] ?? "شماره سفارش: %s\nمبلغ: %s تومان";
        if ($gw === 'frenzyex') {
            require_once __DIR__ . '/frenzyex_lib.php';
            $order = bin2hex(random_bytes(5));
            $p = createPayFrenzyEx($amount, $order);
            if (empty($p['pay_url'])) {
                return ['ok' => false, 'why' => 'frenzyex: ' . mb_substr((string) ($p['message'] ?? json_encode($p)), 0, 150)];
            }
            $pdo->prepare("INSERT INTO payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$from_id, $order, date('Y/m/d H:i:s'), $amount, 'Unpaid', 'frenzyex', null, (string) ($p['request_id'] ?? '')]);
            return ['ok' => true, 'order' => $order, 'text' => sprintf($tpl, $order, number_format($amount)),
                'markup' => json_encode(['inline_keyboard' => [[['text' => $pay_btn, 'url' => $p['pay_url']]]]])];
        }
        if ($gw === 'tetra98') {
            require_once __DIR__ . '/tetra98_lib.php';
            $order = 'tt' . bin2hex(random_bytes(6));
            $p = createPayTetra98($amount, $order);
            if (empty($p['ok'])) {
                return ['ok' => false, 'why' => 'tetra98: ' . ($p['code'] ?? '') . ' ' . mb_substr((string) ($p['message'] ?? ''), 0, 120)];
            }
            $pdo->prepare("INSERT INTO payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$from_id, $order, date('Y/m/d H:i:s'), $amount, 'Unpaid', 'tetra98', null, json_encode(['token' => $p['token']])]);
            $text = sprintf($tpl, $order, number_format($amount));
            if (($p['payable_amount'] ?? '') !== '' && (int) $p['payable_amount'] > $amount * 10) {
                $text .= "\nمبلغ قابل پرداخت با کارمزد درگاه: " . number_format(intdiv((int) $p['payable_amount'], 10)) . " تومان";
            }
            return ['ok' => true, 'order' => $order, 'text' => $text,
                'markup' => json_encode(['inline_keyboard' => [[['text' => $pay_btn, 'url' => $p['payment_url']]]]])];
        }
        if ($gw === 'tronado') {
            $order = bin2hex(random_bytes(5));
            // row first: the order id is Tronado's PaymentID and its callback must find the row
            $pdo->prepare("INSERT INTO payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)")
                ->execute([$from_id, $order, date('Y/m/d H:i:s'), $amount, 'Unpaid', 'Tronado', null]);
            $p = createPayTronado($amount, $order);
            if (empty($p['success']) || empty($p['payment_link'])) {
                $pdo->prepare("UPDATE payment_report SET payment_Status = 'expire' WHERE id_order = ?")->execute([$order]);
                if (function_exists('clearSelectCache')) {
                    clearSelectCache('payment_report');
                }
                return ['ok' => false, 'why' => mb_substr((string) ($p['message'] ?? 'tronado: no link'), 0, 150)];
            }
            tronadoStoreMeta($order, tronadoSealMeta(['token' => $p['token'], 'trx' => $p['trx'], 'trx_price' => $p['trx_price'],
                'wallet' => $p['wallet'], 'estimated_toman' => $p['estimated_toman'], 'created_utc' => gmdate('c')]));
            $trx = rtrim(rtrim(number_format($p['trx'], 6, '.', ''), '0'), '.');
            return ['ok' => true, 'order' => $order, 'tronado' => true, 'help' => smartrial_get('helptronado', '2'),
                'text' => sprintf($textbotlang['users']['Balance']['transactionCreatedTronado'], $order, number_format($amount), $trx),
                'markup' => json_encode(['inline_keyboard' => [[['text' => $pay_btn, 'url' => $p['payment_link']]]]])];
        }
        return ['ok' => false, 'why' => 'unknown gateway ' . $gw];
    }

    /** Admin keyboard of the smart gateway (with the on/off row on top when $withStatus). */
    function smartrial_admin_rows($withStatus = false)
    {
        $cfg = smartrial_cfg();
        $st = smartrial_state();
        $rows = [];
        if ($withStatus) {
            $rows[] = [['text' => smartrial_on() ? '✅ درگاه هوشمند روشن' : '❌ درگاه هوشمند خاموش', 'callback_data' => 'paygwtoggle-smartrial']];
        }
        foreach (smartrial_defs() as $gw => $d) {
            $ready = smartrial_gateway_ready($gw);
            $cool = ($st['cool'][$gw] ?? 0) > time();
            $mark = !$cfg[$gw]['on'] ? '❌' : (!$ready ? '⚠️' : ($cool ? '⏸' : '✅'));
            $rows[] = [
                ['text' => $mark . ' ' . $d['name'], 'callback_data' => 'srl-t-' . $gw],
                ['text' => '⚖️ وزن: ' . $cfg[$gw]['w'], 'callback_data' => 'srl-w-' . $gw],
            ];
        }
        $rows[] = [['text' => '📊 آمار درگاه‌ها', 'callback_data' => 'srl-stats']];
        $rows[] = [['text' => '↩️ بازگشت به درگاه‌ها', 'callback_data' => 'paygwlist']];
        return $rows;
    }

    function smartrial_admin_text()
    {
        $cfg = smartrial_cfg();
        $total = 0;
        foreach ($cfg as $c) {
            $total += $c['on'] ? $c['w'] : 0;
        }
        $t = "💳 <b>درگاه هوشمند ریالی</b>\n"
            . "وضعیت: " . (smartrial_on() ? '✅ روشن' : '❌ خاموش') . "\n\n"
            . "هر پرداخت به‌نوبت (به نسبت وزن) با یکی از درگاه‌های روشن ساخته می‌شود؛ اگر درگاهی لینک نسازد، درگاه بعدی امتحان می‌شود.\n\n";
        $st = smartrial_state();
        foreach (smartrial_defs() as $gw => $d) {
            [$min, $max] = smartrial_range($gw);
            $share = ($cfg[$gw]['on'] && $total > 0) ? round(100 * $cfg[$gw]['w'] / $total) . '٪' : '—';
            $note = !smartrial_gateway_ready($gw) ? ' ⚠️ خود درگاه خاموش است یا کلید ندارد'
                : ((($st['cool'][$gw] ?? 0) > time()) ? ' ⏸ موقتاً کنار گذاشته شده تا ' . date('H:i', $st['cool'][$gw]) : '');
            $t .= "• <b>{$d['name']}</b> ({$d['kind']}): " . ($cfg[$gw]['on'] ? 'در چرخه' : 'خارج از چرخه')
                . " | وزن {$cfg[$gw]['w']} | سهم $share\n   مبلغ: " . number_format($min) . ' تا ' . number_format($max) . " تومان$note\n";
        }
        $t .= "\n✅ در چرخه  ❌ خارج از چرخه  ⚠️ درگاه آماده نیست  ⏸ موقتاً کنار گذاشته شده\nبرای خارج/وارد کردن، روی نام درگاه بزنید.";
        return $t;
    }

    function smartrial_stats_text($now = null)
    {
        global $pdo;
        $now = $now ?? time();
        $st = smartrial_state();
        $t = "📊 <b>آمار درگاه‌های ریالی</b>\n";
        foreach ([[1, '۲۴ ساعت اخیر'], [7, '۷ روز اخیر']] as [$days, $title]) {
            $since = date('Y/m/d H:i:s', $now - $days * 86400);
            $t .= "\n<b>$title</b>\n";
            foreach (smartrial_defs() as $gw => $d) {
                $q = $pdo->prepare("SELECT COUNT(*) n, SUM(payment_Status='paid') p, SUM(IF(payment_Status='paid', price, 0)) s FROM payment_report WHERE Payment_Method = ? AND time >= ?");
                $q->execute([$d['method'], $since]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                $n = (int) $r['n'];
                $p = (int) $r['p'];
                $sent = 0;
                $fail = 0;
                foreach ($st['days'] as $day => $v) {
                    if ($day >= date('Y-m-d', $now - ($days - 1) * 86400)) {
                        $sent += (int) ($v[$gw]['sent'] ?? 0);
                        $fail += (int) ($v[$gw]['fail'] ?? 0);
                    }
                }
                $t .= "• {$d['name']}: $n سفارش، $p موفق" . ($n ? ' (' . round(100 * $p / $n) . '٪)' : '') . '، ' . number_format((int) $r['s']) . " تومان"
                    . " | هوشمند: $sent لینک" . ($fail ? "، $fail خطا" : '') . "\n";
            }
        }
        foreach ($st['err'] as $gw => $e) {
            if ($e !== '' && isset(smartrial_defs()[$gw])) {
                $t .= "\nآخرین خطای " . smartrial_defs()[$gw]['name'] . ': <code>' . htmlspecialchars($e) . '</code>';
            }
        }
        return $t;
    }
}
