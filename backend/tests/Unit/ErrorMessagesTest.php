<?php

use Symfony\Component\Finder\Finder;

it('has a client message for every error code the API raises', function () {
    $codes = [];
    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        preg_match_all("/ApiException\\(\\s*'([a-z_]+)'/", $file->getContents(), $matches);
        $codes = [...$codes, ...$matches[1]];
    }

    $messages = require lang_path('en/errors.php');
    $missing = array_values(array_diff(array_unique($codes), array_keys($messages)));

    expect($codes)->not->toBeEmpty()->and($missing)->toBe([]);
});
