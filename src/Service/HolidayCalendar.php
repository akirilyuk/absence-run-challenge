<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class HolidayCalendar
{
    /** @var array<string, array<int, list<string>>> */
    private const HOLIDAYS = [
        'BY' => [
            2025 => [
                '2025-01-01',
                '2025-01-06',
                '2025-04-18',
                '2025-04-21',
                '2025-05-01',
                '2025-05-29',
                '2025-06-09',
                '2025-06-19',
                '2025-08-15',
                '2025-10-03',
                '2025-11-01',
                '2025-12-25',
                '2025-12-26',
            ],
        ],
        'BE' => [
            2025 => [
                '2025-01-01',
                '2025-03-08',
                '2025-04-18',
                '2025-04-21',
                '2025-05-01',
                '2025-05-29',
                '2025-06-09',
                '2025-10-03',
                '2025-12-25',
                '2025-12-26',
            ],
        ],
    ];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return list<string> list of Y-m-d holiday dates
     */
    public function holidaysForState(string $state, int $year): array
    {
        $state = strtoupper($state);

        if (!isset(self::HOLIDAYS[$state][$year])) {
            if (!isset(self::HOLIDAYS[$state])) {
                $this->logger->warning('Unknown federal state for holidays.', [
                    'state' => $state,
                    'year' => $year,
                ]);
            }

            return [];
        }

        return self::HOLIDAYS[$state][$year];
    }
}
