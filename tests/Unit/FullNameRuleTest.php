<?php

use App\Rules\FullName;

test('full name rule accepts two or more words', function (string $name): void {
    $rule = new FullName;
    $failed = false;

    $rule->validate('name', $name, function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
})->with([
    'John Doe',
    'Jane Marie Smith',
    'María García',
    'Li Wei',
]);

test('full name rule rejects single word', function (string $name): void {
    $rule = new FullName;
    $message = null;

    $rule->validate('name', $name, function (string $msg) use (&$message): void {
        $message = $msg;
    });

    expect($message)->toContain('first name and surname');
})->with([
    'John',
    'Admin',
    '',
]);

test('full name rule handles extra whitespace', function (): void {
    $rule = new FullName;
    $failed = false;

    $rule->validate('name', '  John   Doe  ', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});
