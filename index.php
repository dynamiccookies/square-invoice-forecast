<?php
declare(strict_types=1);

/*
 * Square Invoice Forecast
 * Configuration is loaded from config.php in this same directory.
 */

$configFile = __DIR__ . '/config.php';
if (!is_readable($configFile)) {
    http_response_code(500);
    exit('Square configuration file is missing or unreadable.');
}

$fileConfig = require $configFile;
if (!is_array($fileConfig)) {
    http_response_code(500);
    exit('Square configuration file is invalid.');
}

date_default_timezone_set((string) ($fileConfig['business_timezone'] ?? 'America/Chicago'));

$config = [
    'token' => trim((string) ($fileConfig['square_access_token'] ?? '')),
    'environment' => strtolower(trim((string) ($fileConfig['square_environment'] ?? 'production'))),
    'api_version' => trim((string) ($fileConfig['square_api_version'] ?? '2026-07-15')),
    'location_ids' => array_values(array_filter(array_map('trim', (array) ($fileConfig['square_location_ids'] ?? [])))),
];
$config['base_url'] = $config['environment'] === 'sandbox'
    ? 'https://connect.squareupsandbox.com'
    : 'https://connect.squareup.com';
$config['dashboard_url'] = $config['environment'] === 'sandbox'
    ? 'https://app.squareupsandbox.com/dashboard/invoices'
    : 'https://app.squareup.com/dashboard/invoices';

final class SquareApiException extends RuntimeException {}

/** @return array<string,mixed> */
function squareRequest(array $config, string $method, string $path, ?array $body = null): array
{
    $url = $config['base_url'] . $path;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $headers = [
            'Authorization: Bearer ' . $config['token'],
            'Square-Version: ' . $config['api_version'],
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($header);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new SquareApiException('Unable to connect to Square: ' . $curlError);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new SquareApiException('Square returned an invalid response.');
        if ($httpCode >= 200 && $httpCode < 300) return $decoded;
        if ($httpCode === 429 && $attempt < 3) {
            $wait = min(5, max(1, (int) ($responseHeaders['retry-after'] ?? 1)));
            sleep($wait);
            continue;
        }
        $details = array_map(static fn(array $e): string => (string) ($e['detail'] ?? $e['code'] ?? 'Unknown API error'), $decoded['errors'] ?? []);
        throw new SquareApiException('Square API error (' . $httpCode . '): ' . implode('; ', $details));
    }
    throw new SquareApiException('Square rate limit persisted after retries.');
}

/** @return array{ids:list<string>,business_name:string} */
function getLocationData(array $config): array
{
    $response = squareRequest($config, 'GET', '/v2/locations');
    $requestedIds = array_flip($config['location_ids']);
    $useConfiguredLocations = count($requestedIds) > 0;
    $ids = [];
    $businessName = '';
    foreach ($response['locations'] ?? [] as $location) {
        $locationId = trim((string) ($location['id'] ?? ''));
        if ($locationId === '') continue;
        if ($useConfiguredLocations) {
            if (!isset($requestedIds[$locationId])) continue;
        } elseif (($location['status'] ?? '') !== 'ACTIVE') {
            continue;
        }
        $ids[] = $locationId;
        if ($businessName === '') {
            $businessName = trim((string) ($location['business_name'] ?? ''))
                ?: trim((string) ($location['name'] ?? ''));
        }
    }
    return ['ids' => $ids, 'business_name' => $businessName ?: 'Square business'];
}

/** @return list<array<string,mixed>> */
function getInvoices(array $config, array $locationIds): array
{
    $invoices = [];
    foreach ($locationIds as $locationId) {
        $cursor = null;
        do {
            $body = [
                'query' => [
                    'filter' => ['location_ids' => [$locationId]],
                    'sort' => ['field' => 'INVOICE_SORT_DATE', 'order' => 'DESC'],
                ],
                'limit' => 200,
            ];
            if ($cursor) $body['cursor'] = $cursor;
            $response = squareRequest($config, 'POST', '/v2/invoices/search', $body);
            foreach ($response['invoices'] ?? [] as $invoice) $invoices[] = $invoice;
            $cursor = $response['cursor'] ?? null;
        } while ($cursor);
    }
    return $invoices;
}

function cents(array $money): int { return max(0, (int) ($money['amount'] ?? 0)); }
function h(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(int $amount): string { return '$' . number_format($amount / 100, 2); }
function displayDate(string $date): string {
    if ($date === '') return '—';
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed ? $parsed->format('M j, Y') : $date;
}

$error = null;
$rows = [];
$invoiceCount = 0;
$businessName = 'Square business';
$now = new DateTimeImmutable('now');
$today = $now->format('Y-m-d');
$asOf = $now->format('F j, Y \a\t g:i A T');
$allowedStatuses = ['SCHEDULED', 'UNPAID', 'PARTIALLY_PAID'];

try {
    if ($config['token'] === '' || $config['token'] === 'REPLACE_WITH_YOUR_SQUARE_ACCESS_TOKEN') {
        throw new SquareApiException('The Square access token has not been entered in config.php.');
    }
    $locationData = getLocationData($config);
    $locations = $locationData['ids'];
    $businessName = $locationData['business_name'];
    if (!$locations) throw new SquareApiException('No active Square locations were found.');
    foreach (getInvoices($config, $locations) as $invoice) {
        if (!in_array((string) ($invoice['status'] ?? ''), $allowedStatuses, true)) continue;
        $payments = $invoice['payment_requests'] ?? [];
        $invoiceTotal = 0;
        $invoiceOutstanding = 0;
        foreach ($payments as $request) {
            $computed = cents($request['computed_amount_money'] ?? []);
            $completed = cents($request['total_completed_amount_money'] ?? []);
            $invoiceTotal += $computed;
            $invoiceOutstanding += max(0, $computed - $completed);
        }
        if ($invoiceOutstanding <= 0) continue;
        $invoiceCount++;
        $recipient = $invoice['primary_recipient'] ?? [];
        $personName = trim(implode(' ', array_filter([$recipient['given_name'] ?? '', $recipient['family_name'] ?? ''])));
        $client = trim((string) ($recipient['company_name'] ?? '')) ?: ($personName ?: 'Unknown client');
        $invoiceUrl = trim((string) ($invoice['public_url'] ?? '')) ?: $config['dashboard_url'];
        foreach ($payments as $request) {
            $computed = cents($request['computed_amount_money'] ?? []);
            $completed = cents($request['total_completed_amount_money'] ?? []);
            $remaining = max(0, $computed - $completed);
            if ($remaining <= 0) continue;
            $dueDate = (string) ($request['due_date'] ?? '');
            $timing = $dueDate < $today ? 'Past due' : ($dueDate === $today ? 'Due today' : 'Future');
            $rows[] = [
                'invoice_number' => (string) ($invoice['invoice_number'] ?? '—'),
                'title' => (string) ($invoice['title'] ?? 'Untitled invoice'),
                'client' => $client,
                'event_date' => (string) ($invoice['sale_or_service_date'] ?? ''),
                'invoice_total' => $invoiceTotal,
                'outstanding' => $invoiceOutstanding,
                'due_date' => $dueDate,
                'payment_amount' => $remaining,
                'timing' => $timing,
                'bucket' => $timing === 'Future' ? substr($dueDate, 0, 7) : strtolower(str_replace(' ', '-', $timing)),
                'invoice_status' => ucfirst(strtolower(str_replace('_', ' ', (string) ($invoice['status'] ?? '')))),
                'url' => $invoiceUrl,
            ];
        }
    }
    usort($rows, static fn(array $a, array $b): int => [$a['due_date'], $a['invoice_number']] <=> [$b['due_date'], $b['invoice_number']]);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$pastDue = $dueToday = $future = 0;
$pastDueCount = $dueTodayCount = $futureCount = 0;
$chart = [];
foreach ($rows as $row) {
    if ($row['timing'] === 'Past due') {
        $pastDue += $row['payment_amount'];
        $pastDueCount++;
    } elseif ($row['timing'] === 'Due today') {
        $dueToday += $row['payment_amount'];
        $dueTodayCount++;
    }
    else {
        $future += $row['payment_amount'];
        $futureCount++;
        $month = substr($row['due_date'], 0, 7);
        if (!isset($chart[$month])) $chart[$month] = ['amount' => 0, 'count' => 0];
        $chart[$month]['amount'] += $row['payment_amount'];
        $chart[$month]['count']++;
    }
}
ksort($chart);
$chartItems = [];
if ($pastDue > 0) $chartItems[] = ['key' => 'past-due', 'label' => 'Past due', 'amount' => $pastDue, 'count' => $pastDueCount, 'class' => 'danger', 'year_break' => false];
if ($dueToday > 0) $chartItems[] = ['key' => 'due-today', 'label' => 'Due today', 'amount' => $dueToday, 'count' => $dueTodayCount, 'class' => 'today', 'year_break' => false];
$previousYear = null;
foreach ($chart as $month => $totals) {
    $date = DateTimeImmutable::createFromFormat('!Y-m', $month);
    $year = substr($month, 0, 4);
    $chartItems[] = ['key' => $month, 'label' => $date ? $date->format("M 'y") : $month, 'amount' => $totals['amount'], 'count' => $totals['count'], 'class' => 'future', 'year_break' => $previousYear !== null && $year !== $previousYear];
    $previousYear = $year;
}
$chartMax = max(1, ...array_column($chartItems, 'amount'));
$grandOutstanding = $pastDue + $dueToday + $future;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Square Invoice Payment Forecast</title>
<style>
:root{--ink:#e8edf2;--muted:#8b98a7;--panel:#121922;--line:#26313e;--bg:#080d13;--blue:#57a8ff;--red:#ff6b70;--gold:#efb94e}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif}main{width:min(1600px,calc(100% - 40px));margin:auto;padding:38px 0 70px}.top{display:flex;justify-content:space-between;align-items:end;gap:20px;margin-bottom:24px}.eyebrow{display:block;color:var(--blue);font-size:11px;letter-spacing:.16em;font-weight:800;text-transform:uppercase;margin-bottom:7px}h1,h2,p{margin:0}h1{font-size:32px;letter-spacing:-.04em}h2{font-size:18px}.asof{color:var(--muted);margin-top:8px;font-size:12px}.refresh,.clearGraph{border:1px solid #37638f;background:#123150;color:#dbeeff;border-radius:7px;padding:10px 15px;font-weight:750;cursor:pointer}.clearGraph[hidden]{display:none}.error{border:1px solid #7b3034;background:#32171a;color:#ffc4c6;padding:15px;margin-bottom:18px}.metrics{display:grid;grid-template-columns:repeat(4,1fr);border:1px solid var(--line);background:var(--panel);margin-bottom:18px}.metrics article{position:relative;padding:18px 20px;border-right:1px solid var(--line)}.metrics article:last-child{border:0}.metrics article:not(:first-child)::before{position:absolute;left:0;top:50%;transform:translate(-50%,-50%);width:23px;height:23px;border:1px solid var(--line);border-radius:50%;display:grid;place-items:center;background:var(--panel);color:#b8c4d0;font-size:16px;font-weight:850;line-height:1;z-index:2}.metrics article:nth-child(2)::before,.metrics article:nth-child(3)::before{content:"+"}.metrics article:nth-child(4)::before{content:"=";color:var(--blue)}.metrics span,.metrics small{display:block;color:var(--muted);font-size:11px}.metrics strong{display:block;font-size:25px;letter-spacing:-.04em;margin:7px 0 2px}.red{color:var(--red)}.panel{background:var(--panel);border:1px solid var(--line);margin-top:18px}.head{padding:18px 20px 15px;display:flex;align-items:center;justify-content:space-between;gap:16px}.chartActions{display:flex;align-items:center;gap:14px}.selectionSummary{margin:0 20px 14px;padding:13px 16px;border:1px solid #3d6f9f;border-radius:8px;background:linear-gradient(90deg,#102b43,#0e2133);display:flex;align-items:center;gap:18px;box-shadow:inset 4px 0 var(--blue)}.selectionSummary[hidden]{display:none}.selectionSummary span{color:#9fcfff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em}.selectionSummary strong{color:#f0f7ff;font-size:23px;line-height:1}.selectionSummary small{color:var(--muted);font-size:11px}.legend{display:flex;gap:14px;color:var(--muted);font-size:11px}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px}.dot.future{background:var(--blue)}.dot.danger{background:var(--red)}.dot.today{background:var(--gold)}.chart{min-height:230px;padding:25px 18px 18px;border-top:1px solid var(--line);overflow-x:auto;background:linear-gradient(to top,rgba(87,168,255,.035),transparent)}.chartTrack{min-width:calc(var(--bar-count)*78px);width:100%;display:grid;grid-template-columns:repeat(var(--bar-count),minmax(62px,1fr));align-items:end}.barcol{all:unset;box-sizing:border-box;position:relative;height:180px;min-width:62px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:5px;cursor:pointer;border-radius:5px;padding:0 7px}.barcol:hover,.barcol:focus-visible{background:#182431;outline:none}.barcol.selected{background:#1b3147;box-shadow:inset 0 0 0 1px #4b8dcc}.barcol.yearBreak::before{content:"";position:absolute;left:0;top:0;bottom:0;width:1px;background:#536273}.bar{display:block;width:min(44px,70%);border-radius:4px 4px 0 0}.bar.future{background:linear-gradient(#78b9ff,#367fca)}.bar.danger{background:linear-gradient(#ff8589,#c74349)}.bar.today{background:linear-gradient(#ffd378,#b47a16)}.barvalue{font-size:10px;font-weight:750}.barcount,.barlabel{font-size:10px;color:var(--muted)}.filters{display:flex;gap:8px}.filters input,.filters select{color:var(--ink);background:#0b1118;border:1px solid var(--line);border-radius:6px;padding:8px 10px}.filters input{width:220px}.tablewrap{overflow:auto;border-top:1px solid var(--line)}table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}th{color:var(--muted);text-align:left;text-transform:uppercase;font-size:9px;letter-spacing:.08em;padding:10px 12px;background:#0d131b;border-bottom:1px solid var(--line)}th button{all:unset;cursor:pointer}td{padding:13px 12px;border-bottom:1px solid #202a35}tbody tr:hover{background:#151f2a}td small{display:block;color:var(--muted);margin-top:3px;font-size:10px}.link{color:#83bdff;text-decoration:none;font-weight:800}.link:hover{text-decoration:underline}.dueCell{border-left:3px solid transparent}.dueCell.past-due{background:rgba(255,107,112,.13);border-left-color:var(--red)}.dueCell.due-today{background:rgba(239,185,78,.14);border-left-color:var(--gold)}.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-weight:800;font-size:9px}.badge.unpaid{color:#ffb9bc;background:#481e22}.badge.partially-paid{color:#ffd687;background:#493611}.badge.scheduled{color:#a8d2ff;background:#173654}footer{color:var(--muted);font-size:10px;padding:11px 13px;background:#0d131b}.empty{padding:35px;text-align:center;color:var(--muted)}
@media(max-width:900px){main{width:calc(100% - 24px);padding-top:24px}.top,.head{align-items:flex-start;flex-direction:column}.metrics{grid-template-columns:1fr 1fr}.metrics article:nth-child(2){border-right:0}.metrics article::before{display:none!important}.filters{width:100%}.filters input{width:100%}.chartActions{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.metrics{grid-template-columns:1fr}.metrics article{border-right:0;border-bottom:1px solid var(--line)}.metrics article:not(:first-child)::before{display:grid!important;left:50%;top:0;transform:translate(-50%,-50%)}.filters{flex-direction:column}.refresh{width:100%}}
</style>
</head>
<body><main>
<header class="top"><div><span class="eyebrow"><?=h($businessName)?></span><h1>Invoice payment forecast</h1><p class="asof">As of <?=h($asOf)?> · Square <?=h($config['environment'])?></p></div><button class="refresh" onclick="location.reload()">↻ Refresh from Square</button></header>
<?php if ($error): ?><div class="error"><strong>Report unavailable.</strong> <?=h($error)?></div><?php endif; ?>
<section class="metrics" aria-label="Past due plus due today plus future scheduled equals total outstanding"><article><span>Past due</span><strong class="red"><?=money($pastDue)?></strong><small><?=count(array_filter($rows,fn($r)=>$r['timing']==='Past due'))?> payments</small></article><article><span>Due today</span><strong><?=money($dueToday)?></strong><small><?=count(array_filter($rows,fn($r)=>$r['timing']==='Due today'))?> payments</small></article><article><span>Future scheduled</span><strong><?=money($future)?></strong><small><?=count(array_filter($rows,fn($r)=>$r['timing']==='Future'))?> payments</small></article><article><span>Total outstanding</span><strong><?=money($grandOutstanding)?></strong><small><?=$invoiceCount?> active invoices</small></article></section>
<section class="panel"><div class="head"><div><span class="eyebrow">Cash-flow view</span><h2>Expected invoice payments by month</h2><p class="asof">Select one or more bars to filter the payment table.</p></div><div class="chartActions"><div class="legend"><span><i class="dot danger"></i>Past due</span><span><i class="dot today"></i>Due today</span><span><i class="dot future"></i>Future due</span></div><button type="button" class="clearGraph" id="clearGraph" hidden>Clear graph filters</button></div></div><div class="selectionSummary" id="selectionSummary" hidden aria-live="polite"><span>Selected graph total</span><strong id="selectedTotal">$0.00</strong><small id="selectedMeta"></small></div>
<div class="chart"><?php if (!$chartItems): ?><div class="empty">No outstanding invoice payments found.</div><?php else: ?><div class="chartTrack" style="--bar-count:<?=count($chartItems)?>"><?php foreach($chartItems as $item): $height=max(18,(int)round($item['amount']/$chartMax*135)); ?><button type="button" class="barcol<?=$item['year_break']?' yearBreak':''?>" data-bucket="<?=h($item['key'])?>" data-amount="<?=$item['amount']?>" data-count="<?=$item['count']?>" aria-pressed="false" aria-label="<?=h($item['label'].' '.money($item['amount']).', '.$item['count'].' payments')?>"><span class="barvalue"><?=money($item['amount'])?></span><span class="barcount"><?=$item['count']?> payment<?=$item['count']===1?'':'s'?></span><span class="bar <?=h($item['class'])?>" style="height:<?=$height?>px"></span><span class="barlabel"><?=h($item['label'])?></span></button><?php endforeach; ?></div><?php endif; ?></div></section>
<section class="panel"><div class="head"><div><span class="eyebrow">Payment detail</span><h2>Outstanding payment schedule</h2></div><div class="filters"><input id="search" placeholder="Search invoice or client" aria-label="Search invoices"><select id="timing" aria-label="Filter by timing"><option value="">All payments</option><option>Past due</option><option>Due today</option><option>Future</option></select></div></div>
<div class="tablewrap"><table id="payments"><thead><tr><th><button data-sort="invoice">Invoice ↕</button></th><th><button data-sort="client">Client / title ↕</button></th><th><button data-sort="event">Event date ↕</button></th><th data-sort="number">Total</th><th data-sort="number">Outstanding</th><th><button data-sort="due">Payment due ↕</button></th><th data-sort="number">Payment</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $row): $dueClass=$row['timing']==='Past due'?' past-due':($row['timing']==='Due today'?' due-today':''); ?><tr data-search="<?=h(strtolower($row['invoice_number'].' '.$row['client'].' '.$row['title']))?>" data-timing="<?=h($row['timing'])?>" data-bucket="<?=h($row['bucket'])?>"><td data-value="<?=h($row['invoice_number'])?>"><a class="link" href="<?=h($row['url'])?>" target="_blank" rel="noopener"><?=h($row['invoice_number'])?> ↗</a></td><td data-value="<?=h($row['client'])?>"><strong><?=h($row['client'])?></strong><small><?=h($row['title'])?></small></td><td data-value="<?=h($row['event_date'])?>"><?=h(displayDate($row['event_date']))?></td><td data-value="<?=$row['invoice_total']?>"><?=money($row['invoice_total'])?></td><td data-value="<?=$row['outstanding']?>"><?=money($row['outstanding'])?></td><td class="dueCell<?=$dueClass?>" data-value="<?=h($row['due_date'])?>"><strong><?=h(displayDate($row['due_date']))?></strong></td><td data-value="<?=$row['payment_amount']?>"><strong><?=money($row['payment_amount'])?></strong></td><td><span class="badge <?=h(strtolower(str_replace(' ','-',$row['invoice_status'])))?>"><?=h($row['invoice_status'])?></span></td></tr><?php endforeach; ?>
</tbody></table></div><footer><span id="shown"><?=count($rows)?></span> outstanding payments shown · Amounts reflect unpaid balances as of report time</footer></section>
</main><script>
const table=document.querySelector('#payments'),body=table.tBodies[0],search=document.querySelector('#search'),timing=document.querySelector('#timing'),shown=document.querySelector('#shown'),clearGraph=document.querySelector('#clearGraph'),selectionSummary=document.querySelector('#selectionSummary'),selectedTotal=document.querySelector('#selectedTotal'),selectedMeta=document.querySelector('#selectedMeta'),selectedBuckets=new Set(),currency=new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'});
function updateSelectionSummary(){const bars=[...document.querySelectorAll('.barcol.selected')],active=bars.length>0;selectionSummary.hidden=!active;if(!active)return;const cents=bars.reduce((sum,bar)=>sum+Number(bar.dataset.amount||0),0),payments=bars.reduce((sum,bar)=>sum+Number(bar.dataset.count||0),0),monthsOnly=bars.every(bar=>/^\d{4}-\d{2}$/.test(bar.dataset.bucket)),periodWord=monthsOnly?'month':'period';selectedTotal.textContent=currency.format(cents/100);selectedMeta.textContent=bars.length+' '+periodWord+(bars.length===1?'':'s')+' · '+payments+' payment'+(payments===1?'':'s')}
function filter(){let n=0,q=search.value.trim().toLowerCase();[...body.rows].forEach(r=>{let graphOk=selectedBuckets.size===0||selectedBuckets.has(r.dataset.bucket),ok=(!q||r.dataset.search.includes(q))&&(!timing.value||r.dataset.timing===timing.value)&&graphOk;r.hidden=!ok;if(ok)n++});shown.textContent=n;clearGraph.hidden=selectedBuckets.size===0;clearGraph.textContent=selectedBuckets.size?'Clear graph filters ('+selectedBuckets.size+')':'Clear graph filters';updateSelectionSummary()}
document.querySelectorAll('.barcol[data-bucket]').forEach(bar=>bar.addEventListener('click',()=>{let key=bar.dataset.bucket;if(selectedBuckets.has(key))selectedBuckets.delete(key);else selectedBuckets.add(key);bar.classList.toggle('selected',selectedBuckets.has(key));bar.setAttribute('aria-pressed',selectedBuckets.has(key)?'true':'false');filter()}));
clearGraph.addEventListener('click',()=>{selectedBuckets.clear();document.querySelectorAll('.barcol.selected').forEach(bar=>{bar.classList.remove('selected');bar.setAttribute('aria-pressed','false')});filter()});
search.addEventListener('input',filter);timing.addEventListener('change',filter);
document.querySelectorAll('th button[data-sort]').forEach(btn=>btn.addEventListener('click',()=>{let index=btn.closest('th').cellIndex,dir=btn.dataset.dir==='asc'?'desc':'asc';btn.dataset.dir=dir;let list=[...body.rows];list.sort((a,b)=>{let av=a.cells[index].dataset.value||a.cells[index].innerText,bv=b.cells[index].dataset.value||b.cells[index].innerText;return (av.localeCompare(bv,undefined,{numeric:true})*(dir==='asc'?1:-1))});list.forEach(r=>body.appendChild(r));}));
</script></body></html>
