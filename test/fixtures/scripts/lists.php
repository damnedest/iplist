<?php

declare(strict_types=1);

return [
    'both'      => ['games/game-a.json', 'tools/tool-a.json'],
    'games'     => ['games/*.json'],
    'withcheck' => ['games/*.json', 'check/*.json'],
];
