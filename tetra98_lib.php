<?php
/**
 * [tetra98] TetraPay (tetra98.com) gateway: create a link, verify, settle.
 *
 * API v1: https://api.tetra98.com/api/v1 (sandbox: https://api.sandbox.tetra98.com/api/v1)
 *   POST /payments                 {amount (RIAL, string), order_id, callback_url, webhook_url}
 *   POST /payments/{token}/verify  the only final answer (with a signed webhook)
 * Webhook: X-TetraPay-Signature "t=<unix>,v1=<hex>", HMAC-SHA256(webhook key, "t.rawbody").
 *
 * Mirza amounts are TOMAN; TetraPay amounts are RIAL (x10).
 * The callback return and the webhook both end in tetra98_settle(), which calls
 * verify itself and delivers through claimPaymentPaid(): exactly once per order.
 */

if (!function_exists('tetra98_setting')) {
    function tetra98_setting($name, $default = '')
    {
        if (function_exists('getPaySettingValue')) {
            return trim((string) getPaySettingValue($name, $default));
        }
        return trim((string) config_get('pay', $name, $default));
    }

    function tetra98_is_set($value)
    {
        $value = trim((string) $value);
        return $value !== '' && $value !== '0';
    }

    /** Button shown only with BOTH keys: without the webhook key a paid order whose buyer never returns is lost. */
    function tetra98_enabled()
    {
        return tetra98_setting('statustetra98', '0') === '1'
            && tetra98_is_set(tetra98_setting('apitetra98', ''))
            && tetra98_is_set(tetra98_setting('webhooktetra98', ''));
    }

    function tetra98_sandbox()
    {
        return tetra98_setting('sandboxtetra98', '0') === '1';
    }

    function tetra98_base()
    {
        $custom = tetra98_setting('endpointtetra98', '');   // hidden: tests point this at a mock server
        if (tetra98_is_set($custom)) {
            return rtrim($custom, '/');
        }
        return tetra98_sandbox() ? 'https://api.sandbox.tetra98.com/api/v1' : 'https://api.tetra98.com/api/v1';
    }

    /** Returns [http_code, decoded_json_or_null, curl_error]. */
    function tetra98_api($method, $path, $body = null)
    {
        $key = tetra98_setting('apitetra98', '');
        if (!tetra98_is_set($key)) {
            return [0, null, 'api key is unset'];
        }
        $curl = curl_init(tetra98_base() . $path);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $key];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $opts);
        $raw = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $err = curl_error($curl);
        curl_close($curl);
        if ($raw === false) {
            return [0, null, $err];
        }
        $json = json_decode($raw, true);
        return [$code, is_array($json) ? $json : null, ''];
    }

    function tetra98_rial($toman)
    {
        return (string) ((int) round((float) $toman) * 10);
    }

    /** ['ok'=>true,'token','payment_url'] or ['ok'=>false,'code','message']. */
    function createPayTetra98($amount_toman, $order_id)
    {
        global $domainhosts;
        $cb = 'https://' . $domainhosts . '/payment/tetra98.php';
        [$code, $j, $err] = tetra98_api('POST', '/payments', [
            'amount' => tetra98_rial($amount_toman),
            'order_id' => (string) $order_id,
            'callback_url' => $cb,
            'webhook_url' => $cb,
            'description' => 'Mirza ' . $order_id,
        ]);
        if (($code === 200 || $code === 201) && !empty($j['token']) && !empty($j['payment_url'])) {
            return ['ok' => true, 'token' => (string) $j['token'], 'payment_url' => (string) $j['payment_url'],
                'payable_amount' => (string) ($j['payable_amount'] ?? '')];
        }
        $ecode = (string) ($j['error']['code'] ?? ($err !== '' ? 'NETWORK' : 'HTTP_' . $code));
        error_log('TetraPay: create failed — ' . $ecode . ' ' . mb_substr((string) ($j['error']['message'] ?? $err), 0, 200));
        return ['ok' => false, 'code' => $ecode, 'message' => (string) ($j['error']['message'] ?? $err)];
    }

    /** Signed webhook check: true only for a fresh (<= 5 min) body signed with our webhook key. */
    function tetra98_webhook_ok($raw, $header, $secret, $now = null)
    {
        if (!tetra98_is_set($secret) || $header === '') {
            return false;
        }
        $sig = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $sig[$kv[0]] = $kv[1];
            }
        }
        $t = (string) ($sig['t'] ?? '');
        $v1 = strtolower((string) ($sig['v1'] ?? ''));
        if (!ctype_digit($t) || abs(($now ?? time()) - (int) $t) > 300 || $v1 === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $t . '.' . $raw, $secret), $v1);
    }

    function tetra98_report($text)
    {
        $setting = config_all('setting');
        if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => config_get('topic', 'paymentreport'),
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        }
    }

    function tetra98_store($order_id, array $v)
    {
        global $pdo;
        $pdo->prepare('UPDATE payment_report SET dec_not_confirmed = :d WHERE id_order = :o')
            ->execute([':d' => json_encode($v, JSON_UNESCAPED_UNICODE), ':o' => $order_id]);
    }

    /**
     * Verify one TetraPay token and settle its order once.
     * Returns ['state' => paid|duplicate|short|unpaid|retry|unknown|mismatch, 'order_id', 'status'].
     *   retry    = TetraPay did not answer: the webhook must be re-sent (HTTP 503)
     *   unknown  = no such order of ours, mismatch = amount/order differs (reported, never delivered)
     */
    function tetra98_settle($token, $source, ?callable $verifier = null)
    {
        global $textbotlang;
        $token = (string) $token;
        if (!preg_match('/^[A-Za-z0-9_-]{6,128}$/', $token)) {
            return ['state' => 'unknown'];
        }
        if ($verifier) {
            [$code, $v] = $verifier($token);
        } else {
            [$code, $v] = tetra98_api('POST', '/payments/' . rawurlencode($token) . '/verify');
        }
        if ($code === 404) {
            return ['state' => 'unknown'];
        }
        if ($code !== 200 || !is_array($v) || !isset($v['status'])) {
            error_log("TetraPay: verify unavailable ($source) http=$code");
            return ['state' => 'retry'];
        }
        $order_id = (string) ($v['order_id'] ?? '');
        $status = (string) $v['status'];
        $row = $order_id !== '' ? select('payment_report', '*', 'id_order', $order_id, 'select') : false;
        if (!$row || $row['Payment_Method'] !== 'tetra98') {
            error_log("TetraPay: verify for an order that is not ours ($source)");
            return ['state' => 'unknown', 'status' => $status];
        }
        $stored = json_decode((string) $row['dec_not_confirmed'], true);
        $storedToken = is_array($stored) ? (string) ($stored['token'] ?? '') : (string) $row['dec_not_confirmed'];
        if ($storedToken !== '' && !hash_equals($storedToken, $token)) {
            error_log("TetraPay: token does not belong to order $order_id ($source)");
            return ['state' => 'mismatch', 'order_id' => $order_id, 'status' => $status];
        }
        if ($status !== 'paid') {
            return ['state' => 'unpaid', 'order_id' => $order_id, 'status' => $status];
        }
        if ($row['payment_Status'] === 'paid') {
            return ['state' => 'duplicate', 'order_id' => $order_id, 'status' => $status];
        }

        $price = (int) $row['price'];
        $buyer = select('user', '*', 'id', $row['id_user'], 'select');
        $uname = htmlspecialchars((string) ($buyer['username'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $head = "👤 @{$uname} | <code>{$row['id_user']}</code>\n🧾 سفارش: <code>" . htmlspecialchars($order_id) . "</code>\n"
            . '💰 مبلغ: <b>' . number_format($price) . " تومان</b>\n💳 درگاه: <b>TetraPay</b>"
            . (tetra98_sandbox() || !empty($v['sandbox']) ? ' 🧪 (سندباکس)' : '');

        if ((string) ($v['amount'] ?? '') !== tetra98_rial($price)) {
            if (claimPaymentPaid($order_id)) {     // close it: never delivered for a different amount
                tetra98_store($order_id, ['token' => $token, 'problem' => 'amount_mismatch'] + $v);
                tetra98_report("⚠️ <b>TetraPay: مبلغ پرداخت با سفارش یکی نیست</b>\n$head\nمبلغ درگاه: "
                    . htmlspecialchars((string) ($v['amount'] ?? '?')) . " ریال\nسرویسی تحویل نشد؛ دستی بررسی کنید.");
            }
            return ['state' => 'mismatch', 'order_id' => $order_id, 'status' => $status];
        }

        $payable = (float) ($v['payable_amount'] ?? 0);
        $received = (float) ($v['received_amount'] ?? $payable);
        if (!empty($v['partial']) || ($payable > 0 && $received + 0.5 < $payable)) {
            // short card transfer accepted: credit the share actually paid, deliver nothing
            if (!claimPaymentPaid($order_id)) {
                return ['state' => 'duplicate', 'order_id' => $order_id, 'status' => $status];
            }
            $credit = $payable > 0 ? (int) floor($price * min(1, $received / $payable)) : 0;
            if ($buyer && $credit > 0) {
                addBalance($buyer['id'], $credit);
            }
            tetra98_store($order_id, ['token' => $token, 'short' => true, 'credited' => $credit] + $v);
            sendmessage($row['id_user'], "⚠️ مبلغ واریزی شما کمتر از مبلغ سفارش بود، برای همین سفارش انجام نشد.\n"
                . '💰 مبلغ ' . number_format($credit) . " تومان به کیف پول شما اضافه شد.", null, 'HTML');
            tetra98_report("⚠️ <b>TetraPay: کم‌واریز</b>\n$head\nدریافتی: " . number_format($received / 10)
                . ' از ' . number_format($payable / 10) . " تومان\n➕ " . number_format($credit) . ' تومان به کیف پول اضافه شد؛ سرویسی تحویل نشد.');
            return ['state' => 'short', 'order_id' => $order_id, 'status' => $status];
        }

        if (!claimPaymentPaid($order_id)) {
            return ['state' => 'duplicate', 'order_id' => $order_id, 'status' => $status];
        }
        tetra98_store($order_id, ['token' => $token] + $v);
        if ($row['payment_Status'] === 'expire') {
            // the link was paid after the bot gave up on it: money arrived, so it goes to the wallet
            addBalance($row['id_user'], $price);
            sendmessage($row['id_user'], sprintf($textbotlang['users']['Balance']['addedNotice5'] ?? '%s', number_format($price)), null, 'HTML');
        } else {
            try {
                DirectPayment($order_id, '../images.jpg');
            } catch (Throwable $e) {
                error_log("TetraPay: DirectPayment failed for $order_id: " . $e->getMessage());
            }
            $cash = (int) tetra98_setting('chashbacktetra98', '0');
            if ($cash > 0 && $buyer) {
                $gift = (int) ($price * $cash / 100);
                addBalance($buyer['id'], $gift);
                sendmessage($buyer['id'], sprintf($textbotlang['paymentGateway']['giftReport'] ?? '%s', $gift), null, 'HTML');
            }
        }
        $method = ($v['method'] ?? '') === 'card' ? 'کارت به کارت' : 'آنلاین';
        tetra98_report("💵 <b>پرداخت جدید</b>\n$head ($method)\n🔖 توکن: <code>" . htmlspecialchars($token) . "</code>\n✅ وضعیت: پرداخت‌شده");
        return ['state' => 'paid', 'order_id' => $order_id, 'status' => $status];
    }
}
