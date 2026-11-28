<?php
/**
 * [tetra98] TetraPay return page (GET ?token=&status=) and signed webhook (POST).
 * Both settle through tetra98_settle(), which asks TetraPay's verify itself:
 * the ?status= on the return URL is never trusted.
 */

ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels/Api/Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../tetra98_lib.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange();

$sigHeader = (string) ($_SERVER['HTTP_X_TETRAPAY_SIGNATURE'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sigHeader !== '') {
    header('Content-Type: application/json');
    $raw = (string) file_get_contents('php://input');
    if (!tetra98_webhook_ok($raw, $sigHeader, tetra98_setting('webhooktetra98', ''))) {
        error_log('TetraPay: webhook with a bad or stale signature');
        http_response_code(400);
        echo '{"ok":false}';
        return;
    }
    $ev = json_decode($raw, true);
    $event = (string) ($_SERVER['HTTP_X_TETRAPAY_EVENT'] ?? ($ev['event'] ?? ''));
    // sandbox webhooks are signed with the real key: never let one settle a live order
    $isSandbox = !empty($ev['sandbox']) || strtolower((string) ($_SERVER['HTTP_X_TETRAPAY_SANDBOX'] ?? '')) === 'true';
    if (!is_array($ev) || $event !== 'payment.paid' || $isSandbox !== tetra98_sandbox()) {
        echo '{"ok":true,"ignored":true}';
        return;
    }
    $r = tetra98_settle((string) ($ev['token'] ?? ''), 'webhook');
    if ($r['state'] === 'retry') {
        http_response_code(503);          // TetraPay re-sends after 1, 2, 5, 10 ... minutes
    }
    echo json_encode(['ok' => $r['state'] !== 'retry', 'state' => $r['state']]);
    return;
}

$token = (string) ($_GET['token'] ?? '');
$r = $token !== '' ? tetra98_settle($token, 'return') : ['state' => 'unknown'];

$bot = isset($usernamebot) && $usernamebot !== '' ? 'https://t.me/' . rawurlencode($usernamebot) : '';
switch ($r['state']) {
    case 'paid':
    case 'duplicate':
        [$icon, $title, $msg] = ['✅', 'پرداخت موفق بود', 'نتیجه در ربات برای شما ارسال شد.'];
        break;
    case 'short':
        [$icon, $title, $msg] = ['⚠️', 'مبلغ واریزی کمتر از سفارش بود', 'مبلغ دریافتی به کیف پول شما در ربات اضافه شد.'];
        break;
    case 'retry':
        [$icon, $title, $msg] = ['⏳', 'در حال بررسی پرداخت', 'اگر پرداخت کرده‌اید، تا چند دقیقهٔ دیگر نتیجه در ربات ارسال می‌شود.'];
        break;
    case 'unpaid':
        $pending = ($r['status'] ?? '') === 'pending';
        [$icon, $title, $msg] = $pending
            ? ['⏳', 'پرداخت در حال بررسی است', 'پس از تأیید، نتیجه در ربات برای شما ارسال می‌شود.']
            : ['❌', 'پرداخت انجام نشد', 'اگر مبلغی از حساب شما کم شده، طبق قوانین بانک برگشت داده می‌شود.'];
        break;
    default:
        [$icon, $title, $msg] = ['❌', 'پرداخت پیدا نشد', 'لطفاً از داخل ربات دوباره اقدام کنید.'];
}
header('Content-Type: text/html; charset=utf-8');
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?></title>
<style>body{font-family:Tahoma,Vazirmatn,sans-serif;background:#f4f6f8;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}
.c{background:#fff;border-radius:16px;padding:32px 24px;max-width:380px;width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.08)}
.i{font-size:48px}h1{font-size:20px;margin:12px 0}p{color:#555;line-height:1.9}
a{display:inline-block;margin-top:16px;background:#229ED9;color:#fff;text-decoration:none;padding:12px 28px;border-radius:10px}</style></head>
<body><div class="c"><div class="i"><?= $icon ?></div><h1><?= $h($title) ?></h1><p><?= $h($msg) ?></p>
<?php if (!empty($r['order_id'])): ?><p>شماره سفارش: <b dir="ltr"><?= $h($r['order_id']) ?></b></p><?php endif; ?>
<?php if ($bot !== ''): ?><a href="<?= $h($bot) ?>">بازگشت به ربات</a><?php endif; ?>
</div></body></html>
