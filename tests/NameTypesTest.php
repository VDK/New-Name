<?php
require __DIR__ . '/../src/Data/NameTypes.php';
use App\Data\NameTypes;
$cases = [
    [null, null],
    ['', null],
    ['unknown', null],
    ['family_name_typo', null],
    ['given', NameTypes::GIVEN_SCOPE],
];
foreach (NameTypes::URL_TYPES as $input => $expected) {
    $cases[] = [$input, $expected];
}
foreach (NameTypes::TYPE_ITEMS as $input => $qid) {
    $cases[] = [$input, $input];
}
$failures = [];
foreach ($cases as [$input, $expected]) {
    $actual = NameTypes::fromUrl($input);
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', var_export($input, true), var_export($expected, true), var_export($actual, true));
    }
}
foreach (NameTypes::URL_TYPES as $urlType => $type) {
    if (NameTypes::toUrl($type) !== $urlType) {
        $failures[] = 'URL alias changed for ' . $type;
    }
}
if ($failures !== []) {
    throw new RuntimeException(implode("\n", $failures));
}
echo 'NameTypes URL tests passed (' . count($cases) . " parser cases and all URL aliases).\n";
