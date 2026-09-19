<?php
// Standalone sanity check: replicates the exact day-by-day pricing
// loop from MovingAverageCostingService::recalculateItem() against
// the same numbers verified earlier from Inventory.xlsx, to confirm
// the algorithm (independent of Laravel/DB) produces the same
// results as the spreadsheet.

$price = [110];
for ($i = 0; $i < 9; $i++) {
    if (in_array($i, [0, 1, 2], true)) {
        $price[] = end($price) + 5;
    } elseif (in_array($i, [3, 4, 5, 6], true)) {
        $price[] = end($price) - 3;
    } else {
        $price[] = end($price) + 7;
    }
}

$purchaseQty = [100, 120, 150, 90, 0, 70, 60, 0, 40, 0];
$sold        = [75, 60, 30, 150, 60, 0, 80, 0, 100, 0];

$expected = [
    ['avg' => 110.0000, 'endQty' => 25,  'endVal' => 2750.00],
    ['avg' => 114.1379, 'endQty' => 85,  'endVal' => 9701.72],
    ['avg' => 117.8797, 'endQty' => 205, 'endVal' => 24165.33],
    ['avg' => 120.0520, 'endQty' => 145, 'endVal' => 17407.54],
    ['avg' => 120.0520, 'endQty' => 85,  'endVal' => 10204.42],
    ['avg' => 119.5769, 'endQty' => 155, 'endVal' => 18534.42],
    ['avg' => 118.5787, 'endQty' => 135, 'endVal' => 16008.12],
    ['avg' => 118.5787, 'endQty' => 135, 'endVal' => 16008.12],
    ['avg' => 118.9036, 'endQty' => 75,  'endVal' => 8917.77],
    ['avg' => 118.9036, 'endQty' => 75,  'endVal' => 8917.77],
];

$runningQty = 0.0;
$runningValue = 0.0;
$allPass = true;

foreach (range(0, 9) as $d) {
    $dayBeginningQty = $runningQty;
    $dayBeginningValue = $runningValue;

    $qtyIn = (float) $purchaseQty[$d];
    $valueIn = $qtyIn * $price[$d];

    // Exactly the same two lines as recalculateItem().
    $availableQty = round($dayBeginningQty + $qtyIn, 2);
    $availableValue = round($dayBeginningValue + $valueIn, 2);
    $averageCost = $availableQty > 0 ? round($availableValue / $availableQty, 4) : 0.0;

    $qtyOut = round((float) $sold[$d], 2);
    $valueOut = round($qtyOut * $averageCost, 2);

    $endingQty = round($availableQty - $qtyOut, 2);
    $endingValue = round($availableValue - $valueOut, 2);

    $exp = $expected[$d];
    $pass = abs($averageCost - $exp['avg']) < 0.01
        && abs($endingQty - $exp['endQty']) < 0.01
        && abs($endingValue - $exp['endVal']) < 0.5; // rounding drift tolerance across 10 chained days

    $allPass = $allPass && $pass;

    printf(
        "Day %2d | avg %10.4f (exp %10.4f) | endQty %6.2f (exp %6.2f) | endVal %10.2f (exp %10.2f) | %s\n",
        $d + 1, $averageCost, $exp['avg'], $endingQty, $exp['endQty'], $endingValue, $exp['endVal'],
        $pass ? 'OK' : 'MISMATCH'
    );

    $runningQty = $endingQty;
    $runningValue = $endingValue;
}

echo $allPass ? "\nALL DAYS MATCH THE SPREADSHEET\n" : "\nMISMATCH FOUND\n";
exit($allPass ? 0 : 1);
