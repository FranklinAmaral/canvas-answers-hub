<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = static function (string $message): never {
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
};
$readJson = static function (string $path) use ($fail): array {
    if (! is_file($path)) {
        $fail('Missing license verification input: '.$path);
    }

    try {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $fail('Invalid JSON in license verification input: '.$path);
    }
};
$lock = $readJson($root.'/composer.lock');
$installed = $readJson($root.'/vendor/composer/installed.json');
$lockedPackages = array_column($lock['packages'] ?? [], null, 'name');
$installedPackages = array_column($installed['packages'] ?? [], null, 'name');
$expectedHash = 'b3bc3d38b9ec29d98bc83bd5fa44e583e8303cd97b62d203a9e5e21935126de9';
$notice = $root.'/docs/licenses/NETTE-LICENSE.md';

if (! is_file($root.'/THIRD-PARTY-NOTICES.md') || ! is_file($notice) || hash_file('sha256', $notice) !== $expectedHash) {
    $fail('Nette distribution notices are missing or changed. Preserve THIRD-PARTY-NOTICES.md and docs/licenses/NETTE-LICENSE.md.');
}

foreach (['nette/schema' => 'v1.3.5', 'nette/utils' => 'v4.1.3'] as $name => $version) {
    $locked = $lockedPackages[$name] ?? [];
    $actual = $installedPackages[$name] ?? [];

    if (($locked['version'] ?? null) !== $version || ($actual['version'] ?? null) !== $version) {
        $fail('Review the BSD license adoption and notices for the new or missing version of '.$name.'.');
    }

    if (! in_array('BSD-3-Clause', $locked['license'] ?? [], true) || ! in_array('BSD-3-Clause', $actual['license'] ?? [], true)) {
        $fail('BSD-3-Clause is absent from the package metadata for '.$name.'.');
    }

    $license = $root.'/vendor/'.$name.'/license.md';

    if (! is_file($license) || hash_file('sha256', $license) !== $expectedHash) {
        $fail('The installed license notice for '.$name.' is missing or differs from the verified official version.');
    }
}

fwrite(STDOUT, 'Nette BSD-3-Clause adoption: verified versions and intact distribution notices.'.PHP_EOL);
