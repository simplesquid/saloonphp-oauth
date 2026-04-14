<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('strict types are used everywhere')
    ->expect('SimpleSquid\SaloonOAuth')
    ->toUseStrictTypes();

arch('contracts are interfaces')
    ->expect('SimpleSquid\SaloonOAuth\Contracts')
    ->toBeInterfaces();

arch('concerns are traits')
    ->expect('SimpleSquid\SaloonOAuth\Concerns')
    ->toBeTraits();
