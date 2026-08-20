<?php

declare(strict_types=1);

use Aliziodev\Singapay\Endpoints\Endpoint;
use Aliziodev\Singapay\Events\WebhookReceived;
use Aliziodev\Singapay\Exceptions\SingaPayException;

arch('all source files declare strict types')
    ->expect('Aliziodev\Singapay')
    ->toUseStrictTypes();

arch('no debug output ships in the package')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('endpoint groups extend the shared base')
    ->expect('Aliziodev\Singapay\Endpoints')
    ->classes()
    ->toExtend(Endpoint::class)
    ->ignoring(Endpoint::class);

arch('every exception derives from the package base exception')
    ->expect('Aliziodev\Singapay\Exceptions')
    ->classes()
    ->toExtend(SingaPayException::class)
    ->ignoring(SingaPayException::class);

arch('every webhook event derives from the generic event')
    ->expect('Aliziodev\Singapay\Events')
    ->classes()
    ->toExtend(WebhookReceived::class)
    ->ignoring(WebhookReceived::class);

arch('contracts are interfaces')
    ->expect('Aliziodev\Singapay\Contracts')
    ->toBeInterfaces();

arch('the SDK core never depends on facades')
    ->expect('Aliziodev\Singapay\Http')
    ->not->toUse('Illuminate\Support\Facades');
